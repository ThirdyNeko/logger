<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/../auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../viewer_repo/remarks.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../repo/user_repo.php';
require_once __DIR__ . '/../viewer_repo/users.php';
require_once __DIR__ . '/../viewer_repo/viewer.php';
require_once __DIR__ . '/../viewer_repo/iterations.php';
require_once __DIR__ . '/../viewer_repo/programs.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$userRepo = new UserRepository(qa_db());
$userRow = $userRepo->findByUsername($_SESSION['user']['username']);

if (!$userRow) {
    session_destroy();
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$_SESSION['user']['first_login'] = (bool)$userRow['first_login'];
$db = qa_db();

/* ==========================
   INPUT STATE
========================== */
$selectedProgram   = $_GET['user'] ?? '';
$selectedSession   = $_GET['session'] ?? '';
$selectedIteration = $_GET['iteration'] ?? '';

/* ==========================
   CURRENT USER
========================== */
$username = $_SESSION['user']['username'] ?? '';
$userId   = $username ? getUserIdByUsername($db, $username) : null;

/* ==========================
   STORE QA REMARK (VIEWER)
========================== */
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
        saveQaRemark(
            $db,
            $userId,
            $username,
            $program,
            $sessionId,
            $iteration,
            $remarkName,
            $remark,
            $resolved = false
        );
    }

    header('Location: ' . $_SERVER['PHP_SELF']
        . '?user=' . urlencode($program)
        . '&session=' . urlencode($sessionId)
        . '&iteration=' . $iteration
    );
    exit;
}

/* ==========================
   LOAD ITERATIONS
========================== */
$iterations = [];
if ($selectedProgram && $selectedSession) {
    $iterations = getAllIterations($db, $selectedProgram, $selectedSession);
}

/* ==========================
   LOAD LOGS
========================== */
$logsToShow = [];
if ($selectedProgram && $selectedSession && $selectedIteration) {
    $logsToShow = loadLogsForViewer(
        $db,
        $selectedProgram,
        $selectedSession,
        $selectedIteration,
        null,       // branch
        null,       // userId
        null,       // clientIP
        []          // filteredRemarked MUST be an array
    );
}

/* ==========================
   PROGRAM LIST (for filters if needed)
========================== */
$programs = loadPrograms($db);

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
   ITERATION LIST FOR SELECTED SESSION
========================== */
$iterations = [];
if ($selectedSession && isset($filteredRemarked[$selectedSession])) {
    // Include iterations with remarks
    $iterations = array_keys($filteredRemarked[$selectedSession]);
}

/* ==========================
   ITERATIONS WITH ERRORS
========================== */
$iterations = [];

if ($selectedProgram && $selectedSession) {
    $iterations = getAllIterations(
        $db,
        $selectedProgram,
        $selectedSession,
        null,
        null,
        null,       // branch
        null,       // userId
        null,       // clientIP
    );

    $errorIterations = getErrorIterations(
        $db,
        $selectedProgram,
        $selectedSession,
        null,       // branch
        null,       // userId
        null,       // clientIP
    );

    // Ensure error iterations always appear
    foreach ($errorIterations as $iter => $_) {
        if (!in_array($iter, $iterations, true)) {
            $iterations[] = $iter;
        }
    }

    sort($iterations);
}

/* ==========================
   LOAD REMARKS
========================== */
$filteredRemarked = [];

