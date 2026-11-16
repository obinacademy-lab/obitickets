<?php
declare(strict_types=1);

/**
 * Where an event is, for "Near me", the district / region filter on Browse and the District field on the event form.
 *
 * A built-in list of Uganda's districts: region and the rough position of the main town (good enough to rank events
 * by distance; it is not a map). Towns, neighbourhoods and well-known venues point at their district so an address
 * like "Munyonyo, Kampala" or "Njeru, Jinja District" is recognised. events.district (migration 031) holds the
 * organizer's own choice; events without it are recognised from their address text.
 */

const UG_REGIONS = ['Central', 'Eastern', 'Northern', 'Western'];

/** @return array<string, array{0:string,1:float,2:float}> district => [region, lat, lng] */
function uganda_districts(): array
{
    static $d = null;
    if ($d !== null) {
        return $d;
    }
    $d = [
        // Central
        'Kampala' => ['Central', 0.3476, 32.5825], 'Wakiso' => ['Central', 0.4044, 32.4594], 'Mukono' => ['Central', 0.3533, 32.7553],
        'Mpigi' => ['Central', 0.2250, 32.3136], 'Masaka' => ['Central', -0.3338, 31.7341], 'Luwero' => ['Central', 0.8492, 32.4731],
        'Mityana' => ['Central', 0.4175, 32.0228], 'Mubende' => ['Central', 0.5579, 31.3950], 'Kayunga' => ['Central', 0.7025, 32.8886],
        'Buikwe' => ['Central', 0.3375, 33.0106], 'Nakaseke' => ['Central', 0.7300, 32.4150], 'Nakasongola' => ['Central', 1.3089, 32.4564],
        'Kiboga' => ['Central', 0.9161, 31.7742], 'Kyankwanzi' => ['Central', 1.2000, 31.8000], 'Butambala' => ['Central', 0.1750, 32.1050],
        'Gomba' => ['Central', 0.2200, 31.6800], 'Kalangala' => ['Central', -0.3089, 32.2250], 'Kalungu' => ['Central', -0.0950, 31.7600],
        'Lwengo' => ['Central', -0.4167, 31.4083], 'Lyantonde' => ['Central', -0.4031, 31.1578], 'Rakai' => ['Central', -0.7200, 31.4800],
        'Sembabule' => ['Central', -0.0772, 31.4567], 'Bukomansimbi' => ['Central', -0.1500, 31.6000], 'Kyotera' => ['Central', -0.6300, 31.5500],
        'Kassanda' => ['Central', 0.4500, 31.8000], 'Buvuma' => ['Central', 0.2400, 33.2800],
        // Eastern
        'Jinja' => ['Eastern', 0.4244, 33.2042], 'Mbale' => ['Eastern', 1.0827, 34.1750], 'Tororo' => ['Eastern', 0.6928, 34.1808],
        'Soroti' => ['Eastern', 1.7146, 33.6111], 'Iganga' => ['Eastern', 0.6092, 33.4686], 'Busia' => ['Eastern', 0.4600, 34.0900],
        'Kamuli' => ['Eastern', 0.9472, 33.1197], 'Pallisa' => ['Eastern', 1.1450, 33.7094], 'Kumi' => ['Eastern', 1.4608, 33.9361],
        'Kapchorwa' => ['Eastern', 1.4000, 34.4500], 'Bugiri' => ['Eastern', 0.5714, 33.7417], 'Mayuge' => ['Eastern', 0.4597, 33.4800],
        'Kaliro' => ['Eastern', 0.8950, 33.5050], 'Luuka' => ['Eastern', 0.7000, 33.3000], 'Namutumba' => ['Eastern', 0.8364, 33.6858],
        'Buyende' => ['Eastern', 1.1500, 33.1600], 'Butaleja' => ['Eastern', 0.9250, 33.9550], 'Manafwa' => ['Eastern', 0.9000, 34.3300],
        'Bududa' => ['Eastern', 1.0100, 34.3300], 'Sironko' => ['Eastern', 1.2300, 34.2500], 'Bukedea' => ['Eastern', 1.3500, 34.0500],
        'Ngora' => ['Eastern', 1.4500, 33.7700], 'Serere' => ['Eastern', 1.5000, 33.5500], 'Kaberamaido' => ['Eastern', 1.7700, 33.1600],
        'Amuria' => ['Eastern', 2.0300, 33.6400], 'Katakwi' => ['Eastern', 1.9100, 33.9700], 'Kween' => ['Eastern', 1.4400, 34.6000],
        'Bukwo' => ['Eastern', 1.2600, 34.7500], 'Bulambuli' => ['Eastern', 1.1700, 34.3800], 'Namayingo' => ['Eastern', 0.2400, 33.8800],
        'Budaka' => ['Eastern', 1.0200, 33.9500], 'Kibuku' => ['Eastern', 1.0400, 33.8000], 'Butebo' => ['Eastern', 1.2000, 33.9000],
        'Kalaki' => ['Eastern', 1.8100, 33.3300], 'Kapelebyong' => ['Eastern', 2.1000, 33.9000], 'Bugweri' => ['Eastern', 0.6000, 33.5800],
        'Namisindwa' => ['Eastern', 0.8700, 34.3800],
        // Northern
        'Gulu' => ['Northern', 2.7746, 32.2990], 'Lira' => ['Northern', 2.2499, 32.8999], 'Arua' => ['Northern', 3.0201, 30.9111],
        'Kitgum' => ['Northern', 3.2783, 32.8867], 'Moroto' => ['Northern', 2.5345, 34.6666], 'Kotido' => ['Northern', 2.9806, 34.1331],
        'Adjumani' => ['Northern', 3.3779, 31.7909], 'Moyo' => ['Northern', 3.6609, 31.7247], 'Nebbi' => ['Northern', 2.4783, 31.0889],
        'Koboko' => ['Northern', 3.4136, 30.9599], 'Yumbe' => ['Northern', 3.4651, 31.2469], 'Pader' => ['Northern', 2.8333, 33.0833],
        'Agago' => ['Northern', 2.9700, 33.3300], 'Apac' => ['Northern', 1.9756, 32.5386], 'Kole' => ['Northern', 2.4300, 32.8000],
        'Oyam' => ['Northern', 2.2400, 32.3800], 'Dokolo' => ['Northern', 1.9200, 33.1700], 'Amolatar' => ['Northern', 1.6300, 32.8300],
        'Alebtong' => ['Northern', 2.2500, 33.2300], 'Otuke' => ['Northern', 2.4500, 33.3200], 'Kaabong' => ['Northern', 3.5200, 34.1200],
        'Abim' => ['Northern', 2.7000, 33.6700], 'Napak' => ['Northern', 2.2500, 34.2500], 'Nakapiripirit' => ['Northern', 1.8500, 34.7200],
        'Amudat' => ['Northern', 1.9500, 34.9500], 'Nwoya' => ['Northern', 2.6300, 32.0000], 'Amuru' => ['Northern', 2.8200, 31.9400],
        'Omoro' => ['Northern', 2.7200, 32.4900], 'Lamwo' => ['Northern', 3.5300, 32.8000], 'Zombo' => ['Northern', 2.5100, 30.9100],
        'Pakwach' => ['Northern', 2.4600, 31.5000], 'Maracha' => ['Northern', 3.2900, 30.9400], 'Obongi' => ['Northern', 3.3200, 31.5500],
        'Madi-Okollo' => ['Northern', 2.9500, 31.2500], 'Terego' => ['Northern', 3.1200, 31.1000], 'Karenga' => ['Northern', 3.4800, 33.6800],
        'Nabilatuk' => ['Northern', 2.0500, 34.5800], 'Kwania' => ['Northern', 2.0000, 32.7500],
        // Western
        'Mbarara' => ['Western', -0.6072, 30.6545], 'Kabarole' => ['Western', 0.6710, 30.2750], 'Kasese' => ['Western', 0.1833, 30.0833],
        'Hoima' => ['Western', 1.4331, 31.3524], 'Masindi' => ['Western', 1.6744, 31.7150], 'Kabale' => ['Western', -1.2486, 29.9899],
        'Bushenyi' => ['Western', -0.5853, 30.2114], 'Ntungamo' => ['Western', -0.8794, 30.2642], 'Rukungiri' => ['Western', -0.7906, 29.9250],
        'Kisoro' => ['Western', -1.2853, 29.6850], 'Kanungu' => ['Western', -0.9500, 29.7900], 'Ibanda' => ['Western', -0.1300, 30.4950],
        'Isingiro' => ['Western', -0.8400, 30.8000], 'Kiruhura' => ['Western', -0.1900, 30.8100], 'Kyenjojo' => ['Western', 0.6300, 30.6200],
        'Kamwenge' => ['Western', 0.1900, 30.4500], 'Kyegegwa' => ['Western', 0.5000, 31.0500], 'Kibaale' => ['Western', 0.8000, 31.0700],
        'Kagadi' => ['Western', 0.9400, 30.8100], 'Kakumiro' => ['Western', 0.7800, 31.3200], 'Buliisa' => ['Western', 2.0300, 31.4100],
        'Kiryandongo' => ['Western', 1.8800, 32.0600], 'Bundibugyo' => ['Western', 0.7100, 30.0600], 'Ntoroko' => ['Western', 1.0400, 30.4800],
        'Bunyangabu' => ['Western', 0.4900, 30.2000], 'Kikuube' => ['Western', 1.2700, 31.0500], 'Rubirizi' => ['Western', -0.2700, 30.1100],
        'Mitooma' => ['Western', -0.6200, 30.0200], 'Sheema' => ['Western', -0.5800, 30.3800], 'Buhweju' => ['Western', -0.3500, 30.3400],
        'Rwampara' => ['Western', -0.7000, 30.5200], 'Kazo' => ['Western', -0.0500, 30.7500], 'Rubanda' => ['Western', -1.1900, 29.8500],
        'Rukiga' => ['Western', -1.1300, 30.0700], 'Kitagwenda' => ['Western', -0.0800, 30.3500],
    ];
    ksort($d);
    return $d;
}

