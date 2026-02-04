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
    $sql = "
        SELECT TOP 1 session_id
        FROM qa_logs
        WHERE program_name = :program_name
        ORDER BY created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $selectedProgram]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $latestSessionByProgram[$selectedProgram] = $row['session_id'];
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
    $sql = "SELECT TOP 1 id FROM users WHERE username = :username";
    $stmt = $db->prepare($sql);
    $stmt->execute([':username' => $username]);

    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $userId = $userRow['id'];
    }
}


/* ==========================
   RENAME SESSION
========================= */
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
        // MSSQL equivalent of "INSERT ... ON DUPLICATE KEY UPDATE"
        $tsql = "
            MERGE qa_session_names AS target
            USING (SELECT ? AS program_name, ? AS session_id, ? AS session_name) AS source
            ON target.program_name = source.program_name AND target.session_id = source.session_id
            WHEN MATCHED THEN
                UPDATE SET session_name = source.session_name
            WHEN NOT MATCHED THEN
                INSERT (program_name, session_id, session_name)
                VALUES (source.program_name, source.session_id, source.session_name);
        ";

        $params = [$program, $sessionId, $name];
        $stmt = sqlsrv_query($db, $tsql, $params);

        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }

        sqlsrv_free_stmt($stmt);

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
    $iteration = (int) ($_POST['iteration'] ?? 0);

    $remark     = trim($_POST['remark']);
    $remarkName = trim($_POST['remark_name'] ?? '');

    if ($userId && $username && $program && $sessionId && $remark !== '') {
        $sql = "
            MERGE qa_remarks AS target
            USING (
                SELECT
                    :user_id AS user_id,
                    :username AS username,
                    :program_name AS program_name,
                    :session_id AS session_id,
                    :iteration AS iteration,
                    :remark_name AS remark_name,
                    :remark AS remark
            ) AS source
            ON target.user_id = source.user_id
               AND target.program_name = source.program_name
               AND target.session_id = source.session_id
               AND target.iteration = source.iteration
            WHEN MATCHED THEN
                UPDATE SET
                    remark_name = source.remark_name,
                    remark = source.remark,
                    username = source.username
            WHEN NOT MATCHED THEN
                INSERT (user_id, username, program_name, session_id, iteration, remark_name, remark)
                VALUES (source.user_id, source.username, source.program_name, source.session_id, source.iteration, source.remark_name, source.remark);
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id'      => $userId,
            ':username'     => $username,
            ':program_name' => $program,
            ':session_id'   => $sessionId,
            ':iteration'    => $iteration,
            ':remark_name'  => $remarkName,
            ':remark'       => $remark
        ]);
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
========================= */
$sessionNames = [];

