<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Clock;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

/**
 * 管理担当者の画面（ルート側の role:admin で保護）
 * 申請一覧・承認／却下・取消・貸出・返却（REQ-F-006 / 007 / 009 / 011）
 */
final class AdminController extends Controller
{
    /** 一覧のタブ（表示名 => 対象の状態） */
    private const TABS = [
        'pending'  => ['申請中', ['pending']],
        'approved' => ['承認済み（受け取り待ち）', ['approved']],
        'on_loan'  => ['貸出中', ['on_loan']],
        'closed'   => ['終了', ['completed', 'rejected', 'cancelled', 'expired']],
        'all'      => ['すべて', []],
    ];

    /** GET /admin */
    public function index(Request $request, array $params): Response
    {
        $today = Clock::todayJst();
        $this->app->reservations()->expireStale($today);
        return $this->view('admin/index', [
            'title'   => '管理メニュー',
            'counts'  => $this->app->reservationRepo()->countByStatus(),
            'overdue' => $this->app->loanRepo()->countOverdue($today),
            'today'   => $today,
        ]);
    }

    /** GET /admin/reservations?tab= */
    public function reservations(Request $request, array $params): Response
    {
        $tab = (string) $request->query('tab');
        if (!isset(self::TABS[$tab])) {
            $tab = 'pending';
        }
        $today = Clock::todayJst();
        $this->app->reservations()->expireStale($today);
        return $this->view('admin/reservations/index', [
            'title'        => '予約の管理',
            'tabs'         => self::TABS,
            'tab'          => $tab,
            'reservations' => $this->app->reservationRepo()->list(null, self::TABS[$tab][1]),
            'today'        => $today,
        ]);
    }

    /** GET /admin/reservations/{id} */
    public function show(Request $request, array $params): Response
    {
        $repo = $this->app->reservationRepo();
        $r = $repo->find($params['id']) ?? throw new HttpException(404);
        $items = $repo->items($r['id']);
        $loan = $this->app->loanRepo()->findByReservation($r['id']);
        $today = Clock::todayJst();

        // 承認・貸出の判断材料として、この期間の空き数を出す（自分の分は除く）
        $from = max($r['start_date'], $today) <= $r['end_date'] ? max($r['start_date'], $today) : $r['start_date'];
        $reserved = $this->app->equipment()->reservedQuantities(
            array_column($items, 'equipment_id'), $from, $r['end_date'], $today, $r['id'],
        );
        $stock = $this->app->equipment()->findMany(array_column($items, 'equipment_id'));
        foreach ($items as &$it) {
            $it['free'] = max(0, ($stock[$it['equipment_id']]['total_quantity'] ?? 0) - ($reserved[$it['equipment_id']] ?? 0));
        }
        unset($it);

        return $this->view('admin/reservations/show', [
            'title'     => '予約 #' . $r['id'],
            'r'         => $r,
            'items'     => $items,
            'loan'      => $loan,
            'loanItems' => $loan !== null ? $this->app->loanRepo()->items($loan['id']) : [],
            'returns'   => $loan !== null ? $this->app->loanRepo()->returnsFor($loan['id']) : [],
            'today'     => $today,
            'isSelf'    => $r['user_id'] === $this->userId(),
        ]);
    }

    /** POST /admin/reservations/{id}/approve */
    public function approve(Request $request, array $params): Response
    {
        $id = $params['id'];
        return $this->perform($this->back($id), '承認しました。', fn () => $this->app->reservations()
            ->approve($this->userId(), $id, trim((string) $request->input('comment')), Clock::todayJst()));
    }

    /** POST /admin/reservations/{id}/reject */
    public function reject(Request $request, array $params): Response
    {
        $id = $params['id'];
        return $this->perform($this->back($id), '却下しました。', fn () => $this->app->reservations()
            ->reject($this->userId(), $id, (string) $request->input('reason')));
    }

    /** POST /admin/reservations/{id}/cancel */
    public function cancel(Request $request, array $params): Response
    {
        $id = $params['id'];
        return $this->perform($this->back($id), '取り消しました。', fn () => $this->app->reservations()
            ->cancelByAdmin($this->userId(), $id, (string) $request->input('reason')));
    }

    /** POST /admin/reservations/{id}/checkout */
    public function checkout(Request $request, array $params): Response
    {
        $id = $params['id'];
        return $this->perform($this->back($id), '貸出を記録しました。', fn () => $this->app->reservations()
            ->checkout($this->userId(), $id, (string) $request->input('note'), Clock::todayJst()));
    }

    /** POST /admin/reservations/{id}/return */
    public function returnItems(Request $request, array $params): Response
    {
        $id = $params['id'];
        return $this->perform($this->back($id), '', function () use ($request, $id): string {
            $done = $this->app->reservations()->returnItems(
                $this->userId(), $id, $request->inputMap('return_qty'), (string) $request->input('condition_note'),
            );
            return $done ? 'すべて返却されました。この予約は「返却済み」になりました。' : '一部の返却を記録しました。';
        });
    }

    private function back(int $id): string
    {
        return '/admin/reservations/' . $id;
    }
}
