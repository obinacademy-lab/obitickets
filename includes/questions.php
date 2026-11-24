<?php
// Questions at checkout (11/24, migration 043). Up to QUESTIONS_MAX per event, short answer or a choice, required or not.
const QUESTIONS_MAX = 5;

function questions_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'event_questions'")->fetch();
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The event's questions in order (active ones only unless $all). Each has 'choices' as a list. */
function event_questions(int $eventId, bool $all = false): array
{
    if (!questions_ready()) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM event_questions WHERE event_id = ?' . ($all ? '' : ' AND active = 1') . ' ORDER BY sort_order, id');
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$q) {
        $q['choices'] = $q['kind'] === 'CHOICE' ? question_choices((string) $q['options']) : [];
    }
    unset($q);
    return $rows;
}

/** "Small, Medium\nLarge" -> ['Small', 'Medium', 'Large'] (commas or new lines, blanks dropped, max 20). */
function question_choices(string $raw): array
{
    $parts = array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: []);
    return array_slice(array_values(array_unique(array_filter($parts, static fn ($s) => $s !== ''))), 0, 20);
}

/** Reads the q_*[] rows an event form posts. Returns [rows, errors]. Blank question text = row left empty. */
function parse_question_submission(array $post): array
{
    $rows = [];
    $errors = [];
    foreach (($post['q_label'] ?? []) as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $kind = ($post['q_kind'][$i] ?? '') === 'CHOICE' ? 'CHOICE' : 'TEXT';
        $choices = $kind === 'CHOICE' ? question_choices((string) ($post['q_options'][$i] ?? '')) : [];
        if (mb_strlen($label) > 160) {
            $errors[] = 'Keep each question under 160 letters.';
            continue;
        }
        if ($kind === 'CHOICE' && count($choices) < 2) {
            $errors[] = "The question \"{$label}\" needs at least 2 choices, separated by commas.";
            continue;
        }
        $rows[] = [
            'id' => ctype_digit((string) ($post['q_id'][$i] ?? '')) ? (int) $post['q_id'][$i] : null,
            'label' => $label,
            'kind' => $kind,
            'options' => $kind === 'CHOICE' ? implode("\n", $choices) : null,
            'required' => ($post['q_required'][$i] ?? '0') === '1' ? 1 : 0,
        ];
    }
    if (count($rows) > QUESTIONS_MAX) {
        $errors[] = 'You can ask up to ' . QUESTIONS_MAX . ' questions.';
        $rows = array_slice($rows, 0, QUESTIONS_MAX);
    }
    return [$rows, $errors];
}

/** Saves an event's questions: updates kept ones (by id, this event only), adds new, switches off removed ones. */
function save_event_questions(int $eventId, array $rows): void
{
    if (!questions_ready()) {
        return;
    }
    $pdo = db();
    $keep = [];
    foreach (array_values($rows) as $sort => $q) {
        if ($q['id']) {
            $u = $pdo->prepare('UPDATE event_questions SET label = ?, kind = ?, options = ?, required = ?, active = 1, sort_order = ? WHERE id = ? AND event_id = ?');
            $u->execute([$q['label'], $q['kind'], $q['options'], $q['required'], $sort, $q['id'], $eventId]);
            if ($u->rowCount() > 0 || $pdo->query('SELECT 1 FROM event_questions WHERE id = ' . (int) $q['id'] . ' AND event_id = ' . $eventId)->fetch()) {
                $keep[] = (int) $q['id'];
                continue;
            }
        }
        $pdo->prepare('INSERT INTO event_questions (event_id, label, kind, options, required, sort_order) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$eventId, $q['label'], $q['kind'], $q['options'], $q['required'], $sort]);
        $keep[] = (int) $pdo->lastInsertId();
    }
    $sql = 'UPDATE event_questions SET active = 0 WHERE event_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : '');
    $pdo->prepare($sql)->execute([$eventId]);
}

/** Checks a buyer's answers (question id => text). Returns [clean answers, error or null]. */
function validate_answers(array $questions, array $answers): array
{
    $clean = [];
    foreach ($questions as $q) {
        $a = trim((string) ($answers[(string) $q['id']] ?? $answers[(int) $q['id']] ?? ''));
        $a = mb_substr(preg_replace('/\s+/u', ' ', $a) ?? '', 0, 500);
        if ($a === '') {
            if ((int) $q['required']) {
                return [[], 'Please answer: ' . $q['label']];
            }
            continue;
        }
        if ($q['kind'] === 'CHOICE' && !in_array($a, $q['choices'], true)) {
            return [[], 'Please pick one of the choices for: ' . $q['label']];
        }
        $clean[(int) $q['id']] = $a;
    }
    return [$clean, null];
}

function save_order_answers(int $orderId, array $clean): void
{
    if (!questions_ready() || !$clean) {
        return;
    }
    $ins = db()->prepare('INSERT INTO order_answers (order_id, question_id, answer) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE answer = VALUES(answer)');
    foreach ($clean as $qid => $a) {
        $ins->execute([$orderId, (int) $qid, $a]);
    }
}

/** order id => [question label => answer], for the orders given. */
function answers_for_orders(array $orderIds): array
{
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!questions_ready() || !$orderIds) {
        return [];
    }
    $out = [];
    foreach (array_chunk($orderIds, 500) as $chunk) {
        $stmt = db()->query('SELECT a.order_id, q.label, a.answer FROM order_answers a JOIN event_questions q ON q.id = a.question_id WHERE a.order_id IN (' . implode(',', $chunk) . ') ORDER BY q.sort_order, q.id');
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['order_id']][(string) $r['label']] = (string) $r['answer'];
        }
    }
    return $out;
}

