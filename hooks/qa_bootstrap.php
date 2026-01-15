<?php
// Prefer the session user ID
$userId = $_SESSION['user']['id'] ?? 'guest';

// Then write logs to user-specific folder
$logBase = __DIR__ . "/../../logs/user_{$userId}";
if (!is_dir($logBase)) mkdir($logBase, 0777, true);

// Rest of logging logic...

// 🚫 NEVER LOG PHP DEPRECATIONS (PHP 8.1+)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// --------------------------------------------------
// HARD STOPS (must be first)
// --------------------------------------------------

// Absolute path to logger directory
$LOGGER_ROOT = realpath(__DIR__ . '/logger');

// Current executing script
$currentScript = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');

// 🚫 HARD STOP: Never log anything from logger itself
if ($LOGGER_ROOT && $currentScript && str_starts_with($currentScript, $LOGGER_ROOT)) {
    return;
}

// Prevent recursion
if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) {
    return;
}

// Ignore session-control calls
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['new_session'])
) {
    return;
}

$BACKEND_RECEIVER = 'http://localhost/logger/hooks/receiver_backend.php';

// --------------------------------------------------
// INTERNAL STATE
// --------------------------------------------------
$GLOBALS['__QA_LOGGED__'] = false;
$GLOBALS['__QA_RESPONSE_HASH__'] = null;

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

    @file_get_contents($BACKEND_RECEIVER, false, stream_context_create($opts));
}




/**
 * Extract valid JSON from output
 * Returns decoded array or null
 */
function qa_extract_json($output)
{
    if (!is_string($output)) {
        return null;
    }

    $trim = trim($output);
    if ($trim === '') {
        return null;
    }

    // 🚫 HARD BLOCK HTML / PAGE OUTPUT
    if (
        stripos($trim, '<!doctype') === 0 ||
        stripos($trim, '<html') === 0 ||
        stripos($trim, '<head') !== false ||
        stripos($trim, '<body') !== false ||
        stripos($trim, '<script') !== false
    ) {
        return null;
    }

    // ✅ JSON ONLY
    if ($trim[0] === '{' || $trim[0] === '[') {
        $decoded = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    return null;
}

/**
 * Compute a stable response hash to prevent loops
 */
function qa_response_hash($endpoint, $request, $response)
{
    return md5(
        $endpoint .
        '|' .
        json_encode($request) .
        '|' .
        json_encode($response)
    );
}

/* --------------------------------------------------
   OUTPUT CAPTURE
-------------------------------------------------- */
ob_start(function ($output) {

    if ($GLOBALS['__QA_LOGGED__']) {
        return $output;
    }

    // 🚫 No input = no backend response log
    if (empty($_POST) && empty($_GET)) {
        return $output;
    }

    $json = qa_extract_json($output);
    if ($json === null) {
        return $output;
    }

    $endpoint = $_SERVER['REQUEST_URI'] ?? '';
    $request  = $_POST ?: $_GET;

    $hash = qa_response_hash($endpoint, $request, $json);

    // 🚫 Prevent looped / repeated responses
    if ($GLOBALS['__QA_RESPONSE_HASH__'] === $hash) {
        return $output;
    }

    $GLOBALS['__QA_RESPONSE_HASH__'] = $hash;
    $GLOBALS['__QA_LOGGED__'] = true;

    qa_backend_log([
        'type'      => 'backend-response',
        'method'    => $_SERVER['REQUEST_METHOD'],
        'endpoint'  => $endpoint,
        'status'    => http_response_code(),
        'request'   => $request,
        'response'  => $json,
        'timestamp' => date('c')
    ]);

    return $output;
});

/* --------------------------------------------------
   PHP ERRORS (always log)
-------------------------------------------------- */
set_error_handler(function ($severity, $message, $file, $line) {

    // 🚫 ABSOLUTE HARD STOP: ignore all deprecations
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true; // fully handled, stop propagation
    }

    // Prevent recursion
    if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) {
        return true;
    }

    // 🚫 HARD BLOCK logger files (string check, no realpath)
    if (
        strpos($file, DIRECTORY_SEPARATOR . 'logger' . DIRECTORY_SEPARATOR) !== false ||
        basename($file) === 'logger_index.php'
    ) {
        return true;
    }

    qa_backend_log([
        'type'      => 'backend-error',
        'severity'  => $severity,
        'message'   => $message,
        'file'      => $file,
        'line'      => $line,
        'timestamp' => date('c')
    ]);

    return true; // stop PHP from re-processing
});



/* --------------------------------------------------
   SHUTDOWN SAFETY (last chance, once only)
-------------------------------------------------- */

register_shutdown_function(function () {
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
});

register_shutdown_function(function () {

    $error = error_get_last();

    if (!$error) {
        return;
    }

    if (in_array($error['type'], [E_DEPRECATED, E_USER_DEPRECATED])) {
        return;
    }

    qa_backend_log([
        'type'      => 'backend-fatal',
        'severity'  => $error['type'],
        'message'   => $error['message'],
        'file'      => $error['file'],
        'line'      => $error['line'],
        'timestamp' => date('c')
    ]);
});
