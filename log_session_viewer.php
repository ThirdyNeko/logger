<?php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/iteration_logic/qa_iteration_helper.php';

$sessionId = qa_get_session_id();

/* ============================
   LOG RENDER HELPERS (INLINE)
============================ */

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
        border-radius:6px;
        padding:12px;
        margin:10px 0;
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
        if (!empty($log['severity'])) {
            $html .= '<strong>Severity:</strong> ' . htmlspecialchars(format_severity($log['severity'])) . '<br>';
        }
        if (!empty($log['message'])) {
            $html .= '<strong>Message:</strong> ' . htmlspecialchars($log['message']) . '<br>';
        }
        if (!empty($log['file'])) {
            $html .= '<strong>File:</strong> ' . htmlspecialchars($log['file']) . '<br>';
        }
        if (!empty($log['_lines'])) {
            $html .= '<strong>Line:</strong> ' . htmlspecialchars(implode(', ', $log['_lines'])) . '<br>';
        } elseif (!empty($log['line'])) {
            $html .= '<strong>Line:</strong> ' . (int)$log['line'] . '<br>';
        }
    }

    if (!empty($log['url'])) {
        $html .= '<strong>URL:</strong> ' . htmlspecialchars($log['url']) . '<br>';
    }

    if (!empty($log['request'])) {
        $html .= '<strong>Request:</strong><pre>' . format_log_value($log['request']) . '</pre>';
    }

    if (!empty($log['response'])) {
        $html .= '<strong>Response:</strong><pre>' . format_log_value($log['response']) . '</pre>';
    }

    $html .= '</div>';
    return $html;
}

/* ============================
   FIND PREVIOUS SESSIONS (BY DATE)
============================ */

$remarkFiles = glob(__DIR__ . '/logs/remarked_logs_*.json');

$sessionsByDate = [];

foreach ($remarkFiles as $file) {
    $sid = str_replace(['remarked_logs_', '.json'], '', basename($file));

    if ($sid === $sessionId) {
        continue;
    }

    $createdAt = filectime($file);
    $dateKey   = date('Y-m-d', $createdAt);

    $sessionsByDate[$dateKey][$sid] = [
        'file' => $file,
        'ctime' => $createdAt,
    ];
}

$fromDate = $_GET['from_date'] ?? null;
$toDate   = $_GET['to_date'] ?? null;

$filteredSessionsByDate = [];

if ($fromDate && $toDate) {
    foreach ($sessionsByDate as $date => $sessions) {
        if ($date >= $fromDate && $date <= $toDate) {
            $filteredSessionsByDate[$date] = $sessions;
        }
    }
}

/* Sort dates DESC, sessions DESC */
krsort($filteredSessionsByDate);
foreach ($filteredSessionsByDate as &$sessions) {
    uksort($sessions, fn($a, $b) => strcmp($b, $a));
}
unset($sessions);

$selectedDate      = $_GET['date'] ?? '';
$selectedSession   = $_GET['session'] ?? '';
$selectedIteration = $_GET['iteration'] ?? '';

$remarks    = [];
$iterations = [];

