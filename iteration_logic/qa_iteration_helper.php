<?php

// ✅ Force Manila timezone globally
date_default_timezone_set('Asia/Manila');

/* ============================
   INTERNAL HELPERS
============================ */

function qa_default_session_state(): array
{
    return [
        'session_id'        => 'default',
        'session_name'      => 'default',
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'logging_active'    => true
    ];
}

/* ============================
   SESSION STATE HANDLING
============================ */

function qa_get_session_state(): array
{
    $file = __DIR__ . '/qa_session_state.json';

    if (!file_exists($file)) {
        return qa_default_session_state();
    }

    $state = json_decode(file_get_contents($file), true);

    if (!is_array($state)) {
        return qa_default_session_state();
    }

    // Ensure all required keys exist
    return array_merge(qa_default_session_state(), $state);
}

function qa_save_session_state(array $state): void
{
    file_put_contents(
        __DIR__ . '/qa_session_state.json',
        json_encode($state, JSON_PRETTY_PRINT)
    );
}

/* ============================
   ITERATION LOGIC
============================ */

function qa_assign_iteration_id(string $timestamp): ?int
{
    $state = qa_get_session_state();

    // Stop logging once inactive
    if (empty($state['logging_active'])) {
        return null;
    }

    try {
        // Interpret timestamp in Manila timezone
        $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    } catch (Exception $e) {
        return null;
    }

    $secondKey = $dt->format('Y-m-d H:i:s');

    if ($state['last_second'] !== $secondKey) {
        $state['iteration']++;
        $state['last_second'] = $secondKey;

        // Hard stop at 50
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
    return qa_get_session_state()['session_id'] ?? 'default';
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
    $cleanName = trim($cleanName, '_');

    $state = [
        'session_id'        => $cleanName ?: 'default',
        'session_name'      => $cleanName ?: 'default',
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'logging_active'    => true
    ];

    qa_save_session_state($state);
}
