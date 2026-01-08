<?php

error_log('QA BOOTSTRAP LOADED');

$BACKEND_RECEIVER = 'http://localhost/logger/hooks/receiver_backend.php';

/**
 * Send log to backend receiver
 */
function qa_backend_log(array $data)
{
    global $BACKEND_RECEIVER;

    $payload = json_encode($data);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
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

/* -------------------
   Detect frontend AJAX request
------------------- */
$isFrontendRequest = 
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$isApiLikeResponse =
    (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    )
    ||
    (
        isset($_SERVER['CONTENT_TYPE']) &&
        stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
    )
    ||
    (
        $_SERVER['REQUEST_METHOD'] === 'POST'
    );

if (!$isApiLikeResponse) {
    return;
}


/* -------------------
   Capture normal output
------------------- */
ob_start(function ($output) {
    if (trim($output) !== '') {
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

/* -------------------
   Capture PHP errors
------------------- */
set_error_handler(function ($severity, $message, $file, $line) {
    qa_backend_log([
        'type'      => 'backend-error',
        'severity'  => $severity,
        'message'   => $message,
        'file'      => $file,
        'line'      => $line,
        'timestamp' => date('c')
    ]);
});
