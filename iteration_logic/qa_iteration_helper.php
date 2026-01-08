<?php

// ✅ Force Manila timezone globally
date_default_timezone_set('Asia/Manila');

function qa_get_session_state(): array
{
    $file = __DIR__ . '/qa_session_state.json';

    if (!file_exists($file)) {
        $today = date('Y_m_d');

        $state = [
            'session_name'      => 'Default',
            'session_id'        => 'Default_' . $today,
            'iteration'         => 0,
            'remarks_iteration' => '',
            'last_second'       => null,
            'logging_active'    => true
        ];

        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
        return $state;
    }

    return json_decode(file_get_contents($file), true);
}

function qa_save_session_state(array $state): void
{
    file_put_contents(
        __DIR__ . '/qa_session_state.json',
        json_encode($state, JSON_PRETTY_PRINT)
    );
}

/**
 * ✅ Create a brand-new session with a name
 */
function qa_create_new_session(string $sessionName): void
{
    $safeName = preg_replace('/[^A-Za-z0-9_\- ]/', '', $sessionName);
    $safeName = trim(str_replace(' ', '_', $safeName));

    $today = date('Y_m_d');

    $state = [
        'session_name'      => $safeName,
        'session_id'        => $safeName . '_' . $today,
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'logging_active'    => true
    ];

    qa_save_session_state($state);
}

function qa_assign_iteration_id(string $timestamp): ?int
{
    $state = qa_get_session_state();

    if (!$state['logging_active']) {
        return null;
    }

    $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    $secondKey = $dt->format('Y-m-d H:i:s');

    if ($state['last_second'] !== $secondKey) {
        $state['iteration']++;
        $state['last_second'] = $secondKey;

        if ($state['iteration'] >= 50) {
            $state['logging_active'] = false;
        }

        qa_save_session_state($state);
    }

    return $state['iteration'];
}

function qa_get_session_id(): string
{
    return qa_get_session_state()['session_id'];
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
