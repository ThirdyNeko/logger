<?php

// ✅ Force Manila timezone globally
date_default_timezone_set('Asia/Manila');

// ✅ Ensure PHP session exists
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Resolve per-user QA state file
 */
function qa_get_state_file(): string
{
    $sessionId = session_id();

    return __DIR__ . "/qa_session_state_{$sessionId}.json";
}

function qa_get_session_state(): array
{
    $file = qa_get_state_file();

    if (!file_exists($file)) {
        return [];
    }

    return json_decode(file_get_contents($file), true) ?: [];
}

function qa_save_session_state(array $state): void
{
    file_put_contents(
        qa_get_state_file(),
        json_encode($state, JSON_PRETTY_PRINT)
    );
}

function qa_assign_iteration_id(string $timestamp): ?int
{
    $state = qa_get_session_state();

    // Safety: state not initialized
    if (empty($state)) {
        return null;
    }

    // Stop logging after 50
    if (!$state['logging_active']) {
        return null;
    }

    // ✅ Interpret timestamp in Manila timezone
    $dt = new DateTime($timestamp);
    $dt->setTimezone(new DateTimeZone('Asia/Manila'));
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

function qa_get_session_id(): ?string
{
    return qa_get_session_state()['session_id'] ?? null;
}

function qa_get_logging_status(): array
{
    $state = qa_get_session_state();

    return [
        'iteration' => $state['iteration'] ?? 0,
        'active'    => $state['logging_active'] ?? false,
        'warn40'    => ($state['iteration'] ?? 0) >= 40,
        'warn50'    => ($state['iteration'] ?? 0) >= 50,
    ];
}

/**
 * Create a brand new QA session for THIS USER ONLY
 */
function qa_create_new_session(string $sessionName): void
{
    // Clean & normalize session name
    $cleanName = preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($sessionName));
    $cleanName = trim($cleanName, '_');

    $state = [
        'session_id'        => $cleanName,
        'session_name'      => $cleanName,
        'iteration'         => 0,
        'remarks_iteration' => '',
        'last_second'       => null,
        'logging_active'    => true,
        'created_at'        => date('c'),
        'php_session_id'    => session_id()
    ];

    qa_save_session_state($state);
}

/**
 * Bind QA logging to a specific user (backend-safe)
 * This does NOT destroy existing sessions.
 */
function qa_bind_user_session(string $userId): void
{
    // If already started, do not regenerate
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Namespace the QA session so it doesn't collide
    if (!isset($_SESSION['__QA_BOUND_USER__'])) {
        session_regenerate_id(false); // keep data
        $_SESSION['__QA_BOUND_USER__'] = $userId;
    }
}

/**
 * Ensure QA session state exists for current PHP session
 */
function qa_ensure_session_exists(string $defaultName = 'default'): void
{
    $state = qa_get_session_state();

    if (!empty($state)) {
        return;
    }

    qa_create_new_session($defaultName);
}

/**
 * Convenience helper for backend receivers
 */
function qa_prepare_backend_session(string $userId): void
{
    qa_bind_user_session($userId);
    qa_ensure_session_exists($userId);
}