/** The organizer's question rows for the event forms (existing ones, then empty rows up to the maximum). */
function question_form_rows(?int $eventId, array $posted = []): array
{
    $posted = array_map(static fn ($r) => ['options' => str_replace("\n", ', ', (string) ($r['options'] ?? ''))] + $r, $posted);
    $rows = $posted ?: ($eventId ? array_map(static fn ($q) => ['id' => (int) $q['id'], 'label' => $q['label'], 'kind' => $q['kind'], 'options' => implode(', ', $q['choices']), 'required' => (int) $q['required']], event_questions($eventId)) : []);
    while (count($rows) < QUESTIONS_MAX) {
        $rows[] = ['id' => null, 'label' => '', 'kind' => 'TEXT', 'options' => '', 'required' => 0];
    }
    return $rows;
}

/** The questions block for both event forms. */
function render_question_fields(array $rows): void
{
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
    echo '<fieldset class="q-fields"><legend>Questions for buyers <small>(optional, up to ' . QUESTIONS_MAX . ')</small></legend>'
        . '<p class="q-help">Buyers answer these at checkout. You see the answers in each order and in the attendee CSV. For a choice, type the options separated by commas.</p>';
    foreach ($rows as $i => $q) {
        $n = $i + 1;
        echo '<div class="q-row">'
            . '<input type="hidden" name="q_id[]" value="' . $h($q['id'] ?? '') . '">'
            . '<label class="q-l"><span>Question ' . $n . '</span><input type="text" name="q_label[]" maxlength="160" value="' . $h($q['label']) . '" placeholder="' . ($i === 0 ? 'e.g. T-shirt size' : ($i === 1 ? 'e.g. Who invited you?' : '')) . '"></label>'
            . '<label class="q-k"><span>Type</span><select name="q_kind[]"><option value="TEXT"' . ($q['kind'] !== 'CHOICE' ? ' selected' : '') . '>Short answer</option><option value="CHOICE"' . ($q['kind'] === 'CHOICE' ? ' selected' : '') . '>Choice</option></select></label>'
            . '<label class="q-r"><span>Required</span><select name="q_required[]"><option value="0"' . (!(int) $q['required'] ? ' selected' : '') . '>No</option><option value="1"' . ((int) $q['required'] ? ' selected' : '') . '>Yes</option></select></label>'
            . '<label class="q-o"><span>Choices (for "Choice")</span><input type="text" name="q_options[]" maxlength="1000" value="' . $h($q['options'] ?? '') . '" placeholder="e.g. Small, Medium, Large"></label>'
            . '</div>';
    }
    echo '</fieldset>';
}
