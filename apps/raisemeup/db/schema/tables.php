<?php
return [
    'users' => "CREATE TABLE IF NOT EXISTS users (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        line_user_id        VARCHAR(64) DEFAULT NULL UNIQUE COMMENT 'LINEのuserId(LINE連携前はNULL)',
        invite_code         VARCHAR(12) DEFAULT NULL UNIQUE COMMENT 'Web申込時に発行する、本人のLINE連携用の使い切りコード',
        display_name        VARCHAR(100) DEFAULT NULL COMMENT '本人の呼び名(会話上で使用)',
        full_name           VARCHAR(100) DEFAULT NULL COMMENT '氏名(管理用表示・ご家族とのやり取りで使用。会話上の呼び名とは別)',
        phone               VARCHAR(20) DEFAULT NULL,
        postal_code         VARCHAR(8) DEFAULT NULL,
        address             VARCHAR(255) DEFAULT NULL,
        birthdate           DATE DEFAULT NULL,
        gender              ENUM('male', 'female') DEFAULT NULL COMMENT 'ご本人の性別(任意)。会話の話し方・言葉選びを合わせる用途',
        companion_gender    ENUM('male', 'female', 'random') NOT NULL DEFAULT 'random' COMMENT '申込時にご家族が選択するAIの性別。会話相手の名前決定に使用',
        companion_name      VARCHAR(50) DEFAULT NULL COMMENT 'AIが自己紹介する名前(companion_genderをもとに申込時に自動生成)',
        quiet_hours_start   TIME DEFAULT NULL COMMENT '会話の中でAIに伝えた、システム側から話しかけないでほしい時間帯の開始(例:22:00)',
        quiet_hours_end     TIME DEFAULT NULL COMMENT '同・終了時刻(開始>終了なら日をまたぐ範囲として扱う。例:22:00〜07:00)',
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
        message_type        ENUM('text', 'sticker', 'image', 'audio', 'location', 'other') NOT NULL DEFAULT 'text',
        content             TEXT DEFAULT NULL COMMENT '本文(テキストメッセージの場合)',
        latitude            DECIMAL(10,7) DEFAULT NULL COMMENT '位置情報メッセージの緯度(message_type=locationのみ)',
        longitude           DECIMAL(10,7) DEFAULT NULL COMMENT '位置情報メッセージの経度(message_type=locationのみ)',
        location_address    VARCHAR(255) DEFAULT NULL COMMENT 'LINE側で付与された住所文字列(message_type=locationのみ)',
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

    // スタンダード以上限定の画像認識(原稿対応モード)で読み取ったテキストの保存。画像自体は保存しない
    'document_texts' => "CREATE TABLE IF NOT EXISTS document_texts (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                 BIGINT UNSIGNED NOT NULL,
        source_conversation_id  BIGINT UNSIGNED DEFAULT NULL,
        extracted_text          TEXT NOT NULL COMMENT '写真から読み取った文字起こし(原文ママ、要約しない)',
        status                  ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at              DATETIME DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (source_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
        INDEX idx_user_active (user_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='スタンダード以上限定: 原稿写真から読み取ったテキストの保存(画像自体は保存しない)';",

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
        recurrence_type         ENUM('none', 'daily', 'weekly', 'monthly') NOT NULL DEFAULT 'none' COMMENT '「毎日」「毎週」「毎月」のような繰り返し予定かどうか',
        recurrence_weekday      TINYINT UNSIGNED DEFAULT NULL COMMENT '0(日)〜6(土)。recurrence_type=weeklyのみ使用',
        recurrence_day_of_month TINYINT UNSIGNED DEFAULT NULL COMMENT '1〜31。recurrence_type=monthlyのみ使用',
        reminder_sent           BOOLEAN NOT NULL DEFAULT FALSE COMMENT '前日リマインドの送信済みフラグ',
        same_day_reminder_sent  BOOLEAN NOT NULL DEFAULT FALSE COMMENT '当日朝リマインドの送信済みフラグ',
        source_conversation_id  BIGINT UNSIGNED DEFAULT NULL,
        document_text_id        BIGINT UNSIGNED DEFAULT NULL COMMENT '原稿対応モードでこの予定の元になった書類(document_texts)。書類由来でない予定はNULL',
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (related_person_id) REFERENCES persons(id) ON DELETE SET NULL,
        FOREIGN KEY (source_conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
        FOREIGN KEY (document_text_id) REFERENCES document_texts(id) ON DELETE SET NULL,
        INDEX idx_user_scheduled (user_id, scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='スケジュール(予定)';",

    'medication_logs' => "CREATE TABLE IF NOT EXISTS medication_logs (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        schedule_id         BIGINT UNSIGNED NOT NULL COMMENT '対象のお薬(schedules.id、is_medication=trueのもの)',
        user_id             BIGINT UNSIGNED NOT NULL COMMENT '非正規化(集計クエリの簡略化用。schedule経由でも辿れる)',
        log_date            DATE NOT NULL COMMENT '対象の服薬日(Asia/Tokyo基準)',
        reminder_sent_at    DATETIME DEFAULT NULL COMMENT 'システムからのリマインド送信時刻(リマインド前に本人から自己申告があった場合はNULLのまま)',
        confirmed_at        DATETIME DEFAULT NULL COMMENT '本人から服薬確認が取れた時刻(未確認ならNULL)',
        status              ENUM('pending', 'taken', 'missed') NOT NULL DEFAULT 'pending',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_schedule_date (schedule_id, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服薬リマインド(schedules.is_medication=true)の日ごとの送信・確認記録';",

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
                                'health_concern',
                                'safety_concern',
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
        coming_soon         BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'サービス開始前の予告表示のみ。申込みでは選択不可にする',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_plan_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料金プラン定義';",

    'subscriptions' => "CREATE TABLE IF NOT EXISTS subscriptions (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                 BIGINT UNSIGNED NOT NULL COMMENT '利用者(高齢者)本体',
        family_account_id       BIGINT UNSIGNED NOT NULL COMMENT '契約者(payer)',
        plan_id                 BIGINT UNSIGNED NOT NULL,
        status                  ENUM('trial', 'active', 'trial_expired', 'past_due', 'cancelled', 'abandoned') NOT NULL DEFAULT 'trial',
        trial_ends_at           DATETIME NOT NULL,
        current_period_end      DATETIME DEFAULT NULL COMMENT '課金開始後の次回更新日(決済連携導入後に使用)',
        cancel_at               DATETIME DEFAULT NULL COMMENT '期間終了時解約の予定日時。予約中のみ設定される',
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

    'wellness_check_alerts' => "CREATE TABLE IF NOT EXISTS wellness_check_alerts (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        check_date          DATE NOT NULL COMMENT '対象の日付(Asia/Tokyo基準)',
        window_label        VARCHAR(20) NOT NULL COMMENT 'send_proactive_messages.phpのCHECKIN_WINDOWSのlabel(例: morning, evening)',
        notified_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at         DATETIME DEFAULT NULL COMMENT '通知後に利用者からのinboundが確認でき、家族へ解除連絡を送った時刻(未解除ならNULL)',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_date_window (user_id, check_date, window_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安否確認枠(CHECKIN_WINDOWS)内で応答が無かった場合の判定結果の記録(重複通知防止用)';",

    'urgent_silence_alerts' => "CREATE TABLE IF NOT EXISTS urgent_silence_alerts (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        silence_since       DATETIME NOT NULL COMMENT 'この沈黙エピソードの起点(最後に確認できたinboundの時刻、無ければ登録時点)',
        notified_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at         DATETIME DEFAULT NULL COMMENT '通知後に利用者からのinboundが確認でき、家族へ解除連絡を送った時刻(未解除ならNULL)',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_silence_since (user_id, silence_since)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='send_proactive_messages.phpのURGENT_SILENCE_HOURS超過(危機感通知)の重複防止用記録。silence_sinceが変わる=新しい沈黙エピソードとして再度通知しうる';",

    'family_digest_log' => "CREATE TABLE IF NOT EXISTS family_digest_log (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        week_start          DATE NOT NULL COMMENT '対象週の開始日(月曜、Asia/Tokyo基準)',
        sent_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_week (user_id, week_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='send_family_digest.phpが送信した週次ご家族向けダイジェストの記録(同じ週への重複送信防止用)';",

    'weather_cache' => "CREATE TABLE IF NOT EXISTS weather_cache (
        area_code           VARCHAR(10) NOT NULL PRIMARY KEY COMMENT 'JMA予報区(office)コード',
        summary             TEXT NOT NULL COMMENT 'ClaudeClientの動的プロンプトにそのまま差し込む要約文(天気+警報注意報)',
        fetched_at          DATETIME NOT NULL COMMENT 'JMA APIから取得した時刻。WeatherClient::CACHE_TTL_MINUTES以内なら再取得しない',
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='気象庁(JMA)の天気予報・警報注意報の要約キャッシュ。area_codeごとに1行、TTL付きで使い回す';",

    'demo_chat_sessions' => "CREATE TABLE IF NOT EXISTS demo_chat_sessions (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_token       VARCHAR(64) NOT NULL UNIQUE COMMENT 'クライアントに払い出す不透明なトークン(sessionStorageに保持)',
        ip_address          VARCHAR(45) NOT NULL,
        turn_count          INT UNSIGNED NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ip_created (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='トップページの公開チャットシミュレーション(未ログイン)のレート制限用。件数のみ記録し会話本文は保存しない。実際の利用者データ(users/conversations)とは完全に分離';",

    'support_chat_sessions' => "CREATE TABLE IF NOT EXISTS support_chat_sessions (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_token       VARCHAR(64) NOT NULL UNIQUE COMMENT 'クライアントに払い出す不透明なトークン(sessionStorageに保持)',
        ip_address          VARCHAR(45) NOT NULL,
        turn_count          INT UNSIGNED NOT NULL DEFAULT 0,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ip_created (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='サポートページ(support.php)のサポートbotのレート制限用。件数のみ記録し会話本文は保存しない。demo_chat_sessionsとは用途が別のため独立させている';",

    'user_summaries' => "CREATE TABLE IF NOT EXISTS user_summaries (
        id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                     BIGINT UNSIGNED NOT NULL,
        summary_type                ENUM('schedule', 'relationship', 'preference', 'routine', 'conversation_notes') NOT NULL,
        content                     TEXT NOT NULL,
        source_conversation_max_id  BIGINT UNSIGNED DEFAULT NULL COMMENT 'この要約に反映済みの会話idの最大値(次回再生成の要否判定用)',
        created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_summary_type (user_id, summary_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者ごとの要約(予定/人間関係/好み/日常ルーティン)。バッチで定期再生成しリアルタイム会話のプロンプトに注入する';",

    'conversation_insights' => "CREATE TABLE IF NOT EXISTS conversation_insights (
        id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id                 BIGINT UNSIGNED NOT NULL,
        insight_type            ENUM('user_request', 'trouble') NOT NULL COMMENT 'user_request=利用者からの要望, trouble=会話中に生じたトラブル・不満・誤解',
        content                 TEXT NOT NULL,
        status                  ENUM('new', 'acknowledged') NOT NULL DEFAULT 'new' COMMENT '運営者が確認したかどうか(専用画面は無く手動更新想定)',
        through_conversation_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'この抽出処理が読んだ会話idの最大値(検出時期の目安)',
        created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_type_status (user_id, insight_type, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会話の自己レビューバッチが検出した、利用者からの要望・会話中のトラブル。運営者が後で確認する用途(SQL参照。専用管理画面は無い)';",

    'user_topic_touches' => "CREATE TABLE IF NOT EXISTS user_topic_touches (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        topic_category      ENUM('family_friends', 'hobby', 'food', 'health', 'career_history',
                                  'hometown_childhood', 'pet', 'neighborhood', 'entertainment')
                            NOT NULL COMMENT '自己紹介期間の話題カバレッジ用の固定ジャンル(TopicCoverageRepository::CATEGORIES)',
        first_touched_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '初めて話題に出た日時',
        last_touched_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '直近で話題に出た日時(直近再言及の抑制に使用)',
        touch_count         INT UNSIGNED NOT NULL DEFAULT 1,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_user_topic (user_id, topic_category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='利用者ごとの自己紹介期間の話題カバレッジ。要約とは別に、話題の偏り(同じ話題ばかり)を防ぐための追跡';",

    'family_messages' => "CREATE TABLE IF NOT EXISTS family_messages (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL COMMENT '伝言の宛先(利用者本人)',
        family_account_id   BIGINT UNSIGNED NOT NULL COMMENT '伝言の送り主(ご家族)',
        message             TEXT NOT NULL,
        status              ENUM('pending', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
        delivered_at        DATETIME DEFAULT NULL,
        cancelled_at        DATETIME DEFAULT NULL,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (family_account_id) REFERENCES family_accounts(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ご家族が「TAYORIサポート」経由で本人へ伝える伝言のキュー。次回の会話でTAYORIが自然に伝える';",

    'family_themes' => "CREATE TABLE IF NOT EXISTS family_themes (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL COMMENT '対象の利用者(本人)',
        family_account_id   BIGINT UNSIGNED NOT NULL COMMENT '設定したご家族',
        theme               VARCHAR(255) NOT NULL COMMENT 'ご家族が気にかけてほしいテーマ(例: 水分補給を気にかけてほしい)',
        status              ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
        expires_at          DATETIME NOT NULL COMMENT '作成から一定期間後。過ぎたら一覧・会話への注入から自動的に除外される',
        cancelled_at        DATETIME DEFAULT NULL,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (family_account_id) REFERENCES family_accounts(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ご家族が設定する、TAYORIが出典を明かさず自然に気にかけ続けてほしい継続的なテーマ(伝言とは別物)';",

    'pending_replies' => "CREATE TABLE IF NOT EXISTS pending_replies (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id             BIGINT UNSIGNED NOT NULL,
        line_user_id        VARCHAR(64) NOT NULL COMMENT 'push送信先。usersからの都度JOINを避けるため非正規化',
        reply_text          TEXT NOT NULL,
        send_after          DATETIME NOT NULL COMMENT 'この時刻以降にsend_pending_replies.phpが送信する',
        status              ENUM('pending', 'sending', 'sent', 'cancelled') NOT NULL DEFAULT 'pending',
        claude_model        VARCHAR(50) DEFAULT NULL,
        sent_at             DATETIME DEFAULT NULL,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_status_send_after (status, send_after),
        INDEX idx_user_status (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='緊急性の低い普通の雑談を、即レスの機械的な印象を避けるため数分置いてpush APIで送るための遅延キュー(ConversationHandler::flushPendingReplies/send_pending_replies.phpが処理)';",

    // 特定商取引法12条の6の最終確認画面での同意取得証跡。契約記録として本体アカウントとは
    // ライフサイクルを分離するため、family_accountsへのFOREIGN KEYはあえて付けない
    'consent_logs' => "CREATE TABLE IF NOT EXISTS consent_logs (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        family_account_id   BIGINT UNSIGNED NOT NULL COMMENT '同意した申込者(ご家族=ペイヤー)。FK制約は意図的に付けない(将来のアカウント削除でCASCADE消失させないため)',
        consent_type        ENUM('terms', 'privacy') NOT NULL,
        policy_version      VARCHAR(20) NOT NULL COMMENT 'PolicyVersions::TERMS_VERSION / PRIVACY_VERSION',
        agreed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address          VARCHAR(45) DEFAULT NULL,
        user_agent          VARCHAR(500) DEFAULT NULL,
        created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_family_account (family_account_id),
        INDEX idx_type_version (consent_type, policy_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='申込み時の利用規約・プライバシーポリシー同意ログ(特定商取引法・個人情報保護法対応の証跡)';",
];
