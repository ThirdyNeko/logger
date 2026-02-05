<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';
require_once __DIR__ . '/viewer_repo/sessions.php';
require_once __DIR__ . '/viewer_repo/users.php';
require_once __DIR__ . '/viewer_repo/remarks.php';
require_once __DIR__ . '/viewer_repo/programs.php';
require_once __DIR__ . '/viewer_repo/logs.php';
require_once __DIR__ . '/viewer_repo/iterations.php';

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
   LOAD SESSION NAMES
========================== */
$sessionNames = [];

if ($selectedProgram) {
    $sessionNames = loadSessionNames($db, $selectedProgram);
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
========================== */
$programs = loadPrograms($db);

/* ==========================
   LOAD REMARKS (FILTERED BY USER)
========================== */
$remarked = [];

if ($selectedProgram) {
    $remarked = loadRemarksByProgram($db, $selectedProgram);
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
   LOAD LOGS FOR SELECTED ITERATION OR SUMMARY
========================== */
$logsToShow = [];

if ($selectedProgram && $selectedSession) {
    $logsToShow = loadLogs(
        $db,
        $selectedProgram,
        $selectedSession,
        $selectedIteration,      // integer or 'summary'
        $filteredRemarked        // optional, can be null
    );
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
$allIterations = getAllIterations($db, $selectedProgram); // <- no session filter
$errorIterations = getErrorIterations($db, $selectedProgram, $selectedSession);

foreach ($errorIterations as $iter => $_) {
    if (!in_array($iter, $allIterations, true)) {
        $allIterations[] = $iter;
    }
}

sort($allIterations);
$iterations = $allIterations;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Developer QA Viewer</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
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

    <!-- Session & Iteration Row (only if program selected) -->
    <?php if ($selectedProgram): ?>
    <div class="row g-2 mb-3">
        <?php
        // Fetch sessions
        $summaryMode = ($selectedIteration === 'summary');

        $result = loadSessionsSummary($db, $selectedProgram, $fromDate, $toDate, $summaryMode);

        $sessions = $result['sessions'];
        $sessionOwners = $result['sessionOwners'];
        ?>

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
        </div>

        <?php if ($selectedSession && $iterations): ?>
        <div class="col-md-6">
            <label class="form-label"><strong>Iteration:</strong></label>
            <div class="dropdown">
                <button class="btn btn-outline-dark dropdown-toggle w-100" type="button" id="iterationDropdown" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                    <?= $selectedIteration ? htmlspecialchars($selectedIteration) : '-- Select Iteration --' ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-scroll w-100" aria-labelledby="iterationDropdown">
                    <!-- Session Summary Option -->
                    <li>
                        <a class="dropdown-item <?= $selectedIteration === 'summary' ? 'text-primary fw-semibold' : '' ?>"
                        href="?user=<?= htmlspecialchars($selectedProgram) ?>&session=<?= htmlspecialchars($selectedSession) ?>&iteration=summary&from_date=<?= htmlspecialchars($fromDate) ?>&to_date=<?= htmlspecialchars($toDate) ?>">
                            Session Summary
                        </a>
                    </li>
                    
                    <?php foreach ($iterations as $iter):
                        $remarkName = $filteredRemarked[$selectedSession][$iter]['name'] ?? '';
                        $hasError   = isset($errorIterations[$iter]);

                        $label = $iter;
                        if ($remarkName) $label .= ' - ' . $remarkName;
                        if ($hasError)   $label .= ' - ⚠ Error';
                    ?>
                    <li>
                        <a class="dropdown-item <?= $hasError ? 'text-danger fw-semibold' : '' ?>" 
                        href="?user=<?= htmlspecialchars($selectedProgram) ?>&session=<?= htmlspecialchars($selectedSession) ?>&iteration=<?= $iter ?>&from_date=<?= htmlspecialchars($fromDate) ?>&to_date=<?= htmlspecialchars($toDate) ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <hr>

    <!-- Logs -->
    <?php if (!empty($logsToShow)): ?>
        <?php
        $remarkName = $logsToShow[0]['_remark_name'] ?? '';
        $remarkText = $logsToShow[0]['_remark_text'] ?? '';
        $remarkUser = $filteredRemarked[$selectedSession][$selectedIteration]['username'] ?? 'Unknown';
        ?>
        <?php if ($remarkName || $remarkText): ?>
            <div class="card log-card bg-primary-subtle border-primary p-3 mb-2">
                <strong>Remark Name:</strong> <?= htmlspecialchars($remarkName) ?><br>
                <small>By: <?= htmlspecialchars($remarkUser) ?></small>
            </div>
        <?php endif; ?>
        <?php if ($remarkText): ?>
            <div class="card log-card bg-light p-3 mb-2">
                <strong>Remark:</strong><br>
                <?= nl2br(htmlspecialchars($remarkText)) ?>
            </div>
        <?php endif; ?>

        <?php
        if ($selectedIteration === 'summary') {
            // Group logs by iteration
            $logsByIteration = [];

            foreach ($logsToShow as $log) {
                $iter = $log['iteration'] ?? 0;

                // Only include if it's an error
                if (!is_error_log($log)) continue;

                if (!isset($logsByIteration[$iter])) $logsByIteration[$iter] = [];
                $logsByIteration[$iter][] = $log;
            }

            // Add iterations that have remarks but no errors
            foreach ($filteredRemarked[$selectedSession] ?? [] as $iter => $remark) {
                if (!isset($logsByIteration[$iter])) {
                    $logsByIteration[$iter] = [];
                }
            }

            // Render each iteration
            foreach ($logsByIteration as $iter => $logs) {
                echo '<h5 class="mt-3">Iteration ' . htmlspecialchars($iter) . '</h5>';

                // Show remark if exists
                $remarkEntry = $filteredRemarked[$selectedSession][$iter] ?? null;
                if ($remarkEntry) {
                    echo '<div class="card log-card bg-primary-subtle border-primary p-3 mb-2">';
                    echo '<strong>Remark Name:</strong> ' . htmlspecialchars($remarkEntry['name']) . '<br>';
                    echo '<small>By: ' . htmlspecialchars($remarkEntry['username'] ?? 'Unknown') . '</small>';
                    echo '</div>';

                    if (!empty($remarkEntry['remark'])) {
                        echo '<div class="card log-card bg-light p-3 mb-2">';
                        echo '<strong>Remark:</strong><br>' . nl2br(htmlspecialchars($remarkEntry['remark']));
                        echo '</div>';
                    }
                }

                // Show errors
                $logsToRender = group_error_logs($logs);
                foreach ($logsToRender as $log) {
                    echo render_log_entry($log);
                }
            }

        } else {
            // Single iteration view

            $errorLogs = [];
            $normalLogs = [];

            foreach ($logsToShow as $log) {
                if (is_error_log($log)) {
                    $errorLogs[] = $log;
                } else {
                    $normalLogs[] = $log;
                }
            }

            // 1️⃣ Render normal logs AS-IS (no grouping)
            foreach ($normalLogs as $log) {
                echo render_log_entry($log);
            }

            // 2️⃣ Render grouped backend errors ONLY
            $groupedErrors = group_error_logs($errorLogs);
            foreach ($groupedErrors as $log) {
                echo render_log_entry($log);
            }
        }
        ?>
    <?php endif; ?>

</div>

<!-- Bootstrap JS Bundle -->
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
$latestLog = [];

if ($selectedProgram) {
    $latestLog = getLatestLog($db, $selectedProgram);
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


