<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 予約申請の入力チェック（DBを使わない部分。単体テストできるよう分けている）
 *
 * ルール（config/app.php の reservation。オーナーSQLの注意書きの解釈。PO確認待ち）
 *   - 日付は YYYY-MM-DD。返却予定日は利用開始日以降（REQ-F-005）
 *   - 利用開始日は今日〜今日+max_advance_days 日（90日先まで）
 *   - 期間は開始日・返却予定日を含めて max_days 日以内（7日以内）
 *   - 進行中の予約が max_active 件以上なら申請できない（最大5件）
 *   - 延滞中の貸出がある人は申請できない（延滞利用禁止）
 */
final class ReservationRules
{
    /** @param array{max_days:int,max_advance_days:int,max_active:int,max_items:int,max_quantity:int} $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @return array<string,int> 画面の案内用 */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @param array<int,string> $rawQuantities equipment_id => 入力された数量（文字列）
     * @return array{errors:list<string>, items:array<int,int>} items は数量1以上のものだけ
     */
    public function validate(
        string $start,
        string $end,
        string $purpose,
        array $rawQuantities,
        string $today,
        int $activeCount,
        bool $hasOverdue,
    ): array {
        $errors = [];
        $c = $this->config;

        if ($hasOverdue) {
            $errors[] = '返却予定日を過ぎた貸出があるため、新しい申請はできません。先に返却してください。';
        }
        if ($activeCount >= $c['max_active']) {
            $errors[] = "進行中の予約（申請中・承認済み・貸出中）は {$c['max_active']} 件までです。";
        }

        $s = self::parseDate($start);
        $e = self::parseDate($end);
        if ($s === null) {
            $errors[] = '利用開始日を正しく入力してください。';
        }
        if ($e === null) {
            $errors[] = '返却予定日を正しく入力してください。';
        }
        if ($s !== null && $e !== null) {
            $t = self::parseDate($today) ?? throw new \LogicException('today が不正');
            $limit = $t->modify("+{$c['max_advance_days']} days");
            if ($s < $t) {
                $errors[] = '利用開始日に過去の日付は指定できません。';
            } elseif ($s > $limit) {
                $errors[] = "利用開始日は今日から {$c['max_advance_days']} 日先（" . $limit->format('Y-m-d') . '）までです。';
            }
            if ($e < $s) {
                $errors[] = '返却予定日は利用開始日と同じか、それより後の日付にしてください。';
            } elseif ((int) $s->diff($e)->days + 1 > $c['max_days']) {
                $errors[] = "借りられる期間は {$c['max_days']} 日以内（開始日と返却予定日を含む）です。";
            }
        }

        $purpose = trim($purpose);
        if ($purpose === '') {
            $errors[] = '利用目的を入力してください。';
        } elseif (mb_strlen($purpose) > 500) {
            $errors[] = '利用目的は500文字以内で入力してください。';
        } elseif (preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', $purpose) || !mb_check_encoding($purpose, 'UTF-8')) {
            $errors[] = '利用目的に使えない文字が含まれています。';
        }

        $items = [];
        $badQty = false;
        foreach ($rawQuantities as $id => $raw) {
            $raw = trim($raw);
            if ($raw === '' || $raw === '0') {
                continue;
            }
            if (!preg_match('/^[1-9][0-9]{0,4}$/', $raw) || (int) $raw > $c['max_quantity']) {
                $badQty = true;
                continue;
            }
            $items[(int) $id] = (int) $raw;
        }
        if ($badQty) {
            $errors[] = "数量は 1〜{$c['max_quantity']} の整数で入力してください。";
        } elseif ($items === []) {
            $errors[] = '借りたい備品の数量を1つ以上入力してください。';
        } elseif (count($items) > $c['max_items']) {
            $errors[] = "1回の申請に入れられる備品は {$c['max_items']} 種類までです。";
        }

        return ['errors' => $errors, 'items' => $items];
    }

    /** 実在する日付の YYYY-MM-DD だけ受け付ける（2026-02-30 などは不可） */
    public static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Tokyo'));
        return $d !== false && $d->format('Y-m-d') === $value ? $d : null;
    }
}
