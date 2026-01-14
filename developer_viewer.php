<?php

require_once __DIR__ . '/auth/require_login.php';
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

date_default_timezone_set('Asia/Manila');

/* ==========================
   Helper functions
========================== */

function is_error_log(array $log): bool
{
    return in_array($log['type'] ?? '', ['backend-error'], true);
}

function format_log_value($value): string
{
    if (is_array($value) || is_object($value)) {
        return htmlspecialchars(
            json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );
    }
    return $value === null ? '<em>null</em>' : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function group_error_logs(array $errorLogs): array
{
    $grouped = [];
    foreach ($errorLogs as $log) {
        $key = md5(($log['message'] ?? '') . '|' . ($log['severity'] ?? '') . '|' . ($log['file'] ?? ''));
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
   Session & user setup
========================== */

$userId = (string) ($_SESSION['user']['id'] ?? '');
$role   = $_SESSION['user']['role'] ?? '';

/* ==========================
   Load user ID → username map
========================== */

$userMap = [];
$usersFile = __DIR__ . '/auth/users.json';

if (is_file($usersFile)) {
    $users = json_decode(file_get_contents($usersFile), true);
    foreach ($users ?? [] as $u) {
        if (isset($u['id'], $u['username'])) {
            $userMap[(string)$u['id']] = $u['username'];
        }
    }
}

/* ==========================
   Discover users with logs
========================== */

$logsRoot = __DIR__ . '/logs';
$availableUsers = [];

if (is_dir($logsRoot)) {
    foreach (glob($logsRoot . '/user_*', GLOB_ONLYDIR) as $dir) {
        $availableUsers[] = str_replace('user_', '', basename($dir));
    }
}

sort($availableUsers);

/* ==========================
   Resolve selected user
========================== */

$selectedUser = $_GET['user'] ?? '';

if ($role !== 'developer') {
    $availableUsers = [$userId];
    $selectedUser = $userId;
} else {
    if ($selectedUser !== '' && !in_array($selectedUser, $availableUsers, true)) {
        $selectedUser = '';
    }
}

$hasUserSelected = ($selectedUser !== '');

/* ==========================
   Load remarked logs (only if user selected)
========================== */

$remarked = [];

if ($hasUserSelected) {
    $logBase = __DIR__ . "/logs/user_{$selectedUser}";
    if (is_dir($logBase)) {
        foreach (glob("{$logBase}/remarked_logs_*.json") as $file) {
            $sid = str_replace(['remarked_logs_', '.json'], '', basename($file));
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $remarked[$sid] = [
                    'data'  => $data,
                    'ctime' => filectime($file)
                ];
            }
        }
    }
}

/* ==========================
   Date filtering
========================== */

$fromDate = $_GET['from_date'] ?? '';
$toDate   = $_GET['to_date'] ?? '';
$hasDateSelected = ($fromDate !== '' && $toDate !== '');
$filteredRemarked = [];

if ($hasUserSelected && $hasDateSelected) {
    foreach ($remarked as $sid => $info) {
        $dateKey = date('Y-m-d', $info['ctime']);
        if ($dateKey >= $fromDate && $dateKey <= $toDate) {
            $filteredRemarked[$sid] = $info['data'];
        }
    }
}

krsort($filteredRemarked);

/* ==========================
   Session & iteration
========================== */

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

<button type="button" onclick="window.location.href='auth/logout.php'"
        style="background:#FFFFFF;border:1px solid #000000;color:#000000;padding:8px 14px;border-radius:4px;cursor:pointer;">
    Logout
</button>

<button type="button" onclick="window.location.href='profile.php'"
        style="background:#000000;border:1px solid #000000;color:#FFFFFF;padding:8px 14px;border-radius:4px;cursor:pointer;">
    Profile
</button>

<hr>

<!-- USER FILTER -->
<form method="GET" style="margin-bottom:15px;">
    <label><strong>User:</strong></label>
    <select name="user" onchange="this.form.submit()">
        <option value="">-- Select User --</option>
        <?php foreach ($availableUsers as $uid): ?>
            <option value="<?= htmlspecialchars($uid) ?>" <?= $uid === $selectedUser ? 'selected' : '' ?>>
                <?= htmlspecialchars($userMap[$uid] ?? "User {$uid}") ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($hasUserSelected): ?>
    <!-- DATE RANGE SELECT -->
    <form method="GET" style="margin-bottom:15px;">
        <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser) ?>">
        <label>From:
            <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>"
                   onchange="this.form.submit()">
        </label>
        &nbsp;&nbsp;
        <label>To:
            <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>"
                   onchange="this.form.submit()">
        </label>
    </form>
<?php endif; ?>

<?php if ($hasDateSelected && !empty($filteredRemarked)): ?>
    <!-- SESSION SELECT -->
    <form method="GET" style="margin-bottom:15px;">
        <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser) ?>">
        <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
        <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">

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

<?php if ($selectedSession && !empty($iterations)): ?>
    <!-- ITERATION SELECT -->
    <form method="GET" style="margin-bottom:15px;">
        <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser) ?>">
        <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
        <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
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
