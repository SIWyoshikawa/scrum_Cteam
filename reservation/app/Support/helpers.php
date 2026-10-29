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

/**
 * 予約の状態を「文字＋色」で表示するバッジ（REQ-F-013：色だけに頼らない）
 * $overdue … 貸出中で返却予定日を過ぎている（延滞は状態ではなく派生表示）
 */
function status_badge(string $status, bool $overdue = false): string
{
    if ($overdue) {
        return '<span class="status status-overdue">延滞</span>';
    }
    $label = App\Repositories\ReservationRepository::STATUS_LABELS[$status] ?? '不明';
    $class = preg_match('/^[a-z_]+$/', $status) ? $status : 'unknown';
    return '<span class="status status-' . h($class) . '">' . h($label) . '</span>';
}

/** DATE（Y-m-d）を「2026/11/05（木）」の形に */
function jp_date(string $ymd): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if ($d === false) {
        return h($ymd);
    }
    $w = ['日', '月', '火', '水', '木', '金', '土'][(int) $d->format('w')];
    return $d->format('Y/m/d') . "（{$w}）";
}

/** フォームに埋め込むCSRFトークン DD 6.3 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(Csrf::token()) . '">';
}
