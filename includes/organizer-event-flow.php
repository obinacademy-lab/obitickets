<?php
/**
 * "Focus Flow": the one-question-at-a-time create-event form (event-create.php).
 * It posts exactly the fields the classic form posts (includes/organizer-event-form.php, still used by the
 * edit page), so event-create.php's validation and saving are unchanged. Without JavaScript every step simply
 * shows one after another as a long form. Expects $event, $tiers, $errors and $formAction.
 */
$onlineReady = events_online_ready();
$mode = $onlineReady ? event_attend_mode($event) : 'IN_PERSON';
$startStep = $errors ? 8 : 1;
$totalSteps = 8;
$pad = static fn (int $n): string => sprintf('%02d / %02d', $n, 8);

/** One ticket-type card. Same field names as the classic form's row. */
function ef_tier_row(array $tier, bool $onlineReady): void
{
    $price = (string) ($tier['price'] ?? '');
    if (str_contains($price, '.')) {
        $price = rtrim(rtrim($price, '0'), '.');
        if ($price === '' && ($tier['price'] ?? '') !== '') { $price = '0'; }
    }
    $isFree = $price !== '' && (float) $price <= 0.0 && ($tier['name'] ?? '') !== '';
    $limit = (string) ($tier['max_per_person'] ?? '');
    ?>
      <div class="ef-tier<?= $isFree ? ' is-free' : '' ?>" data-tier>
        <div class="ef-tier-top">
          <label class="ef-fld"><span class="ef-lbl">Name</span><input class="ef-inp" type="text" name="tier_name[]" value="<?= htmlspecialchars((string) ($tier['name'] ?? '')) ?>" placeholder="e.g. Regular" autocomplete="off"></label>
          <label class="ef-fld"><span class="ef-lbl">Description <small>(optional)</small></span><input class="ef-inp" type="text" name="tier_description[]" value="<?= htmlspecialchars((string) ($tier['description'] ?? '')) ?>" placeholder="What does it include?" autocomplete="off"></label>
        </div>
        <div class="ef-tier-grid">
          <label class="ef-fld"><span class="ef-lbl">Price (UGX)</span><input class="ef-inp" type="number" min="0" step="1" inputmode="numeric" name="tier_price[]" value="<?= htmlspecialchars($price) ?>" placeholder="0"<?= $isFree ? ' readonly' : '' ?>><input type="hidden" name="tier_currency[]" value="UGX"></label>
          <label class="ef-fld"><span class="ef-lbl">Quantity</span><input class="ef-inp" type="number" min="1" step="1" inputmode="numeric" name="tier_quantity[]" value="<?= htmlspecialchars((string) ($tier['quantity_total'] ?? '')) ?>" placeholder="e.g. 100"></label>
          <label class="ef-fld"><span class="ef-lbl">Limit per person</span><input class="ef-inp" type="number" min="1" max="<?= FREE_TICKET_MAX_LIMIT ?>" step="1" inputmode="numeric" name="tier_limit[]" value="<?= htmlspecialchars($limit) ?>" placeholder="<?= $isFree ? FREE_TICKET_DEFAULT_LIMIT : 'No limit' ?>" data-free-placeholder="<?= FREE_TICKET_DEFAULT_LIMIT ?>" data-paid-placeholder="No limit"></label>
        </div>
        <div class="ef-tier-foot">
          <?php if ($onlineReady): ?><label class="ef-sw"><input type="checkbox" class="tier-free"<?= $isFree ? ' checked' : '' ?>><i aria-hidden="true"></i><span>Free ticket</span></label><?php endif; ?>
          <span class="ef-pay" data-pay></span>
          <button type="button" class="ef-rm" data-rm aria-label="Remove this ticket type"><svg class="ef-i" aria-hidden="true"><use href="#ef-x"/></svg></button>
        </div>
      </div>
    <?php
}
?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="ef-back" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
  <symbol id="ef-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="ef-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
  <symbol id="ef-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
  <symbol id="ef-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="ef-img" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 8"/></symbol>
  <symbol id="ef-upload" viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></symbol>
  <symbol id="ef-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></symbol>
  <symbol id="ef-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
</svg>

