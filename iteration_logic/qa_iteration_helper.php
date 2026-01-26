<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/db.php';

/* ============================
   USER BINDING
============================ */

function qa_get_user_id(): string
{
    // Only use the device_name sent by the logger
    return (string)($GLOBALS['__QA_USER_ID__'] ?? 'guest');
}

/* ============================
   DEFAULT SESSION STATE
============================ */
function qa_default_session_state(): array
{
    return [
        'session_id'        => 'UNKNOWN',
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null
    ];
}

/* ============================
   STATE HANDLING
============================ */

function qa_generate_next_session_id(string $program, string $userId): string
{
    $db = qa_db();

    // Count existing sessions for this user/program
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM qa_session_state
        WHERE user_id = ? AND session_id LIKE CONCAT(?, '%')
    ");
    $stmt->bind_param('ss', $userId, $program);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0];

    // Return the next session ID
    return $program . '_Test_' . ($count + 1);
}

function qa_get_session_state(): array
{
    $userId = qa_get_user_id();
    $program = $GLOBALS['__QA_PROGRAM__'] ?? 'UNKNOWN_APP';
    $db = qa_db();

    $stmt = $db->prepare("
        SELECT session_id, iteration,
               remarks_iteration, last_second
        FROM qa_session_state
        WHERE user_id = ?
    ");
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        return array_merge(qa_default_session_state(), $row);
    }

    // 🔥 Auto-create session based on program_name
    $sessionId = qa_generate_next_session_id($program, $userId);

    $state = [
        'session_id'        => $sessionId,
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null
    ];

    qa_save_session_state($state);

    return $state;
}


/* ============================
   SAVE SESSION STATE
============================ */
function qa_save_session_state(array $state): void
{
    $userId = qa_get_user_id();
    $db = qa_db();

    // Make sure DB has a unique key: (user_id, session_id)
    $stmt = $db->prepare("
        INSERT INTO qa_session_state
        (user_id, session_id, iteration, remarks_iteration, last_second)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            iteration = VALUES(iteration),
            remarks_iteration = VALUES(remarks_iteration),
            last_second = VALUES(last_second)
    ");

    $stmt->bind_param(
        'ssiss',
        $userId,
        $state['session_id'],
        $state['iteration'],
        $state['remarks_iteration'],
        $state['last_second']
    );

    $stmt->execute();
}

/* ============================
   ITERATION LOGIC
============================ */
function qa_assign_iteration_id(string $timestamp): ?int
{
    $state = qa_get_session_state();

    try {
        $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    } catch (Exception $e) {
        return null;
    }

    $epoch = $dt->getTimestamp();
    $bucketSize = 2;
    $normalizedEpoch = intdiv($epoch, $bucketSize) * $bucketSize;
    $normalizedKey = date('Y-m-d H:i:s', $normalizedEpoch);

    if (($state['last_second'] ?? null) !== $normalizedKey) {
        $state['iteration']++;
        $state['last_second'] = $normalizedKey;
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

/* ============================
   SESSION RESET
============================ */
function qa_create_new_session(string $sessionId): void
{
    qa_save_session_state([
        'session_id'        => $sessionId,
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null
    ]);
}