if ($selectedProgram && $selectedSession) {
    $filteredRemarked = loadRemarksByProgram(
        $db,
        $selectedProgram,
        $selectedSession
    );
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>QA Logger – Iterations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { width: 260px; min-height: 100vh; background: #fff; flex-shrink: 0; }
        .sidebar .user-box { border-bottom: 1px solid #e5e5e5; padding-bottom: 1rem; margin-bottom: 1rem; }
        .clickable-row { cursor: pointer; }

        @media print {

            /* Hide sidebar */
            .sidebar {
                display: none !important;
            }

            /* Remove padding */
            main {
                padding: 0 !important;
            }

            /* Show print header */
            .print-header {
                display: block !important;
            }

            /* Improve spacing */
            body {
                background: white;
            }

        }
    </style>
</head>
<body>

<div class="d-flex vh-100">

    <!-- SIDEBAR -->
    <aside class="sidebar d-flex flex-column border-end p-3" style="height: 100vh;">
        <div class="user-box mb-3">
            <div class="fw-bold text-center text-uppercase">
                Hello <?= htmlspecialchars($_SESSION['user']['username']) ?>
            </div>
        </div>

        <!-- Top buttons -->
        <div class="d-grid gap-2">
            <a href="qa.php" class="btn btn-primary btn-sm">Back to Sessions</a>
            <a href="../profile.php" class="btn btn-outline-dark btn-sm">Profile</a>
            <button onclick="printLogs()" class="btn btn-outline-dark btn-sm">
                Print Activity Log
            </button>
        </div>

        <!-- Spacer pushes logout to the bottom -->
        <div class="mt-auto">
            <a href="../auth/logger_logout.php" class="btn btn-danger btn-sm w-100">Logout</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="flex-fill p-4 overflow-auto" style="height:100%;">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Activity Logs for Session: <?= htmlspecialchars($selectedSession) ?></h4>
        </div>


        <!-- Iteration Dropdown -->
        <div class="mb-3 d-flex align-items-center gap-2">
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="user" value="<?= htmlspecialchars($selectedProgram) ?>">
                <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">

                <label class="form-label"><strong>Activity Log:</strong></label>
                    <div class="dropdown">
                        <button class="btn btn-outline-dark dropdown-toggle w-100" type="button" id="iterationDropdown" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                            <?= $selectedIteration ? htmlspecialchars($selectedIteration) : '-- Select Activity Log --' ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-scroll w-100 text-wrap" aria-labelledby="iterationDropdown">
                            
                            <?php foreach ($iterations as $iter):
                                $remarkName = $filteredRemarked[$selectedSession][$iter]['name'] ?? '';
                                $hasError   = isset($errorIterations[$iter]);

                                $label = $iter;
                                if ($remarkName) $label .= ' - ' . $remarkName;
                                if ($hasError)   $label .= '⚠';
                            ?>
                            <li>
                                <a class="dropdown-item text-wrap <?= $hasError ? 'text-danger fw-semibold' : '' ?>"
                                    href="?user=<?= urlencode($selectedProgram) ?>&session=<?= urlencode($selectedSession) ?>&iteration=<?= urlencode($iter) ?>">
                                    <?= htmlspecialchars($label) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
            </form>
        </div>

        <?php
        // Get current remark data for this session & iteration
        $remarkData = $filteredRemarked[$selectedSession][$selectedIteration] ?? null;
        $hasRemark  = !empty($remarkData['remark']);
        ?>
        <?php if (!empty($selectedIteration)): ?>

            <?php if (!$hasRemark): ?>
                <!-- QA Remark Form -->
                <div class="card p-3 mt-2">
                    <form method="POST">
                        <input type="hidden" name="program" value="<?= htmlspecialchars($selectedProgram) ?>">
                        <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">
                        <input type="hidden" name="iteration" value="<?= htmlspecialchars($selectedIteration) ?>">

                        <input type="text" name="remark_name" class="form-control mb-2" placeholder="Remark name (optional)" maxlength="20" value="<?= htmlspecialchars($remarkData['name'] ?? '') ?>">

                        <textarea name="remark" class="form-control mb-2" placeholder="Enter QA remarks here..." required><?= htmlspecialchars($remarkData['remark'] ?? '') ?></textarea>

                        <button type="submit" class="btn btn-dark w-100">Save Remark</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
            $remarkData = $filteredRemarked[$selectedSession][$selectedIteration] ?? null;
            $hasRemark  = !empty($remarkData['remark']);
            $isResolved = $remarkData['resolved'] ?? false;
        ?>

        <?php if ($hasRemark): ?>
            <div class="card p-2 mb-2 text-start">
                <?php if ($isResolved): ?>
                    <!-- Resolved Badge -->
                    <span class="badge bg-success w-100 py-2">
                        ✅ Remark Resolved
                    </span>

                    <!-- Optional resolver comment -->
                    <?php if (!empty($remarkData['resolve_comment'])): ?>
                        <div class="mb-2">
                            <strong>Comment:</strong>
                            <div class="text-muted">
                                <?= nl2br(htmlspecialchars($remarkData['resolve_comment'])) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Resolved by and at -->
                    <small class="d-block text-muted">
                        By: <?= htmlspecialchars($remarkData['resolved_by'] ?? '---') ?> <br>
                        At: <?= htmlspecialchars($remarkData['resolved_at'] ?? '---') ?>
                    </small>
                <?php else: ?>
                    <!-- Pending Badge -->
                    <span class="badge bg-warning text-dark w-100 py-2">
                        ⏳ Remark Pending
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <br>

        <!-- Logs -->
        <div id="print-area">

        <div class="print-header d-none">
            <h4 class="mb-1">QA Logger Report</h4>
            <div class="small">
                Program: <?= htmlspecialchars($selectedProgram ?? '-') ?><br>
                Session: <?= htmlspecialchars($selectedSession ?? '-') ?><br>
                Activity Log: <?= htmlspecialchars($selectedIteration ?? '-') ?><br>
                Printed by: <?= htmlspecialchars($_SESSION['user']['username']) ?><br>
                Printed at: <?= date('Y-m-d H:i:s') ?>
            </div>
            <hr>
        </div>

        <?php if (!empty($logsToShow)): ?>
            <?php
            // Single iteration remark info
            $remarkEntry = $filteredRemarked[$selectedSession][$selectedIteration] ?? null;
            $remarkName = $remarkEntry['name'] ?? '';
            $remarkText = $remarkEntry['remark'] ?? '';
            $remarkUser = $remarkEntry['username'] ?? 'Unknown';
            ?>

            <?php if ($remarkName || $remarkText) : ?>
                <div class="card log-card bg-primary-subtle border-primary p-3 mb-2">
                    <strong>Remark Name:</strong> <?= htmlspecialchars($remarkName) ?><br>
                    <small>By: <?= htmlspecialchars($remarkUser) ?></small>
                </div>
            <?php endif; ?>

            <?php if ($remarkText) : ?>
                <div class="card log-card bg-light p-3 mb-2">
                    <strong>Remark:</strong><br>
                    <?= nl2br(htmlspecialchars($remarkText)) ?>
                </div>
            <?php endif; ?>
            <?php
            
                // Single iteration view
                $logsToRender = group_error_logs($logsToShow);
                foreach ($logsToRender as $log) {
                    echo render_log_entry($log);
                }
            ?>
            
        <?php endif; ?>
        </div>
    </main>
</div>

<!-- Bootstrap JS -->
<script src="../scripts/bootstrap.bundle.min.js"></script>

<script>
function printLogs() {
    const printContents = document.getElementById('print-area').innerHTML;
    const originalContents = document.body.innerHTML;

    document.body.innerHTML = printContents;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}
</script>

</body>
</html>
