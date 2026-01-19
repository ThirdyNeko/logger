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
    ORDER BY iteration ASC, created_at ASC
");
$stmt->bind_param('ss', $userId, $sessionId);
$stmt->execute();
$result = $stmt->get_result();
$allLogs = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ==========================
   Group logs by iteration
========================== */
$grouped = [];
foreach ($allLogs as $log) {
    $id = $log['iteration'] ?? 'unknown';
    $grouped[$id][] = $log;
}
ksort($grouped);

/* ==========================
   Determine current iteration
========================== */
$currentIteration = $_GET['iteration'] ?? (count($grouped) ? array_key_last($grouped) : null);
$currentLogs = $currentIteration !== null && isset($grouped[$currentIteration])
    ? $grouped[$currentIteration]
    : [];

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

function is_error_log(array $log): bool
{
    return in_array($log['type'] ?? '', ['backend-error', 'backend-fatal'], true);
}

function group_error_logs(array $errorLogs): array
{
    $grouped = [];

    foreach ($errorLogs as $log) {
        $keyParts = [
            $log['message'] ?? '',
            $log['type'] ?? '',
            $log['endpoint'] ?? ''
        ];

        $key = md5(implode('|', $keyParts));

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
    $html = '<div style="
        border:1px solid #ddd;
        border-radius:4px;
        padding:10px;
        margin-bottom:10px;
        background:#fafafa;
    ">';

    if (!empty($log['iteration'])) {
        $html .= '<strong>Iteration:</strong> ' . htmlspecialchars($log['iteration']) . '<br>';
    }
    if (!empty($log['type'])) {
        $html .= '<strong>Type:</strong> ' . htmlspecialchars($log['type']) . '<br>';
    }
    if (!empty($log['endpoint'])) {
        $html .= '<strong>Endpoint:</strong> ' . htmlspecialchars($log['endpoint']) . '<br>';
    }
    if (!empty($log['method'])) {
        $html .= '<strong>Method:</strong> ' . htmlspecialchars($log['method']) . '<br>';
    }
    if (isset($log['status_code'])) {
        $html .= '<strong>Status:</strong> ' . (int)$log['status_code'] . '<br>';
    }

    if (!empty($log['request_body'])) {
        $html .= '<strong>Request:</strong><pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px 14px;
            margin:8px 0 12px 0;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family: Consolas, Monaco, \'Courier New\', monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' . format_log_value($log['request_body']) . '</pre>';
    }

    if (!empty($log['response_body'])) {
        $html .= '<strong>Response:</strong><pre style="
            background:#f8f9fa;
            color:#212529;
            padding:12px 14px;
            margin:8px 0 12px 0;
            border-radius:6px;
            border:1px solid #dee2e6;
            font-family: Consolas, Monaco, \'Courier New\', monospace;
            font-size:13px;
            line-height:1.5;
            overflow-x:auto;
            white-space:pre-wrap;
            word-break:break-word;
        ">' . format_log_value($log['response_body']) . '</pre>';
    }

    if (!empty($log['_count']) && $log['_count'] > 1) {
        $html .= '<strong>Occurrences:</strong> ' . (int)$log['_count'] . '<br>';
    }

    if (!empty($log['created_at'])) {
        $html .= '<strong>Created At:</strong> ' . htmlspecialchars($log['created_at']) . '<br>';
    }

    $html .= '</div>';
    return $html;
}

/* ==========================
   Prepare logs for rendering
========================== */
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

        <!-- Render logs for this iteration -->
        <?php
        foreach ($logsToRender as $log) {
            echo render_log_entry($log);
        }
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
