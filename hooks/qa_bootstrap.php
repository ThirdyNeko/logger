<?php
// ==========================
// QA Logger Bootstrap
// ==========================

// Prefer session user ID
$userId = $_SESSION['user']['id'] ?? 'guest';

// 🚫 NEVER LOG PHP DEPRECATIONS (PHP 8.1+)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// -----------------------------
// HARD STOPS (must be first)
// -----------------------------

$LOGGER_ROOT = realpath(__DIR__ . '/logger') ?: '';
$currentScript = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';

// Stop logging if inside logger folder
if ($LOGGER_ROOT && str_starts_with($currentScript, $LOGGER_ROOT)) {
    return;
}

// Stop logging internal logger requests
if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) {
    return;
}

// Stop logging session-control calls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_session'])) {
    return;
}

// Backend receiver
$BACKEND_RECEIVER = 'http://localhost/logger/hooks/receiver_backend.php';

// -----------------------------
// INTERNAL STATE
// -----------------------------
$GLOBALS['__QA_LOGGED__'] = false;
$GLOBALS['__QA_RESPONSE_HASH__'] = null;

// ==========================
// LOGGING FUNCTIONS
// ==========================

/**
 * Send log to backend receiver
 */
function qa_backend_log(array $data)
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    $data['user_id'] = $_SESSION['user']['id'] ?? 'guest';
    global $BACKEND_RECEIVER;

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  =>
                "Content-Type: application/json\r\n" .
                "X-QA-INTERNAL: 1\r\n",
            'content' => json_encode($data),
            'timeout' => 1
        ]
    ];

    $response = @file_get_contents($BACKEND_RECEIVER, false, stream_context_create($opts));
    if ($response === false) {
        error_log("[QA] Failed to send log to receiver_backend");
    }
}

/**
 * Extract valid JSON from output
 */
function qa_extract_json($output)
{
    if (!is_string($output)) return null;

    $trim = trim($output);
    if ($trim === '') return null;

    // Block HTML output
    if (
        stripos($trim, '<!doctype') === 0 ||
        stripos($trim, '<html') === 0 ||
        stripos($trim, '<head') !== false ||
        stripos($trim, '<body') !== false ||
        stripos($trim, '<script') !== false
    ) {
        return null;
    }

    // JSON only
    if ($trim[0] === '{' || $trim[0] === '[') {
        $decoded = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    return null;
}

/**
 * Compute stable hash to prevent loops
 */
function qa_response_hash($endpoint, $request, $response)
{
    return md5($endpoint . '|' . json_encode($request) . '|' . json_encode($response));
}

// ==========================
// OUTPUT CAPTURE
// ==========================
ob_start(function ($output) {

    if ($GLOBALS['__QA_LOGGED__']) return $output;
    if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) return $output;

    // No input = skip
    if (empty($_POST) && empty($_GET)) return $output;

    $json = qa_extract_json($output);
    if ($json === null) return $output;

    $endpoint = $_SERVER['REQUEST_URI'] ?? '';
    $request  = $_POST ?: $_GET;

    $hash = qa_response_hash($endpoint, $request, $json);

    if ($GLOBALS['__QA_RESPONSE_HASH__'] === $hash) return $output;

    $GLOBALS['__QA_RESPONSE_HASH__'] = $hash;
    $GLOBALS['__QA_LOGGED__'] = true;

    qa_backend_log([
        'type'      => 'backend-response',
        'method'    => $_SERVER['REQUEST_METHOD'],
        'endpoint'  => $endpoint,
        'status'    => http_response_code(),
        'request_body'   => $request,
        'response_body'  => $json,
        'timestamp' => date('c')
    ]);

    return $output;
});

// ==========================
// PHP ERROR HANDLER
// ==========================
set_error_handler(function ($severity, $message, $file, $line) {

    // Ignore deprecations
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED])) return true;

    if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) return true;
    if (str_contains($file ?? '', DIRECTORY_SEPARATOR . 'logger' . DIRECTORY_SEPARATOR)) return true;

    qa_backend_log([
        'type'      => 'backend-error',
        'severity'  => $severity,
        'message'   => $message,
        'file'      => $file,
        'line'      => $line,
        'timestamp' => date('c')
    ]);

    return true; // prevent further PHP handling
});

// ==========================
// SHUTDOWN FUNCTION (fatal errors)
// ==========================
register_shutdown_function(function () {
    if (ob_get_level() > 0) @ob_end_flush();
});

register_shutdown_function(function () {

    $error = error_get_last();
    if (!$error) return;
    if (in_array($error['type'], [E_DEPRECATED, E_USER_DEPRECATED])) return;

    if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) return;
    if (str_contains($error['file'] ?? '', DIRECTORY_SEPARATOR . 'logger' . DIRECTORY_SEPARATOR)) return;

    qa_backend_log([
        'type'      => 'backend-fatal',
        'severity'  => $error['type'],
        'message'   => $error['message'],
        'file'      => $error['file'],
        'line'      => $error['line'],
        'timestamp' => date('c')
    ]);
});
