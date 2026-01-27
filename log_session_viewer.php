<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

$db = qa_db();

$username = $_SESSION['user']['username'] ?? '';
$userId = null;

if ($username) {
    $stmtUser = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmtUser->bind_param('s', $username);
    $stmtUser->execute();
    $res = $stmtUser->get_result();
    $userRow = $res->fetch_assoc();
    $stmtUser->close();

    $userId = $userRow['id'] ?? null;
}
/* ==========================
   RENAME SESSION
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['rename_session'], $_POST['program'], $_POST['session'])
) {
    define('QA_SKIP_LOGGING', true);

    $program   = $_POST['program'];
    $sessionId = $_POST['session'];
    $name      = trim($_POST['rename_session']);

    if ($program && $sessionId && $name !== '') {
        $stmt = $db->prepare("
            INSERT INTO qa_session_names (program_name, session_id, session_name)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE session_name = VALUES(session_name)
        ");
        $stmt->bind_param('sss', $program, $sessionId, $name);
        $stmt->execute();
        $stmt->close();
    }

    // Stay on same selection
    header('Location: ' . $_SERVER['PHP_SELF']
        . '?user=' . urlencode($program)
        . '&session=' . urlencode($sessionId)
    );
    exit;
}

/* ==========================
   STORE QA REMARK (VIEWER)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['remark'], $_POST['iteration'])
) {
    define('QA_SKIP_LOGGING', true);
    
    $program   = $_POST['program'] ?? '';
    $sessionId = $_POST['session'] ?? '';
    $iteration = (int) $_POST['iteration'];

    $remark     = trim($_POST['remark']);
    $remarkName = trim($_POST['remark_name'] ?? '');

    if ($userId && $username && $program && $sessionId && $remark !== '') {
        $stmt = $db->prepare("
            INSERT INTO qa_remarks
                (user_id, username, program_name, session_id, iteration, remark_name, remark)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                remark_name = VALUES(remark_name),
                remark = VALUES(remark),
                username = VALUES(username)
        ");

        $stmt->bind_param(
            'isssiss',
            $userId,
            $username,
            $program,
            $sessionId,
            $iteration,
            $remarkName,
            $remark
        );

        $stmt->execute();
        $stmt->close();
    }

    header('Location: ' . $_SERVER['PHP_SELF']
        . '?user=' . urlencode($program)
        . '&session=' . urlencode($sessionId)
        . '&iteration=' . $iteration
    );
    exit;
}


/* ==========================
   INPUT STATE
========================== */
$selectedProgram     = $_GET['user'] ?? '';
$selectedSession   = $_GET['session'] ?? '';
$selectedIteration = $_GET['iteration'] ?? '';
$fromDate          = $_GET['from_date'] ?? '';
$toDate            = $_GET['to_date'] ?? '';

/* ==========================
   LOAD SESSION NAMES
========================== */
$sessionNames = [];

