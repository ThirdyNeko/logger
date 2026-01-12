<?php
require_once __DIR__ . '/auth/require_login.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';


$userId    = $_SESSION['user']['id'] ?? 'guest';

/* ==========================
   Helpers
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
    return in_array($log['type'] ?? '', ['backend-error'], true);
}

function group_error_logs(array $errorLogs): array
{
    $grouped = [];
    foreach ($errorLogs as $log) {
        $keyParts = [$log['message'] ?? '', $log['severity'] ?? '', $log['file'] ?? ''];
        $key = md5(implode('|', $keyParts));

        if (!isset($grouped[$key])) {
            $grouped[$key] = $log;
            $grouped[$key]['_lines'] = [];
        }

        if (!empty($log['line'])) {
            $grouped[$key]['_lines'][] = (int)$log['line'];
        }
    }

    foreach ($grouped as &$g) {
        $g['_lines'] = array_values(array_unique($g['_lines']));
        sort($g['_lines']);
    }

    return array_values($grouped);
}

function render_log_entry(array $log): string
{
    $html = '<div style="border:1px solid #ddd;border-radius:6px;padding:12px;margin:10px 0;background:#fafafa;">';
    if (!empty($log['type'])) $html .= '<strong>Type:</strong> ' . htmlspecialchars($log['type']) . '<br>';
    if (!empty($log['request'])) $html .= '<strong>Request:</strong><pre>' . format_log_value($log['request']) . '</pre>';
    if (!empty($log['response'])) $html .= '<strong>Response:</strong><pre>' . format_log_value($log['response']) . '</pre>';
    $html .= '</div>';
    return $html;
}

/* ==========================
   Load remarked logs (per-user folder)
========================== */

$logBase = __DIR__ . "/logs/user_{$userId}";
$remarked = [];

if (is_dir($logBase)) {
    $remarkFiles = glob("{$logBase}/remarked_logs_*.json");
    foreach ($remarkFiles as $file) {
        $sid = str_replace(['remarked_logs_', '.json'], '', basename($file));
        $data = json_decode(file_get_contents($file), true);
        if ($data && is_array($data)) {
            $remarked[$sid] = [
                'data' => $data,
                'ctime' => filectime($file)
            ];
        }
    }
}

/* ==========================
   Filter by date range if provided
========================== */
$fromDate = $_GET['from_date'] ?? null;
$toDate   = $_GET['to_date'] ?? null;
$filteredRemarked = [];

foreach ($remarked as $sid => $info) {
    $dateKey = date('Y-m-d', $info['ctime']);
    if ($fromDate && $toDate) {
        if ($dateKey >= $fromDate && $dateKey <= $toDate) {
            $filteredRemarked[$sid] = $info['data'];
        }
    } else {
        $filteredRemarked[$sid] = $info['data'];
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
</head>
<body>

<h1>Previous Sessions – Remarks (Read-Only)</h1>

<button onclick="window.location.href='logger_index.php'">Return to Logger</button>

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
            <option value="<?= htmlspecialchars($iter) ?>" <?= ($iter === $selectedIteration ? 'selected' : '') ?>>
                <?= htmlspecialchars($iter) ?><?= !empty($filteredRemarked[$selectedSession][$iter]['name']) ? ' - '.$filteredRemarked[$selectedSession][$iter]['name'] : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<hr>

<!-- DISPLAY LOGS -->
<?php
if ($selectedSession && $selectedIteration && isset($filteredRemarked[$selectedSession][$selectedIteration])) {
    $entry = $filteredRemarked[$selectedSession][$selectedIteration];
    $errorLogsRaw = [];
    $normalLogs   = [];
    foreach ($entry['logs'] ?? [] as $log) {
        if (is_error_log($log)) $errorLogsRaw[] = $log;
        else $normalLogs[] = $log;
    }
    $errorLogs = group_error_logs($errorLogsRaw);
?>

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

<?php } ?>

</body>
</html>
