<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ReservationRules;
use App\Support\Clock;
use App\Support\Request;
use App\Support\Response;

/** 備品の一覧・検索（REQ-F-003 / 004）。期間を入れると、その期間の空き数も出す */
final class ItemController extends Controller
{
    /** GET /items?q=&category=&start=&end= */
    public function index(Request $request, array $params): Response
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 50);
        $cat = $request->query('category');
        $categoryId = $cat !== null && preg_match('/^[1-9][0-9]{0,9}$/', $cat) ? (int) $cat : null;

        $start = (string) $request->query('start');
        $end = (string) $request->query('end');
        $today = Clock::todayJst();
        $periodError = null;
        $hasPeriod = false;
        if ($start !== '' || $end !== '') {
            $s = ReservationRules::parseDate($start);
            $e = ReservationRules::parseDate($end);
            if ($s === null || $e === null || $e < $s) {
                $periodError = '期間は「開始日 ≦ 返却予定日」の正しい日付で入力してください。';
            } else {
                $hasPeriod = true;
            }
        }

        $repo = $this->app->equipment();
        $items = $repo->search($q, $categoryId);
        if ($hasPeriod && $items !== []) {
            $reserved = $repo->reservedQuantities(array_column($items, 'id'), $start, $end, $today);
            foreach ($items as &$it) {
                $it['free'] = max(0, $it['total_quantity'] - ($reserved[$it['id']] ?? 0));
            }
            unset($it);
        }

        return $this->view('items/index', [
            'title'       => '備品をさがす',
            'items'       => $items,
            'categories'  => $repo->categories(),
            'q'           => $q,
            'categoryId'  => $categoryId,
            'start'       => $start,
            'end'         => $end,
            'hasPeriod'   => $hasPeriod,
            'periodError' => $periodError,
            'today'       => $today,
            'canReserve'  => in_array($this->app->user['role'], ['student', 'teacher'], true),
        ]);
    }
}
