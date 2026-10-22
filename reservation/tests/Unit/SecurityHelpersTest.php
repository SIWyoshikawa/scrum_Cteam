<?php
declare(strict_types=1);

use App\Support\Csrf;
use App\Support\Env;
use App\Support\Url;

return [
    'h() は < > & " \' をすべて無害化する（XSS対策）' => function (): void {
        assert_same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', h('<script>alert("x")</script>'));
        assert_same('&#039; onmouseover=&#039;x', h("' onmouseover='x"), '属性の中でも安全');
        assert_same('a&amp;b', h('a&b'));
    },
    'h() は壊れたUTF-8でも空文字にならず置換する' => function (): void {
        assert_true(h("abc\xC3") !== '', 'ENT_SUBSTITUTE');
    },
    'CSRF：正しいトークンだけ通る' => function (): void {
        $token = Csrf::token();
        assert_same(64, strlen($token), '32バイト＝16進64文字');
        assert_true(Csrf::validate($token), '正しいトークン');
        assert_same(false, Csrf::validate('wrong'), '違うトークン');
        assert_same(false, Csrf::validate(null), 'トークンなし');
        assert_same(false, Csrf::validate(['array']), '配列で送られた場合');
    },
    'CSRF：セッションにトークンが無いと必ず拒否' => function (): void {
        $_SESSION = [];
        assert_same(false, Csrf::validate(''), '空同士でも通さない');
    },
    'CSRF：rotate 後は古いトークンが使えない' => function (): void {
        $old = Csrf::token();
        Csrf::rotate();
        assert_same(false, Csrf::validate($old));
    },
    'URL：アプリ内パスにだけベースパスを付ける' => function (): void {
        Url::setBase('/reservation');
        assert_same('/reservation/login', url('/login'));
        assert_throws(InvalidArgumentException::class, fn () => url('//evil.example.com'), '外部URL不可');
        assert_throws(InvalidArgumentException::class, fn () => url('https://evil.example.com'), '外部URL不可');
        Url::setBase('');
    },
    '.env の読み込み：コメント・引用符・不正なキー' => function (): void {
        $v = Env::parse("# comment\nAPP_NAME=\"abc def\"\nlower=x\nDB_PORT = 3307\n");
        assert_same(['APP_NAME' => 'abc def', 'DB_PORT' => '3307'], $v);
    },
    '.env の読み込み：日本語コメントや値が途中で切れない（UTF-8の0x85バイト対策）' => function (): void {
        // 「ぅ」は UTF-8 で E3 81 85。/\R/ を u フラグ無しで使うとここで行が割れてしまう
        $v = Env::parse("# 説明ぅ=テスト\nAPP_NAME=予約ぅシステム\nDB_PASSWORD=abc\n");
        assert_same(['APP_NAME' => '予約ぅシステム', 'DB_PASSWORD' => 'abc'], $v);
    },
];
