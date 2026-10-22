<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 例外・エラーの共通処理（DD 6.5）
 * - 利用者には安全な文言と「参照ID」だけを見せる
 * - SQL・接続先・スタックトレースなどの詳細はサーバ側ログにだけ書く
 */
final class ErrorHandler
{
    private ?View $view = null;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function register(): void
    {
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                $this->logger->warning('deprecated', ['message' => $message, 'file' => $file, 'line' => $line]);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (\Throwable $e): void {
            $this->handle($e)->send(false);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logger->error('fatal', $error);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    $this->renderSafe(500, HttpException::defaultMessage(500))->send(false);
                }
            }
        });
    }

    public function setView(View $view): void
    {
        $this->view = $view;
    }

    /** 例外を「利用者に見せてよいレスポンス」に変換する */
    public function handle(\Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            if ($e->status >= 500) {
                $this->logger->error('http_exception', ['status' => $e->status]);
            }
            $response = $this->renderSafe($e->status, $e->getMessage());
            $response->headers += $e->headers;
            return $response;
        }

        // 想定外の例外：詳細はログだけ（PDOException の SQL や接続情報もここで止まる）
        $this->logger->error('unhandled_exception', [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ]);
        return $this->renderSafe(500, HttpException::defaultMessage(500));
    }

    private function renderSafe(int $status, string $message): Response
    {
        $ref = $this->logger->correlationId();
        if ($this->view !== null) {
            try {
                return new Response($this->view->render('errors/error', [
                    'title'   => 'エラー',
                    'status'  => $status,
                    'message' => $message,
                    'ref'     => $ref,
                ]), $status);
            } catch (\Throwable) {
                // テンプレート自体が壊れていても下の最小表示に落とす
            }
        }
        $body = '<!doctype html><meta charset="utf-8"><title>エラー</title>'
            . '<p>' . h($message) . '</p><p>参照ID: ' . h($ref) . '</p>';
        return new Response($body, $status);
    }
}