<form class="ef" id="efForm" method="post" action="<?= htmlspecialchars($formAction) ?>" enctype="multipart/form-data" novalidate
      data-start="<?= $startStep ?>" data-steps="<?= $totalSteps ?>" data-mode="<?= htmlspecialchars($mode) ?>" data-rate="<?= PLATFORM_COMMISSION_RATE ?>" data-online="<?= $onlineReady ? '1' : '0' ?>"
      data-max-image="<?= EVENT_MEDIA_MAX_IMAGE_BYTES ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="status" value="DRAFT" id="efStatus">
  <h1 class="ef-sr">Create your event</h1>

  <div class="ef-top">
    <a class="ef-back" href="/my-events.php"><svg class="ef-i" aria-hidden="true"><use href="#ef-back"/></svg>My events</a>
    <div class="ef-dots" id="efDots" aria-hidden="true"></div>
    <span class="ef-count" id="efCount" aria-live="polite"></span>
  </div>

  <?php if ($errors): ?>
    <div class="ef-errs" role="alert">
      <b>Nearly there. Please fix this:</b>
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="ef-stage">

    <section class="ef-step" data-step="1" data-req="1" aria-labelledby="efH1">
      <p class="ef-q"><?= $pad(1) ?></p>
      <h2 class="ef-h" id="efH1" tabindex="-1">What is your event called?</h2>
      <p class="ef-lede">A short, clear name works best.</p>
      <input class="ef-big" id="title" name="title" type="text" value="<?= htmlspecialchars($event['title']) ?>" placeholder="Type your event name" maxlength="200" autocomplete="off" aria-label="Event title" data-focus>
      <p class="ef-msg" data-msg role="alert"></p>
      <p class="ef-key">Press <kbd>Enter</kbd> to continue</p>
    </section>

    <section class="ef-step" data-step="2" aria-labelledby="efH2">
      <p class="ef-q"><?= $pad(2) ?></p>
      <h2 class="ef-h" id="efH2" tabindex="-1">What kind of event is it?</h2>
      <p class="ef-lede">Pick a category and an icon for the card.</p>
      <div class="ef-stack">
        <div class="ef-chips" role="radiogroup" aria-label="Category">
          <?php foreach (EVENT_CATEGORIES as $cat): ?>
            <label><input type="radio" name="category" value="<?= htmlspecialchars($cat) ?>"<?= $event['category'] === $cat ? ' checked' : '' ?>><span><?= htmlspecialchars($cat) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div>
          <p class="ef-lbl" style="margin-bottom:10px">Card icon <small>(shown when there is no banner)</small></p>
          <div class="ef-emo" role="radiogroup" aria-label="Card icon">
            <?php foreach (EVENT_EMOJIS as $emoji): ?>
              <label><input type="radio" name="banner_emoji" value="<?= $emoji ?>"<?= $event['banner_emoji'] === $emoji ? ' checked' : '' ?>><span><?= $emoji ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="ef-step" data-step="3" data-req="1" aria-labelledby="efH3">
      <p class="ef-q"><?= $pad(3) ?></p>
      <h2 class="ef-h" id="efH3" tabindex="-1"><?= $onlineReady ? 'How will people attend?' : 'Where will it happen?' ?></h2>
      <p class="ef-lede"><?= $onlineReady ? 'At a venue, online, or both.' : 'Tell people where to go.' ?></p>
      <div<?= $onlineReady ? ' id="attendPanel" data-mode="' . htmlspecialchars($mode) . '"' : '' ?>>
        <?php if ($onlineReady): ?>
          <div class="ef-seg" role="radiogroup" aria-label="How will people attend">
            <?php foreach (ATTEND_MODES as $value => $label): ?>
              <label><input type="radio" name="attend_mode" value="<?= $value ?>"<?= $mode === $value ? ' checked' : '' ?>><span><?= htmlspecialchars($label) ?><small><?= $value === 'IN_PERSON' ? 'At a venue' : ($value === 'ONLINE' ? 'Zoom, Meet' : 'Venue + online') ?></small></span></label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="ef-mf ef-only-venue">
          <div class="ef-g2">
            <label class="ef-fld"><span class="ef-lbl">Venue name</span><input class="ef-inp" id="venue_name" name="venue_name" type="text" value="<?= htmlspecialchars($mode === 'ONLINE' ? '' : $event['venue_name']) ?>" placeholder="e.g. Lutembe Beach" autocomplete="off"></label>
            <label class="ef-fld"><span class="ef-lbl">Venue address <small>(optional)</small></span><input class="ef-inp" id="venue_address" name="venue_address" type="text" value="<?= htmlspecialchars($event['venue_address'] ?? '') ?>" placeholder="Town or street" autocomplete="off"></label>
          </div>
        </div>
        <?php if ($onlineReady): ?>
        <div class="ef-mf ef-only-online">
          <div class="ef-fld"><span class="ef-lbl" id="efPlat">Meeting platform</span>
            <div class="ef-chips ef-chips-sm" role="radiogroup" aria-labelledby="efPlat">
              <?php foreach (ONLINE_PLATFORMS as $p): ?>
                <label><input type="radio" name="online_platform" value="<?= htmlspecialchars($p) ?>"<?= ($event['online_platform'] ?? 'Zoom') === $p ? ' checked' : '' ?>><span><?= htmlspecialchars($p) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <label class="ef-fld"><span class="ef-lbl">Meeting link</span><input class="ef-inp" id="online_url" name="online_url" type="text" inputmode="url" autocomplete="off" placeholder="https://zoom.us/j/..." value="<?= htmlspecialchars((string) ($event['online_url'] ?? '')) ?>"></label>
          <label class="ef-fld"><span class="ef-lbl">Passcode or joining notes <small>(optional)</small></span><input class="ef-inp" name="online_notes" type="text" maxlength="500" autocomplete="off" placeholder="Passcode 482913" value="<?= htmlspecialchars((string) ($event['online_notes'] ?? '')) ?>"></label>
          <div class="ef-lock"><svg class="ef-i" aria-hidden="true"><use href="#ef-lock"/></svg><span>The meeting link is never shown on the public event page. Only people with a ticket see it, on their ticket and in their emails.</span></div>
        </div>
        <?php endif; ?>
      </div>
      <p class="ef-msg" data-msg role="alert"></p>
    </section>

    <section class="ef-step" data-step="4" data-req="1" aria-labelledby="efH4">
      <p class="ef-q"><?= $pad(4) ?></p>
      <h2 class="ef-h" id="efH4" tabindex="-1">When does it start and end?</h2>
      <p class="ef-lede">Pick both so people can plan.</p>
      <div class="ef-g2">
        <label class="ef-fld"><span class="ef-lbl">Starts</span><input class="ef-inp" id="starts_at" name="starts_at" type="datetime-local" value="<?= htmlspecialchars($event['starts_at']) ?>" data-focus></label>
        <label class="ef-fld"><span class="ef-lbl">Ends</span><input class="ef-inp" id="ends_at" name="ends_at" type="datetime-local" value="<?= htmlspecialchars($event['ends_at']) ?>"></label>
      </div>
      <p class="ef-msg" data-msg role="alert"></p>
    </section>

    <section class="ef-step" data-step="5" aria-labelledby="efH5">
      <p class="ef-q"><?= $pad(5) ?></p>
      <h2 class="ef-h" id="efH5" tabindex="-1">Add a banner image</h2>
      <p class="ef-lede">A good picture sells tickets. You can skip this and add it later by editing the event.</p>
      <div class="ef-stack">
        <label class="ef-drop" id="efBannerDrop"><span class="ef-pv" id="efBannerPv"></span><span class="ef-dic"><svg class="ef-i" aria-hidden="true"><use href="#ef-img"/></svg></span><b>Choose a banner</b><small>JPG, PNG, WEBP or GIF, up to 5MB. Without one, the card icon is used instead.</small><span class="ef-rp">Replace</span><input id="banner_image" name="banner_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif"></label>
        <label class="ef-drop ef-drop-sm" id="efGalleryDrop"><span class="ef-dic"><svg class="ef-i" aria-hidden="true"><use href="#ef-upload"/></svg></span><b id="efGalleryLabel">Add photos and short clips <small style="display:inline">(optional)</small></b><small>Up to <?= EVENT_MEDIA_MAX_IMAGES ?> photos (5MB each) and <?= EVENT_MEDIA_MAX_VIDEOS ?> videos (20MB each)</small><input name="gallery[]" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm"></label>
      </div>
      <p class="ef-msg" data-msg role="alert"></p>
    </section>

    <section class="ef-step" data-step="6" aria-labelledby="efH6">
      <p class="ef-q"><?= $pad(6) ?></p>
      <h2 class="ef-h" id="efH6" tabindex="-1">Tell people about it</h2>
      <p class="ef-lede">A few lines about what to expect. Leave a blank line between paragraphs.</p>
      <textarea class="ef-inp" id="description" name="description" rows="6" placeholder="What should people know?" aria-label="Description" data-focus><?= htmlspecialchars($event['description']) ?></textarea>
    </section>

    <section class="ef-step" data-step="7" data-req="1" aria-labelledby="efH7">
      <p class="ef-q"><?= $pad(7) ?></p>
      <h2 class="ef-h" id="efH7" tabindex="-1">What do tickets cost?</h2>
      <p class="ef-lede">Add one or more ticket types. Free tickets are welcome.</p>
      <div class="ef-stack">
        <div class="ef-tiers" id="tier-rows">
          <?php foreach ($tiers as $tier): ef_tier_row($tier, $onlineReady); endforeach; ?>
        </div>
        <button type="button" class="ef-add" id="add-tier"><svg class="ef-i" aria-hidden="true"><use href="#ef-plus"/></svg>Add another ticket type</button>
        <div class="ef-comm"><svg class="ef-i" aria-hidden="true"><use href="#ef-info"/></svg><span>obitickets keeps a <b><?= commission_percent_label() ?>% commission</b> on each paid ticket sold. The prices above are what your attendees pay, and your payout per ticket is <?= 100 - (float) commission_percent_label() ?>% of that.<?php if ($onlineReady): ?> Switch a ticket to <b>Free</b> and there is no price, no fee and no payment step. Free tickets are limited per person (<?= FREE_TICKET_DEFAULT_LIMIT ?> unless you choose another number).<?php endif; ?></span></div>
        <div class="ef-pot"><div><small>If every ticket sells</small><b id="efPot">UGX 0</b></div><span>Your payout across all ticket types, after the commission.</span></div>
      </div>
      <p class="ef-msg" data-msg role="alert"></p>
    </section>

    <section class="ef-step" data-step="8" aria-labelledby="efH8">
      <p class="ef-q"><?= $pad(8) ?></p>
      <h2 class="ef-h" id="efH8" tabindex="-1">Ready when you are</h2>
      <p class="ef-lede">Publish to put it in front of everyone, or save a draft that only you can see.</p>
      <ul class="ef-chk" id="efChk">
        <li data-check="title"><i><svg class="ef-i" aria-hidden="true"><use href="#ef-check"/></svg></i><span>Event title</span><button type="button" data-go="1">Edit</button></li>
        <li data-check="where"><i><svg class="ef-i" aria-hidden="true"><use href="#ef-check"/></svg></i><span><?= $onlineReady ? 'Venue or meeting link' : 'Venue' ?></span><button type="button" data-go="3">Edit</button></li>
        <li data-check="when"><i><svg class="ef-i" aria-hidden="true"><use href="#ef-check"/></svg></i><span>Start and end</span><button type="button" data-go="4">Edit</button></li>
        <li data-check="tickets"><i><svg class="ef-i" aria-hidden="true"><use href="#ef-check"/></svg></i><span>A ticket type</span><button type="button" data-go="7">Edit</button></li>
        <li data-check="banner" data-optional><i><svg class="ef-i" aria-hidden="true"><use href="#ef-check"/></svg></i><span>Banner image <em>optional</em></span><button type="button" data-go="5">Edit</button></li>
        <li data-check="desc" data-optional><i><svg class="ef-i" aria-hidden="true"><use href="#ef-check"/></svg></i><span>Description <em>optional</em></span><button type="button" data-go="6">Edit</button></li>
      </ul>
      <div class="ef-fin">
        <button class="ef-btn ef-red" type="submit" name="status" value="PUBLISHED" data-gate>Publish event <svg class="ef-i" aria-hidden="true"><use href="#ef-arrow"/></svg></button>
        <button class="ef-btn ef-out" type="submit" name="status" value="DRAFT" data-gate>Save as draft</button>
      </div>
      <p class="ef-miss" id="efMiss" aria-live="polite"></p>
    </section>

  </div>

  <div class="ef-nav" id="efNav">
    <button class="ef-btn ef-ghost" type="button" id="efPrev">Back</button>
    <button class="ef-btn ef-red" type="button" id="efNext">Continue <svg class="ef-i" aria-hidden="true"><use href="#ef-arrow"/></svg></button>
    <button class="ef-btn ef-ghost ef-skip" type="button" id="efSkip">Skip for now</button>
  </div>

  <aside class="ef-mini" aria-label="Live preview">
    <p class="ef-eyebrow">Live preview</p>
    <div class="ef-card" id="ntPreview" aria-live="polite">
      <div class="ef-card-im" id="efCardIm"><span class="ef-card-emoji" id="ntEmoji"></span><div class="ef-pills"><span id="ntWhere"></span><span class="ef-pill-g" id="ntFree" hidden>Free</span></div></div>
      <div class="ef-card-tx"><b id="ntTitle">Your event title</b><span id="ntWhen">Pick a date</span><div class="ef-card-pr" id="ntPrice"></div></div>
    </div>
  </aside>
</form>

<template id="efTierTpl">
  <?php ef_tier_row(['name' => '', 'description' => '', 'price' => '0', 'quantity_total' => '100', 'max_per_person' => ''], $onlineReady); ?>
</template>

<script src="/assets/js/event-flow.js?v=<?= @filemtime(__DIR__ . '/../assets/js/event-flow.js') ?: time() ?>"></script>
