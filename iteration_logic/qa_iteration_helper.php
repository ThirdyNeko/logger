<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../repo/qa_session_repo.php';

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
    $repo = new QaSessionRepository(qa_db());
    $count = $repo->countSessionsForProgram($program);
    return $program . '_Test_' . ($count + 1);
}

function qa_get_session_state(): array
{
    $userId  = qa_get_user_id();
    $program = $GLOBALS['__QA_PROGRAM__'] ?? 'UNKNOWN_APP';
    $repo = new QaSessionRepository(qa_db());

    $row = $repo->getLatestSession($userId, $program);

    if ($row) {
        return array_merge(qa_default_session_state(), $row);
    }

    return qa_create_new_session($program, $userId);
}

/* ============================
   SAVE SESSION STATE
============================ */

function qa_save_session_state(array $state): void
{
    $userId = qa_get_user_id();
    $repo = new QaSessionRepository(qa_db());
    $repo->saveSession($state, $userId);
}

/* ============================
   ITERATION LOGIC
============================ */
function qa_assign_iteration_id(string $timestamp): ?int
{
    $userId  = qa_get_user_id();
    $program = $GLOBALS['__QA_PROGRAM__'] ?? 'UNKNOWN_APP';
    $state   = qa_get_session_state();

    try {
        $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    } catch (Exception $e) {
        return null;
    }

    $bucketSize = 2;

    $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    $epoch = $dt->getTimestamp();
    $normalizedEpoch = intdiv($epoch, $bucketSize) * $bucketSize;

    // Convert stored last_second to epoch for comparison
    $lastSecondEpoch = $state['last_second'] 
        ? (new DateTime($state['last_second'], new DateTimeZone('Asia/Manila')))->getTimestamp()
        : null;

    if ($lastSecondEpoch !== $normalizedEpoch) {
        if ($state['iteration'] >= 50) {
            $state = qa_create_new_session($program, $userId);
            return 1;
        }

        $state['iteration']++;
        // Save as DATETIME in SQL
        $state['last_second'] = date('Y-m-d H:i:s', $normalizedEpoch);
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
   SESSION RESET / CREATE NEW SESSION
============================ */
function qa_create_new_session(string $program, string $userId): array
{
    $sessionId = qa_generate_next_session_id($program, $userId);

    $state = [
        'session_id'        => $sessionId,
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'program_name'      => $program
    ];

    qa_save_session_state($state);

    return $state;
}