/** Towns, neighbourhoods and famous venues => their district (checked before district names, longest first). */
function uganda_place_aliases(): array
{
    return [
        // Kampala city areas
        'Munyonyo' => 'Kampala', 'Kololo' => 'Kampala', 'Ntinda' => 'Kampala', 'Lugogo' => 'Kampala', 'Nakasero' => 'Kampala',
        'Kabalagala' => 'Kampala', 'Bugolobi' => 'Kampala', 'Muyenga' => 'Kampala', 'Makerere' => 'Kampala', 'Wandegeya' => 'Kampala',
        'Kansanga' => 'Kampala', 'Naguru' => 'Kampala', 'Mengo' => 'Kampala', 'Rubaga' => 'Kampala', 'Lubaga' => 'Kampala',
        'Nsambya' => 'Kampala', 'Kawempe' => 'Kampala', 'Makindye' => 'Kampala', 'Nakawa' => 'Kampala', 'Kisementi' => 'Kampala',
        'Old Kampala' => 'Kampala', 'Kamwokya' => 'Kampala', 'Bukoto' => 'Kampala', 'Kyanja' => 'Kampala', 'Luzira' => 'Kampala',
        'Kisugu' => 'Kampala', 'Kibuli' => 'Kampala', 'Kataza' => 'Kampala', 'Industrial Area' => 'Kampala', 'Kololo Airstrip' => 'Kampala',
        'Ggaba' => 'Kampala', 'Gaba' => 'Kampala',
        // Wakiso towns around Kampala
        'Entebbe' => 'Wakiso', 'Kira' => 'Wakiso', 'Namboole' => 'Wakiso', 'Nansana' => 'Wakiso', 'Bweyogerere' => 'Wakiso',
        'Kajjansi' => 'Wakiso', 'Gayaza' => 'Wakiso', 'Matugga' => 'Wakiso', 'Kasangati' => 'Wakiso', 'Najjera' => 'Wakiso',
        'Kyaliwajjala' => 'Wakiso', 'Namugongo' => 'Wakiso', 'Kitende' => 'Wakiso', 'Lubowa' => 'Wakiso', 'Mutundwe' => 'Wakiso',
        'Kakiri' => 'Wakiso', 'Bulenga' => 'Wakiso', 'Wakaliga' => 'Wakiso', 'Kawuku' => 'Wakiso', 'Garuga' => 'Wakiso', 'Mabamba' => 'Wakiso',
        // elsewhere
        'Seeta' => 'Mukono', 'Namanve' => 'Mukono', 'Lugazi' => 'Buikwe', 'Njeru' => 'Buikwe', 'Nkokonjeru' => 'Buikwe',
        'Bujagali' => 'Jinja', 'Source of the Nile' => 'Jinja', 'Fort Portal' => 'Kabarole', 'Lake Bunyonyi' => 'Rubanda',
        'Ssese' => 'Kalangala', 'Sese Islands' => 'Kalangala', 'Murchison' => 'Nwoya', 'Queen Elizabeth' => 'Rubirizi',
        'Bwindi' => 'Kanungu', 'Sipi' => 'Kapchorwa', 'Kidepo' => 'Kaabong', 'Nkozi' => 'Mpigi', 'Bombo' => 'Luwero',
        'Wobulenzi' => 'Luwero', 'Nyendo' => 'Masaka', 'Bigodi' => 'Kamwenge', 'Kibale' => 'Kamwenge', 'Mweya' => 'Rubirizi',
        'Rwenzori' => 'Kasese', 'Busia Town' => 'Busia', 'Malaba' => 'Tororo', 'Nimule' => 'Adjumani',
    ];
}

