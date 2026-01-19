<?php

require_once __DIR__ . '/auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

$userId    = $_SESSION['user']['id'] ?? 'guest';
$sessionId = qa_get_session_id();

/* ==========================
   Helpers for rendering logs
========================== */
function format_log_value($value)
{
    if (is_array($value) || is_object($value)) {
        return htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    }
    return $value === null ? '<em>null</em>' : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_error_log(array $log): bool
{
    return in_array($log['type'] ?? '', ['backend-error', 'backend-fatal'], true);
}

function group_error_logs(array $errorLogs): array
{
    $grouped = [];
    foreach ($errorLogs as $log) {
        $keyParts = [
            $log['message'] ?? '',
            $log['type'] ?? '',
            $log['endpoint'] ?? ''
        ];

        $key = md5(implode('|', $keyParts));

        if (!isset($grouped[$key])) {
            $grouped[$key] = $log;
            $grouped[$key]['_count'] = 0;
        }

        $grouped[$key]['_count']++;
    }

    return array_values($grouped);
}

function render_log_entry(array $log): string
{
    $type = $log['type'] ?? '';
    $html = '<div style="
        border:1px solid #ddd;
        border-radius:4px;
        padding:10px;
        margin-bottom:10px;
        background:#fafafa;
    ">';

    if (!empty($type)) {
        $html .= '<strong>Type:</strong> ' . htmlspecialchars($type) . '<br>';
    }

    if (!empty($log['request_body'])) {
        $json = json_decode($log['request_body'], true);
        $pretty = $json !== null ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $log['request_body'];
        $html .= '<strong>Request:</strong><pre>' . htmlspecialchars($pretty) . '</pre>';
    }

    if (!empty($log['response_body'])) {
        $json = json_decode($log['response_body'], true);
        $pretty = $json !== null ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $log['response_body'];
        $html .= '<strong>Response:</strong><pre>' . htmlspecialchars($pretty) . '</pre>';
    }

    if (!empty($log['_count']) && $log['_count'] > 1) {
        $html .= '<strong>Occurrences:</strong> ' . (int)$log['_count'] . '<br>';
    }

    if (!empty($log['created_at'])) {
        $html .= '<strong>Created At:</strong> ' . htmlspecialchars($log['created_at']) . '<br>';
    }

    $html .= '</div>';
    return $html;
}

/* ==========================
   Load remarked iterations from DB
========================= */

$db = qa_db();
$remarked = [];

/*
 Structure:
 $remarked[session_id][iteration] = [
     'name'   => remark_name,
     'remark' => remark,
     'ctime'  => timestamp
 ];
*/
$stmt = $db->prepare("
    SELECT session_id, iteration, remark_name, remark, created_at
    FROM qa_remarks
    WHERE user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $sid = $row['session_id'];
    $iter = (int)$row['iteration'];

    $remarked[$sid][$iter] = [
        'name'   => $row['remark_name'],
        'remark' => $row['remark'],
        'ctime'  => strtotime($row['created_at'])
    ];
}
$stmt->close();

/* ==========================
   Filter by date range if provided
========================= */
$fromDate = $_GET['from_date'] ?? null;
$toDate   = $_GET['to_date'] ?? null;
$filteredRemarked = [];

foreach ($remarked as $sid => $iterations) {
    foreach ($iterations as $iter => $entry) {
        $dateKey = date('Y-m-d', $entry['ctime']);
        if ($fromDate && $toDate) {
            if ($dateKey >= $fromDate && $dateKey <= $toDate) {
                $filteredRemarked[$sid][$iter] = $entry;
            }
        } else {
            $filteredRemarked[$sid][$iter] = $entry;
        }
    }
}

/* Sort sessions DESC */
krsort($filteredRemarked);

$selectedSession   = $_GET['session'] ?? '';
$selectedIteration = $_GET['iteration'] ?? '';
$iterations = [];

if ($selectedSession && isset($filteredRemarked[$selectedSession])) {
    $iterations = array_keys($filteredRemarked[$selectedSession]);
}



?>

<!DOCTYPE html>
<html>
<head>
    <title>QA Log Session Viewer</title>
    <style>
        body { font-family: sans-serif; padding:20px; max-width:900px; margin:auto; background:#f4f6f8; }
        .log-box { background:#fff; border:1px solid #ccc; border-radius:6px; padding:15px; margin-bottom:20px; }
        select, input[type=date] { padding:5px; margin-top:5px; }
    </style>
    <link rel="stylesheet" href="css/design.css">
</head>
<body>

<h1>Previous Sessions – Remarks (Read-Only)</h1>

<button class="btn-white" onclick="window.location.href='logger_index.php'">Return to Logger</button>

<hr>

<!-- DATE RANGE SELECT -->
<form method="GET" style="margin-bottom:15px;">
    <label>From: <input type="date" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>" onchange="this.form.submit()"></label>
    &nbsp;&nbsp;
    <label>To: <input type="date" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>" onchange="this.form.submit()"></label>
</form>

<!-- SESSION SELECT -->
<?php if (!empty($filteredRemarked)): ?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate ?? '') ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate ?? '') ?>">

    <label><strong>Select Session:</strong></label>
    <select name="session" onchange="this.form.submit()">
        <option value="">-- Select Session --</option>
        <?php foreach ($filteredRemarked as $sid => $data): ?>
            <option value="<?= htmlspecialchars($sid) ?>" <?= ($sid === $selectedSession ? 'selected' : '') ?>>
                <?= htmlspecialchars(str_replace('_', ' ', $sid)) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<!-- ITERATION SELECT -->
<?php if ($selectedSession && !empty($iterations)): ?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate ?? '') ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate ?? '') ?>">
    <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">

    <label><strong>Select Activity Log:</strong></label>
    <select name="iteration" onchange="this.form.submit()">
        <option value="">-- Select Iteration --</option>
        <?php foreach ($iterations as $iter): ?>
            <option value="<?= htmlspecialchars($iter) ?>" <?= ($iter == $selectedIteration ? 'selected' : '') ?>>
                <?= htmlspecialchars($iter) ?><?= !empty($filteredRemarked[$selectedSession][$iter]['name']) ? ' - '.$filteredRemarked[$selectedSession][$iter]['name'] : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<hr>

