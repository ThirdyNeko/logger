<?php
require_once __DIR__ . '/auth/require_login.php';
define('QA_SKIP_LOGGING', true);

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

/* ==========================
   SESSION STATE / REMARKS
========================== */

$qaState = qa_get_session_state();

// Save selection
if (isset($_GET['remark_iteration'])) {
    $qaState['remarks_iteration'] = $_GET['remark_iteration'];
    qa_save_session_state($qaState);
}

function qa_normalize_session_name(string $name): string
{
    return strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
}

function qa_load_session_names(): array
{
    $db = qa_db();
    $userId = qa_get_user_id();

    $stmt = $db->prepare("
        SELECT session_name
        FROM qa_sessions
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'session_name');
}

function qa_save_session_name(string $name): void
{
    $db = qa_db();
    $userId = qa_get_user_id();

    $stmt = $db->prepare("
        INSERT IGNORE INTO qa_sessions (user_id, session_name)
        VALUES (?, ?)
    ");
    $stmt->bind_param('is', $userId, $name);
    $stmt->execute();
}

// Restore selection
$selectedRemarksIteration = $qaState['remarks_iteration'] ?? '';

/* ==========================
   Handle new session
========================== */
if (isset($_POST['new_session'])) {
    $_SERVER['QA_SKIP_LOGGING'] = true;

    $rawName = trim($_POST['session_name'] ?? '');
    if ($rawName === '') {
        $rawName = 'Unnamed_Session';
    }

    $sessionName = qa_normalize_session_name($rawName);
    $existingNames = qa_load_session_names();

    if (in_array($sessionName, $existingNames, true)) {
        echo "<script>
            alert('⚠️ Session name already exists for your account. Please choose a different name.');
            window.history.back();
        </script>";
        exit;
    }

    qa_create_new_session($sessionName);
    qa_save_session_name($sessionName);

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;

} else {
    if (isset($_SERVER['QA_SKIP_LOGGING'])) {
        unset($_SERVER['QA_SKIP_LOGGING']);
    }
}

$status = qa_get_logging_status();
$sessionId = qa_get_session_id();
$sessionState = qa_get_session_state();
$currentSessionName = isset($sessionState['session_name'])
    ? str_replace('_', ' ', $sessionState['session_name'])
    : 'Unknown';

$userId = $_SESSION['user']['id'] ?? 'guest';

/* ==========================
   Load logs from database
========================== */
$db = qa_db(); // mysqli connection helper

$stmt = $db->prepare("
    SELECT *
    FROM qa_logs
    WHERE user_id = ? AND session_id = ?
    ORDER BY iteration_id ASC, timestamp ASC
");
$stmt->bind_param('si', $userId, $sessionId);
$stmt->execute();
$result = $stmt->get_result();
$allLogs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ==========================
   Group logs by iteration_id
========================== */
$grouped = [];
foreach ($allLogs as $log) {
    $id = $log['iteration_id'] ?? 'unknown';
    $grouped[$id][] = $log;
}
ksort($grouped);

/* ==========================
   Load remarked logs
========================== */
$REMARK_FILE = __DIR__ . "/logs/user_{$userId}/remarked_logs_{$sessionId}.json";
$remarked = file_exists($REMARK_FILE)
    ? json_decode(file_get_contents($REMARK_FILE), true)
    : [];

// Preserve selected iteration for dropdown
$selectedRemarkIteration = $_GET['remark_iteration'] ?? '';

// Determine current iteration to display
$currentIteration = $_GET['iteration']
    ?? (count($grouped) ? array_key_last($grouped) : null);
$currentLogs = $grouped[$currentIteration] ?? [];
$currentRemark = $remarked[$currentIteration]['remark'] ?? '';

/* ==========================
   HELPER FUNCTIONS
========================== */
function format_log_value($value)
{
    if (is_array($value) || is_object($value)) {
        return htmlspecialchars(
            json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    if ($value === null) {
        return '<em>null</em>';
    }

    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_severity(int $severity): string
{
    return match ($severity) {
        E_NOTICE, E_USER_NOTICE      => "NOTICE ($severity)",
        E_WARNING, E_USER_WARNING    => "WARNING ($severity)",
        E_ERROR, E_USER_ERROR        => "ERROR ($severity)",
        E_PARSE                     => "PARSE ERROR ($severity)",
        default                     => "SEVERITY $severity",
    };
}

function is_error_log(array $log): bool
{
    return in_array(
        $log['type'] ?? '',
        ['backend-error'],
        true
    );
}

function group_error_logs(array $errorLogs): array
{
    $grouped = [];

    foreach ($errorLogs as $log) {
        $keyParts = [
            $log['message']  ?? '',
            $log['severity'] ?? '',
            $log['file']     ?? ''
        ];

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
    $html = '<div style="
        border:1px solid #ddd;
        border-radius:4px;
        padding:10px;
        margin-bottom:10px;
        background:#fafafa;
    ">';

    if (!empty($log['type'])) {
        $html .= '<strong>Type:</strong> ' . htmlspecialchars($log['type']) . '<br>';
    }

    if (
        ($log['type'] ?? '') === 'backend-error'
        || ($log['type'] ?? '') === 'backend-exception'
        || ($log['type'] ?? '') === 'backend-fatal'
    ) {
        if (isset($log['severity'])) {
            $html .= '<strong>Severity:</strong> '
                . htmlspecialchars(format_severity($log['severity']))
                . '<br>';
        }

        if (!empty($log['message'])) {
            $html .= '<strong>Message:</strong> '
                . htmlspecialchars($log['message'])
                . '<br>';
        }

        if (!empty($log['file'])) {
            $html .= '<strong>File:</strong> '
                . htmlspecialchars($log['file'])
                . '<br>';
        }

        if (!empty($log['_lines'])) {
            $html .= '<strong>Line:</strong> '
                . htmlspecialchars(implode(', ', $log['_lines']))
                . '<br>';
        } elseif (!empty($log['line'])) {
            $html .= '<strong>Line:</strong> '
                . (int)$log['line']
                . '<br>';
        }
    }

    if (!empty($log['url'])) {
        $html .= '<strong>URL:</strong> ' . htmlspecialchars($log['url']) . '<br>';
    }

    if (!empty($log['request'])) {
        $html .= '<strong>Request:</strong><pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px 14px;
            margin:8px 0 12px 0;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family: Consolas, Monaco, \"Courier New\", monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' .
            format_log_value(
                is_string($log['request'])
                    ? $log['request']
                    : json_encode($log['request'], JSON_PRETTY_PRINT)
            ) .
            '</pre>';
    }

    if (!empty($log['response'])) {
        $html .= '<strong>Response:</strong><pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px 14px;
            margin:8px 0 12px 0;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family: Consolas, Monaco, \"Courier New\", monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' .
            format_log_value($log['response']) .
            '</pre>';
    }

    $html .= '</div>';
    return $html;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>QA Logger Viewer</title>
    <style>
        body { font-family: Arial; background:#f5f5f5; padding:20px; }
        .log-box { background:#fff; padding:15px; margin-bottom:20px; border-radius:6px; }
        pre { background:#111; color:#0f0; padding:10px; overflow:auto; }
        textarea { width:100%; height:60px; }
        button { padding:6px 12px; margin-top:5px; }
        select { padding:4px; margin-bottom:10px; }
    </style>
    <link rel="stylesheet" href="css/design.css">
</head>
<body>

<h1>QA Logger/Viewer</h1>


<form method="POST" style="margin-bottom:15px; display:flex; gap:10px; align-items:center;"
      onsubmit="return promptSessionName();">

    <input type="hidden" name="session_name" id="session_name_input">

    <!-- Start New Session -->


    <button
        class="btn-black"
        type="submit"
        name="new_session">
        Start New Session
    </button>

    <!-- Go to Session Viewer -->
    <button
        class="btn-white"
        type="button"
        onclick="window.location.href='log_session_viewer.php'">
        View Sessions
    </button>


    <div style = "align-self: right; margin-left: auto; display: flex; gap: 10px;">
        <button
            class="btn-white"
            type="button"
            onclick="window.location.href='auth/logger_logout.php'">
            Logout
        </button>

        <button
            class="btn-black"
            type="button"
            onclick="window.location.href='profile.php'">
            Profile
        </button>
    </div>


</form>

<script>
function promptSessionName() {
    const name = prompt(
        "Enter Session Name:\n\nExample:\n• Login QA\n• Checkout Bug\n• Regression Round 2"
    );

    if (name === null) {
        return false; // user cancelled
    }

    document.getElementById('session_name_input').value =
        qa_normalize_session_name_js(name);

    return true;
}

function qa_normalize_session_name_js(name) {
    return name.trim().replace(/\s+/g, ' ').toUpperCase();
}
</script>

<div style="
    margin-bottom:20px;
    border-radius:4px;
    font-size: 25px;
">
    <strong>Current Session:</strong><br>
    <?= htmlspecialchars($currentSessionName) ?>
</div>



<?php if ($status['warn40'] && !$status['warn50']): ?>
<div style="
    background:#fff3cd;
    border:1px solid #ffecb5;
    color:#664d03;
    padding:10px;
    margin-bottom:15px;
    border-radius:4px;
">
⚠️ <strong>Warning:</strong>
Iteration count is high (<?= $status['iteration'] ?>/50).
</div>
<?php endif; ?>

<?php if ($status['warn50']): ?>
<div style="
    background:#f8d7da;
    border:1px solid #f5c2c7;
    color:#842029;
    padding:10px;
    margin-bottom:15px;
    border-radius:4px;
">
🚨 <strong>Logging stopped.</strong><br>
Maximum logs limit (50) reached.<br>
Start a new session to resume logging.
</div>
<?php endif; ?>

<div style="margin-bottom:15px;color:#555;">
Current Log count:
<strong><?= (int)$status['iteration'] ?></strong>
| Logging status:
<strong><?= $status['active'] ? 'ACTIVE' : 'STOPPED' ?></strong>
</div>


<form method="GET">
    <label for="iteration">Select Activity Log:</label>
    <select name="iteration" id="iteration" onchange="this.form.submit()">
        <?php foreach ($grouped as $id => $_): ?>
        <option value="<?= htmlspecialchars($id) ?>" <?= $id == $currentIteration ? 'selected' : '' ?>>
            <?= htmlspecialchars($id) ?>
        </option>
        <?php endforeach; ?>
    </select>
</form>



<div class="log-box">

    <form method="POST">

        <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
            <h3 style="margin:0;">
                Activity Log ID: <?= htmlspecialchars($currentIteration) ?>
            </h3>

            <input
                type="text"
                name="remark_name"
                placeholder="Remark name: max 20 characters"
                required
                maxlength="20"
                style="
                    padding:6px 8px;
                    border:1px solid #ccc;
                    border-radius:4px;
                    min-width:220px;
                "
                value="<?= htmlspecialchars($remarked[$currentIteration]['name'] ?? '') ?>"
            >
        </div>

        <?php
        // logs rendering stays EXACTLY the same
        ?>

        <input
            type="hidden"
            name="iteration_id"
            value="<?= htmlspecialchars($currentIteration) ?>"
        >

        <label>Remarks:</label>
        <textarea
            name="remark"
            placeholder="Enter QA remarks here..."
            required
        ><?= htmlspecialchars($currentRemark) ?></textarea>

        <br>
        <button type="submit">Save Remark</button>

    </form>
</div>



    <?php
    // -------------------------------
    // Prepare logs for rendering
    // -------------------------------
    $errorLogsRaw = [];
    $normalLogs   = [];

    foreach ($currentLogs as $log) {
        if (is_error_log($log)) {
            $errorLogsRaw[] = $log;
        } else {
            $normalLogs[] = $log;
        }
    }

    // Group duplicate errors by message + severity + file
    $errorLogs = group_error_logs($errorLogsRaw);

    // Merge grouped errors with normal logs
    $logsToRender = array_merge($errorLogs, $normalLogs);
    ?>

    <?php foreach ($logsToRender as $log): ?>
        <?= render_log_entry($log) ?>
    <?php endforeach; ?>

</div>


<hr>

<h2>Saved / Remarked Logs</h2>

<?php if (!$remarked): ?>
<p>No remarked logs yet.</p>
<?php endif; ?>

<?php $remarkIds = array_keys($remarked); ?>

<form method="GET">
    <label for="remarkSelect"><strong>Select Activity Log:</strong></label>
    <select
        id="remarkSelect"
        onchange="filterRemarks(this.value)"
    >
        <option value="">-- Select Activity Log --</option>

        <?php foreach ($remarked as $rid => $entry): ?>
            <option
                value="<?= htmlspecialchars($rid) ?>"
                <?= ((string)$rid === (string)$currentIteration) ? 'selected' : '' ?>
            >
                <?= htmlspecialchars($rid) ?>
                <?php if (!empty($entry['name'])): ?>
                    - <?= htmlspecialchars($entry['name']) ?>
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>



<?php foreach ($remarked as $id => $entry): ?>
<div class="log-box remark-item" data-iteration="<?= htmlspecialchars($id) ?>" style="display:none;">
    <h3>Activity Log ID: <?= htmlspecialchars($id) ?></h3>

    <?php if (!empty($entry['name'])): ?>
        <strong>Remark Name:</strong>
        <p><?= htmlspecialchars($entry['name']) ?></p>
    <?php endif; ?>

    <strong>Remark:</strong>

    <p><?= nl2br(htmlspecialchars($entry['remark'])) ?></p>

    <?php
    /* ============================
       SPLIT LOGS
    ============================ */
    $errorLogsRaw = [];
    $normalLogs   = [];

    foreach ($entry['logs'] as $log) {
        if (is_error_log($log)) {
            $errorLogsRaw[] = $log;
        } else {
            $normalLogs[] = $log;
        }
    }

/* GROUP duplicate errors */
$errorLogs = group_error_logs($errorLogsRaw);


    /* ============================
       PAGINATION (ERROR LOGS ONLY)
    ============================ */
    $errorsPerPage = 10;
    $totalErrors  = count($errorLogs);
    $totalPages   = max(1, ceil($totalErrors / $errorsPerPage));

    $pageKey = 'error_page_' . $id;
    $currentPage = max(1, min(
        (int)($_GET[$pageKey] ?? 1),
        $totalPages
    ));

    $offset = ($currentPage - 1) * $errorsPerPage;
    $pagedErrors = array_slice($errorLogs, $offset, $errorsPerPage);
    ?>

    <?php if ($errorLogs): ?>
        <h4 style="color:#842029;margin-top:15px;">🚨 Error Logs</h4>

        <?php foreach ($pagedErrors as $elog): ?>
            <?= render_log_entry($elog) ?>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div style="margin-top:10px;">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a
                        href="?<?= http_build_query(array_merge($_GET, [$pageKey => $p])) ?>"
                        style="
                            margin-right:6px;
                            padding:4px 8px;
                            border:1px solid #ccc;
                            border-radius:4px;
                            text-decoration:none;
                            <?= $p == $currentPage ? 'background:#842029;color:#fff;' : '' ?>
                        "
                    >
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($normalLogs): ?>
        <h4 style="margin-top:15px;">📄 Other Logs</h4>
        <?php foreach ($normalLogs as $nlog): ?>
            <?= render_log_entry($nlog) ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <small>Saved at: <?= htmlspecialchars($entry['saved_at']) ?></small>
</div>
<?php endforeach; ?>

<script>
function filterRemarks(iterationId) {
    document.querySelectorAll('.remark-item').forEach(el => {
        el.style.display =
            el.dataset.iteration === iterationId ? 'block' : 'none';
    });
}
</script>

<script>
const CURRENT_ITERATION = <?= (int)$status['iteration'] ?>;
const LOGGING_ACTIVE = <?= $status['active'] ? 'true' : 'false' ?>;
</script>

<script>
let lastIteration = CURRENT_ITERATION;

// Poll every 2 seconds
if (LOGGING_ACTIVE) {
    setInterval(async () => {
        try {
            const res = await fetch('iteration_logic/logger_iteration_status.php', {
                cache: 'no-store'
            });
            const data = await res.json();

            if (data.iteration > lastIteration) {
                window.location.href =
                    '<?= $_SERVER['PHP_SELF'] ?>?iteration=' + data.iteration;
            }
        } catch (e) {
            // silently ignore
        }
    }, 2000);
}
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const selected = "<?= htmlspecialchars($selectedRemarksIteration) ?>";
    if (selected) {
        filterRemarks(selected);
    }
});
</script>

</body>

<style>
    body { font-family: Arial; background:#f5f5f5; padding:20px; }
    .log-box { background:#fff; padding:15px; margin-bottom:20px; border-radius:6px; }
    pre { background:#111; color:#0f0; padding:10px; overflow:auto; }
    textarea { width:100%; height:60px; }
    button { padding:6px 12px; margin-top:5px; }
    select { padding:4px; margin-bottom:10px; }
</style>


</html>