/** True when the district is one of ours (exact name). */
function is_uganda_district(?string $name): bool
{
    return $name !== null && $name !== '' && isset(uganda_districts()[$name]);
}

/** Recognises a district from free text (venue name / address). Null when nothing matches or it is clearly abroad. */
function detect_district(string ...$texts): ?string
{
    $text = ' ' . trim(implode(' , ', $texts)) . ' ';
    if (trim($text) === '') {
        return null;
    }
    // Another country named and Uganda not: leave it out of every Ugandan district.
    if (preg_match('/\b(Kenya|Nairobi|Tanzania|Rwanda|Kigali|Ghana|Nigeria|Lagos|Accra|South Sudan|Juba|Burundi|Congo|DRC)\b/i', $text)
        && !preg_match('/\bUganda\b/i', $text)) {
        return null;
    }
    // "X District" wins over a town that happens to share a name with another district.
    foreach (array_keys(uganda_districts()) as $d) {
        if (preg_match('/\b' . preg_quote($d, '/') . '\s+District\b/i', $text)) {
            return $d;
        }
    }
    $aliases = uganda_place_aliases();
    uksort($aliases, static fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach ($aliases as $place => $d) {
        if (preg_match('/\b' . preg_quote($place, '/') . '\b/i', $text)) {
            return $d;
        }
    }
    foreach (array_keys(uganda_districts()) as $d) {
        if (preg_match('/\b' . preg_quote($d, '/') . '\b/i', $text)) {
            return $d;
        }
    }
    return null;
}

/** True once migration 031 (events.district) has run. */
function places_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW COLUMNS FROM events LIKE 'district'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The event's district: the organizer's choice when there is one, otherwise recognised from the address. */
function event_district(array $event): ?string
{
    $set = (string) ($event['district'] ?? '');
    if (is_uganda_district($set)) {
        return $set;
    }
    if (($event['attend_mode'] ?? 'IN_PERSON') === 'ONLINE') {
        return null;
    }
    return detect_district((string) ($event['venue_address'] ?? ''), (string) ($event['venue_name'] ?? ''));
}

function district_region(?string $district): ?string
{
    return is_uganda_district($district) ? uganda_districts()[$district][0] : null;
}

/** Straight-line distance between two districts' main towns, in km. */
function district_distance_km(string $a, string $b): float
{
    $all = uganda_districts();
    if (!isset($all[$a], $all[$b])) {
        return INF;
    }
    [, $la1, $lo1] = $all[$a];
    [, $la2, $lo2] = $all[$b];
    $r = 6371.0;
    $dLat = deg2rad($la2 - $la1);
    $dLon = deg2rad($lo2 - $lo1);
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}

/** The district whose main town is closest to a point (used once by "Near me"; the point itself is never stored). */
function nearest_district(float $lat, float $lng): ?string
{
    if ($lat < -2.0 || $lat > 4.5 || $lng < 29.0 || $lng > 35.5) {
        return null; // outside Uganda
    }
    $best = null;
    $bestD = INF;
    foreach (uganda_districts() as $name => [, $la, $lo]) {
        $d = ($la - $lat) ** 2 + (($lo - $lng) * cos(deg2rad($lat))) ** 2;
        if ($d < $bestD) {
            $bestD = $d;
            $best = $name;
        }
    }
    // More than ~70 km from every district town: probably over a border.
    return $best !== null && sqrt($bestD) * 111.0 <= 70.0 ? $best : null;
}

/** Saves the organizer's district choice; does nothing before migration 031. */
function set_event_district(int $eventId, ?string $district): void
{
    if (!places_ready()) {
        return;
    }
    db()->prepare('UPDATE events SET district = ? WHERE id = ?')->execute([is_uganda_district($district) ? $district : null, $eventId]);
}

/** <select> options grouped by region. */
function district_options_html(?string $selected): string
{
    $by = [];
    foreach (uganda_districts() as $name => [$region]) {
        $by[$region][] = $name;
    }
    $out = '';
    foreach (UG_REGIONS as $region) {
        $out .= '<optgroup label="' . htmlspecialchars($region) . ' region">';
        foreach ($by[$region] ?? [] as $name) {
            $out .= '<option value="' . htmlspecialchars($name) . '"' . ($name === $selected ? ' selected' : '') . '>' . htmlspecialchars($name) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
}

/* ---------------------------------------------------------------- dates on Browse (East Africa Time) */

const SEARCH_WHEN = ['today' => 'Today', 'tomorrow' => 'Tomorrow', 'weekend' => 'This weekend', 'week' => 'This week', 'month' => 'This month'];

/**
 * The window a date chip means, as [from, to] timestamps. An event matches when it runs at any point inside it
 * (so a festival that started yesterday still shows under "Today").
 */
function search_when_window(string $when, ?int $now = null): ?array
{
    $now = $now ?? time();
    $tz = new DateTimeZone('Africa/Kampala');
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
    $dow = (int) $today->format('N'); // 1 = Monday ... 7 = Sunday
    switch ($when) {
        case 'today':
            return [$now, $today->modify('+1 day')->getTimestamp() - 1];
        case 'tomorrow':
            $t = $today->modify('+1 day');
            return [$t->getTimestamp(), $t->modify('+1 day')->getTimestamp() - 1];
        case 'weekend':
            // Friday evening to Sunday night; on Saturday or Sunday it starts now.
            $sat = $dow >= 6 ? $today->modify('-' . ($dow - 6) . ' days') : $today->modify('+' . (6 - $dow) . ' days');
            $from = $sat->modify('-1 day')->setTime(17, 0)->getTimestamp();
            return [max($now, $from), $sat->modify('+2 days')->getTimestamp() - 1];
        case 'week':
            return [$now, $today->modify('+' . (8 - $dow) . ' days')->getTimestamp() - 1];
        case 'month':
            return [$now, $today->modify('first day of next month')->getTimestamp() - 1];
    }
    return null;
}

/** Place name => district, for the event form's live guess (aliases and every district's own name). */
function district_detect_map(): array
{
    $map = uganda_place_aliases();
    foreach (array_keys(uganda_districts()) as $d) {
        $map[$d] = $d;
    }
    return $map;
}
