<?php
session_start();
require __DIR__ . '/../config/db.php';

$error   = '';
$success = '';

$usernameValue = '';
$roleValue     = 'user';
$passwordValue = '';
$confirmPasswordValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue        = htmlspecialchars($_POST['username'] ?? '');
    $roleValue            = $_POST['role'] ?? 'user';
    $passwordValue        = htmlspecialchars($_POST['password'] ?? '');
    $confirmPasswordValue = htmlspecialchars($_POST['confirm_password'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username        = trim($_POST['username'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role            = $_POST['role'] ?? 'user';

    // --------------------------
    // Password match check
    // --------------------------
    if ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    }

    // --------------------------
    // Password strength check
    // --------------------------
    if (
        !$error &&
        (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))
    ) {
        $error = 'Password must contain at least one capital letter and one number';
    }

    // --------------------------
    // Check duplicate username
    // --------------------------
    if (!$error) {
        $check = $conn->prepare("
            SELECT id FROM users WHERE username = ? LIMIT 1
        ");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'Username already exists';
        }
        $check->close();
    }

    // --------------------------
    // Create user
    // --------------------------
    if (!$error) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO users (username, password_hash, role, first_login)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->bind_param("sss", $username, $hash, $role);

        if ($stmt->execute()) {
            $success = 'User created successfully';
            $usernameValue = $passwordValue = $confirmPasswordValue = '';
            $roleValue = 'user';
        } else {
            $error = 'Failed to create user';
        }

        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create User</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../css/bootstrap.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">

    <style>
        body {
            background-color: #f8f9fa;
        }
        .page-container {
            padding-top: 100px;
        }
        .signup-card {
            max-width: 500px;
            margin: auto;
        }
        .password-input-group input {
            padding-right: 2.5rem;
        }
        .password-input-group .input-group-text {
            cursor: pointer;
        }
    </style>
</head>

<body>

<div class="container page-container">

    <h2 class="text-center mb-4">Create User</h2>

    <div class="card shadow-sm signup-card p-4">

        <h4 class="text-center mb-4">New User Details</h4>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="post">

            <!-- Username -->
            <div class="mb-3">
                <input
                    type="text"
                    name="username"
                    class="form-control"
                    placeholder="Username"
                    value="<?= $usernameValue ?>"
                    required
                >
            </div>

            <!-- Password -->
            <div class="input-group mb-3 password-input-group">
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    placeholder="Password"
                    value="<?= $passwordValue ?>"
                    required
                >
                <span class="input-group-text toggle-password" data-target="password">
                    <i class="bi bi-eye"></i>
                </span>
            </div>

            <!-- Confirm Password -->
            <div class="input-group mb-3 password-input-group">
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    class="form-control"
                    placeholder="Confirm Password"
                    value="<?= $confirmPasswordValue ?>"
                    required
                >
                <span class="input-group-text toggle-password" data-target="confirm_password">
                    <i class="bi bi-eye"></i>
                </span>
            </div>

            <!-- Role -->
            <div class="mb-4">
                <select name="role" class="form-select" required>
                    <option value="qa" <?= $roleValue === 'qa' ? 'selected' : '' ?>>QA</option>
                    <option value="developer" <?= $roleValue === 'developer' ? 'selected' : '' ?>>Developer</option>
                </select>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn btn-danger w-100 mb-3">
                Create User
            </button>
        </form>

        <!-- Back -->
        <button
            type="button"
            class="btn btn-outline-dark w-100"
            onclick="window.location.href='login.php'">
            Back to Login
        </button>

    </div>
</div>

<!-- Password Toggle Script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toggle-password').forEach(toggle => {
        toggle.addEventListener('click', () => {
            const input = document.getElementById(toggle.dataset.target);
            const icon = toggle.querySelector('i');

            input.type = input.type === 'password' ? 'text' : 'password';
            icon.classList.toggle('bi-eye');
            icon.classList.toggle('bi-eye-slash');
        });
    });
});
</script>

</body>
</html>
