<?php
declare(strict_types=1);

namespace App\Support;

/**
 * セッション管理（DD 6.3 / NF-003）
 * - Cookie: HttpOnly / SameSite=Lax / HTTPSなら Secure
 * - 30分無操作で期限切れ
 * - ログイン成功時にセッションIDを作り直す（セッション固定攻撃対策）
 * - ログアウト時にセッションとCookieを破棄
 */
final class Session
{
    private static string $cookiePath = '/';

    /** @param array{name:string, idle_timeout:int} $config */
    public static function start(array $config, string $basePath, bool $isHttps): void
    {
        ini_set('session.use_strict_mode', '1');   // 知らないセッションIDを受け付けない
        ini_set('session.use_only_cookies', '1');  // URLにセッションIDを載せない
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) $config['idle_timeout']);

        self::$cookiePath = $basePath === '' ? '/' : $basePath . '/';

        session_name($config['name']);
        session_set_cookie_params([
            'lifetime' => 0,               // ブラウザを閉じたら消える
            'path'     => self::$cookiePath,
            'secure'   => $isHttps,
            'httponly' => true,            // JavaScriptから読めない
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $last = $_SESSION['_last_activity'] ?? null;
        if (is_int($last) && ($now - $last) > $config['idle_timeout']) {
            $wasLoggedIn = isset($_SESSION['user_id']);
            self::clear();
            session_regenerate_id(true);
            if ($wasLoggedIn) {
                self::flash('info', '30分以上操作がなかったため、ログアウトしました。もう一度ログインしてください。');
            }
        }
        $_SESSION['_last_activity'] = $now;
    }

    /** ログイン成功時：新しいIDに切り替え、古いIDのデータは消す */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
        Csrf::rotate();
    }

    public static function clear(): void
    {
        $_SESSION = [];
    }

    /** ログアウト：サーバ側データとブラウザ側Cookieの両方を消す */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'secure'   => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string, message:string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }
}
