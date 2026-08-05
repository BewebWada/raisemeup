<?php
// LINE友だち追加が確認できた際の共通処理。
// OAuthコールバック(isFriendの同期チェック)・followイベントのWebhook・
// 申込完了画面の「次へ」ボタン(能動チェック)の3経路すべてから呼ばれる。
class FriendConfirmationService
{
    public static function confirmUser(PDO $pdo, UserRepository $userRepo, LineClient $lineClient, array $user): void
    {
        $userRepo->markOnboarded((int) $user['id']);
        $companionName = $user['companion_name'] ?: 'たより';
        // この時点ではdisplay_nameは未取得(申込み時には聞かず、会話の中で自然に確認する方針のため)なので、
        // 名前で呼びかけず自己紹介のみ行い、呼び名は会話の中でさりげなく尋ねる
        $greeting = "はじめまして!{$companionName}です。\nこれから、よろしくお願いします。\nよかったら、何てお呼びすればいいか教えてくださいね。\n何でも気軽に話しかけてください。";
        if ($lineClient->push($user['line_user_id'], $greeting)) {
            // send_proactive_messages.phpの無連絡検知(conversationsの最終更新から判定)に影響するため、
            // outboundメッセージは通常の会話と同様にここでも記録しておく
            $pdo->prepare(
                'INSERT INTO conversations (user_id, direction, message_type, content) VALUES (?, "outbound", "text", ?)'
            )->execute([(int) $user['id'], $greeting]);
        }

        self::notifyFamilyUserLinked($pdo, (int) $user['id']);
    }

    // ご利用者様のLINE連携(友だち追加まで)が完了したことを、紐づく家族(既に自分自身のLINE連携を
    // 完了している場合のみ)に知らせる。コピーしたURLをご本人の端末に送った家族が、完了を待って
    // 手動で確認し続けなくて済むようにするための通知
    private static function notifyFamilyUserLinked(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare(
            'SELECT f.line_user_id, COALESCE(u.full_name, u.display_name) AS user_name
             FROM user_family_links l
             JOIN family_accounts f ON f.id = l.family_account_id
             JOIN users u ON u.id = l.user_id
             WHERE l.user_id = ? AND l.is_active = 1
               AND f.line_user_id IS NOT NULL AND f.friend_confirmed_at IS NOT NULL'
        );
        $stmt->execute([$userId]);
        $families = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($families)) {
            return;
        }

        $familyLineClient = new LineClient(Config::get('LINE_FAMILY_CHANNEL_SECRET'), Config::get('LINE_FAMILY_CHANNEL_ACCESS_TOKEN'));
        foreach ($families as $f) {
            $userLabel = !empty($f['user_name']) ? $f['user_name'] . '様' : 'ご利用者様';
            $familyLineClient->push(
                $f['line_user_id'],
                "{$userLabel}のLINE連携が完了しました。\nこれから会話が始まりますので、しばらく見守ってあげてください。"
            );
        }
    }

    // ブロック後に再度友だち追加された場合の軽量処理。confirmUser()と違い、初回の「はじめまして」挨拶
    // (=会話開始・家族への「LINE連携が完了しました」通知)は再送しない。友だち追加確認の記録のみ更新し、
    // 短い再開メッセージだけを送る
    public static function reconnectUser(UserRepository $userRepo, LineClient $lineClient, array $user): void
    {
        $userRepo->reconfirmFriend((int) $user['id']);
        $companionName = $user['companion_name'] ?: 'たより';
        $lineClient->push($user['line_user_id'], "{$companionName}です。またお話しできて嬉しいです。これからもよろしくお願いしますね。");
    }

    public static function confirmFamily(FamilyAccountRepository $familyRepo, LineClient $lineClient, array $family): void
    {
        $familyRepo->markFriendConfirmed((int) $family['id']);
        $familyName = $family['name'] ?: 'ご家族';
        $lineClient->push(
            $family['line_user_id'],
            "{$familyName}様、登録が完了しました。\n無料期間の終了案内など、お支払いに関するご連絡をこちらのアカウントからお送りします。"
        );
    }
}
