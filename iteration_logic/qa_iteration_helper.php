<?php

date_default_timezone_set('Asia/Manila');

/* ============================
   USER BINDING
============================ */

function qa_get_user_key(): string
{
    // Priority 1: backend payload
    if (!empty($GLOBALS['__QA_USER_ID__'])) {
        return (string)$GLOBALS['__QA_USER_ID__'];
    }

    // Priority 2: PHP session (UI)
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!empty($_SESSION['user']['id'])) {
        return (string)$_SESSION['user']['id'];
    }

    // Fallback
    return 'guest';
}



function qa_get_state_file(): string
{
    $userKey = qa_get_user_key();
    return __DIR__ . "/qa_session_state_user_{$userKey}.json";
}

/* ============================
   DEFAULT STATE
============================ */

function qa_default_session_state(): array
{
    return [
        'session_id'        => 'UNKNOWN',
        'session_name'      => 'UNKNOWN',
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'logging_active'    => true // 🔴 CRITICAL
    ];
}


/* ============================
   STATE HANDLING
============================ */

function qa_get_session_state(): array
{
    $file = qa_get_state_file();

    if (!file_exists($file)) {
        return qa_default_session_state();
    }

    $state = json_decode(file_get_contents($file), true);

    if (!is_array($state)) {
        return qa_default_session_state();
    }

    return array_merge(qa_default_session_state(), $state);
}


function qa_save_session_state(array $state): void
{
    file_put_contents(
        qa_get_state_file(),
        json_encode($state, JSON_PRETTY_PRINT)
    );
}

/* ============================
   ITERATION LOGIC
============================ */

function qa_assign_iteration_id(string $timestamp): ?int
{
    $state = qa_get_session_state();

    // 🚫 Do not start logging implicitly
    if (empty($state['logging_active'])) {
        return null;
    }

    try {
        $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    } catch (Exception $e) {
        return null;
    }

    // -----------------------------
    // NORMALIZE TIMESTAMP
    // -----------------------------
    $epoch = $dt->getTimestamp();

    // Group into 2-second buckets
    $bucketSize = 2;
    $normalizedEpoch = intdiv($epoch, $bucketSize) * $bucketSize;

    $normalizedKey = date('Y-m-d H:i:s', $normalizedEpoch);

    // -----------------------------
    // ITERATION LOGIC
    // -----------------------------
    if (($state['last_second'] ?? null) !== $normalizedKey) {
        $state['iteration']++;
        $state['last_second'] = $normalizedKey;

        if ($state['iteration'] >= 50) {
            $state['logging_active'] = false;
        }

        qa_save_session_state($state);
    }

    return $state['iteration'];
}

/* ============================
   READ-ONLY HELPERS
============================ */

function qa_get_session_id(): string
{
    return qa_get_session_state()['session_id'] ?? 'UNKNOWN';
}

function qa_get_logging_status(): array
{
    $state = qa_get_session_state();

    return [
        'iteration' => $state['iteration'],
        'active'    => $state['logging_active'],
        'warn40'    => $state['iteration'] >= 40,
        'warn50'    => $state['iteration'] >= 50,
    ];
}

/* ============================
   SESSION RESET
============================ */

function qa_create_new_session(string $sessionName): void
{
    $cleanName = preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($sessionName));
    $cleanName = trim($cleanName, '_') ?: 'UNKNOWN';

    qa_save_session_state([
        'session_id'        => $cleanName,
        'session_name'      => $cleanName,
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'logging_active'    => true
    ]);
}
