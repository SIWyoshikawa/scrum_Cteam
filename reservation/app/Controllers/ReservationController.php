<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Clock;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * 生徒・教員の予約（REQ-F-005 / 008 / 010）
 * ルートは ['auth', 'role:user']。他人の予約は findOwned で 404 にする（IDOR対策）。
 */
final class ReservationController extends Controller
{
    /** GET /reservations/new?start=&end=&equipment= */
    public function create(Request $request, array $params): Response
    {
        $pre = [];
        $eq = (string) $request->query('equipment');
        if (preg_match('/^[1-9][0-9]{0,9}$/', $eq)) {
            $pre[(int) $eq] = '1';
        }
        return $this->form([
            'start'      => (string) $request->query('start'),
            'end'        => (string) $request->query('end'),
            'purpose'    => '',
            'quantities' => $pre,
        ], []);
    }

    /** POST /reservations/new */
    public function store(Request $request, array $params): Response
    {
        $input = [
            'start'      => (string) $request->input('start_date'),
            'end'        => (string) $request->input('end_date'),
            'purpose'    => (string) $request->input('purpose'),
            'quantities' => $request->inputMap('qty'),
        ];
        $result = $this->app->reservations()->request(
            $this->userId(), $input['start'], $input['end'], $input['purpose'], $input['quantities'], Clock::todayJst(),
        );
        if ($result['errors'] !== []) {
            return $this->form($input, $result['errors'], 422);
        }
        Session::flash('info', '予約を申請しました。管理担当者の承認をお待ちください。');
        return Response::redirect('/my/reservations/' . $result['id']);
    }

    /** GET /my/reservations */
    public function index(Request $request, array $params): Response
    {
        $today = Clock::todayJst();
        $this->app->reservations()->expireStale($today);
        return $this->view('my/index', [
            'title'        => '自分の予約・貸出',
            'reservations' => $this->app->reservationRepo()->list($this->userId(), []),
            'today'        => $today,
        ]);
    }

    /** GET /my/reservations/{id} */
    public function show(Request $request, array $params): Response
    {
        $repo = $this->app->reservationRepo();
        $r = $repo->findOwned($params['id'], $this->userId()) ?? throw new HttpException(404);
        $loan = $this->app->loanRepo()->findByReservation($r['id']);
        return $this->view('my/show', [
            'title'       => '予約の詳細',
            'r'           => $r,
            'items'       => $repo->items($r['id']),
            'loan'        => $loan,
            'loanItems'   => $loan !== null ? $this->app->loanRepo()->items($loan['id']) : [],
            'today'       => Clock::todayJst(),
        ]);
    }

    /** POST /my/reservations/{id}/cancel */
    public function cancel(Request $request, array $params): Response
    {
        $id = $params['id'];
        return $this->perform('/my/reservations/' . $id, '予約を取り消しました。',
            fn () => $this->app->reservations()->cancelByOwner($this->userId(), $id));
    }

    /** POST /notifications/read（本人のお知らせを既読に） */
    public function readNotifications(Request $request, array $params): Response
    {
        $this->app->notifications()->markAllRead($this->userId());
        return Response::redirect('/');
    }

    /** @param array<string,mixed> $input @param list<string> $errors */
    private function form(array $input, array $errors, int $status = 200): Response
    {
        $today = Clock::todayJst();
        $rules = $this->app->reservationRules()->config();
        return $this->view('reservations/new', [
            'title'     => '予約を申請する',
            'input'     => $input,
            'errors'    => $errors,
            'equipment' => $this->app->equipment()->search('', null),
            'today'     => $today,
            'maxDate'   => (new \DateTimeImmutable($today))->modify("+{$rules['max_advance_days']} days")->format('Y-m-d'),
            'rules'     => $rules,
        ], $status);
    }
}
