<?php
// --------------------------------------------------
// HARD STOPS (must be first)
// --------------------------------------------------

// Do not log QA-internal requests (prevents recursion)
if (!empty($_SERVER['HTTP_X_QA_INTERNAL'])) {
    return;
}

// Do not log session-control requests
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['new_session'])
) {
    return;
}

error_log('QA BOOTSTRAP LOADED');

$BACKEND_RECEIVER = 'http://localhost/logger/hooks/receiver_backend.php';

// --------------------------------------------------
// INTERNAL STATE
// --------------------------------------------------
$GLOBALS['__QA_LOGGED__'] = false;

/**
 * Send log to backend receiver
 */
function qa_backend_log(array $data)
{
    global $BACKEND_RECEIVER;

    // Limit response size to prevent huge payloads
    if (isset($data['response'])) {
        $encoded = json_encode($data['response']);
        if (strlen($encoded) > 1000) { // adjust 1000 as needed
            $data['response'] = '[TRUNCATED LARGE RESPONSE]';
        }
    }

    $payload = json_encode($data);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  =>
                "Content-Type: application/json\r\n" .
                "X-QA-INTERNAL: 1\r\n",
            'content' => $payload,
            'timeout' => 1
        ]
    ];

    @file_get_contents($BACKEND_RECEIVER, false, stream_context_create($opts));
}

/**
 * Format output (decode JSON if possible)
 */
function qa_format_output($output)
{
    if (is_string($output)) {
        $trim = trim($output);

        if (($trim[0] ?? '') === '{' || ($trim[0] ?? '') === '[') {
            $decoded = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $output;
    }

    if (is_array($output) || is_object($output)) {
        return $output;
    }

    return (string)$output;
}

/* --------------------------------------------------
   Detect API / AJAX-like requests ONLY
-------------------------------------------------- */
$isApiLikeResponse =
    (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    )
    ||
    (
        isset($_SERVER['CONTENT_TYPE']) &&
        stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
    );

if (!$isApiLikeResponse) {
    return;
}

/* --------------------------------------------------
   Capture output ONCE (no repeat logging)
-------------------------------------------------- */
ob_start(function ($output) {
    if ($GLOBALS['__QA_LOGGED__']) {
        return $output;
    }

    if (trim($output) !== '') {
        $GLOBALS['__QA_LOGGED__'] = true;

        qa_backend_log([
            'type'      => 'backend-response',
            'status'    => http_response_code(),
            'response'  => qa_format_output($output),
            'request'   => $_POST ?: $_GET ?: null,
            'timestamp' => date('c')
        ]);
    }

    return $output;
});

/* --------------------------------------------------
   Capture PHP errors (does NOT recurse)
-------------------------------------------------- */
set_error_handler(function ($severity, $message, $file, $line) {
    if ($GLOBALS['__QA_LOGGED__']) {
        return false;
    }

    $GLOBALS['__QA_LOGGED__'] = true;

    qa_backend_log([
        'type'      => 'backend-error',
        'severity'  => $severity,
        'message'   => $message,
        'file'      => $file,
        'line'      => $line,
        'timestamp' => date('c')
    ]);

    return false;
});

/* --------------------------------------------------
   Final safety: shutdown capture (fires once max)
-------------------------------------------------- */
register_shutdown_function(function () {
    if ($GLOBALS['__QA_LOGGED__']) {
        return;
    }

    $output = ob_get_contents();
    if (trim($output) === '') {
        return;
    }

    $GLOBALS['__QA_LOGGED__'] = true;

    qa_backend_log([
        'type'      => 'backend-response',
        'status'    => http_response_code(),
        'response'  => qa_format_output($output),
        'request'   => $_POST ?: $_GET ?: null,
        'timestamp' => date('c')
    ]);
});
