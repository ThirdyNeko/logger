<?php
session_start();
require __DIR__ . '/../db.php';

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
<html>
<head>
    <title>Create User</title>
    <link rel="stylesheet" href="../css/design.css">
</head>
<body class = "signup-body">
    <div class = "page-container">
        <h1>Create User</h1>
        <div class="signup-card">
            <h2> New User Details</h2>
            <?php if ($error): ?>
                <p style="color:red"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p style="color:green"><?= htmlspecialchars($success) ?></p>
            <?php endif; ?>

            <form class = "signup-form" method="post">
                <input class = "signup-input" name="username" placeholder="Username" value="<?= $usernameValue ?>" required>
                <input class = "signup-input" name="password" type="password" placeholder="Password" value="<?= $passwordValue ?>" required>
                <input class = "signup-input" name="confirm_password" type="password" placeholder="Confirm Password" value="<?= $confirmPasswordValue ?>" required>
                <select class = "signup-form"name="role">
                    <option value="qa" <?= $roleValue === 'qa' ? 'selected' : '' ?>>QA</option>
                    <option value="developer" <?= $roleValue === 'developer' ? 'selected' : '' ?>>Developer</option>
                </select>
                <button class = "btn-white" type="submit">Create User</button>
            </form>
            <button class="signup-return-button"
                            type="button" 
                            onclick="window.location.href='login.php'">
                            Back to login
            </button>
        </div>
        
    </div>

</body>
</html>
