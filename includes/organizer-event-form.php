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

  <div class="field">
    <label for="venue_name">Venue name</label>
    <input id="venue_name" name="venue_name" type="text" value="<?= htmlspecialchars($event['venue_name']) ?>" required>
  </div>
  <div class="field">
    <label for="venue_address">Venue address</label>
    <input id="venue_address" name="venue_address" type="text" value="<?= htmlspecialchars($event['venue_address'] ?? '') ?>">
  </div>

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
  <p class="field-hint" style="margin-bottom:14px">obitickets keeps a 10% commission on each ticket sold — the prices below are what your attendees pay; your payout per ticket is 90% of that.</p>
  <div id="tier-rows">
    <?php foreach ($tiers as $tier): ?>
      <div class="tier-form-row">
        <?php if (!empty($tier['id'])): ?><input type="hidden" name="tier_id[]" value="<?= (int) $tier['id'] ?>"><?php endif; ?>
        <div class="field"><label>Name</label><input type="text" name="tier_name[]" value="<?= htmlspecialchars($tier['name']) ?>" placeholder="e.g. Regular"></div>
        <div class="field"><label>Description</label><input type="text" name="tier_description[]" value="<?= htmlspecialchars($tier['description']) ?>" placeholder="Optional"></div>
        <div class="field"><label>Price (UGX)</label><input type="number" min="0" step="1" name="tier_price[]" value="<?= htmlspecialchars((string) $tier['price']) ?>"><input type="hidden" name="tier_currency[]" value="UGX"></div>
        <div class="field"><label>Quantity</label><input type="number" min="1" step="1" name="tier_quantity[]" value="<?= htmlspecialchars((string) $tier['quantity_total']) ?>"></div>
        <button type="button" class="icon-btn remove-tier" title="Remove this ticket type">&times;</button>
      </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-line" id="add-tier">+ Add another ticket type</button>

  <button class="btn btn-purple btn-lg btn-block" type="submit" style="margin-top:28px"><?= htmlspecialchars($submitLabel) ?></button>
</form>

<template id="tier-row-template">
  <div class="tier-form-row">
    <div class="field"><label>Name</label><input type="text" name="tier_name[]" placeholder="e.g. Regular"></div>
    <div class="field"><label>Description</label><input type="text" name="tier_description[]" placeholder="Optional"></div>
    <div class="field"><label>Price (UGX)</label><input type="number" min="0" step="1" name="tier_price[]" value="0"><input type="hidden" name="tier_currency[]" value="UGX"></div>
    <div class="field"><label>Quantity</label><input type="number" min="1" step="1" name="tier_quantity[]" value="100"></div>
    <button type="button" class="icon-btn remove-tier" title="Remove this ticket type">&times;</button>
  </div>
</template>
