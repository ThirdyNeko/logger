<?php

require_once __DIR__ . '/auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

$userId = $_SESSION['user']['id'] ?? 'guest';
$db = qa_db();

/* ==========================
   INPUT STATE
========================== */
$selectedUser      = $_GET['user'] ?? '';
$selectedSession   = $_GET['session'] ?? '';
$selectedIteration = $_GET['iteration'] ?? '';
$fromDate          = $_GET['from_date'] ?? '';
$toDate            = $_GET['to_date'] ?? '';

/* ==========================
   Helpers
========================== */
function is_error_log(array $log): bool
{
    return in_array($log['type'] ?? '', ['backend-error', 'backend-fatal'], true);
}

function group_error_logs(array $errorLogs): array
{
    $grouped = [];
    foreach ($errorLogs as $log) {
        $key = md5(($log['message'] ?? '').($log['type'] ?? '').($log['endpoint'] ?? ''));
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

    // Always show type
    $html .= '<strong>Type:</strong> ' . htmlspecialchars($type) . '<br>';

    // --- Backend Error Styling ---
    if ($type === 'backend-error') {
        $json = json_decode($log['response_body'], true);
        $pretty = $json !== null ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $log['response_body'];

        $html .= '<strong>Response:</strong>
        <pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family:Consolas,monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' . htmlspecialchars($pretty) . '</pre>';

        // Occurrences
        if (!empty($log['_count']) && $log['_count'] > 1) {
            $extra = (int)$log['_count'] - 1;
            $html .= '<div style="
                margin-top:6px;
                padding:6px 10px;
                background:#fff3cd;
                border:1px solid #ffe69c;
                border-radius:6px;
                color:#664d03;
                font-size:13px;
            ">
                + ' . $extra . ' more occurrence' . ($extra > 1 ? 's' : '') . ' of the same error
            </div>';
        }

        $html .= '</div>';
        return $html; // Early return for backend-error
    }

    // --- Fields for non-special types ---
    if (!in_array($type, ['frontend-io', 'backend-response'], true)) {
        if (!empty($log['iteration'])) {
            $html .= '<strong>Iteration:</strong> ' . htmlspecialchars($log['iteration']) . '<br>';
        }
        if (!empty($log['method'])) {
            $html .= '<strong>Method:</strong> ' . htmlspecialchars($log['method']) . '<br>';
        }
        if (isset($log['status_code'])) {
            $html .= '<strong>Status:</strong> ' . (int)$log['status_code'] . '<br>';
        }
    }

    // Endpoint only for backend-response
    if ($type === 'backend-response' && !empty($log['endpoint'])) {
        $html .= '<strong>Endpoint:</strong> ' . htmlspecialchars($log['endpoint']) . '<br>';
    }

    // Request body (all types)
    if (!empty($log['request_body'])) {
        $json = json_decode($log['request_body'], true);
        $pretty = $json !== null ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $log['request_body'];
        $html .= '<strong>Request:</strong>
        <pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px 14px;
            margin:8px 0 12px 0;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family:Consolas,Monaco,\'Courier New\',monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' . htmlspecialchars($pretty) . '</pre>';
    }

    // Response body (all types)
    if (!empty($log['response_body'])) {
        $json = json_decode($log['response_body'], true);
        $pretty = $json !== null ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $log['response_body'];
        $html .= '<strong>Response:</strong>
        <pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px 14px;
            margin:8px 0 12px 0;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family:Consolas,Monaco,\'Courier New\',monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' . htmlspecialchars($pretty) . '</pre>';
    }

    // Occurrences for grouped errors
    if (!empty($log['_count']) && $log['_count'] > 1) {
        $html .= '<strong>Occurrences:</strong> ' . (int)$log['_count'] . '<br>';
    }

    // Created at (skip for frontend-io)
    if ($type !== 'frontend-io' && !empty($log['created_at'])) {
        $html .= '<strong>Created At:</strong> ' . htmlspecialchars($log['created_at']) . '<br>';
    }

    $html .= '</div>';
    return $html;
}


/* ==========================
   USERS WITH REMARKS
========================== */
// Fetch users with remarks along with their username
$usersWithRemarks = [];
$stmt = $db->prepare("
    SELECT DISTINCT r.user_id, u.username
    FROM qa_remarks r
    LEFT JOIN users u ON u.id = r.user_id
    ORDER BY u.username ASC
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $usersWithRemarks[$row['user_id']] = $row['username'] ?? 'User '.$row['user_id'];
}
$stmt->close();


/* ==========================
   LOAD REMARKS (FILTERED BY USER)
========================== */
$remarked = [];

if ($selectedUser) {
    $stmt = $db->prepare("
        SELECT session_id, iteration, remark_name, remark, created_at
        FROM qa_remarks
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('s', $selectedUser);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sid  = $row['session_id'];
        $iter = (int)$row['iteration'];

        $remarked[$sid][$iter] = [
            'name'   => $row['remark_name'],
            'remark' => $row['remark'],
            'ctime'  => strtotime($row['created_at'])
        ];
    }
    $stmt->close();
}

/* ==========================
   DATE FILTER
========================== */
$filteredRemarked = [];
foreach ($remarked as $sid => $iters) {
    foreach ($iters as $iter => $entry) {
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
krsort($filteredRemarked);

/* ==========================
   SESSION / ITERATION LIST
========================== */
$iterations = [];
if ($selectedSession && isset($filteredRemarked[$selectedSession])) {
    $iterations = array_keys($filteredRemarked[$selectedSession]);
}

/* ==========================
   LOAD LOGS FOR REMARK
========================== */
$showRemarks = $selectedUser && $selectedSession && $selectedIteration
    && isset($filteredRemarked[$selectedSession][$selectedIteration]);

$errorLogs = $normalLogs = [];
if ($showRemarks) {
    $entry = $filteredRemarked[$selectedSession][$selectedIteration];
    $parentIteration = (int)preg_split('/\s*-\s*/', $selectedIteration)[0];

    $stmt = $db->prepare("
        SELECT *
        FROM qa_logs
        WHERE user_id = ? AND session_id = ? AND iteration = ?
        ORDER BY created_at ASC
    ");
    $stmt->bind_param('ssi', $selectedUser, $selectedSession, $parentIteration);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $errorLogsRaw = [];
    foreach ($logs as $log) {
        if (is_error_log($log)) $errorLogsRaw[] = $log;
        else $normalLogs[] = $log;
    }
    $errorLogs = group_error_logs($errorLogsRaw);
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>QA Log Session Viewer</title>
    <link rel="stylesheet" href="css/design.css">
    <style>
        body { font-family:sans-serif; padding:20px; max-width:900px; margin:auto; background:#f4f6f8; }
        .log-box { background:#fff; border:1px solid #ccc; border-radius:6px; padding:15px; margin-bottom:15px; }
        select, input[type=date] { padding:5px; margin-top:5px; }
        .header-buttons { display:flex; gap:10px; justify-content:flex-end; margin-bottom:15px; }
    </style>
</head>
<body>

<h1>Previous Sessions – Remarks (Read-Only)</h1>
<hr>

<!-- HEADER BUTTONS -->
<div class="header-buttons">
    <button class="btn-white" type="button" onclick="window.location.href='auth/logger_logout.php'">Logout</button>
    <button class="btn-black" type="button" onclick="window.location.href='profile.php'">Profile</button>
</div>

<!-- USER SELECT -->
<?php if (!empty($usersWithRemarks)): ?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
    <label><strong>Select User:</strong></label>
    <select name="user" onchange="this.form.submit()">
        <option value="">-- Select User --</option>
        <?php foreach ($usersWithRemarks as $uid => $username): ?>
            <option value="<?= htmlspecialchars($uid) ?>" <?= $uid == $selectedUser ? 'selected' : '' ?>>
                <?= htmlspecialchars($username) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<!-- DATE FILTER -->
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser) ?>">
    <label>From: <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" onchange="this.form.submit()"></label>
    <label>To: <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" onchange="this.form.submit()"></label>
</form>

<!-- SESSION SELECT -->
<?php if ($selectedUser && $filteredRemarked): ?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser) ?>">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
    <label><strong>Select Session:</strong></label>
    <select name="session" onchange="this.form.submit()">
        <option value="">-- Select Session --</option>
        <?php foreach ($filteredRemarked as $sid => $_): ?>
            <option value="<?= htmlspecialchars($sid) ?>" <?= $sid === $selectedSession ? 'selected' : '' ?>>
                <?= htmlspecialchars(str_replace('_',' ',$sid)) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<!-- ITERATION SELECT -->
<?php if ($selectedSession && $iterations): ?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedUser) ?>">
    <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
    <label><strong>Select Activity Log:</strong></label>
    <select name="iteration" onchange="this.form.submit()">
        <option value="">-- Select Iteration --</option>
        <?php foreach ($iterations as $iter): ?>
            <option value="<?= $iter ?>" <?= $iter == $selectedIteration ? 'selected' : '' ?>>
                <?= $iter ?> - <?= htmlspecialchars($filteredRemarked[$selectedSession][$iter]['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<hr>

<!-- REMARKS + LOGS -->
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

    <?php if (!empty($errorLogs)): ?>
        <div class="log-box" style="background:#fff3f3;">
            <h3>⚠️ Error Logs</h3>
            <?php foreach ($errorLogs as $log) echo render_log_entry($log); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($normalLogs)): ?>
        <div class="log-box">
            <h3>📄 Other Logs</h3>
            <?php foreach ($normalLogs as $log) echo render_log_entry($log); ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
