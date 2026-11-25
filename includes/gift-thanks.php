<?php
/**
 * OBI GIFTS: an organizer says thank you to a fan who sent a gift on their video.
 *
 *  - A supporter who gave their name gets the thank-you in a private chat from the organizer (it lands in their normal chats, not in "requests",
 *    because they started the relationship by gifting) plus a notification that opens that chat.
 *  - A supporter who chose to stay anonymous gets the thank-you as a notification only. No chat is opened and nothing the organizer sees or
 *    receives ever names them: the organizer cannot learn who an anonymous supporter is from this feature.
 *  - One thank-you per gift. Limits stop it becoming spam: a few per supporter a day and a daily cap per organizer.
 *  - The record that a gift was thanked lives in gift_events (kind thanked_chat / thanked_notice), so no new table is needed.
 */

const GIFT_THANKS_MAX_CHARS = 300;
const GIFT_THANKS_PER_FAN_PER_DAY = 3;      // thank-yous one supporter may receive from one organizer in 24 hours
const GIFT_THANKS_PER_DAY = 100;            // thank-yous one organizer may send in 24 hours

/** A friendly message to start from (the organizer can change every word). */
function gift_thanks_default_message(string $giftName, string $organizerName): string
{
    $organizerName = trim($organizerName) !== '' ? trim($organizerName) : 'the team';
    return 'Thank you so much for the ' . $giftName . '! Your support means the world to us. See you at the next event. - ' . $organizerName;
}

/**
 * Which of these gifts were already thanked, and with what words.
 * @param list<int> $txIds
 * @return array<int, array{message:string, at:string, channel:string}>
 */
function gift_thanks_for(array $txIds): array
{
    $txIds = array_values(array_unique(array_filter(array_map('intval', $txIds))));
    if (!$txIds || !function_exists('gifts_ready') || !gifts_ready()) {
        return [];
    }
    $st = db()->query("SELECT gift_tx_id, kind, details, created_at FROM gift_events WHERE kind IN ('thanked_chat','thanked_notice') AND gift_tx_id IN (" . implode(',', $txIds) . ') ORDER BY id');
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['gift_tx_id']] = ['message' => (string) $r['details'], 'at' => (string) $r['created_at'], 'channel' => $r['kind'] === 'thanked_chat' ? 'chat' : 'notice'];
    }
    return $out;
}

/** Plain text only: control characters out, blank lines limited, trimmed. */
function gift_thanks_clean(string $s): string
{
    return function_exists('dm_clean') ? dm_clean($s) : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
}

/**
 * Sends the thank-you for one gift. $organizerId is the organizer's own account (the gift's recipient).
 * @return array{ok:bool, error?:string, channel?:string, chat_id?:int}
 */
function gift_thank(int $organizerId, int $txId, string $message): array
{
    $fail = static fn (string $e): array => ['ok' => false, 'error' => $e];
    if (!gifts_ready()) {
        return $fail('Gifts are not available yet.');
    }
    $message = gift_thanks_clean($message);
    if ($message === '') {
        return $fail('Write your thank-you message first.');
    }
    if (mb_strlen($message) > GIFT_THANKS_MAX_CHARS) {
        return $fail('Keep it to ' . GIFT_THANKS_MAX_CHARS . ' characters or fewer.');
    }
    $pdo = db();
    $lock = 'gift_thanks_' . $txId;
    $got = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 5)')->fetchColumn();
    if ($got !== 1) {
        return $fail('Please try again in a moment.');
    }
    try {
        $st = $pdo->prepare('SELECT t.id, t.sender_id, t.post_id, t.is_anonymous, t.payment_status, t.delivery_status, g.name AS gift_name
                             FROM gift_transactions t JOIN gift_catalog g ON g.id = t.gift_id WHERE t.id = ? AND t.recipient_organizer_id = ?');
        $st->execute([$txId, $organizerId]);
        $tx = $st->fetch();
        if (!$tx || $tx['payment_status'] !== 'SUCCESSFUL' || $tx['delivery_status'] !== 'DELIVERED') {
            return $fail('You can only thank someone for a gift you received.');
        }
        if (gift_thanks_for([$txId])) {
            return $fail('You already thanked them for this gift.');
        }
        $senderId = (int) $tx['sender_id'];
        $sender = $pdo->prepare("SELECT id FROM users WHERE id = ? AND account_status = 'ACTIVE'");
        $sender->execute([$senderId]);
        if (!$sender->fetchColumn()) {
            return $fail('This supporter can no longer be reached.');
        }
        // limits
        $perFan = $pdo->prepare("SELECT COUNT(*) FROM gift_events e JOIN gift_transactions t ON t.id = e.gift_tx_id
                                 WHERE e.kind IN ('thanked_chat','thanked_notice') AND t.recipient_organizer_id = ? AND t.sender_id = ? AND e.created_at >= NOW() - INTERVAL 24 HOUR");
        $perFan->execute([$organizerId, $senderId]);
        if ((int) $perFan->fetchColumn() >= GIFT_THANKS_PER_FAN_PER_DAY) {
            return $fail('You have already thanked this supporter a few times today. Try again tomorrow.');
        }
        $perDay = $pdo->prepare("SELECT COUNT(*) FROM gift_events e JOIN gift_transactions t ON t.id = e.gift_tx_id
                                 WHERE e.kind IN ('thanked_chat','thanked_notice') AND t.recipient_organizer_id = ? AND e.created_at >= NOW() - INTERVAL 24 HOUR");
        $perDay->execute([$organizerId]);
        if ((int) $perDay->fetchColumn() >= GIFT_THANKS_PER_DAY) {
            return $fail('You have sent a lot of thank-yous today. Please continue tomorrow.');
        }
        if (social_blocked_between($organizerId, $senderId)) {
            return $fail('This thank-you cannot be delivered.');
        }

        $orgName = (string) $pdo->query('SELECT COALESCE(NULLIF(op.org_name, \'\'), u.name) FROM users u LEFT JOIN organizer_profiles op ON op.user_id = u.id WHERE u.id = ' . (int) $organizerId)->fetchColumn();
        $anonymous = !empty($tx['is_anonymous']);
        $chatId = null;
        if (!$anonymous) {
            $open = dm_open($organizerId, $senderId);
            if (!$open['ok']) {
                return $fail((string) ($open['error'] ?? 'Could not open the chat.'));
            }
            $chatId = (int) $open['id'];
            // they gifted, so this is a normal chat for them, not a message request
            $pdo->prepare('UPDATE dm_conversations SET accepted_at = COALESCE(accepted_at, NOW()) WHERE id = ?')->execute([$chatId]);
            $sent = dm_send($organizerId, $chatId, $message);
            if (!$sent['ok']) {
                return $fail((string) ($sent['error'] ?? 'Could not send the message.'));
            }
        }
        gift_log($txId, $anonymous ? 'thanked_notice' : 'thanked_chat', $message);
        // the supporter is told in the bell: it opens the chat, or (anonymous) the video
        $link = $chatId ? '/chat.php?c=' . $chatId : ($tx['post_id'] ? '/socials.php?v=' . (int) $tx['post_id'] : '/');
        try {
            create_notification($senderId, 'thank_you', $orgName . ' thanked you for your ' . $tx['gift_name'], $anonymous ? $message : mb_substr($message, 0, 150), $link, $organizerId);
        } catch (Throwable $e) {
            error_log('[gift] thank-you notification failed: ' . $e->getMessage());
        }
        return ['ok' => true, 'channel' => $anonymous ? 'notice' : 'chat'] + ($chatId ? ['chat_id' => $chatId] : []);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}
