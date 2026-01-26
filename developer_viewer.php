<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

$db = qa_db();

/* ==========================
   INPUT STATE
========================== */
$selectedProgram     = $_GET['user'] ?? '';
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

        // ✅ SHOW ENDPOINT
        if (!empty($log['endpoint'])) {
            $html .= '<strong>Endpoint:</strong> ' . htmlspecialchars($log['endpoint']) . '<br>';
        }

        $json = json_decode($log['response_body'], true);
        $pretty = $json !== null
            ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $log['response_body'];

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
                + ' . $extra . ' more occurrence' . ($extra > 1 ? 's' : '') . '
            </div>';
        }

        // Created at
        if (!empty($log['created_at'])) {
            $html .= '<strong>Created At:</strong> ' . htmlspecialchars($log['created_at']) . '<br>';
        }

        $html .= '</div>';
        return $html;
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
   PROGRAM LIST (FROM LOGS)
========================== */

$programs = [];

$stmt = $db->prepare("
    SELECT DISTINCT program_name
    FROM qa_logs
    WHERE program_name IS NOT NULL
    ORDER BY program_name ASC
");
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $programs[$row['program_name']] =
        $row['program_name'] ?: 'Unknown Program (' . $row['program_name'] . ')';
}

$stmt->close();


/* ==========================
   LOAD REMARKS (FILTERED BY USER)
========================== */
$remarked = [];

if ($selectedProgram) {
    $stmt = $db->prepare("
        SELECT session_id, iteration, remark_name, remark, created_at, user_id, username
        FROM qa_remarks
        WHERE program_name = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('s', $selectedProgram);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sid  = $row['session_id'];
        $iter = (int)$row['iteration'];

        $remarked[$sid][$iter] = [
            'name'     => $row['remark_name'],
            'remark'   => $row['remark'],
            'ctime'    => strtotime($row['created_at']),
            'user_id'  => $row['user_id'],
            'username' => $row['username'] ?? 'Unknown'
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
   LOAD LOGS FOR SELECTED ITERATION ONLY
========================== */
$logsToShow = [];

if ($selectedProgram && $selectedSession && $selectedIteration !== '') {
    $stmt = $db->prepare("
        SELECT *
        FROM qa_logs
        WHERE program_name = ? AND session_id = ? AND iteration = ?
        ORDER BY created_at ASC
    ");
    $stmt->bind_param('ssi', $selectedProgram, $selectedSession, $selectedIteration);
    $stmt->execute();
    $logsToShow = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Add remark info if exists
    $remarkEntry = $filteredRemarked[$selectedSession][$selectedIteration] ?? null;
    if ($remarkEntry) {
        foreach ($logsToShow as &$log) {
            $log['_remark_name'] = $remarkEntry['name'];
            $log['_remark_text'] = $remarkEntry['remark'];
        }
        unset($log);
    }
}

/* ==========================
   ITERATION LIST FOR SELECTED SESSION
========================== */
$iterations = [];
if ($selectedSession && isset($filteredRemarked[$selectedSession])) {
    // Include iterations with remarks
    $iterations = array_keys($filteredRemarked[$selectedSession]);
}

// Also include iterations without remarks
$stmt = $db->prepare("
    SELECT DISTINCT iteration
    FROM qa_logs
    WHERE program_name = ? AND session_id = ?
    ORDER BY iteration ASC
");
$stmt->bind_param('ss', $selectedProgram, $selectedSession);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $iter = (int)$row['iteration'];
    if (!in_array($iter, $iterations, true)) {
        $iterations[] = $iter;
    }
}
$stmt->close();
sort($iterations);



?>

<!DOCTYPE html>
<html>
<head>
    <title>Developer QA Viewer</title>
    <link rel="stylesheet" href="css/design.css">
    <style>
        body { font-family:sans-serif; padding:20px; max-width:900px; margin:auto; background:#f4f6f8; }
        .log-box { background:#fff; border:1px solid #ccc; border-radius:6px; padding:15px; margin-bottom:15px; }
        select, input[type=date] { padding:5px; margin-top:5px; }
        .header-buttons { display:flex; gap:10px; justify-content:flex-end; margin-bottom:15px; }
    </style>
</head>
<body>

<h1>Developer QA Viewer</h1>
<hr>

<!-- HEADER BUTTONS -->
<div class="header-buttons">
    <button class="btn-white" type="button" onclick="window.location.href='auth/logger_logout.php'">Logout</button>
    <button class="btn-black" type="button" onclick="window.location.href='profile.php'">Profile</button>
</div>

<!-- USER SELECT -->
<?php if (!empty($programs)): ?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
    <label><strong>Select Program:</strong></label>
    <select name="user" onchange="this.form.submit()">
        <option value="">-- Select Program --</option>
        <?php foreach ($programs as $programId => $programName): ?>
            <option value="<?= htmlspecialchars($programId) ?>" <?= $programId == $selectedProgram ? 'selected' : '' ?>>
                <?= htmlspecialchars($programName) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>


<!-- DATE FILTER -->
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedProgram) ?>">
    <label>From: <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" onchange="this.form.submit()"></label>
    <label>To: <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" onchange="this.form.submit()"></label>
</form>

<!-- SESSION SELECT -->
<?php if ($selectedProgram): ?>
<?php
// Get all sessions from logs for this user
$sessions = [];
$stmt = $db->prepare("
    SELECT DISTINCT session_id
    FROM qa_logs
    WHERE program_name = ?
    ORDER BY session_id ASC
");
$stmt->bind_param('s', $selectedProgram);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $sessions[] = $row['session_id'];
}
$stmt->close();
?>
<form method="GET" style="margin-bottom:15px;">
    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedProgram) ?>">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
    <label><strong>Select Session:</strong></label>
    <select name="session" onchange="this.form.submit()">
        <option value="">-- Select Session --</option>
        <?php foreach ($sessions as $sid): ?>
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
    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedProgram) ?>">
    <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">
    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
    <label><strong>Select Iteration:</strong></label>
    <select name="iteration" onchange="this.form.submit()">
        <option value="">-- Select Iteration --</option>
        <?php foreach ($iterations as $iter): ?>
            <?php
            $remarkName = $filteredRemarked[$selectedSession][$iter]['name'] ?? '';
            $label = $iter . ($remarkName ? ' - ' . $remarkName : '');
            ?>
            <option value="<?= $iter ?>" <?= $iter == $selectedIteration ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>


<hr>

<?php if (!empty($logsToShow)): ?>
    <?php
    $remarkName = $logsToShow[0]['_remark_name'] ?? '';
    $remarkText = $logsToShow[0]['_remark_text'] ?? '';
    $remarkUser = $filteredRemarked[$selectedSession][$selectedIteration]['username'] ?? 'Unknown';
    ?>
    <?php if ($remarkName || $remarkText): ?>
        <div class="log-box" style="background:#eaf4ff;">
            <strong>Remark Name:</strong> <?= htmlspecialchars($remarkName) ?><br>
            <small>By: <?= htmlspecialchars($remarkUser) ?></small>
        </div>
    <?php endif; ?>
    <?php if ($remarkText): ?>
        <div class="log-box" style="background:#f9f9f9;">
            <strong>Remark:</strong><br>
            <?= nl2br(htmlspecialchars($remarkText)) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($logsToShow as $log): ?>
        <?= render_log_entry($log) ?>
    <?php endforeach; ?>
<?php endif; ?>


</body>
</html>