<!-- DISPLAY REMARKS + LOGS ONLY AFTER FILTERS -->
<?php
$showRemarks = $selectedSession && $selectedIteration 
    && isset($filteredRemarked[$selectedSession][$selectedIteration]);

if ($showRemarks && $selectedSession) {

    // Extract only the iteration number (parent iteration) from $selectedIteration
    // Assumes format like "1 - Login Bug" or just "1"
    $parentIteration = (int) preg_split('/\s*-\s*/', $selectedIteration)[0];

    // Fetch logs referenced by this remark
    $logs = [];
    $stmt = $db->prepare("
        SELECT *
        FROM qa_logs
        WHERE user_id = ? AND session_id = ? AND iteration = ?
        ORDER BY created_at ASC
    ");
    // user_id = string, session_id = string, iteration = int
    $stmt->bind_param('ssi', $userId, $selectedSession, $parentIteration);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Separate error logs and normal logs
    $errorLogsRaw = [];
    $normalLogs   = [];
    foreach ($logs as $log) {
        if (is_error_log($log)) $errorLogsRaw[] = $log;
        else $normalLogs[] = $log;
    }
    $errorLogs = group_error_logs($errorLogsRaw);
}

?>

<?php if ($showRemarks): ?>

    <?php if (!empty($entry['name'])): ?>
    <div class="log-box">
        <strong>Remark Name:</strong> <?= htmlspecialchars($entry['name']) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($entry['remark'])): ?>
    <div class="log-box">
        <strong>Remark:</strong><br>
        <?= nl2br(htmlspecialchars($entry['remark'])) ?>
    </div>
    <?php endif; ?>

    <!-- Display logs referenced by this remark -->
    <?php if (!empty($errorLogs)): ?>
    <div class="log-box" style="background:#fff3f3;">
        <h3>⚠️ Error Logs</h3>
        <?php foreach ($errorLogs as $log): ?>
            <?= render_log_entry($log) ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($normalLogs)): ?>
    <div class="log-box">
        <h3>📄 Other Logs</h3>
        <?php foreach ($normalLogs as $log): ?>
            <?= render_log_entry($log) ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>
