<?php

error_log('QA BOOTSTRAP LOADED');

// Only activate if frontend QA is enabled
if (!isset($_SERVER['HTTP_X_QA_ENABLE'])) {
    return;
}

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

function qa_format_output($output)
{
    // If JSON string → decode
    if (is_string($output)) {
        $trim = trim($output);

        if (
            ($trim[0] ?? '') === '{' ||
            ($trim[0] ?? '') === '['
        ) {
            $decoded = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $output;
    }

    // Arrays / objects → return as-is
    if (is_array($output) || is_object($output)) {
        return $output;
    }

    // Fallback
    return (string)$output;
}


/**
 * Capture output
 */
ob_start(function ($output) {
    if (trim($output) !== '') {
        qa_backend_log([
            'type'      => 'backend-output',
            'response'  => qa_format_output($output),
            'timestamp' => date('c')
        ]);
    }
    return $output;
});


/**
 * Capture PHP errors
 */
set_error_handler(function ($severity, $message, $file, $line) {
    qa_backend_log([
        'type' => 'backend-error',
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'timestamp' => date('c')
    ]);
});
