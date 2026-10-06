<?php
declare(strict_types=1);

/**
 * HTML for the event community: posts, comments and their menus. Used by
 * event.php for the first page of the feed and by api/community.php for
 * "load more" / "load comments" / "new comment", so the markup lives in one
 * place. Every piece of user text goes through htmlspecialchars().
 */

function ev_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** "just now", "5m", "2h", "3d", then "12 Sep". */
function community_time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) ($diff / 60) . 'm';
    }
    if ($diff < 86400) {
        return (int) ($diff / 3600) . 'h';
    }
    if ($diff < 86400 * 7) {
        return (int) ($diff / 86400) . 'd';
    }
    return date('j M', strtotime($datetime));
}

/** Soft, stable avatar colour per person. */
function community_avatar_tone(int $seed): string
{
    $tones = ['#EBDCD7', '#D9E4E4', '#E3DAEA', '#F1E3C9', '#DCE6D3', '#E8D3D8'];
    return $tones[abs($seed) % count($tones)];
}

function community_avatar_html(string $name, int $seed, bool $organizer = false, string $size = ''): string
{
    $bg = $organizer ? '#DC2626' : community_avatar_tone($seed);
    $color = $organizer ? '#fff' : '#1B1417';
    return '<span class="ev-avatar' . ($size ? ' ev-avatar-' . $size : '') . '" style="background:' . $bg . '; color:' . $color . '" aria-hidden="true">' . ev_h(community_initials($name)) . '</span>';
}

/**
 * Per-request facts the renderers need about the event and the viewer.
 * @return array{user:?array, event:array, is_mod:bool, can_post:bool, org_name:string, login_url:string, logged_in:bool}
 */
function community_ctx(array $event, ?array $user, string $orgName): array
{
    return [
        'user' => $user,
        'logged_in' => $user !== null,
        'event' => $event,
        'is_mod' => can_moderate_event($user, $event),
        'can_post' => can_post_to_event($user, $event),
        'org_name' => $orgName,
        'login_url' => '/login.php?next=' . urlencode('/event.php?slug=' . $event['slug'] . '#community'),
    ];
}

/** Emoji summary like "❤️ 🔥 38" for a set of reactions. */
function community_reaction_summary_html(array $breakdown, int $total): string
{
    if ($total <= 0) {
        return '<span class="ev-react-sum" data-total="0"></span>';
    }
    $emoji = '';
    foreach (array_keys($breakdown) as $type) {
        $emoji .= (REACTION_TYPES[$type] ?? '') . ' ';
    }
    return '<span class="ev-react-sum" data-total="' . $total . '">' . trim($emoji) . ' <b>' . $total . '</b></span>';
}

function community_reaction_picker_html(): string
{
    $html = '<div class="ev-picker" role="group" aria-label="Choose a reaction" hidden>';
    foreach (REACTION_TYPES as $key => $emoji) {
        $html .= '<button type="button" class="ev-picker-btn" data-reaction="' . $key . '" aria-label="' . ev_h(ucfirst(strtolower($key))) . '">' . $emoji . '</button>';
    }
    return $html . '</div>';
}

/** The ⋯ menu. Items depend on who is looking at whose content. */
function community_menu_html(string $type, int $id, int $ownerId, array $ctx, bool $official = false, bool $pinned = false): string
{
    $items = [];
    $user = $ctx['user'];
    $mine = $user && (int) $user['id'] === $ownerId;
    $attr = ' data-type="' . $type . '" data-id="' . $id . '"';

    if (!$mine) {
        $items[] = '<button type="button" class="ev-menu-item" data-act="report"' . $attr . '>Report</button>';
    }
    if ($ctx['is_mod'] && !$mine) {
        $items[] = '<button type="button" class="ev-menu-item" data-act="hide"' . $attr . '>Hide</button>';
    }
    if ($type === 'POST' && $official && $ctx['is_mod']) {
        $items[] = '<button type="button" class="ev-menu-item" data-act="' . ($pinned ? 'unpin' : 'pin') . '"' . $attr . '>' . ($pinned ? 'Unpin' : 'Pin to top') . '</button>';
    }
    if ($mine || $ctx['is_mod']) {
        $items[] = '<button type="button" class="ev-menu-item danger" data-act="delete"' . $attr . '>Delete</button>';
    }
    if (!$mine && !$ctx['is_mod'] && $user) {
        $items[] = '<button type="button" class="ev-menu-item" data-act="block" data-user="' . $ownerId . '">Block this person</button>';
    }
    $label = $type === 'POST' ? 'Post options' : 'Comment options';
    return '<div class="ev-menu-wrap"><button type="button" class="ev-menu-btn" aria-label="' . $label . '" aria-haspopup="true" aria-expanded="false">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></button>'
        . '<div class="ev-menu" role="menu" hidden>' . implode('', $items) . '</div></div>';
}

