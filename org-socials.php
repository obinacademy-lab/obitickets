<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Organizer "Socials": the plan (free month, then a monthly payment by mobile money), a shortcut to post,
// and how the organizer's posts are doing. Rules and payments live in includes/socials.php.

$ctx = require_organizer_access('social.view');
$organizerId = (int) $ctx['organizer_id'];
$ready = socials_ready();
$status = socials_status($organizerId);
$events = $ready ? socials_postable_events($organizerId) : [];
$posts = $ready ? socials_organizer_posts($organizerId, 24) : [];
$payments = [];
if ($ready) {
    $stmt = db()->prepare("SELECT * FROM social_payments WHERE organizer_id = ? AND status IN ('PAID','GRANTED','FAILED') ORDER BY id DESC LIMIT 6");
    $stmt->execute([$organizerId]);
    $payments = $stmt->fetchAll();
}
$until = $status['until'] ? date('j M Y', strtotime($status['until'])) : null;
$payerPhone = (string) (current_user()['phone'] ?? '');

$pageTitle = 'Socials';
render_organizer_head('socials', $ctx);
?>
<div class="admin-page-head">
  <div><h1>Socials</h1><p>Post videos and photos of your events. Everyone on obitickets can watch, react, comment and get a ticket straight from your post.</p></div>
  <a class="btn btn-line" href="/socials.php">Open the Socials feed</a>
</div>

<?php if (!$ready): ?>
  <div class="admin-card"><h2 style="font-size:1.1rem">Socials is being switched on</h2><p style="margin-top:6px">This page will open as soon as the setup is finished. Nothing is needed from you.</p></div>
<?php else: ?>

<div class="sx-grid">
  <section class="admin-card sx-plan sx-<?= htmlspecialchars($status['state']) ?>" id="sxPlan" data-state="<?= htmlspecialchars($status['state']) ?>">
    <?php if ($status['state'] === 'none'): ?>
      <span class="sx-tag">First month free</span>
      <h2>Start posting on Socials</h2>
      <p>Your first <?= (int) SOCIALS_TRIAL_DAYS ?> days are free. After that it is <?= htmlspecialchars(socials_price_label()) ?> for every <?= (int) SOCIALS_PERIOD_DAYS ?> days, paid by MTN MoMo or Airtel Money. Nothing is charged automatically.</p>
      <ul class="sx-list">
        <li>Post event videos, photos and slideshows with sound</li>
        <li>A Get Tickets button on every post</li>
        <li>See how many views, likes and ticket sales each post brings</li>
      </ul>
      <button type="button" class="btn btn-purple btn-lg" id="sxTrial">Start my free month</button>
    <?php elseif ($status['state'] === 'trial'): ?>
      <span class="sx-tag">Free month</span>
      <h2><?= (int) $status['days_left'] ?> day<?= $status['days_left'] === 1 ? '' : 's' ?> left</h2>
      <p>Your free month runs until <strong><?= htmlspecialchars((string) $until) ?></strong>. Pay any time to keep posting after that; the paid days are added after your free month, so you lose nothing by paying early.</p>
    <?php elseif ($status['state'] === 'active'): ?>
      <span class="sx-tag sx-tag-on">Active</span>
      <h2>Active until <?= htmlspecialchars((string) $until) ?></h2>
      <p><?= (int) $status['days_left'] ?> day<?= $status['days_left'] === 1 ? '' : 's' ?> left. Add another <?= (int) SOCIALS_PERIOD_DAYS ?> days any time; they are added after your current period.</p>
    <?php else: ?>
      <span class="sx-tag sx-tag-off">Ended</span>
      <h2>Your Socials plan has ended</h2>
      <p>Your posts are still in the feed. To post new ones, pay <?= htmlspecialchars(socials_price_label()) ?> for the next <?= (int) SOCIALS_PERIOD_DAYS ?> days.</p>
    <?php endif; ?>

    <?php if ($status['state'] !== 'none'): ?>
      <form class="sx-pay" id="sxPay" novalidate>
        <label for="sxPhone">Mobile money number (MTN or Airtel)</label>
        <div class="sx-pay-row">
          <input id="sxPhone" type="tel" inputmode="tel" autocomplete="tel" placeholder="07XX XXX XXX" value="<?= htmlspecialchars($payerPhone) ?>">
          <button type="submit" class="btn btn-purple" id="sxPayBtn">Pay <?= htmlspecialchars(socials_price_label()) ?></button>
        </div>
        <p class="sx-hint">You will get a prompt on this phone to approve the payment. <?= (int) SOCIALS_PERIOD_DAYS ?> days are added as soon as it is confirmed.</p>
      </form>
      <div class="sx-wait" id="sxWait" hidden role="status" aria-live="polite">
        <span class="sx-spin" aria-hidden="true"></span>
        <div><strong>Check your phone</strong><span id="sxWaitText">Approve the mobile money prompt to finish. Please do not pay twice.</span></div>
      </div>
    <?php endif; ?>
    <p class="sx-msg" id="sxMsg" role="alert" hidden></p>
  </section>

  <section class="admin-card sx-post">
    <h2>Post to Socials</h2>
    <?php if (!$status['can_post']): ?>
      <p>Start your free month or renew your plan to post.</p>
    <?php elseif (!$events): ?>
      <p>Posts are tied to an event. Publish an event first, then come back to post about it.</p>
      <a class="btn btn-purple" href="/event-create.php">Create an event</a>
    <?php else: ?>
      <p>Choose the event the post is about. It gets a Get Tickets button for that event.</p>
      <form class="sx-post-form" action="/org-social.php" method="get">
        <label class="sr-only" for="sxEvent">Event</label>
        <select id="sxEvent" name="event">
          <?php foreach ($events as $e): ?>
            <option value="<?= (int) $e['id'] ?>"><?= htmlspecialchars($e['title']) ?><?= (int) $e['ended'] === 1 ? ' (ended)' : ' · ' . htmlspecialchars(date('j M', strtotime((string) $e['starts_at']))) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-purple">Upload a video or photo</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<section class="admin-card" style="margin-top:16px">
  <h2 style="font-size:1.1rem">Your posts</h2>
  <?php if (!$posts): ?>
    <p style="margin-top:6px">Nothing posted yet. Your posts and their numbers will show here.</p>
  <?php else: ?>
    <div class="sx-posts">
      <?php foreach ($posts as $p):
          $thumb = (string) ($p['media_type'] === 'VIDEO' ? ($p['poster_path'] ?: '') : $p['media_path']);
          $thumb = preg_match('#^/uploads/[A-Za-z0-9/_.\-]+$#', $thumb) ? $thumb : '';
      ?>
        <a class="sx-postcard" href="/socials.php?v=<?= (int) $p['id'] ?>">
          <span class="sx-thumb"><?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy"><?php endif; ?><em><?= $p['media_type'] === 'VIDEO' ? 'Video' : ($p['media_type'] === 'SLIDESHOW' ? 'Slideshow' : 'Photo') ?></em></span>
          <span class="sx-postbody">
            <b><?= htmlspecialchars($p['event_title']) ?></b>
            <small><?= htmlspecialchars(community_time_ago((string) $p['created_at'])) ?></small>
            <span class="sx-nums"><span><strong><?= htmlspecialchars(moment_compact((int) $p['view_count'])) ?></strong> views</span><span><strong><?= htmlspecialchars(moment_compact((int) $p['reaction_count'])) ?></strong> likes</span><span><strong><?= htmlspecialchars(moment_compact((int) $p['comment_count'])) ?></strong> comments</span><span><strong><?= (int) $p['checkouts'] ?></strong> checkouts</span></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($payments): ?>
