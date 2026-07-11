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
];
