<?php
require_once __DIR__ . '/includes/bootstrap.php';

$ctx = require_organizer_access('audience.use');
$organizerId = $ctx['organizer_id'];

$ready = audience_ready();
$events = $ready ? audience_events($organizerId) : [];

$eventId = (int) ($_GET['event'] ?? $_POST['event'] ?? 0);
$current = null;
foreach ($events as $e) {
    if ((int) $e['id'] === $eventId) {
        $current = $e;
    }
}
if (!$current && $events) {
    // Default to the event with the most people waiting to hear from you.
    $current = $events[0];
    foreach ($events as $e) {
        if ($e['never_bought'] > $current['never_bought']) {
            $current = $e;
        }
    }
}

$error = null;
$draft = null;
if ($current && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    verify_csrf();
    [$action, $personId] = array_pad(explode(':', (string) $_POST['send'], 2), 2, '0');
    $draft = (string) ($_POST['message'] ?? '');
    $result = audience_handle((int) $current['id'], (int) $personId, $ctx, $action, $draft);
    if ($result['ok']) {
        header('Location: ' . ($result['redirect'] ?? '/org-interested.php?event=' . (int) $current['id'] . '&sent=1&page=' . max(1, (int) ($_POST['page'] ?? 1))));
        exit;
    }
    $error = $result['error'];
}

$page = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
$perPage = 30;
$people = [];
$total = 0;
if ($current) {
    $total = $current['never_bought'];
    $people = audience_list((int) $current['id'], $perPage, ($page - 1) * $perPage);
}
$totalPages = max(1, (int) ceil($total / $perPage));
$sender = followup_sender((int) $ctx['actor_id'], $ctx['is_owner'] ? 'ORGANIZER' : 'TEAM');
$orgEvent = $current ? audience_event((int) $current['id'], $organizerId) : null;
$message = $draft ?? ($orgEvent ? audience_default_message($orgEvent, $sender) : '');
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$ago = static function (string $at): string {
    $s = max(0, time() - strtotime($at));
    if ($s < 3600) {
        return max(1, (int) round($s / 60)) . ' min ago';
    }
    if ($s < 86400) {
        return (int) round($s / 3600) . ' h ago';
    }
    return (int) round($s / 86400) . ' d ago';
};