if ($selectedProgram) {
    $sql = "
        SELECT session_id, session_name
        FROM qa_session_names
        WHERE program_name = :program_name
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $selectedProgram]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sessionNames[$row['session_id']] = $row['session_name'];
    }
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

    // Normalize endpoints
    $endpoints = !empty($log['_endpoints']) && is_array($log['_endpoints'])
        ? $log['_endpoints']
        : (!empty($log['endpoint']) ? [$log['endpoint']] : []);

    // Determine card style
    $cardClass = 'bg-light border';
    if ($type === 'backend-error') {
        $cardClass = 'bg-danger-subtle border-danger';
    }

    $html = '<div class="card mb-3 ' . $cardClass . '">';
    $html .= '<div class="card-body p-3">';

    // Card title
    $html .= '<h6 class="card-title mb-2">' . 
             ($type === 'backend-error' 
                 ? '<span class="text-danger">Backend Error</span>' 
                 : htmlspecialchars($type)) . 
             '</h6>';

    // Endpoints
    if (!empty($endpoints)) {
        $html .= '<p class="mb-2"><strong>Endpoints:</strong><br>';
        foreach ($endpoints as $ep) {
            [$file, $line] = array_pad(explode(':', $ep, 2), 2, '');
            $html .= '• <code>' . htmlspecialchars($file) . '</code>';
            if ($line !== '') $html .= ' : <code>' . htmlspecialchars($line) . '</code>';
            $html .= '<br>';
        }
        $html .= '</p>';
    }

    // Request body
    if (!empty($log['request_body'])) {
        $json = json_decode($log['request_body'], true);
        $pretty = $json !== null
            ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $log['request_body'];
        $html .= '<p class="mb-2"><strong>Request:</strong><pre class="p-2 bg-white border rounded" style="overflow-x:auto;">' 
                 . htmlspecialchars($pretty) . '</pre></p>';
    }

    // Response body
    if (!empty($log['response_body'])) {
        $json = json_decode($log['response_body'], true);
        $pretty = $json !== null
            ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $log['response_body'];
        $html .= '<p class="mb-2"><strong>Response:</strong><pre class="p-2 bg-white border rounded" style="overflow-x:auto;">' 
                 . htmlspecialchars($pretty) . '</pre></p>';
    }

    // Iteration / Method / Status
    if (!in_array($type, ['frontend-io', 'backend-response'], true)) {
        if (!empty($log['iteration'])) $html .= '<p class="mb-1"><strong>Iteration:</strong> ' . htmlspecialchars($log['iteration']) . '</p>';
        if (!empty($log['method'])) $html .= '<p class="mb-1"><strong>Method:</strong> ' . htmlspecialchars($log['method']) . '</p>';
        if (isset($log['status_code'])) $html .= '<p class="mb-1"><strong>Status:</strong> ' . (int)$log['status_code'] . '</p>';
    }

    // Occurrences
    if ($type === 'backend-error' && !empty($log['_count']) && $log['_count'] > 1) {
        $extra = (int)$log['_count'] - 1;
        $html .= '<div class="alert alert-warning p-2 mt-2 mb-2" role="alert">
                    <strong>Occurrences:</strong> ' . (int)$log['_count'] . '<br>
                    + ' . $extra . ' more occurrence' . ($extra > 1 ? 's' : '') . '
                </div>';
    }

    // Created At
    if (!empty($log['created_at'])) {
        $html .= '<p class="text-muted small mb-0">Created at: ' . htmlspecialchars($log['created_at']) . '</p>';
    }

    $html .= '</div></div>';

    return $html;
}

/* ==========================
   PROGRAM LIST (FROM LOGS)
========================= */

$programs = [];

$sql = "
    SELECT DISTINCT program_name
    FROM qa_logs
    WHERE program_name IS NOT NULL
    ORDER BY program_name ASC
";

$stmt = $db->prepare($sql);
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $programs[$row['program_name']] =
        $row['program_name'] ?: 'Unknown Program (' . $row['program_name'] . ')';
}

/* ==========================
   LOAD REMARKS (FILTERED BY USER)
========================= */
$remarked = [];

if ($selectedProgram) {
    $sql = "
        SELECT session_id, iteration, remark_name, remark, created_at
        FROM qa_remarks
        WHERE program_name = :program_name
        ORDER BY created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $selectedProgram]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sid  = $row['session_id'];
        $iter = (int) $row['iteration'];

        $remarked[$sid][$iter] = [
            'name'   => $row['remark_name'],
            'remark' => $row['remark'],
            'ctime'  => strtotime($row['created_at'])
        ];
    }
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
========================= */
$logsToShow = [];

if ($selectedProgram && $selectedSession && $selectedIteration !== '') {
    $sql = "
        SELECT *
        FROM qa_logs
        WHERE program_name = :program_name
          AND session_id = :session_id
          AND iteration = :iteration
        ORDER BY created_at ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':program_name' => $selectedProgram,
        ':session_id'   => $selectedSession,
        ':iteration'    => (int) $selectedIteration
    ]);

    $logsToShow = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

/* ==========================
   ITERATIONS WITH ERRORS
========================= */
$errorIterations = [];

if ($selectedProgram && $selectedSession) {
    $sql = "
        SELECT DISTINCT iteration
        FROM qa_logs
        WHERE program_name = :program_name
          AND session_id = :session_id
          AND type IN ('backend-error', 'backend-fatal')
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':program_name' => $selectedProgram,
        ':session_id'   => $selectedSession
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $errorIterations[(int) $row['iteration']] = true;
    }
}

// Also include iterations without remarks
$sql = "
    SELECT DISTINCT iteration
    FROM qa_logs
    WHERE program_name = :program_name
      AND session_id = :session_id
      AND (
            (:from_date_empty = '' OR :to_date_empty = '')
            OR CONVERT(DATE, created_at) BETWEEN :from_date_val AND :to_date_val
          )
    ORDER BY iteration ASC
