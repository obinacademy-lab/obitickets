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
$GLOBALS['orgExtraCss'] = ['org-socials-pro.css'];
render_organizer_head('socials', $ctx);
$dash = $ready ? socials_dashboard_data($organizerId, (int) current_user()['id']) : ['stats' => [], 'rows' => [], 'events' => []];
$st = $dash['stats'];
$rows = $dash['rows'];
$evById = $dash['events'];
$counts = ['all' => count($rows), 'published' => 0, 'draft' => 0];
foreach ($rows as $r) { $counts[$r['status']]++; }
$heroPost = null;
foreach ($rows as $r) { if ($r['status'] === 'published' && $r['thumb'] !== '') { $heroPost = $r; break; } }
$heroEvent = $heroPost ? ($evById[$heroPost['event_id']] ?? null) : ($events ? ($evById[(int) $events[0]['id']] ?? null) : null);
$heroImg = $heroPost ? $heroPost['thumb'] : (string) ($heroEvent['banner_image'] ?? '');
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$evRange = static function (array $e): string {
    $a = strtotime((string) $e['starts_at']); $b = strtotime((string) $e['ends_at']);
    if (date('Y-m-d', $a) === date('Y-m-d', $b)) { return date('j M Y', $a); }
    return date('M Y', $a) === date('M Y', $b) ? date('j', $a) . ' – ' . date('j M Y', $b) : date('j M', $a) . ' – ' . date('j M Y', $b);
};
$stateLabel = ['none' => 'Not started', 'trial' => 'Free month', 'active' => 'Active', 'expired' => 'Ended'][$status['state']] ?? '';
$canPost = $status['can_post'] && $events;
?>
<div class="sp" id="sp">
  <div class="sp-head">
    <h1>Socials <span class="sp-badge"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"/></svg>Premium</span></h1>
    <a class="sp-feed" href="/socials.php">Open the Socials feed</a>
  </div>
<?php if ($ready): ?>
  <nav class="sp-jump" aria-label="Socials sections">
    <a href="#spUp"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>Upload</a>
    <a href="#spVids"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9.5v5l4-2.5z"/></svg>My videos</a>
    <a href="#spStats"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>Analytics</a>
    <a href="#sxPlan"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"/></svg>Plan</a>
    <a href="/socials.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>Feed</a>
  </nav>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="sp-card" style="padding:24px"><h2>Socials is being switched on</h2><p>This page will open as soon as the setup is finished. Nothing is needed from you.</p></div>
