<?php
declare(strict_types=1);

namespace App\Support;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\Middleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\AuthAuditLogRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\AuthAuditLogger;
use App\Services\AuthService;
use App\Services\LoginThrottle;
use App\Services\PasswordPolicy;

/**
 * アプリ本体：1リクエストの流れをまとめる
 *   HTTPSチェック → セッション開始 → ログイン中ユーザー読み込み
 *   → ルート照合 → CSRF照合（POST） → ミドルウェア（認証・権限） → コントローラ
 */
final class App
{
    /** @var array<string, class-string<Middleware>> */
    private const MIDDLEWARE = [
        'auth'  => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'role'  => RoleMiddleware::class,
    ];

    public readonly Database $db;
    public readonly View $view;
    public Request $request;
    /** 操作元IP（監査ログ用）。コマンドから使うときは null */
    public ?string $ip = null;
    /** @var array<string,mixed>|null ログイン中ユーザー（DBから毎回読み直す） */
    public ?array $user = null;

    private ?AuthService $auth = null;
    private ?AuthAuditLogger $audit = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        public readonly array $config,
        public readonly Logger $logger,
        private readonly ErrorHandler $errorHandler,
    ) {
        Url::setBase($config['base_path']);
        $this->db = new Database($config['db']);
        $this->view = new View(BASE_PATH . '/app/Views');
        $this->view->share('appName', $config['app_name']);
        $this->errorHandler->setView($this->view);
    }

    public function run(): void
    {
        $this->request = Request::fromGlobals($this->config['base_path']);
        $this->ip = $this->request->ip();
        try {
            $this->enforceHttps();
            Session::start($this->config['session'], $this->config['base_path'], $this->request->isHttps());
            $this->loadCurrentUser();
            $response = $this->dispatch();
        } catch (\Throwable $e) {
            $response = $this->errorHandler->handle($e);
        }
        $response->send($this->request->isHttps());
    }

    private function dispatch(): Response
    {
        $route = require BASE_PATH . '/app/routes.php';
        /** @var Router $route */
        $match = $route->match($this->request->method, $this->request->path);

        // 状態を変えるリクエストは、ルートに関係なく必ずCSRF照合（DD 6.3）
        if (!$this->request->isSafeMethod()) {
            $blocked = (new CsrfMiddleware())->handle($this, $this->request, null);
            if ($blocked !== null) {
                return $blocked;
            }
        }

        foreach ($match['middleware'] as $spec) {
            [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
            $class = self::MIDDLEWARE[$name] ?? throw new \LogicException('未知のミドルウェア: ' . $name);
            $blocked = (new $class())->handle($this, $this->request, $arg);
            if ($blocked !== null) {
                return $blocked;
            }
        }

        $this->view->share('currentUser', $this->user);
        $this->view->share('flashes', Session::takeFlashes());

        [$class, $method] = $match['handler'];
        return (new $class($this))->{$method}($this->request, $match['params']);
    }

    /** 学内LANなど localhost 以外から HTTP で来たら拒否（localhost開発のみHTTP可 DD 6.3） */
    private function enforceHttps(): void
    {
        if ($this->config['require_https_for_remote']
            && !$this->request->isHttps()
            && !$this->request->isFromLocalhost()) {
            throw new HttpException(403, 'このシステムは HTTPS で接続してください。');
        }
    }

    /**
     * ログイン中ユーザーの読み込み（DB設計書 5-4）
     * 毎リクエスト、user_sessions の行・未失効・ユーザーactive・30分以内の操作 を確認する。
     * どれかがダメなら PHP セッションも破棄してログアウト扱い。
     */
    private function loadCurrentUser(): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId)) {
            return;
        }
        $sessions = $this->sessions();
        $tokenHash = SessionRepository::hashToken(session_id());
        $state = $sessions->check($tokenHash, $userId, $this->config['session']['idle_timeout']);
        $user = $state === 'valid' ? $this->users()->findById($userId) : null;

        if ($user === null) {
            if ($state === 'expired') {
                $sessions->revoke($tokenHash);
            }
            Session::clear();
            session_regenerate_id(true);
            Session::flash('info', $state === 'expired'
                ? '30分以上操作がなかったため、ログアウトしました。もう一度ログインしてください。'
                : 'ログイン状態が無効になりました。もう一度ログインしてください。');
            return;
        }

        $sessions->touch($tokenHash);
        $this->user = $user;
    }

    // ---- 依存オブジェクト（簡易DIコンテナ） ----

    public function users(): UserRepository
    {
        return new UserRepository($this->db->pdo());
    }

    public function sessions(): SessionRepository
    {
        return new SessionRepository($this->db->pdo());
    }

    public function audit(): AuthAuditLogger
    {
        return $this->audit ??= new AuthAuditLogger(new AuthAuditLogRepository($this->db->pdo()), $this->ip);
    }

    public function passwordPolicy(): PasswordPolicy
    {
        return new PasswordPolicy($this->config['password']);
    }

    public function auth(): AuthService
    {
        return $this->auth ??= new AuthService(
            $this->db,
            $this->users(),
            $this->sessions(),
            new LoginThrottle(new LoginAttemptRepository($this->db->pdo()), $this->config['login_throttle']),
            $this->audit(),
            $this->passwordPolicy(),
            $this->ip,
        );
    }

    public function accounts(): AccountService
    {
        return new AccountService($this->db, $this->users(), $this->sessions(), $this->audit(), $this->passwordPolicy());
    }
}