";

$stmt = $db->prepare($sql);
$stmt->execute([
    ':program_name'   => $selectedProgram,
    ':session_id'     => $selectedSession,
    ':from_date_empty'=> $fromDate,
    ':to_date_empty'  => $toDate,
    ':from_date_val'  => $fromDate,
    ':to_date_val'    => $toDate
]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $iter = (int) $row['iteration'];
    if (!in_array($iter, $iterations, true)) {
        $iterations[] = $iter;
    }
}

sort($iterations);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QA Viewer</title>

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

    <h1 class="text-center mb-3">QA Viewer</h1>
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
        $sessionOwners = []; // session_id => username

        if ($fromDate && $toDate) {
            $sql = "
                SELECT DISTINCT session_id, user_id
                FROM qa_logs
                WHERE program_name = :program_name
                AND CONVERT(DATE, created_at) BETWEEN :from_date AND :to_date
                ORDER BY user_id ASC
            ";
            $params = [
                ':program_name' => $selectedProgram,
                ':from_date'    => $fromDate,
                ':to_date'      => $toDate
            ];
        } else {
            $sql = "
                SELECT DISTINCT session_id, user_id
                FROM qa_logs
                WHERE program_name = :program_name
                ORDER BY user_id ASC
            ";
            $params = [
                ':program_name' => $selectedProgram
            ];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sessions[] = $row['session_id'];
            $sessionOwners[$row['session_id']] = $row['user_id'] ?? 'Unknown';
        }
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
                        $sessionName = $sessionNames[$sid] ?? str_replace('_',' ',$sid);
                        $ownerName = $sessionOwners[$sid] ?? 'Unknown';
                        $label = $ownerName . ' - ' . $sessionName;
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
                        $hasError   = isset($errorIterations[$iter]);

                        $label = $iter;
                        if ($remarkName) $label .= ' - ' . $remarkName;
                        if ($hasError)   $label .= ' - ⚠ Error';
                    ?>
                    <li>
                        <a class="dropdown-item <?= $hasError ? 'text-danger fw-semibold' : '' ?>" href="?user=<?= htmlspecialchars($selectedProgram) ?>&session=<?= htmlspecialchars($selectedSession) ?>&iteration=<?= $iter ?>&from_date=<?= htmlspecialchars($fromDate) ?>&to_date=<?= htmlspecialchars($toDate) ?> ">
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

    <div class="log-container">
    <?php if (!empty($logsToShow)):

        $errorLogs  = [];
        $normalLogs = [];

        foreach ($logsToShow as $log) {
            if (is_error_log($log)) {
                $errorLogs[] = $log;
            } else {
                $normalLogs[] = $log;
            }
        }

        // 1️⃣ Render normal logs AS-IS
        foreach ($normalLogs as $log) {
            echo render_log_entry($log);
        }

        // 2️⃣ Render grouped backend errors ONLY
        $groupedErrors = group_error_logs($errorLogs);
        foreach ($groupedErrors as $log) {
            echo render_log_entry($log);
        }

    endif; ?>
    </div>

</div>

<!-- Bootstrap JS -->
<script src="scripts/bootstrap.bundle.min.js"></script>

<script>
function updateDate(type, value) {
    const params = new URLSearchParams(window.location.search);

    if (type === 'from') params.set('from_date', value);
    if (type === 'to') params.set('to_date', value);

    // Keep the current program/session/iteration in URL
    if ('<?= $selectedProgram ?>') params.set('user', '<?= htmlspecialchars($selectedProgram) ?>');
    if ('<?= $selectedSession ?>') params.set('session', '<?= htmlspecialchars($selectedSession) ?>');
    if ('<?= $selectedIteration ?>') params.set('iteration', '<?= htmlspecialchars($selectedIteration) ?>');

    // Redirect to same page with new date params
    window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?' + params.toString();
}
</script>

<?php
$latestLog = ['session_id' => '', 'iteration' => 0];

if ($selectedProgram) {
    $sql = "
        SELECT TOP 1 session_id, iteration
        FROM qa_logs
        WHERE program_name = :program_name
        ORDER BY created_at DESC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':program_name' => $selectedProgram]);

    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($res) {
        $latestLog = [
            'session_id' => $res['session_id'],
            'iteration'  => (int)$res['iteration']
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