<?php else: ?>

  <div class="sp-grid">
    <!-- hero -->
    <section class="sp-hero" aria-label="obitickets Socials">
      <div class="sp-hero-tx">
        <h2><span>OBITICKETS</span> <b>SOCIALS</b></h2>
        <p>Grow your events with videos.</p>
        <ul>
          <li>Upload event videos</li>
          <li>Reach more attendees</li>
          <li>Drive ticket sales</li>
        </ul>
      </div>
      <a class="sp-hero-play" href="/socials.php<?= $heroPost ? '?v=' . (int) $heroPost['id'] : '' ?>" aria-label="Watch on Socials"><svg width="34" height="34" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="#fff"/></svg></a>
      <div class="sp-phone" aria-hidden="true">
        <div class="sp-screen">
          <?php if ($heroImg !== ''): ?><img src="<?= $h($heroImg) ?>" alt=""><?php endif; ?>
          <span class="sp-screen-play"><svg width="18" height="18" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" fill="#fff"/></svg></span>
        </div>
      </div>
      <?php if ($heroEvent): ?>
      <div class="sp-hero-ev">
        <div><b><?= $h($heroEvent['title']) ?></b><small><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><?= $h($evRange($heroEvent)) ?></small></div>
        <a href="/event.php?slug=<?= urlencode((string) $heroEvent['slug']) ?>">Get Tickets</a>
      </div>
      <?php endif; ?>
    </section>

    <!-- plan -->
    <section class="sp-card sp-plan sp-<?= $h($status['state']) ?>" id="sxPlan" data-state="<?= $h($status['state']) ?>">
      <div class="sp-plan-top">
        <span class="sp-crown" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z"/></svg></span>
        <div><div class="sp-plan-name">Socials Pro <span class="sp-state"><?= $h($stateLabel) ?></span></div><small>Your premium organizer plan</small></div>
      </div>
      <ul class="sp-plan-rows">
        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span>Plan:</span><b><?= $status['state'] === 'trial' ? 'Free month' : 'Monthly' ?> · <?= $h(socials_price_label()) ?></b></li>
        <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span><?= $status['state'] === 'expired' ? 'Ended:' : 'Expires:' ?></span><b><?= $until ? $h($until) : '&ndash;' ?><?= in_array($status['state'], ['trial', 'active'], true) ? ' <em>(' . (int) $status['days_left'] . ' day' . ($status['days_left'] === 1 ? '' : 's') . ' left)</em>' : '' ?></b></li>
      </ul>
      <?php if ($status['state'] === 'none'): ?>
        <p class="sp-plan-note">Your first <?= (int) SOCIALS_TRIAL_DAYS ?> days are free. After that <?= $h(socials_price_label()) ?> per <?= (int) SOCIALS_PERIOD_DAYS ?> days by MTN or Airtel. Nothing is charged automatically.</p>
        <button type="button" class="sp-btn sp-btn-red" id="sxTrial">Start my free month</button>
      <?php else: ?>
        <details class="sp-manage"<?= $status['state'] === 'expired' ? ' open' : '' ?>>
          <summary class="sp-btn sp-btn-line"><?= $status['state'] === 'expired' ? 'Renew subscription' : 'Manage subscription' ?><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></summary>
          <form class="sx-pay" id="sxPay" novalidate>
            <label for="sxPhone">Add <?= (int) SOCIALS_PERIOD_DAYS ?> days: mobile money number (MTN or Airtel)</label>
            <div class="sx-pay-row">
              <input id="sxPhone" type="tel" inputmode="tel" autocomplete="tel" placeholder="07XX XXX XXX" value="<?= $h($payerPhone) ?>">
              <button type="submit" class="sp-btn sp-btn-red" id="sxPayBtn">Pay <?= $h(socials_price_label()) ?></button>
            </div>
            <p class="sx-hint">You get a prompt on this phone. Paid days are added after your current period.</p>
          </form>
          <div class="sx-wait" id="sxWait" hidden role="status" aria-live="polite">
            <span class="sx-spin" aria-hidden="true"></span>
            <div><strong>Check your phone</strong><span id="sxWaitText">Approve the mobile money prompt to finish. Please do not pay twice.</span></div>
          </div>
        </details>
      <?php endif; ?>
      <p class="sx-msg" id="sxMsg" role="alert" hidden></p>
    </section>

    <!-- numbers -->
    <div class="sp-stats" id="spStats">
      <div class="sp-card sp-stat"><i class="sp-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></i><div><b><?= number_format((int) $st['views']) ?></b><span>Video views</span><small>across <?= (int) $st['posts'] ?> post<?= (int) $st['posts'] === 1 ? '' : 's' ?></small></div></div>
      <div class="sp-card sp-stat"><i class="sp-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7-4.5-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.5-9 9-9 9z"/></svg></i><div><b><?= number_format((int) $st['likes']) ?></b><span>Likes</span><small>reactions on your posts</small></div></div>
      <div class="sp-card sp-stat"><i class="sp-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.3A8 8 0 1 1 21 12z"/></svg></i><div><b><?= number_format((int) $st['comments']) ?></b><span>Comments</span><small>from viewers</small></div></div>
      <div class="sp-card sp-stat"><i class="sp-ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M3 9V6a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v3a3 3 0 0 0 0 6v3a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-3a3 3 0 0 0 0-6z"/></svg></i><div><b><?= number_format((int) $st['tickets']) ?></b><span>Tickets sold</span><small>by people who came from a post</small></div></div>
    </div>

    <!-- upload -->
    <section class="sp-card sp-up" id="spUp" aria-labelledby="spUpH"
      data-upload="/api/moment-upload.php" data-csrf="<?= $h(csrf_token()) ?>" data-max="<?= (int) moment_video_max_bytes() ?>" data-max-total="<?= (int) MOMENT_VIDEO_CAP_BYTES ?>" data-max-seconds="<?= (int) MOMENT_MAX_SECONDS ?>" data-cap="<?= (int) MOMENT_CAPTION_MAX ?>">
      <h2 id="spUpH">Upload Event Video</h2>
      <?php if (!$status['can_post']): ?>
        <div class="sp-lock"><b>Posting is locked</b><span><?= $status['state'] === 'none' ? 'Start your free month to post.' : 'Renew your plan to post new videos.' ?> Your posts stay up either way.</span></div>
      <?php elseif (!$events): ?>
        <div class="sp-lock"><b>Publish an event first</b><span>Every post is tied to one of your published events.</span><a class="sp-btn sp-btn-red" href="/event-create.php">Create an event</a></div>
      <?php else: ?>
      <form id="spForm" novalidate>
        <label class="sp-drop" id="spDrop">
          <input type="file" id="spFile" accept="video/mp4,video/quicktime,video/webm,video/*,image/jpeg,image/png,image/webp" hidden>
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 18a4.5 4.5 0 0 1-.5-9A6 6 0 0 1 18 8.5a4 4 0 0 1-.5 9.5"/><path d="M12 12v8M9 15l3-3 3 3"/></svg>
          <b>Drag &amp; drop your video here</b><small>or click to select a file</small>
        </label>
        <div class="sp-file" id="spFileRow" hidden>
          <span class="sp-file-th" id="spFileTh"></span>
          <span class="sp-file-tx"><b id="spFileName"></b><small id="spFileSize"></small></span>
          <span class="sp-file-ok" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#16A34A"/><path d="m7.5 12.5 3 3 6-6.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          <button type="button" class="sp-file-x" id="spFileX" aria-label="Remove this file">&times;</button>
        </div>
        <label class="sp-lbl" for="spEvent">Select Event <i>*</i></label>
        <div class="sp-sel">
          <span class="sp-sel-th" id="spEvTh"></span>
          <select id="spEvent">
            <?php foreach ($events as $e): $full = $evById[(int) $e['id']] ?? $e; ?>
              <option value="<?= (int) $e['id'] ?>" data-img="<?= $h($full['banner_image'] ?? '') ?>"><?= $h($e['title']) ?> · <?= $h($evRange($full)) ?><?= (int) $e['ended'] === 1 ? ' (ended)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="sp-lbl" for="spCap">Caption <i>*</i></label>
        <div class="sp-cap"><textarea id="spCap" rows="3" maxlength="<?= (int) MOMENT_CAPTION_MAX ?>" placeholder="The biggest festival is coming! Get ready."></textarea><small id="spCapN">0/<?= (int) MOMENT_CAPTION_MAX ?></small></div>
        <label class="sp-lbl" for="spTag">Add Hashtags</label>
        <div class="sp-tags" id="spTags"><input id="spTag" placeholder="#obitickets then Enter" autocomplete="off"></div>
        <div class="sp-prog" id="spProg" hidden><i id="spBar"></i><span id="spProgT">Uploading…</span></div>
        <p class="sp-msg" id="spMsg" role="alert" hidden></p>
        <button type="submit" class="sp-btn sp-btn-red sp-pub" id="spPub"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>Publish Video</button>
        <a class="sp-adv" href="/org-social.php">Photo slideshows, sounds and cropping: Upload and manage</a>
      </form>
      <?php endif; ?>
    </section>

    <!-- videos -->
    <section class="sp-card sp-vids" id="spVids" aria-labelledby="spVidH">
      <div class="sp-vids-top">
        <h2 id="spVidH">Your Social Videos</h2>
        <?php if ($canPost): ?><a class="sp-btn sp-btn-red" href="#spUp" id="spUpGo"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Upload Video</a><?php endif; ?>
      </div>
      <div class="sp-tabs" role="tablist" aria-label="Filter your posts">
        <button type="button" role="tab" class="on" aria-selected="true" data-f="all">All Videos (<?= $counts['all'] ?>)</button>
        <button type="button" role="tab" aria-selected="false" data-f="published">Published (<?= $counts['published'] ?>)</button>
        <button type="button" role="tab" aria-selected="false" data-f="draft">Drafts (<?= $counts['draft'] ?>)</button>
      </div>
      <?php if (!$rows): ?>
        <p class="sp-empty">Nothing posted yet. Your videos and their numbers will show here.</p>
      <?php else: ?>
      <div class="sp-table-wrap">
        <table class="sp-table">
          <thead><tr><th>Video</th><th>Event</th><th>Views</th><th>Likes</th><th>Comments</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $e = $evById[$r['event_id']] ?? null; ?>
            <tr data-status="<?= $h($r['status']) ?>">
              <td><div class="sp-v">
                <a class="sp-v-th" href="<?= $r['status'] === 'published' ? '/socials.php?v=' . (int) $r['id'] : '/org-social.php?event=' . (int) $r['event_id'] ?>"><?php if ($r['thumb'] !== ''): ?><img src="<?= $h($r['thumb']) ?>" alt="" loading="lazy"><?php elseif ($r['type'] === 'video'): ?><video src="<?= $h($r['src']) ?>#t=0.5" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video><?php endif; ?><span class="sp-v-play" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" fill="#fff"/></svg></span><?php if ($r['type'] === 'video' && $r['duration']): ?><em><?= $h(moment_clock($r['duration'])) ?></em><?php elseif ($r['type'] !== 'video'): ?><em><?= $r['type'] === 'slideshow' ? 'Slides' : 'Photo' ?></em><?php endif; ?></a>
                <div><b><?= $h($r['title']) ?></b><small><?= $h(date('M j, Y', strtotime($r['created']))) ?></small></div>
              </div></td>
              <td><?php if ($e): ?><div class="sp-e"><?php if (!empty($e['banner_image'])): ?><img src="<?= $h($e['banner_image']) ?>" alt="" loading="lazy"><?php else: ?><span class="sp-e-ph"></span><?php endif; ?><div><b><?= $h($e['title']) ?></b><small><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><?= $h($evRange($e)) ?></small></div></div><?php endif; ?></td>
              <td class="sp-n" data-l="views"><?= number_format($r['views']) ?></td>
              <td class="sp-n" data-l="likes"><?= number_format($r['likes']) ?></td>
              <td class="sp-n" data-l="comments"><?= number_format($r['comments']) ?></td>
              <td><span class="sp-st sp-st-<?= $h($r['status']) ?>"><?= $r['status'] === 'draft' ? 'Draft' : 'Published' ?></span></td>
              <td><details class="sp-more"><summary aria-label="More actions">&bull;&bull;&bull;</summary><div>
                <?php if ($r['status'] === 'published'): ?><a href="/socials.php?v=<?= (int) $r['id'] ?>">View on Socials</a><?php endif; ?>
                <a href="/org-social.php?event=<?= (int) $r['event_id'] ?>"><?= $r['status'] === 'draft' ? 'Finish and post' : 'Edit or delete' ?></a>
              </div></details></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($payments): ?>
  <details class="sp-card sp-pays">
    <summary>Payment history</summary>
    <div class="admin-table-wrap" style="margin-top:12px">
      <table class="admin-table">
        <thead><tr><th>Date</th><th>Amount</th><th>Status</th><th>Covers</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $pay): ?>
            <tr>
              <td><?= $h(date('j M Y, g:ia', strtotime((string) $pay['created_at']))) ?></td>
              <td class="mono"><?= $pay['status'] === 'GRANTED' ? 'Added by obitickets' : 'UGX ' . number_format((float) $pay['amount']) ?></td>
              <td><?= $pay['status'] === 'FAILED' ? 'Not completed' : 'Paid' ?></td>
              <td><?= $pay['period_end'] ? $h(date('j M', strtotime((string) $pay['period_start'])) . ' to ' . date('j M Y', strtotime((string) $pay['period_end']))) : '&ndash;' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php if ($ready): ?>
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
<script src="/assets/js/chunk-upload.js?v=<?= @filemtime(__DIR__ . '/assets/js/chunk-upload.js') ?: time() ?>"></script>
<script src="/assets/js/org-socials-pro.js?v=<?= @filemtime(__DIR__ . '/assets/js/org-socials-pro.js') ?: time() ?>"></script>
<?php endif; ?>

<?php render_organizer_foot(); ?>