/** Name + badges for an author. Official posts speak as the organizer. */
function community_author_html(array $row, array $ctx, bool $official): string
{
    $isOrganizer = (int) ($row['is_organizer'] ?? 0) === 1;
    $name = $official || $isOrganizer ? $ctx['org_name'] : community_display_name((string) $row['author_name']);
    $badges = '';
    if ($official) {
        $badges .= '<span class="ev-chip ev-chip-official">Official organizer update</span>';
    } elseif ($isOrganizer) {
        $badges .= '<span class="ev-chip ev-chip-org">Organizer</span>';
    } elseif ((int) ($row['is_verified_attendee'] ?? 0) === 1) {
        $badges .= '<span class="ev-chip ev-chip-verified">Verified attendee</span>';
    }
    return '<span class="ev-author-name">' . ev_h($name) . '</span>' . $badges;
}

/** One post card. @param array<string,mixed> $p a row from get_event_feed() */
function community_post_html(array $p, array $ctx): string
{
    $id = (int) $p['id'];
    $official = $p['post_type'] === 'ORGANIZER_UPDATE';
    $isOrganizer = $official || (int) $p['is_organizer'] === 1;
    $pinned = (int) $p['is_pinned'] === 1;
    $breakdown = $p['reactions'] ?? [];
    $mine = $p['my_reaction'] ?? null;
    $commentCount = (int) $p['comment_count'];
    $commentsOn = (int) $p['comments_enabled'] === 1;
    $authorName = $isOrganizer ? $ctx['org_name'] : community_display_name((string) $p['author_name']);

    $h = '<article class="ev-post' . ($official ? ' is-official' : '') . ($pinned ? ' is-pinned' : '') . '" id="post-' . $id . '" data-post-id="' . $id . '" data-author="' . (int) $p['author_id'] . '">';
    $h .= '<header class="ev-post-head">' . community_avatar_html($authorName, (int) $p['author_id'], $isOrganizer);
    $h .= '<div class="ev-post-who"><div class="ev-author">' . community_author_html($p, $ctx, $official) . '</div>';
    $h .= '<div class="ev-post-meta">' . ($pinned ? '<span class="ev-pin">Pinned</span> &middot; ' : '') . ev_h(community_time_ago($p['created_at'])) . '</div></div>';
    $h .= community_menu_html('POST', $id, (int) $p['author_id'], $ctx, $official, $pinned) . '</header>';

    if ($p['body'] !== null && $p['body'] !== '') {
        $h .= '<div class="ev-post-body">' . nl2br(ev_h($p['body'])) . '</div>';
    }
    if (!empty($p['image_path'])) {
        $h .= '<button type="button" class="ev-post-photo" data-lightbox-src="' . ev_h($p['image_path']) . '" aria-label="View photo"><img src="' . ev_h($p['image_path']) . '" alt="Photo shared by ' . ev_h($authorName) . '" loading="lazy"></button>';
    }

    $h .= '<div class="ev-post-stats">' . community_reaction_summary_html($breakdown, (int) $p['reaction_count']);
    $h .= '<span class="ev-grow"></span>';
    if ($commentsOn || $commentCount > 0) {
        $h .= '<button type="button" class="ev-count-btn" data-act="toggle-comments">' . $commentCount . ' comment' . ($commentCount === 1 ? '' : 's') . '</button>';
    }
    $h .= '</div>';

    $mineEmoji = $mine && isset(REACTION_TYPES[$mine]) ? REACTION_TYPES[$mine] : '';
    $h .= '<div class="ev-post-actions"><div class="ev-react-wrap">';
    $h .= '<button type="button" class="ev-action ev-react-btn' . ($mine ? ' is-on' : '') . '" data-act="react-open" data-my="' . ev_h((string) $mine) . '" aria-haspopup="true">'
        . '<span class="ev-react-ico">' . ($mineEmoji ?: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 5.6a5.2 5.2 0 0 0-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 0 0-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 0 0 0-7.4z"/></svg>') . '</span> '
        . '<span class="ev-react-label">' . ($mine ? ev_h(ucfirst(strtolower((string) $mine))) : 'React') . '</span></button>';
    $h .= community_reaction_picker_html() . '</div>';
    if ($commentsOn) {
        $h .= '<button type="button" class="ev-action" data-act="toggle-comments"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-5.4A8 8 0 1 1 21 12z"/></svg> Comment</button>';
    }
    $h .= '<button type="button" class="ev-action" data-act="share-post"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg> Share</button></div>';

    if ($commentsOn || $commentCount > 0) {
        $h .= '<div class="ev-thread" hidden data-loaded="0"><div class="ev-comments"></div>';
        if ($commentsOn) {
            $h .= $ctx['logged_in']
                ? '<form class="ev-comment-form" data-post-id="' . $id . '"><label class="sr-only" for="cf' . $id . '">Add a comment</label><input id="cf' . $id . '" type="text" name="body" maxlength="' . COMMENT_MAX_CHARS . '" placeholder="Add a comment" autocomplete="off"><button type="submit" class="ev-send" aria-label="Send comment"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/></svg></button></form>'
                : '<a class="ev-login-prompt" href="' . ev_h($ctx['login_url']) . '">Log in to join the conversation</a>';
        } else {
            $h .= '<div class="ev-muted-note">Comments are turned off for this post.</div>';
        }
        $h .= '</div>';
    }
    return $h . '</article>';
}

/** One comment, with its replies if it has any. @param array<string,mixed> $c */
function community_comment_html(array $c, array $ctx, bool $isReply = false): string
{
    $id = (int) $c['id'];
    $isOrganizer = (int) ($c['is_organizer'] ?? 0) === 1;
    $name = $isOrganizer ? $ctx['org_name'] : community_display_name((string) $c['author_name']);
    $mine = $c['my_reaction'] ?? null;
    $count = (int) ($c['reaction_count'] ?? 0);

    $h = '<div class="ev-comment' . ($isReply ? ' is-reply' : '') . '" id="comment-' . $id . '" data-comment-id="' . $id . '" data-author="' . (int) $c['user_id'] . '" data-name="' . ev_h($name) . '">';
    $h .= community_avatar_html($name, (int) $c['user_id'], $isOrganizer, 'sm');
    $h .= '<div class="ev-comment-main"><div class="ev-bubble"><div class="ev-bubble-top"><span class="ev-author-name">' . ev_h($name) . '</span>';
    if ($isOrganizer) {
        $h .= '<span class="ev-chip ev-chip-org">Organizer</span>';
    } elseif ((int) ($c['is_verified_attendee'] ?? 0) === 1) {
        $h .= '<span class="ev-chip ev-chip-verified">Verified attendee</span>';
    }
    $h .= community_menu_html('COMMENT', $id, (int) $c['user_id'], $ctx) . '</div>';
    if (!empty($c['reply_to_comment_id'])) {
        if (($c['reply_to_status'] ?? '') === 'PUBLISHED' && ($c['reply_to_name'] ?? '') !== '') {
            $quoted = !empty($c['reply_to_is_organizer']) ? $ctx['org_name'] : community_display_name((string) $c['reply_to_name']);
            $h .= '<a class="ev-quote" href="#comment-' . (int) $c['reply_to_comment_id'] . '" data-jump="' . (int) $c['reply_to_comment_id'] . '"><b>' . ev_h($quoted) . '</b><span>' . ev_h((string) $c['reply_to_body']) . '</span></a>';
        } else {
            $h .= '<div class="ev-quote is-gone"><span>Original comment is no longer available</span></div>';
        }
    }
    $h .= '<div class="ev-comment-text">' . nl2br(ev_h($c['body'])) . '</div></div>';
    $h .= '<div class="ev-comment-meta"><span>' . ev_h(community_time_ago($c['created_at'])) . '</span>';
    $h .= '<div class="ev-react-wrap"><button type="button" class="ev-link-btn ev-react-btn' . ($mine ? ' is-on' : '') . '" data-act="react-open" data-target="comment" data-my="' . ev_h((string) $mine) . '">'
        . ($mine && isset(REACTION_TYPES[$mine]) ? REACTION_TYPES[$mine] . ' ' . ev_h(ucfirst(strtolower((string) $mine))) : 'React') . '</button>' . community_reaction_picker_html() . '</div>';
    $h .= '<button type="button" class="ev-link-btn" data-act="reply">Reply</button>';
    $h .= '<span class="ev-comment-count">' . ($count > 0 ? '&#10084;&#65039; ' . $count : '') . '</span></div>';
    $h .= '<div class="ev-replies">';
    foreach ($c['replies'] ?? [] as $reply) {
        $h .= community_comment_html($reply, $ctx, true);
    }
    $h .= '</div></div></div>';
    return $h;
}
