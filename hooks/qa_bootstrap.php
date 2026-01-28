<?php

define('QA_APP_PROGRAM', qa_get_app_root_name());
define('QA_DEVICE_NAME', qa_get_device_name());

/**
 * Get the name of the root folder of the app
 */
function qa_get_app_root_name(): string
{
    try {
        // Get the requested URL path
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $pathParts = array_filter(explode('/', $path)); // remove empty segments

        // Take the first folder after domain as the app root
        if (!empty($pathParts)) {
            return array_values($pathParts)[0]; // first segment
        }
    } catch (Exception $e) {
        // fallback
    }

    return 'UNKNOWN_APP';
}
function qa_get_device_name(): string
{
    // Check persistent cookie first
    if (!empty($_COOKIE['qa_device_id'])) {
        return $_COOKIE['qa_device_id'];
    }

    // Fallback to IP + User-Agent hash
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown_ip';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown_ua';
    return 'device_' . substr(md5($ip . '|' . $ua), 0, 12);
}



// Rest of logging logic...

// 🚫 NEVER LOG PHP DEPRECATIONS (PHP 8.1+)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// --------------------------------------------------
// HARD STOPS (must be first)
// --------------------------------------------------

// Absolute path to logger directory
$LOGGER_ROOT = realpath(__DIR__ . '/../../logger');

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
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Use the device_id from the payload if set
    $data['user_id'] = $data['device_id'] ?? $_SESSION['user']['id'] ?? 'guest';
    $data['program'] = $data['program'] ?? qa_get_app_root_name();

    $url = 'http://127.0.0.1/logger/hooks/receiver_backend.php';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-QA-INTERNAL: 1'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_exec($ch);
    curl_close($ch);
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
    $hasInput =
        !empty($_POST) ||
        !empty($_GET) ||
        file_get_contents('php://input') !== '';

    if (!$hasInput) {
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
        'program_name' => QA_APP_PROGRAM,
        'device_name' => QA_DEVICE_NAME,
        'endpoint'  => $endpoint,
        'status'    => http_response_code(),
        'request'   => $request,
        'response'  => $json,
        'timestamp' => date('c')
    ]);

    return $output;
});

/* --------------------------------------------------
   PHP ERRORS (always log, deduplicated)
-------------------------------------------------- */
set_error_handler(function ($severity, $message, $file, $line) {

    // 🚫 ABSOLUTE HARD STOP: ignore all deprecations
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return true; // fully handled, stop propagation
    }

    // Prevent recursion (avoid logging inside the logger)
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

    // ----------------------------
    // DEDUPLICATION: one log per unique issue per request
    // ----------------------------
    static $seen = [];
    $key = $file . ':' . $line . ':' . $message;

    if (isset($seen[$key])) {
        return true; // already logged, skip
    }
    $seen[$key] = true;

    // ----------------------------
    // LOG IT
    // ----------------------------
    qa_backend_log([
        'type'        => 'backend-error',
        'program_name'=> QA_APP_PROGRAM,
        'device_name' => QA_DEVICE_NAME,
        'response'    => [
            'severity' => $severity,
            'message'  => $message,
        ],
        'endpoint'    => "$file:$line",
        'timestamp'   => date('c')
    ]);

    return true; // stop PHP from re-processing
});


/* --------------------------------------------------
   SHUTDOWN SAFETY (last chance, once only)
-------------------------------------------------- */
register_shutdown_function(function () {

    static $handled = false;
    if ($handled) return;
    $handled = true;

    $error = error_get_last();
    if (!$error) return;

    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($error['type'], $fatal, true)) return;

    // 🚫 NEVER touch output buffers here
    // 🚫 NEVER start sessions
    // 🚫 NEVER curl

    error_log('[QA_FATAL] ' . json_encode([
        'file' => $error['file'],
        'line' => $error['line'],
        'msg'  => $error['message'],
    ]));
});

