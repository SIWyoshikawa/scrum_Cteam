<?php
declare(strict_types=1);

use App\Support\HttpException;
use App\Support\Router;

$make = static function (): Router {
    $r = new Router();
    $r->get('/login', ['C', 'show']);
    $r->post('/login', ['C', 'login']);
    $r->post('/logout', ['C', 'logout'], ['auth']);
    $r->get('/items/{id}', ['C', 'item'], ['auth']);
    return $r;
};

return [
    '登録したルートに一致する' => function () use ($make): void {
        $m = $make()->match('POST', '/logout');
        assert_same(['C', 'logout'], $m['handler']);
        assert_same(['auth'], $m['middleware']);
    },
    'HEAD は GET として扱う' => function () use ($make): void {
        assert_same(['C', 'show'], $make()->match('HEAD', '/login')['handler']);
    },
    'GET /logout は 405（GETで状態を変えさせない）' => function () use ($make): void {
        $e = assert_throws(HttpException::class, fn () => $make()->match('GET', '/logout'));
        assert_same(405, $e->status);
        assert_same(['Allow' => 'POST'], $e->headers);
    },
    '未登録のパスは 404' => function () use ($make): void {
        $e = assert_throws(HttpException::class, fn () => $make()->match('GET', '/admin/secret'));
        assert_same(404, $e->status);
    },
    'ID は正の整数だけ受け付ける（不正IDを入口で拒否）' => function () use ($make): void {
        assert_same(['id' => 42], $make()->match('GET', '/items/42')['params']);
        foreach (['/items/0', '/items/-1', '/items/1abc', "/items/1'OR'1", '/items/01'] as $bad) {
            $e = assert_throws(HttpException::class, fn () => $make()->match('GET', $bad), $bad);
            assert_same(404, $e->status, $bad);
        }
    },
];
