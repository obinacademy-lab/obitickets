<?php
declare(strict_types=1);

/**
 * Event page community extras (Moments, the community feed, event reactions and the activity numbers).
 * They are switched off on the public event page, but all the code and data stay in place. To bring them back,
 * create an empty file named community-ui.on in the config folder (next to config.php); delete it to hide them again.
 */
function event_community_ui(): bool
{
    return is_file(__DIR__ . '/../config/community-ui.on');
}
