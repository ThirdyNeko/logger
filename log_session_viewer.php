<?php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

$sessionId = qa_get_session_id();

/* ============================
   FIND PREVIOUS SESSIONS
============================ */

$remarkFiles = glob(__DIR__ . '/logs/remarked_logs_*.json');

$previousSessions = [];
foreach ($remarkFiles as $file) {
    $sid = str_replace(['remarked_logs_', '.json'], '', basename($file));
    if ($sid !== $sessionId) {
        $previousSessions[$sid] = $file;
    }
}
krsort($previousSessions); // newest first

/* ============================
   LOAD SELECTED SESSION
============================ */

$selectedSession   = $_GET['session'] ?? '';
$selectedIteration = $_GET['iteration'] ?? '';
$remarks           = [];
$iterations        = [];

if ($selectedSession && isset($previousSessions[$selectedSession])) {
    $remarks = json_decode(
        file_get_contents($previousSessions[$selectedSession]),
        true
    ) ?? [];

    $iterations = array_keys($remarks);
}

/* ============================
   LOG RENDER HELPERS
============================ */

function format_log_value($value)
{
    if (is_array($value) || is_object($value)) {
        return htmlspecialchars(
            json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    if ($value === null) {
        return '<em>null</em>';
    }

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function render_log_entry(array $log): string
{
    $html = '<div style="
        border:1px solid #ddd;
        border-radius:6px;
        padding:12px;
        margin:10px 0;
        background:#fafafa;
    ">';

    if (!empty($log['type'])) {
        $html .= '<strong>Type:</strong> ' . htmlspecialchars($log['type']) . '<br>';
    }

    if (!empty($log['url'])) {
        $html .= '<strong>URL:</strong> ' . htmlspecialchars($log['url']) . '<br>';
    }

    if (!empty($log['request'])) {
        $html .= '<strong>Request:</strong><pre>' .
            format_log_value($log['request']) .
            '</pre>';
    }

    if (!empty($log['response'])) {
        $html .= '<strong>Response:</strong><pre>' .
            format_log_value($log['response']) .
            '</pre>';
    }

    $html .= '</div>';
    return $html;
}

?>



<hr>

<h2>Previous Sessions – Remarks (Read-Only)</h2>

<!-- SESSION SELECTOR -->
<form method="GET">
    <label for="sessionSelect"><strong>Select Session:</strong></label>
    <select id="sessionSelect" name="session" onchange="this.form.submit()">
        <option value="">-- Select Session --</option>
        <?php foreach ($previousSessions as $sid => $_): ?>
            <option value="<?= htmlspecialchars($sid) ?>"
                <?= $sid === $selectedSession ? 'selected' : '' ?>>
                <?= htmlspecialchars($sid) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<!-- ITERATION SELECTOR -->
<?php if ($selectedSession && !empty($iterations)): ?>
<form method="GET" style="margin-top:10px;">
    <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">

    <label for="iterationSelect"><strong>Select Iteration:</strong></label>
    <select id="iterationSelect" name="iteration" onchange="this.form.submit()">
        <option value="">-- Select Iteration --</option>
        <?php foreach ($iterations as $iter): ?>
            <option value="<?= htmlspecialchars($iter) ?>"
                <?= $iter === $selectedIteration ? 'selected' : '' ?>>
                <?= htmlspecialchars($iter) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<?php if ($selectedSession && empty($remarks)): ?>
    <p><em>No remarks found for this session.</em></p>
<?php endif; ?>

<!-- DISPLAY SELECTED ITERATION -->
<?php if ($selectedIteration && isset($remarks[$selectedIteration])): 
    $entry = $remarks[$selectedIteration];
?>
<div class="log-box">
    <h3>Iteration ID: <?= htmlspecialchars($selectedIteration) ?></h3>

    <strong>Remark:</strong>
    <p><?= nl2br(htmlspecialchars($entry['remark'])) ?></p>

    <hr>

    <strong>📄 Other Logs</strong>

    <?php if (!empty($entry['logs'])): ?>
        <?php foreach ($entry['logs'] as $log): ?>
            <?= render_log_entry($log) ?>
        <?php endforeach; ?>
    <?php else: ?>
        <p><em>No logs recorded for this iteration.</em></p>
    <?php endif; ?>

    <small>Saved at: <?= htmlspecialchars($entry['saved_at']) ?></small>
</div>
<?php endif; ?>
