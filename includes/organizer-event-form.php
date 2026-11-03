<?php
/**
 * Shared event form, used by both event-create.php and event-edit.php.
 * Expects $event (title/category/description/venue_name/venue_address/
 * banner_emoji/banner_image/starts_at/ends_at/status), $tiers (list of
 * tier arrays, each optionally carrying an 'id'), $formAction and
 * $submitLabel. $existingMedia (event_media rows) is optional — empty on
 * the create form, since an event doesn't exist yet to attach gallery rows to.
 */
$existingMedia = $existingMedia ?? [];
$onlineReady = events_online_ready();

/** One ticket-type row. A ticket with price 0 is free: the Free switch sets the price to 0 and locks it. */
function render_tier_form_row(array $tier, bool $withId): void
{
    $price = (string) ($tier['price'] ?? '0');
    if (str_contains($price, '.')) {
        $price = rtrim(rtrim($price, '0'), '.'); // 60000.00 -> 60000, 0.00 -> 0
        if ($price === '') { $price = '0'; }
    }
    $isFree = $price !== '' && (float) $price <= 0.0 && ($tier['name'] ?? '') !== '';
    $limit = (string) ($tier['max_per_person'] ?? '');
    ?>
      <div class="tier-form-row<?= $isFree ? ' is-free' : '' ?>">
        <?php if ($withId && !empty($tier['id'])): ?><input type="hidden" name="tier_id[]" value="<?= (int) $tier['id'] ?>"><?php endif; ?>
        <div class="field"><label>Name</label><input type="text" name="tier_name[]" value="<?= htmlspecialchars((string) ($tier['name'] ?? '')) ?>" placeholder="e.g. Regular"></div>
        <div class="field"><label>Description</label><input type="text" name="tier_description[]" value="<?= htmlspecialchars((string) ($tier['description'] ?? '')) ?>" placeholder="Optional"></div>
        <div class="field tier-price-field"><label>Price (UGX)</label><input type="number" min="0" step="1" name="tier_price[]" value="<?= htmlspecialchars($price) ?>"<?= $isFree ? ' readonly' : '' ?>><input type="hidden" name="tier_currency[]" value="UGX">
          <?php if ($GLOBALS['onlineReady']): ?><label class="tier-free-sw"><input type="checkbox" class="tier-free"<?= $isFree ? ' checked' : '' ?>><span class="sw-track" aria-hidden="true"></span><span>Free ticket</span></label><?php endif; ?></div>
        <div class="field"><label>Quantity</label><input type="number" min="1" step="1" name="tier_quantity[]" value="<?= htmlspecialchars((string) ($tier['quantity_total'] ?? '')) ?>"></div>
        <div class="field tier-limit-field"><label>Limit per person</label><input type="number" min="1" max="<?= FREE_TICKET_MAX_LIMIT ?>" step="1" name="tier_limit[]" value="<?= htmlspecialchars($limit) ?>" placeholder="<?= $isFree ? FREE_TICKET_DEFAULT_LIMIT : 'No limit' ?>" data-free-placeholder="<?= FREE_TICKET_DEFAULT_LIMIT ?>" data-paid-placeholder="No limit"></div>
        <button type="button" class="icon-btn remove-tier" title="Remove this ticket type">&times;</button>
      </div>
    <?php
}
?>
<form method="post" action="<?= htmlspecialchars($formAction) ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>

  <div class="field">
    <label for="title">Event title</label>
    <input id="title" name="title" type="text" value="<?= htmlspecialchars($event['title']) ?>" required>
  </div>

  <div class="field-grid-2">
    <div class="field">
      <label for="category">Category</label>
      <select id="category" name="category">
        <?php foreach (EVENT_CATEGORIES as $cat): ?>
          <option value="<?= htmlspecialchars($cat) ?>" <?= $event['category'] === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="banner_emoji">Card icon</label>
      <select id="banner_emoji" name="banner_emoji">
        <?php foreach (EVENT_EMOJIS as $emoji): ?>
          <option value="<?= $emoji ?>" <?= $event['banner_emoji'] === $emoji ? 'selected' : '' ?>><?= $emoji ?> <?= $emoji ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="field">
    <label for="banner_image">Banner image (optional)</label>
    <?php if (!empty($event['banner_image'])): ?>
      <div class="banner-preview">
        <img src="<?= htmlspecialchars($event['banner_image']) ?>" alt="">
        <label class="remove-banner"><input type="checkbox" name="remove_banner_image" value="1"> Remove this image</label>
      </div>
    <?php endif; ?>
    <input id="banner_image" name="banner_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
    <div class="field-hint">JPG, PNG, WEBP or GIF, up to 5MB. Without one, the card icon above is used instead.</div>
  </div>

  <div class="field">
    <label>Event gallery (optional)</label>
    <div class="field-hint" style="margin-top:-3px; margin-bottom:10px">Photos and short clips from a past edition help attendees see what they're buying into. Up to <?= EVENT_MEDIA_MAX_IMAGES ?> photos and <?= EVENT_MEDIA_MAX_VIDEOS ?> videos.</div>
    <?php if ($existingMedia): ?>
      <div class="gallery-manage-grid">
        <?php foreach ($existingMedia as $media): ?>
          <label class="gallery-manage-item">
            <?php if ($media['media_type'] === 'VIDEO'): ?>
              <video src="<?= htmlspecialchars($media['file_path']) ?>" muted></video>
              <span class="gallery-manage-badge">Video</span>
            <?php else: ?>
              <img src="<?= htmlspecialchars($media['file_path']) ?>" alt="">
            <?php endif; ?>
            <span class="gallery-manage-remove"><input type="checkbox" name="remove_media[]" value="<?= (int) $media['id'] ?>"> Remove</span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <input name="gallery[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" multiple>
    <div class="field-hint">Photos: JPG/PNG/WEBP/GIF up to 5MB each. Videos: MP4/WEBM up to 20MB each.</div>
  </div>

  <div class="field">
    <label for="description">Description</label>
    <textarea id="description" name="description" rows="5"><?= htmlspecialchars($event['description']) ?></textarea>
    <div class="field-hint">Leave a blank line between paragraphs.</div>
  </div>

  <?php if (!$onlineReady): ?>
  <div class="field">
    <label for="venue_name">Venue name</label>
    <input id="venue_name" name="venue_name" type="text" value="<?= htmlspecialchars($event['venue_name']) ?>" required>
  </div>
  <div class="field">
    <label for="venue_address">Venue address</label>
    <input id="venue_address" name="venue_address" type="text" value="<?= htmlspecialchars($event['venue_address'] ?? '') ?>">
  </div>
  <?php else: $mode = event_attend_mode($event); ?>
  <section class="nt-panel" id="attendPanel" data-mode="<?= htmlspecialchars($mode) ?>" aria-label="How will people attend">
    <div class="nt-grid">
      <div class="nt-main">
        <div class="nt-eyebrow">Format</div>
        <h3 class="nt-h">How will people attend?</h3>
        <div class="nt-seg" role="radiogroup" aria-label="How will people attend">
          <?php foreach (ATTEND_MODES as $value => $label): ?>
            <label><input type="radio" name="attend_mode" value="<?= $value ?>"<?= $mode === $value ? ' checked' : '' ?>><span><?= $label ?><small><?= $value === 'IN_PERSON' ? 'At a venue' : ($value === 'ONLINE' ? 'Zoom, Meet' : 'Venue + online') ?></small></span></label>
          <?php endforeach; ?>
        </div>

        <div class="nt-venue">
          <div class="nt-fld"><label for="venue_name">Venue name</label><input class="nt-inp" id="venue_name" name="venue_name" type="text" value="<?= htmlspecialchars($mode === 'ONLINE' ? '' : $event['venue_name']) ?>"></div>
          <div class="nt-fld"><label for="venue_address">Venue address</label><input class="nt-inp" id="venue_address" name="venue_address" type="text" value="<?= htmlspecialchars($event['venue_address'] ?? '') ?>"></div>
        </div>

        <div class="nt-online">
          <div class="nt-fld"><label id="platLabel">Meeting platform</label>
            <div class="nt-chips" role="radiogroup" aria-labelledby="platLabel">
              <?php foreach (ONLINE_PLATFORMS as $p): ?>
                <label><input type="radio" name="online_platform" value="<?= htmlspecialchars($p) ?>"<?= ($event['online_platform'] ?? 'Zoom') === $p ? ' checked' : '' ?>><span><?= htmlspecialchars($p) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="nt-fld"><label for="online_url">Meeting link</label><input class="nt-inp" id="online_url" name="online_url" type="text" inputmode="url" autocomplete="off" placeholder="https://zoom.us/j/..." value="<?= htmlspecialchars((string) ($event['online_url'] ?? '')) ?>"><div class="nt-hint">Paste the invite link from Zoom, Google Meet, Teams or YouTube. It must start with https://</div></div>
          <div class="nt-fld"><label for="online_notes">Passcode or joining notes <span>(optional)</span></label><input class="nt-inp" id="online_notes" name="online_notes" type="text" maxlength="500" placeholder="Passcode 482913. Join 10 minutes early." value="<?= htmlspecialchars((string) ($event['online_notes'] ?? '')) ?>"></div>
          <div class="nt-lock"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><span><b>The link is private.</b> It is never shown on the public event page. Only people with a ticket see it, on their ticket and in their emails.</span></div>
        </div>
      </div>

      <aside class="nt-side" aria-label="Live preview">
        <div class="nt-eyebrow">Live preview &middot; what attendees see</div>
        <div class="nt-card" id="ntPreview" aria-live="polite">
          <div class="nt-card-im"><span class="nt-card-emoji" id="ntEmoji"></span><div class="nt-pills"><span class="nt-pill" id="ntWhere"></span><span class="nt-pill nt-pill-g" id="ntFree" hidden>Free</span></div></div>
          <div class="nt-card-tx"><b id="ntTitle">Your event title</b><span id="ntWhen">Pick a date</span><div class="nt-card-pr" id="ntPrice"></div></div>
        </div>
        <p class="nt-note">The card, search results and the event page all show it this way.</p>
      </aside>
    </div>
  </section>
  <?php endif; ?>

  <div class="field-grid-2">
    <div class="field">
      <label for="starts_at">Starts</label>
      <input id="starts_at" name="starts_at" type="datetime-local" value="<?= htmlspecialchars($event['starts_at']) ?>" required>
    </div>
    <div class="field">
      <label for="ends_at">Ends</label>
      <input id="ends_at" name="ends_at" type="datetime-local" value="<?= htmlspecialchars($event['ends_at']) ?>" required>
    </div>
  </div>

  <div class="field">
    <label for="status">Visibility</label>
    <select id="status" name="status">
      <option value="DRAFT" <?= $event['status'] === 'DRAFT' ? 'selected' : '' ?>>Draft — only you can see it</option>
      <option value="PUBLISHED" <?= $event['status'] === 'PUBLISHED' ? 'selected' : '' ?>>Published — visible to everyone</option>
    </select>
  </div>

  <div class="poster-divider" style="margin:28px 0"></div>

  <h3 style="font-size:1.1rem">Ticket types</h3>
  <p class="field-hint" style="margin-bottom:14px">obitickets keeps a 10% commission on each paid ticket sold — the prices below are what your attendees pay; your payout per ticket is 90% of that. Switch a ticket to <b>Free</b> and there is no price, no fee and no payment step. Free tickets are limited per person (<?= FREE_TICKET_DEFAULT_LIMIT ?> unless you choose another number).</p>
  <div id="tier-rows">
    <?php foreach ($tiers as $tier): render_tier_form_row($tier, true); endforeach; ?>
  </div>
  <button type="button" class="btn btn-line" id="add-tier">+ Add another ticket type</button>

  <button class="btn btn-purple btn-lg btn-block" type="submit" style="margin-top:28px"><?= htmlspecialchars($submitLabel) ?></button>
</form>

<template id="tier-row-template">
  <?php render_tier_form_row(['name' => '', 'description' => '', 'price' => '0', 'quantity_total' => '100', 'max_per_person' => ''], false); ?>
</template>

<script src="/assets/js/event-form.js?v=<?= @filemtime(__DIR__ . '/../assets/js/event-form.js') ?: time() ?>"></script>
