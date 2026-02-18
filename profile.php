<?php
session_name('QA_LOGGER_SESSION');
session_start();
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/repo/user_repo.php';
require_once __DIR__ . '/auth/require_login.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$userRepo = new UserRepository(qa_db()); // $pdo = PDO connection from config

/* --------------------------------------------------
   Fetch latest user state from DB
-------------------------------------------------- */
$userRow = $userRepo->findByUsername($_SESSION['user']['username']);

if (!$userRow) {
    session_destroy();
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$_SESSION['user']['first_login'] = (bool)$userRow['first_login'];

$error = '';
$success = '';

$currentPasswordValue = '';
$newPasswordValue     = '';
$confirmPasswordValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $currentPasswordValue = htmlspecialchars($_POST['current_password'] ?? '');
    $newPasswordValue     = htmlspecialchars($_POST['new_password'] ?? '');
    $confirmPasswordValue = htmlspecialchars($_POST['confirm_password'] ?? '');
}


/* --------------------------------------------------
   Handle password change
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';

    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $error = 'Password must contain at least one capital letter and one number';

    } elseif (!password_verify($currentPassword, $userRow['password_hash'])) {
        $error = 'Current password is incorrect';

    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        if ($userRepo->updatePassword($_SESSION['user']['username'], $newHash)) {
            $_SESSION['user']['first_login'] = false;
            $success = 'Password updated successfully';
        } else {
            $error = 'Failed to update password';
        }
    }
}

/* --------------------------------------------------
   Role-based redirect
-------------------------------------------------- */
$redirectUrl = match($_SESSION['user']['role'] ?? '') {
    'developer' => 'index.php',
    'qa'        => 'qa/qa.php',
    default     => 'login.php'
};
?>

<!doctype html>
<html>
<head>
    <title>My Profile</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="bootstrap-icons/font/bootstrap-icons.min.css">
    <style>
        .profile-container {
            padding-top: 100px;
        }
        .profile-card {
            max-width: 500px;
            margin: 100px auto;
        }
        .password-input-group input {
            padding-right: 2.5rem; /* space for eye icon */
        }
        .password-input-group .input-group-text {
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-light">

<div class="container profile-container">

    <h2 class="text-center mb-4">My Profile</h2>
    <h3 class="text-center mb-5">Hello <?= htmlspecialchars($_SESSION['user']['username']) ?></h3>

    <div class="card shadow-sm profile-card p-4">
        <h4 class="text-center mb-4">Change Password</h4>

        <p><strong>Username:</strong> <?= htmlspecialchars($_SESSION['user']['username']) ?></p>
        <p><strong>Role:</strong> <span class="text-uppercase"><?= htmlspecialchars($_SESSION['user']['role']) ?></span></p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="post">

            <!-- Current Password -->
            <div class="input-group mb-3 password-input-group">
                <input 
                type="password" 
                name="current_password" 
                id="current_password" 
                class="form-control" 
                placeholder="Current password" 
                value="<?= $currentPasswordValue ?>" 
                required>
                <span class="input-group-text toggle-password"><i class="bi bi-eye"></i></span>
            </div>

            <!-- New Password -->
            <div class="input-group mb-3 password-input-group">
                <input 
                type="password" 
                name="new_password" 
                id="new_password" 
                class="form-control" 
                placeholder="New password" 
                value="<?= $newPasswordValue ?>" 
                required>
                <span class="input-group-text toggle-password"><i class="bi bi-eye"></i></span>
            </div>

            <!-- Confirm Password -->
            <div class="input-group mb-3 password-input-group">
                <input 
                type="password" 
                name="confirm_password" 
                id="confirm_password" 
                class="form-control" 
                placeholder="Confirm new password" 
                value="<?= $confirmPasswordValue ?>" 
                required>
                <span class="input-group-text toggle-password"><i class="bi bi-eye"></i></span>
            </div>

            <button type="submit" class="btn btn-dark w-100 mb-3">Change Password</button>
        </form>

        <button
            class="btn btn-outline-dark w-100"
            id="returnBtn"
            data-first-login="<?= $_SESSION['user']['first_login'] ? '1' : '0' ?>"
            data-redirect="<?= htmlspecialchars($redirectUrl) ?>"
        >
            Return to Dashboard
        </button>

    </div>
</div>

<!-- First Login Warning Modal -->
<div class="modal fade" id="firstLoginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Action Required
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                You must change your password before accessing the dashboard.
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                    OK
                </button>
            </div>
        </div>
    </div>
</div>


<script src="scripts/bootstrap.bundle.min.js"></script>
<!-- Toggle Password Script -->
<script>
document.querySelectorAll('.toggle-password').forEach(span => {
    const input = span.previousElementSibling;
    const icon = span.querySelector('i');

    span.addEventListener('click', () => {
        const type = input.type === 'password' ? 'text' : 'password';
        input.type = type;

        icon.classList.toggle('bi-eye');
        icon.classList.toggle('bi-eye-slash');
    });
});
</script>
<script src="scripts/profile.js"></script>

</body>
</html>