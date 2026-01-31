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


$latestSessionByProgram = [];

if (!empty($selectedProgram)) {
    $stmt = $db->prepare("
        SELECT session_id
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
        $latestSessionByProgram[$selectedProgram] = $res['session_id'];
    }
}

$isActiveSession =
    $selectedProgram
    && $selectedSession
    && isset($latestSessionByProgram[$selectedProgram])
    && $selectedSession === $latestSessionByProgram[$selectedProgram];


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

    // 🔒 Only allow rename if this is the latest session for that program
    if ($name !== ''
        && isset($latestSessionByProgram[$program])
        && $sessionId === $latestSessionByProgram[$program]
    ) {
        $stmt = $db->prepare("
            INSERT INTO qa_session_names (program_name, session_id, session_name)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE session_name = VALUES(session_name)
        ");
        $stmt->bind_param('sss', $program, $sessionId, $name);
        $stmt->execute();
        $stmt->close();

        // ✅ Update local array so dropdown shows new name immediately
        $sessionNames[$sessionId] = $name;
    }

    // Keep the redirect — optional, ensures page reload
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
    // 🔁 Normalize endpoints (ALWAYS)
    $endpoints = [];

    if (!empty($log['_endpoints']) && is_array($log['_endpoints'])) {
        $endpoints = $log['_endpoints'];
    } elseif (!empty($log['endpoint'])) {
        $endpoints = [$log['endpoint']];
    }
    $html = '<div style="
        border:1px solid #ddd;
        border-radius:4px;
        padding:10px;
        margin-bottom:10px;
        background:#fafafa;
    ">';

    // Always show type
    $html .= '<strong>Type:</strong> ' . htmlspecialchars($type) . '<br>';

    // 📍 Endpoints (always shown)
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

    // --- Backend Error Styling ---
    if ($type === 'backend-error') {


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

        // 📍 Endpoints (always shown — preserved)
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
    WHERE program_name = ?
    AND session_id = ?
    AND (
            (? = '' OR ? = '')
            OR DATE(created_at) BETWEEN ? AND ?
        )
    ORDER BY iteration ASC
");
$stmt->bind_param(
    'ssssss',
    $selectedProgram,
    $selectedSession,
    $fromDate,
    $toDate,
    $fromDate,
    $toDate
);
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Developer QA Viewer</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="bootstrap-icons/font/bootstrap-icons.min.css">

    <style>
        .header-buttons { display:flex; gap:10px; justify-content:flex-end; margin-bottom:15px; }
        .log-card { margin-bottom:15px; }
        .dropdown-menu-scroll { max-height: 200px; overflow-y: auto; }
    </style>
</head>
<body class="bg-light">

<div class="container py-4">

    <h1 class="text-center mb-3">Developer QA Viewer</h1>
    <hr>

    <!-- Header Buttons -->
    <div class="header-buttons mb-3">
        <button class="btn btn-outline-dark" type="button" onclick="window.location.href='auth/logger_logout.php'">Logout</button>
        <button class="btn btn-dark" type="button" onclick="window.location.href='profile.php'">Profile</button>
    </div>

    <!-- Program & Date Row -->
    <div class="row g-2 mb-3">
        <?php if (!empty($programs)): ?>
        <div class="col-md-4">
            <label class="form-label"><strong>Program:</strong></label>
            <div class="dropdown">
                <button class="btn btn-outline-dark dropdown-toggle w-100" type="button" id="programDropdown" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                    <?= $selectedProgram ? htmlspecialchars($selectedProgram) : '-- Select Program --' ?>
                </button>
                <ul class="dropdown-menu w-100" aria-labelledby="programDropdown">
                    <?php foreach ($programs as $programId => $programName): ?>
                        <li>
                            <a class="dropdown-item" href="?user=<?= htmlspecialchars($programId) ?>&from_date=<?= htmlspecialchars($fromDate) ?>&to_date=<?= htmlspecialchars($toDate) ?>">
                                <?= htmlspecialchars($programName) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-md-4">
            <label class="form-label">From:</label>
            <input type="date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>" onchange="updateDate('from', this.value)">
        </div>
        <div class="col-md-4">
            <label class="form-label">To:</label>
            <input type="date" class="form-control" value="<?= htmlspecialchars($toDate) ?>" onchange="updateDate('to', this.value)">
        </div>
    </div>

    <!-- Session & Iteration Row -->
    <?php if ($selectedProgram): ?>
    <div class="row g-2 mb-3">
        <?php
        // Fetch sessions
        $sessions = [];
        if ($fromDate && $toDate) {
            $stmt = $db->prepare("SELECT DISTINCT session_id FROM qa_logs WHERE program_name=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY session_id ASC");
            $stmt->bind_param('sss', $selectedProgram, $fromDate, $toDate);
        } else {
            $stmt = $db->prepare("SELECT DISTINCT session_id FROM qa_logs WHERE program_name=? ORDER BY session_id ASC");
            $stmt->bind_param('s', $selectedProgram);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $sessions[] = $row['session_id']; }
        $stmt->close();
        ?>

        <!-- Session Dropdown -->
        <div class="col-md-6">
            <label class="form-label"><strong>Session:</strong></label>
            <div class="dropdown">
                <button class="btn btn-outline-dark dropdown-toggle w-100" type="button" id="sessionDropdown" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                    <?= $selectedSession ? htmlspecialchars($sessionNames[$selectedSession] ?? $selectedSession) : '-- Select Session --' ?>
                </button>
                <ul class="dropdown-menu w-100" aria-labelledby="sessionDropdown">
                    <?php foreach ($sessions as $sid):
                        $label = $sessionNames[$sid] ?? str_replace('_',' ',$sid);
                    ?>
                    <li>
                        <a class="dropdown-item" href="?user=<?= htmlspecialchars($selectedProgram) ?>&session=<?= htmlspecialchars($sid) ?>&from_date=<?= htmlspecialchars($fromDate) ?>&to_date=<?= htmlspecialchars($toDate) ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Session Rename (only if session selected) -->
            <?php if ($selectedSession): ?>
                <div class="card p-3 mt-2">
                    <?php if ($isActiveSession): ?>
                        <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="program" value="<?= htmlspecialchars($selectedProgram) ?>">
                            <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">
                            <input type="text" name="rename_session" class="form-control" placeholder="Rename active session" value="<?= htmlspecialchars($sessionNames[$selectedSession] ?? '') ?>">
                            <button type="submit" class="btn btn-dark">Rename</button>
                        </form>
                    <?php else: ?>
                        <small>🔒 Only the active session can be renamed</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Iteration Dropdown -->
        <?php if ($selectedSession && $iterations): ?>
        <div class="col-md-6">
            <label class="form-label"><strong>Iteration:</strong></label>
            <div class="dropdown">
                <button class="btn btn-outline-dark dropdown-toggle w-100" type="button" id="iterationDropdown" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                    <?= $selectedIteration ? htmlspecialchars($selectedIteration) : '-- Select Iteration --' ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-scroll w-100" aria-labelledby="iterationDropdown">
                    <?php foreach ($iterations as $iter):
                        $remarkName = $filteredRemarked[$selectedSession][$iter]['name'] ?? '';
                        $label = $iter . ($remarkName ? ' - ' . $remarkName : '');
                    ?>
                    <li>
                        <a class="dropdown-item" href="?user=<?= htmlspecialchars($selectedProgram) ?>&session=<?= htmlspecialchars($selectedSession) ?>&iteration=<?= $iter ?>&from_date=<?= htmlspecialchars($fromDate) ?>&to_date=<?= htmlspecialchars($toDate) ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- QA Remark Form -->
            <?php if ($selectedIteration): ?>
                <div class="card p-3 mt-2">
                    <form method="POST">
                        <input type="hidden" name="program" value="<?= htmlspecialchars($selectedProgram) ?>">
                        <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">
                        <input type="hidden" name="iteration" value="<?= htmlspecialchars($selectedIteration) ?>">

                        <input type="text" name="remark_name" class="form-control mb-2" placeholder="Remark name (optional)" maxlength="20" value="<?= htmlspecialchars($filteredRemarked[$selectedSession][$selectedIteration]['name'] ?? '') ?>">

                        <textarea name="remark" class="form-control mb-2" placeholder="Enter QA remarks here..." required><?= htmlspecialchars($filteredRemarked[$selectedSession][$selectedIteration]['text'] ?? '') ?></textarea>

                        <button type="submit" class="btn btn-dark w-100">Save Remark</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <hr>

    <!-- Logs -->
    <div class="log-container">
        <?php if (!empty($logsToShow)):
            $logsToRender = group_error_logs($logsToShow);
            foreach ($logsToRender as $log):
                echo render_log_entry($log);
            endforeach;
        endif; ?>
    </div>

</div>

<!-- Bootstrap JS -->
<script src="scripts/bootstrap.bundle.min.js"></script>

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
