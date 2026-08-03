<?php
// 既存テーブルへのカラム追加等、CREATE TABLE IF NOT EXISTSでは対応できない変更をここに追記していく。
// 各文は何度実行しても安全な形(ADD COLUMN IF NOT EXISTS等)で書くこと。
return [
    'schedules.scheduled_end_at' => "ALTER TABLE schedules
        ADD COLUMN IF NOT EXISTS scheduled_end_at DATETIME DEFAULT NULL
        COMMENT '期間の最終日(単発の予定はNULL)' AFTER scheduled_at;",

    'users.invite_code' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS invite_code VARCHAR(12) DEFAULT NULL UNIQUE
        COMMENT 'Web申込時に発行する、本人のLINE連携用の使い切りコード' AFTER line_user_id;",

    // line_user_idはWeb申込時点(LINE未連携)ではまだ分からないためNULLを許容する。
    // MODIFY COLUMNには「IF NOT EXISTS」に相当する構文が無いが、MODIFY自体が繰り返し実行しても
    // 同じ結果になる(冪等な)操作なので、このファイルの「何度実行しても安全」という方針とは矛盾しない。
    'users.line_user_id_nullable' => "ALTER TABLE users
        MODIFY COLUMN line_user_id VARCHAR(64) DEFAULT NULL UNIQUE COMMENT 'LINEのuserId(LINE連携前はNULL)';",

    // 'pending'を追加(Web申込直後・LINE未連携の状態)。DEFAULTも'active'から'pending'に変更。
    'users.status_pending' => "ALTER TABLE users
        MODIFY COLUMN status ENUM('pending', 'active', 'paused', 'terminated') NOT NULL DEFAULT 'pending';",

    'family_accounts.invite_code' => "ALTER TABLE family_accounts
        ADD COLUMN IF NOT EXISTS invite_code VARCHAR(12) DEFAULT NULL UNIQUE
        COMMENT '家族自身がLINE通知を受け取りたい場合の任意の連携コード' AFTER line_user_id;",

    'plans.stripe_ids' => "ALTER TABLE plans
        ADD COLUMN IF NOT EXISTS stripe_product_id VARCHAR(255) DEFAULT NULL AFTER description,
        ADD COLUMN IF NOT EXISTS stripe_price_id VARCHAR(255) DEFAULT NULL AFTER stripe_product_id;",

    'family_accounts.stripe_customer_id' => "ALTER TABLE family_accounts
        ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(255) DEFAULT NULL UNIQUE
        COMMENT 'Stripe側のCustomer ID(決済情報登録後に設定)' AFTER is_billing_contact;",

    'users.companion' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS companion_gender ENUM('male', 'female', 'random') NOT NULL DEFAULT 'random'
        COMMENT '申込時にご家族が選択するAIの性別。会話相手の名前決定に使用' AFTER birthdate,
        ADD COLUMN IF NOT EXISTS companion_name VARCHAR(50) DEFAULT NULL
        COMMENT 'AIが自己紹介する名前(companion_genderをもとに申込時に自動生成)' AFTER companion_gender;",

    // 'abandoned'を追加(LINE未連携のまま一定期間放置され、自動キャンセルされた契約)
    'subscriptions.status_abandoned' => "ALTER TABLE subscriptions
        MODIFY COLUMN status ENUM('trial', 'active', 'trial_expired', 'past_due', 'cancelled', 'abandoned') NOT NULL DEFAULT 'trial';",

    'plans.coming_soon' => "ALTER TABLE plans
        ADD COLUMN IF NOT EXISTS coming_soon BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'サービス開始前の予告表示のみ。申込みでは選択不可にする' AFTER is_active;",

    'users.postal_code' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS postal_code VARCHAR(8) DEFAULT NULL AFTER phone;",

    'users.quiet_hours' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS quiet_hours_start TIME DEFAULT NULL
        COMMENT '会話の中でAIに伝えた、システム側から話しかけないでほしい時間帯の開始(例:22:00)' AFTER companion_name,
        ADD COLUMN IF NOT EXISTS quiet_hours_end TIME DEFAULT NULL
        COMMENT '同・終了時刻(開始>終了なら日をまたぐ範囲として扱う。例:22:00〜07:00)' AFTER quiet_hours_start;",

    'users.gender' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS gender ENUM('male', 'female') DEFAULT NULL
        COMMENT 'ご本人の性別(任意)。会話の話し方・言葉選びを合わせる用途' AFTER birthdate;",

    // 詐欺以外に、利用者本人の安全・健康に関わる気になる発言も検知対象に加えるためのカテゴリ追加
    'risk_patterns.category_health_safety' => "ALTER TABLE risk_patterns
        MODIFY COLUMN category ENUM(
            'money_request',
            'personal_info_request',
            'urgency_pressure',
            'isolation_attempt',
            'unfamiliar_contact',
            'health_concern',
            'safety_concern',
            'other'
        ) NOT NULL;",

    'urgent_silence_alerts.resolved_at' => "ALTER TABLE urgent_silence_alerts
        ADD COLUMN IF NOT EXISTS resolved_at DATETIME DEFAULT NULL
        COMMENT '通知後に利用者からのinboundが確認でき、家族へ解除連絡を送った時刻(未解除ならNULL)' AFTER notified_at;",

    // urgent通知時にTAYORIから本人へ位置情報を尋ねられるようにするための拡張
    'conversations.location' => "ALTER TABLE conversations
        MODIFY COLUMN message_type ENUM('text', 'sticker', 'image', 'audio', 'location', 'other') NOT NULL DEFAULT 'text',
        ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) DEFAULT NULL
        COMMENT '位置情報メッセージの緯度(message_type=locationのみ)' AFTER content,
        ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) DEFAULT NULL
        COMMENT '位置情報メッセージの経度(message_type=locationのみ)' AFTER latitude,
        ADD COLUMN IF NOT EXISTS location_address VARCHAR(255) DEFAULT NULL
        COMMENT 'LINE側で付与された住所文字列(message_type=locationのみ)' AFTER longitude;",

    'wellness_check_alerts.resolved_at' => "ALTER TABLE wellness_check_alerts
        ADD COLUMN IF NOT EXISTS resolved_at DATETIME DEFAULT NULL
        COMMENT '通知後に利用者からのinboundが確認でき、家族へ解除連絡を送った時刻(未解除ならNULL)' AFTER notified_at;",

    // 「毎週土曜日」「毎月10日」のような繰り返し予定に対応するための拡張
    'schedules.recurrence' => "ALTER TABLE schedules
        ADD COLUMN IF NOT EXISTS recurrence_type ENUM('none', 'weekly', 'monthly') NOT NULL DEFAULT 'none'
        COMMENT '「毎週」「毎月」のような繰り返し予定かどうか' AFTER status,
        ADD COLUMN IF NOT EXISTS recurrence_weekday TINYINT UNSIGNED DEFAULT NULL
        COMMENT '0(日)〜6(土)。recurrence_type=weeklyのみ使用' AFTER recurrence_type,
        ADD COLUMN IF NOT EXISTS recurrence_day_of_month TINYINT UNSIGNED DEFAULT NULL
        COMMENT '1〜31。recurrence_type=monthlyのみ使用' AFTER recurrence_weekday;",

    // 服薬など「毎日」の繰り返し予定にも対応するため、'daily'を追加
    'schedules.recurrence_daily' => "ALTER TABLE schedules
        MODIFY COLUMN recurrence_type ENUM('none', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'none'
        COMMENT '「毎日」「毎週」「毎月」のような繰り返し予定かどうか';",

    // 前日リマインドとは別に、当日朝のリマインドにも対応するための拡張
    'schedules.same_day_reminder' => "ALTER TABLE schedules
        ADD COLUMN IF NOT EXISTS same_day_reminder_sent BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT '当日朝リマインドの送信済みフラグ' AFTER reminder_sent;",

    // 服薬管理(決まった時刻のリマインド+飲んだかどうかの確認)対象かどうかのフラグ。
    // trueの対象はsend_proactive_messages.phpのsendMedicationRemindersが時刻到来を検知してリマインドする
    'schedules.is_medication' => "ALTER TABLE schedules
        ADD COLUMN IF NOT EXISTS is_medication BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT '決まった時刻の服薬リマインド+確認の対象かどうか' AFTER recurrence_day_of_month;",

    // 利用者本人と同様、ご家族側もLINEログイン(line_user_id)だけでなく友だち追加までを必須にするため、
    // 「友だち追加(followイベント)を確認できた時刻」を別途持たせる。line_user_idはOAuth成功時点で
    // 設定されるが、友だち追加していない間はここがNULLのままなので、放置判定・通知対象の判定に使う
    'family_accounts.friend_confirmed_at' => "ALTER TABLE family_accounts
        ADD COLUMN IF NOT EXISTS friend_confirmed_at DATETIME DEFAULT NULL
        COMMENT '友だち追加(followイベント)が確認できた時刻。未確認ならNULL' AFTER line_user_id;",

    // 初回だけ自動生成して以降固定する、AIコンパニオン自身の軽い自己紹介(2〜3個の短い文の配列)。
    // 会話中に自分の話として使うことで、利用者から聞き出すだけの一方的なやり取りにならないようにする。
    // 将来項目を増やしても既存データの移行が不要なようJSON型にしている
    'users.companion_persona' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS companion_persona JSON DEFAULT NULL
        COMMENT '初回に一度だけ自動生成し以降固定するAIコンパニオン自身の軽い自己紹介(短い文の配列)' AFTER companion_name;",

    // display_name(呼び名)は会話の中でTAYORIが呼びかける用途に限定し、こちらはマイページでご家族が
    // 入力する管理用の氏名。マイページ表示や家族向けLINE通知など、ご家族とのやり取りではこちらを優先して使う
    'users.full_name' => "ALTER TABLE users
        ADD COLUMN IF NOT EXISTS full_name VARCHAR(100) DEFAULT NULL
        COMMENT '氏名(管理用表示・ご家族とのやり取りで使用。会話上の呼び名とは別)' AFTER display_name;",

    // TAYORI自身が過去の会話(自分の返信含む)を読み返し、不自然さ・噛み合わなさを自己レビューして
    // 次回以降の会話に活かすための5つ目の要約種別
    'user_summaries.conversation_notes' => "ALTER TABLE user_summaries
        MODIFY COLUMN summary_type ENUM('schedule', 'relationship', 'preference', 'routine', 'conversation_notes') NOT NULL;",
];
