<?php
declare(strict_types=1);

use App\Support\Env;

/**
 * アプリ設定。値は .env から読む（ここに秘密を直書きしない）。
 */
return [
    'app_name'  => '学内施設・備品予約/貸出管理',
    'app_env'   => Env::get('APP_ENV', 'production'),
    // htdocs/reservation に置いた場合は "/reservation"。VirtualHostで直下に置くなら空文字。
    'base_path' => rtrim(Env::get('APP_BASE_PATH', ''), '/'),
    // true: localhost以外からのHTTPアクセスを拒否（学内LANではHTTPS必須 DD 6.3）
    'require_https_for_remote' => Env::bool('APP_REQUIRE_HTTPS_FOR_REMOTE', true),

    'db' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('DB_PORT', '3306'),
        'name'     => Env::get('DB_NAME', 'equipment_management'),
        'user'     => Env::get('DB_USER', ''),
        'password' => Env::get('DB_PASSWORD', ''),
    ],

    'session' => [
        'name'         => 'RSVSESSID',
        'idle_timeout' => 30 * 60,   // 30分無操作で期限切れ（NF-003）
    ],

    'login_throttle' => [
        'window_seconds'  => 15 * 60, // 15分間に
        'max_failures'    => 5,       // 同じユーザーIDで5回失敗したら
        'lock_seconds'    => 15 * 60, // 15分ロック
        'ip_max_failures' => 20,      // 同一IPから15分で20回失敗したらIP単位で一時拒否（DB設計書 5-8）
    ],

    // パスワードのルール（初回変更・本人変更）。bcrypt は72バイトまでしか使わない
    'password' => [
        'min_length' => 12,
        'max_bytes'  => 72,
    ],

    // 予約のルール（オーナーSQLの注意書き「予約7日以内/90日先/最大5件」をこう解釈。PO確認待ち）
    'reservation' => [
        'max_days'         => 7,   // 1回の予約で借りられる日数（開始日・返却予定日を含む）
        'max_advance_days' => 90,  // 利用開始日は今日から何日先まで申請できるか
        'max_active'       => 5,   // 1人が同時に持てる進行中の予約（申請中・承認済み・貸出中）
        'max_items'        => 10,  // 1回の申請に入れられる備品の種類数
        'max_quantity'     => 99,  // 1種類あたりの数量の上限
    ],

    'log_path' => BASE_PATH . '/storage/logs',
];