if ($fromDate && $toDate && $selectedDate && $selectedSession) {
    $sessionMeta = $filteredSessionsByDate[$selectedDate][$selectedSession] ?? null;

    if ($sessionMeta && file_exists($sessionMeta['file'])) {
        $remarks = json_decode(file_get_contents($sessionMeta['file']), true);
        if (is_array($remarks)) {
            $iterations = array_map('strval', array_keys($remarks));
        }
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>QA Log Session Viewer</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            max-width: 900px;
            margin: auto;
            background: #f4f6f8;
        }

        h2, h3 { margin-top:0; }

        .session-info, .log-box, .error-box {
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .error-box { background:#fff3f3; border-color:#e0a0a0; }

        select { padding: 5px; margin-top: 5px; }
        hr { border:none; border-top:1px solid #ddd; margin:15px 0; }
        small { color: #666; }
    </style>
</head>
<body>



<h1>Previous Sessions – Remarks (Read-Only)</h1>

<form method="POST" style="margin-bottom:15px; display:flex; gap:10px; align-items:center;"
      onsubmit="return promptSessionName();">

    <!-- Go to Logger -->
    <button
    type="button"
    onclick="window.location.href='logger_index.php'"
    style="
        background:#FFFFFF;
        border:1px solid #000000;
        color:#000000;
        padding:8px 14px;
        border-radius:4px;
        cursor:pointer;
    ">
    Return to Logger
</button>

    </form>

<div class="session-info">

    <!-- DATE RANGE SELECT -->
    <form method="GET">
        <label><strong>Select Date Range:</strong></label><br><br>

        <label>
            From:
            <input
                type="date"
                name="from_date"
                value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>"
                onchange="this.form.submit()"
            >
        </label>

        &nbsp;&nbsp;

        <label>
            To:
            <input
                type="date"
                name="to_date"
                value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>"
                onchange="this.form.submit()"
            >
        </label>
    </form>

    <!-- SESSION SELECT -->
    <?php if ($fromDate && $toDate && !empty($filteredSessionsByDate)): ?>
        <form method="GET" style="margin-top:10px;">
            <!-- preserve date range -->
            <input type="hidden" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
            <input type="hidden" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">

            <label><strong>Select Session:</strong></label><br>
            <select name="session" onchange="this.form.submit()">
                <option value="">-- Select Session --</option>

                <?php foreach ($filteredSessionsByDate as $date => $sessions): ?>
                    <?php foreach ($sessions as $sid => $meta): ?>
                        <option value="<?= htmlspecialchars($sid) ?>"
                            <?= $sid === $selectedSession ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sid) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            </select>
        </form>
    <?php endif; ?>

    <!-- ITERATION SELECT -->
    <?php if ($selectedSession && !empty($iterations)): ?>
        <form method="GET" style="margin-top:10px;">
            <!-- preserve date range + session -->
            <input type="hidden" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
            <input type="hidden" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">
            <input type="hidden" name="session" value="<?= htmlspecialchars($selectedSession) ?>">

            <label><strong>Select Activity Log:</strong></label><br>
            <select name="iteration" onchange="this.form.submit()">
                <option value="">-- Select Activity Log --</option>

                <?php foreach ($iterations as $iter): ?>
                    <option value="<?= htmlspecialchars($iter) ?>"
                        <?= ($iter === $selectedIteration) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($iter) ?>
                        <?php if (!empty($remarks[$iter]['name'])): ?>
                            - <?= htmlspecialchars($remarks[$iter]['name']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </form>
    <?php endif; ?>

</div>


<?php if ($selectedIteration && isset($remarks[$selectedIteration])):
    $entry = $remarks[$selectedIteration];

    $errorLogsRaw = [];
    $normalLogs   = [];

    foreach ($entry['logs'] ?? [] as $log) {
        if (is_error_log($log)) {
            $errorLogsRaw[] = $log;
        } else {
            $normalLogs[] = $log;
        }
    }

    $errorLogs = group_error_logs($errorLogsRaw);
?>

<div class="log-box">
    <h3>Activity Log ID: <?= htmlspecialchars($selectedIteration) ?></h3>

    <?php if (!empty($entry['name'])): ?>
        <strong>Remark Name:</strong>
        <p><?= htmlspecialchars($entry['name']) ?></p>
    <?php endif; ?>

    <strong>Remark:</strong>
    <p><?= nl2br(htmlspecialchars($entry['remark'])) ?></p>

    <small>Saved at: <?= htmlspecialchars($entry['saved_at']) ?></small>
</div>


<?php if (!empty($errorLogs)): ?>
<div class="error-box">
    <h3>⚠️ Errors</h3>
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

<?php elseif ($selectedSession): ?>
    <p><em>No data available for this iteration.</em></p>
<?php endif; ?>

</body>
</html>