if ($selectedProgram) {
    $stmt = $db->prepare("
        SELECT session_id, session_name
        FROM qa_session_names
        WHERE program_name = ?
    ");
    $stmt->bind_param('s', $selectedProgram);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sessionNames[$row['session_id']] = $row['session_name'];
    }
    $stmt->close();
}

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
        // Decode response_body safely
        $decoded = json_decode($log['response_body'] ?? '', true);

        $message  = $decoded['message'] ?? '';
        $severity = $decoded['severity'] ?? '';

        // 🔑 Logical grouping key
        $key = md5(
            ($log['type'] ?? '') . '|' . $message . '|' . $severity
        );

        if (!isset($grouped[$key])) {
            $base = $log;

            // Remove per-occurrence fields
            unset($base['endpoint']);

            $base['_count'] = 0;
            $base['_endpoints'] = [];

            $grouped[$key] = $base;
        }

        // Collect endpoints
        if (!empty($log['endpoint'])) {
            $grouped[$key]['_endpoints'][$log['endpoint']] = true;
        }

        $grouped[$key]['_count']++;
    }

    // Normalize endpoint list
    foreach ($grouped as &$group) {
        $group['_endpoints'] = array_keys($group['_endpoints']);
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

        // Normalize endpoints first
        if (!empty($log['_endpoints']) && is_array($log['_endpoints'])) {
            $endpoints = $log['_endpoints'];
        } elseif (!empty($log['endpoint'])) {
            $endpoints = [$log['endpoint']];
        } else {
            $endpoints = [];
        }

        // 🔴 Error container styling override
        $html = '<div style="
            border:1px solid #f1aeb5;
            border-left:6px solid #dc3545;
            border-radius:6px;
            padding:12px;
            margin-bottom:12px;
            background:#f8d7da;
        ">';

        $html .= '<strong style="color:#842029;">Backend Error</strong><br>';

        // 📍 Endpoints (grouped)
        if (!empty($endpoints)) {
            $html .= '<strong>Endpoints:</strong><br>';
            foreach ($endpoints as $ep) {
                $parts = explode(':', $ep, 2);
                $file = $parts[0];
                $line = $parts[1] ?? '';

                $html .= '• <code>' . htmlspecialchars($file) . '</code>';
                if ($line !== '') {
                    $html .= ' : <code>' . htmlspecialchars($line) . '</code>';
                }
                $html .= '<br>';
            }
        }

        // 📦 Response (single)
        if (!empty($log['response_body'])) {
            $json = json_decode($log['response_body'], true);
            $pretty = $json !== null
                ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $log['response_body'];

            $html .= '<div style="margin-top:10px;">
                <strong>Response:</strong>
                <pre style="
                    background:#f1f3f5;
                    color:#212529;
                    padding:12px;
                    border-radius:6px;
                    border:1px solid #ced4da;
                    font-family:Consolas,monospace;
                    font-size:13px;
                    line-height:1.5;
                    overflow-x:auto;
                    white-space:pre-wrap;
                    word-break:break-word;
                ">' . htmlspecialchars($pretty) . '</pre>
            </div>';
        }

        // 🟡 Occurrences (old UX preserved)
        if (!empty($log['_count']) && $log['_count'] > 1) {
            $extra = (int)$log['_count'] - 1;

            $html .= '<div style="
                margin-top:8px;
                padding:8px 12px;
                background:#fff3cd;
                border:1px solid #ffe69c;
                border-radius:6px;
                color:#664d03;
                font-size:13px;
            ">
                <strong>Occurrences:</strong> ' . (int)$log['_count'] . '<br>
                + ' . $extra . ' more occurrence' . ($extra > 1 ? 's' : '') . ' of the same error
            </div>';
        }

        // 🕒 Created at
        if (!empty($log['created_at'])) {
            $html .= '<div style="margin-top:6px;font-size:12px;color:#6c757d;">
                Created at: ' . htmlspecialchars($log['created_at']) . '
            </div>';
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
        SELECT session_id, iteration, remark_name, remark, created_at
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
    <title>QA Logger</title>
    <link rel="stylesheet" href="css/design.css">
    <style>
        body { font-family:sans-serif; padding:20px; max-width:900px; margin:auto; background:#f4f6f8; }
        .log-box { background:#fff; border:1px solid #ccc; border-radius:6px; padding:15px; margin-bottom:15px; }
        select, input[type=date] { padding:5px; margin-top:5px; }
        .header-buttons { display:flex; gap:10px; justify-content:flex-end; margin-bottom:15px; }
    </style>
</head>
<body>

<h1>QA Logger/Viewer</h1>
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
            <?php
            $label = $sessionNames[$sid]
                ?? str_replace('_',' ', $sid);
            ?>
            <option value="<?= htmlspecialchars($sid) ?>" <?= $sid === $selectedSession ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>
<?php endif; ?>

<?php if ($selectedProgram && $selectedSession): ?>
<div class="log-box" style="background:#fff7e6; margin-bottom:15px;">
    <form method="POST" style="display:flex; gap:8px; align-items:center;">
        <input type="hidden" name="program" value="<?= htmlspecialchars($selectedProgram) ?>">
        <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">

        <input
            type="text"
            name="rename_session"
            placeholder="Rename this session"
            maxlength="50"
            value="<?= htmlspecialchars($sessionNames[$selectedSession] ?? '') ?>"
            style="flex:1; padding:6px 8px;"
        >

        <button class="btn-black" type="submit">
            Rename
        </button>
    </form>
</div>
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
    // Show remark if exists
    $remarkName = $logsToShow[0]['_remark_name'] ?? '';
    $remarkText = $logsToShow[0]['_remark_text'] ?? '';
    ?>
    <?php if ($remarkName): ?>
        <div class="log-box" style="background:#eaf4ff;">
            <strong>Remark Name:</strong> <?= htmlspecialchars($remarkName) ?>
        </div>
    <?php endif; ?>
    <?php if ($remarkText): ?>
        <div class="log-box" style="background:#f9f9f9;">
            <strong>Remark:</strong><br>
            <?= nl2br(htmlspecialchars($remarkText)) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($logsToShow)): ?>
        <?php
        // Group backend-error logs before rendering
        $logsToRender = group_error_logs($logsToShow);
        ?>
        
        <?php foreach ($logsToRender as $log): ?>
            <?= render_log_entry($log) ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($selectedIteration) && !empty($logsToShow)): ?>
<div class="log-box" style="background:#f1f8ff;">
    <form method="POST">

        <h3 style="margin-top:0;">
            QA Remark – Iteration <?= htmlspecialchars($selectedIteration) ?>
        </h3>

        <input type="hidden" name="program" value="<?= htmlspecialchars($selectedProgram) ?>">
        <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">
        <input type="hidden" name="iteration" value="<?= htmlspecialchars($selectedIteration) ?>">

        <input
            type="text"
            name="remark_name"
            placeholder="Remark name (optional)"
            maxlength="20"
            style="
                padding:6px 8px;
                border:1px solid #ccc;
                border-radius:4px;
                width:100%;
                margin-bottom:8px;
            "
            value="<?= htmlspecialchars($remarkName ?? '') ?>"
        >

        <textarea
            name="remark"
            placeholder="Enter QA remarks here..."
            required
            style="
                width:100%;
                min-height:80px;
                padding:8px;
                border-radius:4px;
                border:1px solid #ccc;
            "
        ><?= htmlspecialchars($remarkText ?? '') ?></textarea>

        <br><br>
        <button class="btn-black" type="submit">
            Save Remark
        </button>
    </form>
</div>
<?php endif; ?>

<?php
$latestLog = ['session_id' => '', 'iteration' => 0];

if ($selectedProgram) {
    $stmt = $db->prepare("
        SELECT session_id, iteration
        FROM qa_logs
        WHERE program_name = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param('s', $selectedProgram);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        $latestLog = [
            'session_id' => $res['session_id'],
            'iteration' => (int)$res['iteration']
        ];
    }
}
?>

<script>
const selectedProgram = "<?= htmlspecialchars($selectedProgram) ?>";
const latestSessionOnLoad = "<?= htmlspecialchars($latestLog['session_id']) ?>";
const latestIterationOnLoad = <?= $latestLog['iteration'] ?>;

if (selectedProgram) {
    setInterval(async () => {
        try {
            const res = await fetch('iteration_logic/logger_iteration_status.php?program=' 
                + encodeURIComponent(selectedProgram), 
                { cache: 'no-store' });
            const data = await res.json();

            // Only redirect if a new iteration or session has been added after page load
            const hasNewIteration =
                data.latestIteration > latestIterationOnLoad
                || data.latestSession !== latestSessionOnLoad;

            if (data.active && hasNewIteration) {
                window.location.href = '<?= $_SERVER['PHP_SELF'] ?>'
                    + '?user=' + encodeURIComponent(selectedProgram)
                    + '&session=' + encodeURIComponent(data.latestSession)
                    + '&iteration=' + data.latestIteration
                    + '&from_date=<?= htmlspecialchars($fromDate) ?>'
                    + '&to_date=<?= htmlspecialchars($toDate) ?>';
            }

        } catch (e) {
            console.error('Polling error', e);
        }
    }, 2000);
}
</script>

</body>
</html>
