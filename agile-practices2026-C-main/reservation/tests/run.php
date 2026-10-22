<?php
declare(strict_types=1);

/**
 * 単体テストの簡易ランナー（DBもWebサーバも不要）
 *   php tests/run.php
 */

define('BASE_PATH', dirname(__DIR__));
error_reporting(E_ALL);
set_error_handler(static function (int $s, string $m, string $f, int $l): bool {
    throw new ErrorException($m, 0, $s, $f, $l);
});
spl_autoload_register(static function (string $class): void {
    foreach (['App\\Tests\\' => '/tests/', 'App\\' => '/app/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = BASE_PATH . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
require BASE_PATH . '/app/Support/helpers.php';

function assert_same(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf("%s\n    期待: %s\n    実際: %s", $label, var_export($expected, true), var_export($actual, true)));
    }
}

function assert_true(bool $cond, string $label = ''): void
{
    assert_same(true, $cond, $label);
}

/** @param callable():void $fn */
function assert_throws(string $class, callable $fn, string $label = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new RuntimeException("{$label}: {$class} を期待したが " . $e::class . ' が発生');
    }
    throw new RuntimeException("{$label}: 例外 {$class} が発生しなかった");
}

$passed = 0;
$failed = 0;
foreach (glob(__DIR__ . '/Unit/*Test.php') ?: [] as $file) {
    /** @var array<string, callable():void> $cases */
    $cases = require $file;
    foreach ($cases as $name => $case) {
        $_SESSION = [];
        try {
            $case();
            $passed++;
            echo "  OK   " . basename($file, '.php') . " › {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  NG   " . basename($file, '.php') . " › {$name}\n    " . $e->getMessage() . "\n";
        }
    }
}
echo "\n合計: {$passed} 件成功 / {$failed} 件失敗\n";
exit($failed === 0 ? 0 : 1);
