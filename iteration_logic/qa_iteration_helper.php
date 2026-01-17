<?php

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/db.php';

/* ============================
   USER BINDING
============================ */

function qa_get_user_id(): int
{
    // Priority 1: backend payload
    if (!empty($GLOBALS['__QA_USER_ID__'])) {
        return (int)$GLOBALS['__QA_USER_ID__'];
    }

    // Priority 2: PHP session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['user']['id'])) {
        return (int)$_SESSION['user']['id'];
    }

    // ❌ NO guest rows in DB
    throw new RuntimeException('Unauthenticated user');
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
    $userId = qa_get_user_id();
    $db = qa_db();

    $stmt = $db->prepare("
        SELECT session_id, session_name, iteration,
               remarks_iteration, last_second, logging_active
        FROM qa_session_state
        WHERE user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    return $row
        ? array_merge(qa_default_session_state(), $row)
        : qa_default_session_state();
}



function qa_save_session_state(array $state): void
{
    $userId = qa_get_user_id();
    $db = qa_db();

    $stmt = $db->prepare("
        INSERT INTO qa_session_state
        (user_id, session_id, session_name, iteration,
         remarks_iteration, last_second, logging_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            session_id = VALUES(session_id),
            session_name = VALUES(session_name),
            iteration = VALUES(iteration),
            remarks_iteration = VALUES(remarks_iteration),
            last_second = VALUES(last_second),
            logging_active = VALUES(logging_active)
    ");

    $stmt->bind_param(
        'ississi',
        $userId,
        $state['session_id'],
        $state['session_name'],
        $state['iteration'],
        $state['remarks_iteration'],
        $state['last_second'],
        $state['logging_active']
    );

    $stmt->execute();
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
        'logging_active'    => 1
    ]);
}

