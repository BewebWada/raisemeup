<?php
return [
    'users' => "CREATE TABLE IF NOT EXISTS users (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        line_user_id        VARCHAR(64) DEFAULT NULL UNIQUE COMMENT 'LINEのuserId(LINE連携前はNULL)',
        invite_code         VARCHAR(12) DEFAULT NULL UNIQUE COMMENT 'Web申込時に発行する、本人のLINE連携用の使い切りコード',
        display_name        VARCHAR(100) DEFAULT NULL COMMENT '本人の呼び名(会話上で使用)',
        phone               VARCHAR(20) DEFAULT NULL,
        address             VARCHAR(255) DEFAULT NULL,
        birthdate           DATE DEFAULT NULL,
        companion_gender    ENUM('male', 'female', 'random') NOT NULL DEFAULT 'random' COMMENT '申込時にご家族が選択するAIの性別。会話相手の名前決定に使用',
        companion_name      VARCHAR(50) DEFAULT NULL COMMENT 'AIが自己紹介する名前(companion_genderをもとに申込時に自動生成)',
        status              ENUM('pending', 'active', 'paused', 'terminated') NOT NULL DEFAULT 'pending',
        onboarded_at        DATETIME DEFAULT NULL COMMENT '初回会話開始日時',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者(高齢者)本体';",

    'family_accounts' => "CREATE TABLE IF NOT EXISTS family_accounts (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(100) NOT NULL,
        email               VARCHAR(255) DEFAULT NULL UNIQUE,
        phone               VARCHAR(20) DEFAULT NULL,
        line_user_id        VARCHAR(64) DEFAULT NULL UNIQUE COMMENT '家族側もLINE通知を受ける場合',
        invite_code         VARCHAR(12) DEFAULT NULL UNIQUE COMMENT '家族自身がLINE通知を受け取りたい場合の任意の連携コード',
        password_hash       VARCHAR(255) DEFAULT NULL COMMENT '管理画面ログイン用(将来のダッシュボード用)',
        is_billing_contact  BOOLEAN NOT NULL DEFAULT FALSE COMMENT '課金情報を持つアカウントか',
        stripe_customer_id  VARCHAR(255) DEFAULT NULL UNIQUE COMMENT 'Stripe側のCustomer ID(決済情報登録後に設定)',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='家族・介護者アカウント(ペイヤー)';",

    'user_family_links' => "CREATE TABLE IF NOT EXISTS user_family_links (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        family_account_id   BIGINT UNSIGNED NOT NULL,
        relation            VARCHAR(50) DEFAULT NULL COMMENT '例: 息子, 娘, ケアマネージャー',
        role                ENUM('payer', 'viewer', 'emergency_contact') NOT NULL DEFAULT 'viewer',
        notify_priority     TINYINT UNSIGNED DEFAULT 1 COMMENT '通知順位。1が最優先',
        is_active           BOOLEAN NOT NULL DEFAULT TRUE,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (family_account_id) REFERENCES family_accounts(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_family (user_id, family_account_id),
        INDEX idx_user_priority (user_id, notify_priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者と家族・介護者の紐づけ(多対多)';",

    'conversations' => "CREATE TABLE IF NOT EXISTS conversations (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        line_message_id     VARCHAR(64) DEFAULT NULL COMMENT 'LINE側のmessageId(重複防止用)',
        direction           ENUM('inbound', 'outbound') NOT NULL COMMENT 'inbound=利用者→AI, outbound=AI→利用者',
        message_type        ENUM('text', 'sticker', 'image', 'audio', 'other') NOT NULL DEFAULT 'text',
        content             TEXT DEFAULT NULL COMMENT '本文(テキストメッセージの場合)',
        claude_model        VARCHAR(50) DEFAULT NULL COMMENT '生成に使ったモデル(outboundのみ)',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_created (user_id, created_at),
        UNIQUE KEY uniq_line_message (line_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会話ログ';",

    'persons' => "CREATE TABLE IF NOT EXISTS persons (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL COMMENT 'どの利用者の交友関係か',
        canonical_name      VARCHAR(100) NOT NULL COMMENT '会話上での呼称。例: 田中さん, 娘さん',
        first_mentioned_at  DATETIME NOT NULL COMMENT '初出の会話日時',
        last_mentioned_at   DATETIME NOT NULL COMMENT '直近で話題に出た日時',
        mention_count       INT UNSIGNED NOT NULL DEFAULT 1,
        notes               TEXT DEFAULT NULL COMMENT '自由記述メモ(要約など)',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_name (user_id, canonical_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人物プロフィール本体';",

    'person_attributes' => "CREATE TABLE IF NOT EXISTS person_attributes (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        person_id               BIGINT UNSIGNED NOT NULL,
        attribute_type          ENUM('relation', 'birthday', 'phone', 'address', 'email', 'occupation', 'other') NOT NULL,
        attribute_value         VARCHAR(255) NOT NULL,
        is_current              BOOLEAN NOT NULL DEFAULT TRUE COMMENT '現在有効な値かどうか',
        valid_from              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'この値が有効になった時点',
        valid_to                DATETIME DEFAULT NULL COMMENT 'この値が上書きされた時点(履歴保持用)',
        source_conversation_id  BIGINT UNSIGNED DEFAULT NULL COMMENT 'どの会話から抽出したか',
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
        FOREIGN KEY (source_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
        INDEX idx_person_type_current (person_id, attribute_type, is_current)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人物属性(関係性の変遷を含む拡張型)';",

    'schedules' => "CREATE TABLE IF NOT EXISTS schedules (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                 BIGINT UNSIGNED NOT NULL,
        title                   VARCHAR(255) NOT NULL,
        scheduled_at            DATETIME DEFAULT NULL COMMENT '日時が確定している場合(期間の場合は開始日)',
        scheduled_end_at        DATETIME DEFAULT NULL COMMENT '期間の最終日(単発の予定はNULL)',
        scheduled_date_text     VARCHAR(100) DEFAULT NULL COMMENT '曖昧な日付表現(例:来週あたり)の保持用',
        location                VARCHAR(255) DEFAULT NULL,
        related_person_id       BIGINT UNSIGNED DEFAULT NULL COMMENT '関連する人物(personsへの参照)',
        status                  ENUM('upcoming', 'completed', 'cancelled') NOT NULL DEFAULT 'upcoming',
        reminder_sent           BOOLEAN NOT NULL DEFAULT FALSE,
        source_conversation_id  BIGINT UNSIGNED DEFAULT NULL,
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (related_person_id) REFERENCES persons(id) ON DELETE SET NULL,
        FOREIGN KEY (source_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
        INDEX idx_user_scheduled (user_id, scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='スケジュール(予定)';",

    // risk_patterns: pattern_name に UNIQUE KEY を追加した版(シードのUPSERT判定に使用)
    'risk_patterns' => "CREATE TABLE IF NOT EXISTS risk_patterns (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pattern_name        VARCHAR(100) NOT NULL COMMENT '例: 振込要求, 緊急性を煽る表現',
        category            ENUM(
                                'money_request',
                                'personal_info_request',
                                'urgency_pressure',
                                'isolation_attempt',
                                'unfamiliar_contact',
                                'other'
                            ) NOT NULL,
        keywords            JSON NOT NULL COMMENT '例: [\"振込\",\"口座番号\",\"今すぐ\"]',
        regex_pattern       VARCHAR(255) DEFAULT NULL COMMENT '正規表現での高度な検知(任意)',
        risk_level          ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
        description         TEXT DEFAULT NULL,
        is_active           BOOLEAN NOT NULL DEFAULT TRUE,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pattern_name (pattern_name),
        INDEX idx_active_category (is_active, category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詐欺検知パターン定義';",

    'risk_events' => "CREATE TABLE IF NOT EXISTS risk_events (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                 BIGINT UNSIGNED NOT NULL,
        conversation_id         BIGINT UNSIGNED NOT NULL COMMENT '検知元となった会話',
        risk_pattern_id         BIGINT UNSIGNED DEFAULT NULL COMMENT '発火したパターン(手動判定の場合はNULL)',
        matched_keywords        JSON DEFAULT NULL COMMENT '実際にマッチしたキーワード',
        risk_level              ENUM('low', 'medium', 'high') NOT NULL,
        status                  ENUM('pending', 'notified', 'reviewed', 'false_positive', 'escalated') NOT NULL DEFAULT 'pending',
        notified_family         BOOLEAN NOT NULL DEFAULT FALSE,
        notified_at             DATETIME DEFAULT NULL,
        reviewed_by             BIGINT UNSIGNED DEFAULT NULL COMMENT 'family_accounts.id (確認した家族)',
        reviewed_at             DATETIME DEFAULT NULL,
        notes                   TEXT DEFAULT NULL,
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (risk_pattern_id) REFERENCES risk_patterns(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by) REFERENCES family_accounts(id) ON DELETE SET NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='詐欺検知イベント記録';",

    'plans' => "CREATE TABLE IF NOT EXISTS plans (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code                VARCHAR(30) NOT NULL COMMENT '例: standard, family_watch, premium_medical',
        name                VARCHAR(100) NOT NULL,
        price_yen           INT UNSIGNED NOT NULL,
        description         VARCHAR(255) DEFAULT NULL,
        stripe_product_id   VARCHAR(255) DEFAULT NULL,
        stripe_price_id     VARCHAR(255) DEFAULT NULL COMMENT 'Stripe側のPrice ID(sync_stripe_plans.phpで設定)',
        is_active           BOOLEAN NOT NULL DEFAULT TRUE,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_plan_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料金プラン定義';",

    'subscriptions' => "CREATE TABLE IF NOT EXISTS subscriptions (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                 BIGINT UNSIGNED NOT NULL COMMENT '利用者(高齢者)本体',
        family_account_id       BIGINT UNSIGNED NOT NULL COMMENT '契約者(payer)',
        plan_id                 BIGINT UNSIGNED NOT NULL,
        status                  ENUM('trial', 'active', 'trial_expired', 'past_due', 'cancelled') NOT NULL DEFAULT 'trial',
        trial_ends_at           DATETIME NOT NULL,
        current_period_end      DATETIME DEFAULT NULL COMMENT '課金開始後の次回更新日(決済連携導入後に使用)',
        payment_provider        VARCHAR(30) DEFAULT NULL COMMENT '将来Stripe等を導入した際のプロバイダ名。未導入の間は常にNULL',
        payment_customer_ref    VARCHAR(255) DEFAULT NULL COMMENT '決済プロバイダ側の顧客/契約ID。未導入の間は常にNULL',
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (family_account_id) REFERENCES family_accounts(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES plans(id),
        INDEX idx_status (status),
        INDEX idx_trial_ends (trial_ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者ごとの契約状態(無料期間〜有料プラン)';",

    'user_summaries' => "CREATE TABLE IF NOT EXISTS user_summaries (
        id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                     BIGINT UNSIGNED NOT NULL,
        summary_type                ENUM('schedule', 'relationship', 'preference', 'routine') NOT NULL,
        content                     TEXT NOT NULL,
        source_conversation_max_id  BIGINT UNSIGNED DEFAULT NULL COMMENT 'この要約に反映済みの会話idの最大値(次回再生成の要否判定用)',
        created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_summary_type (user_id, summary_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者ごとの要約(予定/人間関係/好み/日常ルーティン)。バッチで定期再生成しリアルタイム会話のプロンプトに注入する';",
];