<section class="admin-card" style="margin-top:16px">
  <h2 style="font-size:1.1rem">Payments</h2>
  <div class="admin-table-wrap" style="margin-top:12px">
    <table class="admin-table">
      <thead><tr><th>Date</th><th>Amount</th><th>Status</th><th>Covers</th></tr></thead>
      <tbody>
        <?php foreach ($payments as $pay): ?>
          <tr>
            <td><?= htmlspecialchars(date('j M Y, g:ia', strtotime((string) $pay['created_at']))) ?></td>
            <td class="mono"><?= $pay['status'] === 'GRANTED' ? 'Added by obitickets' : 'UGX ' . number_format((float) $pay['amount']) ?></td>
            <td><?= $pay['status'] === 'FAILED' ? 'Not completed' : 'Paid' ?></td>
            <td><?= $pay['period_end'] ? htmlspecialchars(date('j M', strtotime((string) $pay['period_start'])) . ' to ' . date('j M Y', strtotime((string) $pay['period_end']))) : '&ndash;' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var msg = document.getElementById('sxMsg');
  function api(body) {
    body.csrf_token = csrf;
    return fetch('/api/socials.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }, function () { return { error: 'We could not reach the server. Check your connection and try again.' }; });
  }
  function say(text) { if (!msg) { return; } msg.textContent = text; msg.hidden = !text; }

  var trial = document.getElementById('sxTrial');
  if (trial) {
    trial.addEventListener('click', function () {
      trial.disabled = true; say('');
      api({ action: 'trial_start' }).then(function (r) {
        if (r && r.ok) { window.location.reload(); return; }
        trial.disabled = false; say((r && r.error) || 'Could not start your free month. Please try again.');
      });
    });
  }

  var form = document.getElementById('sxPay'), wait = document.getElementById('sxWait'), waitText = document.getElementById('sxWaitText');
  var btn = document.getElementById('sxPayBtn'), phone = document.getElementById('sxPhone');
  var polls = 0;
  function poll(id) {
    api({ action: 'pay_poll', paymentId: id }).then(function (r) {
      if (r && r.status === 'PAID') { waitText.textContent = 'Payment confirmed. Updating your plan...'; window.location.reload(); return; }
      if (r && r.status === 'FAILED') {
        wait.hidden = true; form.hidden = false; btn.disabled = false;
        say((r.statusMessage || 'The payment was not completed.') + ' You have not been charged for this attempt.');
        return;
      }
      polls += 1;
      if (polls > 60) {   // about four minutes
        wait.hidden = true; form.hidden = false; btn.disabled = false;
        say('We have not received a confirmation yet. If you approved the prompt, reload this page in a minute; your plan updates on its own. Please do not pay twice.');
        return;
      }
      setTimeout(function () { poll(id); }, 4000);
    });
  }
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault(); say('');
      if (phone.value.replace(/\D/g, '').length < 9) { say('Enter a valid mobile money number.'); phone.focus(); return; }
      btn.disabled = true;
      api({ action: 'pay_start', phone: phone.value }).then(function (r) {
        if (!r || !r.ok) { btn.disabled = false; say((r && r.error) || 'Could not start the payment. Please try again.'); return; }
        form.hidden = true; wait.hidden = false; polls = 0;
        setTimeout(function () { poll(r.paymentId); }, 4000);
      });
    });
  }
})();
</script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