$pageTitle = 'Interested';
render_organizer_head('interested', $ctx);
?>
<style>
.aud-events{display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:12px; margin-bottom:22px}
.aud-event{display:block; background:var(--surface); border:1px solid var(--line); border-radius:16px; padding:14px 16px; color:var(--ink); text-decoration:none; position:relative; transition:border-color .2s var(--ov-ease), transform .2s var(--ov-ease)}
.aud-event:hover{border-color:var(--ink); transform:translateY(-1px)}
.aud-event.on{border-color:var(--ink); box-shadow:inset 3px 0 0 var(--ov-gold)}
.aud-event b{display:block; font-size:.92rem; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
.aud-event small{display:block; color:var(--muted-2); font-size:.76rem; margin-top:2px}
.aud-event .n{display:block; margin-top:10px; font-family:'Outfit','Plus Jakarta Sans',sans-serif; font-size:1.55rem; font-weight:700; line-height:1}
.aud-event .l{color:var(--muted); font-size:.74rem}
.aud-compose{padding:18px 20px; margin-bottom:18px}
.aud-compose h3{font-size:.95rem; margin:0 0 4px}
.aud-compose p{color:var(--muted); font-size:.8rem; margin:0 0 12px}
.aud-compose textarea{width:100%; min-height:150px; resize:vertical; border:1px solid var(--line); border-radius:12px; padding:12px 14px; font:inherit; font-size:.88rem; line-height:1.55; background:var(--paper); color:var(--ink)}
.aud-compose textarea:focus{outline:2px solid var(--ov-gold); outline-offset:1px; background:#fff}
.aud-act{display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap}
.aud-act .btn{padding:7px 14px; font-size:.8rem; display:inline-flex; align-items:center; gap:6px}
.aud-act .btn[disabled]{opacity:.45; cursor:not-allowed}
.aud-tag{display:inline-block; font-size:.68rem; font-weight:600; padding:2px 8px; border-radius:99px; background:#FDF3DC; color:#8A5A00; margin-left:6px}
.aud-sent{display:block; color:var(--muted-2); font-size:.72rem; margin-top:4px; text-align:right}
.aud-note{color:var(--muted); font-size:.8rem; margin-top:14px}
.aud-list{background:var(--surface); border:1px solid var(--line); border-radius:18px; overflow:hidden}
.aud-head,.aud-row{display:grid; grid-template-columns:minmax(0,1.6fr) minmax(0,.8fr) auto; gap:14px; align-items:center; padding:12px 18px}
.aud-head{font-size:.68rem; letter-spacing:.08em; text-transform:uppercase; color:var(--muted-2); border-bottom:1px solid var(--line)}
.aud-row + .aud-row{border-top:1px solid var(--line)}
.aud-who{display:flex; align-items:center; gap:12px; min-width:0; font-weight:600}
.aud-looked{color:var(--muted); font-size:.84rem; line-height:1.4}
.aud-reach{min-width:0}
@media (max-width:640px){
  .aud-events{display:flex; overflow-x:auto; scroll-snap-type:x mandatory; gap:10px; margin-inline:-16px; padding:0 16px 8px; -webkit-overflow-scrolling:touch}
  .aud-event{flex:0 0 78%; scroll-snap-align:start}
  .aud-head{display:none}
  .aud-row{grid-template-columns:1fr; gap:8px; padding:14px 16px}
  .aud-act{justify-content:stretch}
  .aud-act .btn{flex:1; justify-content:center; padding:10px 14px}
  .aud-sent{text-align:left}
}
</style>

<div class="admin-page-head">
  <div><h1>Interested</h1><p>People who looked at your events but have not bought a ticket yet. Reach out to bring them in.</p></div>
</div>

<?php if (isset($_GET['sent'])): ?><span data-flash="Email sent." hidden></span><?php endif; ?>
<?php if ($error): ?><span data-flash="<?= $h($error) ?>" data-flash-type="error" hidden></span><?php endif; ?>

<?php if (!$ready): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-people"/></svg></div>
    <h3>Coming online shortly</h3>
    <p>This page is being switched on. Check back in a little while.</p>
  </div>
<?php elseif (!$events): ?>
  <div class="admin-empty">
    <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-cal"/></svg></div>
    <h3>No upcoming events to market</h3>
    <p>When you have a published event that has not happened yet, the people who look at it will show up here.</p>
    <a class="btn btn-line" href="/my-events.php">Go to my events</a>
  </div>
<?php else: ?>

  <div class="aud-events">
    <?php foreach ($events as $e): ?>
      <a class="aud-event<?= (int) $e['id'] === (int) $current['id'] ? ' on' : '' ?>" href="/org-interested.php?event=<?= (int) $e['id'] ?>">
        <b><?= $h($e['title']) ?></b>
        <small><?= $h(date('D, d M Y', strtotime($e['starts_at']))) ?></small>
        <span class="n"><?= (int) $e['never_bought'] ?></span><span class="l">viewed, never bought</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="admin-mini-stat-row">
    <div class="admin-mini-stat"><span class="n"><?= (int) $current['viewed_known'] ?></span><span class="l">Members who viewed</span></div>
    <div class="admin-mini-stat"><span class="n"><?= (int) $current['bought'] ?></span><span class="l">Of them, bought</span></div>
    <div class="admin-mini-stat active"><span class="n"><?= (int) $current['never_bought'] ?></span><span class="l">Viewed, never bought</span></div>
    <div class="admin-mini-stat"><span class="n"><?= (int) $current['anonymous'] ?></span><span class="l">Guests (not signed in)</span></div>
  </div>

  <?php if (!$people): ?>
    <div class="admin-empty">
      <div class="admin-empty-ic"><svg width="26" height="26"><use href="#ic-people"/></svg></div>
      <h3><?= (int) $current['viewed_known'] > 0 ? 'Everyone who looked has bought' : 'No one to reach yet' ?></h3>
      <p><?= (int) $current['viewed_known'] > 0 ? 'Nobody is waiting on a nudge for this event right now.' : 'Views are counted from the day this page went live. Members who open this event and do not buy will appear here.' ?></p>
    </div>
  <?php else: ?>
    <form method="post" action="/org-interested.php?event=<?= (int) $current['id'] ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="event" value="<?= (int) $current['id'] ?>">
      <input type="hidden" name="page" value="<?= $page ?>">

      <div class="admin-card aud-compose">
        <h3>Your message</h3>
        <p>It is sent to one person at a time, and <code>{name}</code> becomes their first name. WhatsApp opens on your own phone with this text ready to send. Only links to obitickets can be included.</p>
        <label for="aud-msg" class="sr-only" style="position:absolute; left:-9999px">Message</label>
        <textarea id="aud-msg" name="message" maxlength="<?= AUDIENCE_MAX_MESSAGE ?>" required><?= $h($message) ?></textarea>
      </div>

      <div class="aud-list">
        <div class="aud-head"><span>Person</span><span>Looked</span><span style="text-align:right">Reach out</span></div>
        <?php foreach ($people as $p): ?>
              <?php
              $emailBlocked = !$p['can_email'] || ($p['emailed_at'] && strtotime($p['emailed_at']) > time() - AUDIENCE_COOLDOWN_HOURS * 3600);
              $waBlocked = $p['whatsapp'] === null || ($p['whatsapped_at'] && strtotime($p['whatsapped_at']) > time() - AUDIENCE_COOLDOWN_HOURS * 3600);
              ?>
              <div class="aud-row">
                <div class="aud-who"><span class="avatar"><?= $h(initials_from_name($p['name'])) ?></span><div><?= $h($p['name']) ?><?php if ((int) $p['tried_to_pay'] > 0): ?><span class="aud-tag">Tried to pay</span><?php endif; ?></div></div>
                <div class="aud-looked"><?= (int) $p['view_count'] ?> <?= (int) $p['view_count'] === 1 ? 'view' : 'views' ?> &middot; last <?= $h($ago((string) $p['last_viewed_at'])) ?></div>
                <div class="aud-reach">
                  <div class="aud-act">
                    <button class="btn btn-purple" type="submit" name="send" value="email:<?= (int) $p['user_id'] ?>"<?= $emailBlocked ? ' disabled' : '' ?><?= !$p['can_email'] ? ' title="No usable email address"' : ($emailBlocked ? ' title="Emailed recently"' : '') ?>>Email</button>
                    <button class="btn btn-line" type="submit" name="send" value="whatsapp:<?= (int) $p['user_id'] ?>" formtarget="_blank"<?= $waBlocked ? ' disabled' : '' ?><?= $p['whatsapp'] === null ? ' title="No mobile number on file"' : ($waBlocked ? ' title="Messaged recently"' : '') ?>><svg width="14" height="14" aria-hidden="true"><use href="#ic-whatsapp"/></svg>WhatsApp</button>
                  </div>
                  <?php if ($p['emailed_at'] || $p['whatsapped_at']): ?>
                    <span class="aud-sent"><?= $p['emailed_at'] ? 'Emailed ' . $h(date('d M', strtotime($p['emailed_at']))) : '' ?><?= $p['emailed_at'] && $p['whatsapped_at'] ? ' &middot; ' : '' ?><?= $p['whatsapped_at'] ? 'WhatsApp opened ' . $h(date('d M', strtotime($p['whatsapped_at']))) : '' ?></span>
                  <?php endif; ?>
                </div>
              </div>
        <?php endforeach; ?>
      </div>
    </form>

    <?php if ($totalPages > 1): ?>
      <div class="admin-pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $page): ?><span class="current"><?= $i ?></span><?php else: ?><a href="?event=<?= (int) $current['id'] ?>&page=<?= $i ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

    <p class="aud-note">People who tried to pay come first. Everyone here has an obitickets account. To keep this friendly, each person can be contacted at most <?= AUDIENCE_MAX_PER_CHANNEL ?> times per channel about an event, at least <?= AUDIENCE_COOLDOWN_HOURS ?> hours apart. Guests who were not signed in are counted but cannot be contacted.</p>
  <?php endif; ?>
<?php endif; ?>

<?php render_organizer_foot(); ?>
