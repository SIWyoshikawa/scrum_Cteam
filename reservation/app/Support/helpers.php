<?php
declare(strict_types=1);

/**
 * テンプレートで使う共通関数
 */

use App\Support\Csrf;
use App\Support\Url;

/**
 * HTMLエスケープ（HTML本文・属性の両方で使える）DD 6.2
 * テンプレートで変数を出すときは必ず h() を通す。
 */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** アプリ内URL（ベースパス付き）。外部URLは作らない。 */
function url(string $path = '/'): string
{
    return Url::to($path);
}

/** フォームに埋め込むCSRFトークン DD 6.3 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(Csrf::token()) . '">';
}
