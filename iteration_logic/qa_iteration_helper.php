<?php

// ✅ Force Manila timezone globally
date_default_timezone_set('Asia/Manila');

function qa_get_session_state(): array
{
    $file = __DIR__ . '/qa_session_state.json';

    if (!file_exists($file)) {
        $state = [
            'session_id'        => date('Y_m_d_H_i_s'), // Manila time
            'iteration'         => 0,
            'remarks_iteration' => '',
            'last_second'       => null,
            'logging_active'    => true
        ];

        file_put_contents($file, json_encode($state));
        return $state;
    }

    return json_decode(file_get_contents($file), true);
}

function qa_save_session_state(array $state): void
{
    file_put_contents(__DIR__ . '/qa_session_state.json', json_encode($state));
}

function qa_assign_iteration_id(string $timestamp): ?int
{
    $state = qa_get_session_state();

    // Stop logging after 50
    if (!$state['logging_active']) {
        return null;
    }

    // ✅ Interpret timestamp in Manila timezone
    $dt = new DateTime($timestamp, new DateTimeZone('Asia/Manila'));
    $secondKey = $dt->format('Y-m-d H:i:s');

    if ($state['last_second'] !== $secondKey) {
        $state['iteration']++;
        $state['last_second'] = $secondKey;

        // Threshold checks
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
