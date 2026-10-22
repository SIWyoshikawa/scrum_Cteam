<?php
declare(strict_types=1);

namespace App\Support;

/**
 * テンプレート描画。テンプレート内では必ず h() でエスケープして出力する。
 */
final class View
{
    /** @var array<string,mixed> 全画面共通の値（ログイン中ユーザーなど） */
    private array $shared = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $data += $this->shared;
        $content = $this->renderFile($template, $data);
        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/]+$#', $template)) {
            throw new \InvalidArgumentException('不正なテンプレート名');
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('テンプレートがありません: ' . $template);
        }
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
