<?php
session_start();

$usersFile = __DIR__ . '/users.json';
$users = file_exists($usersFile)
    ? json_decode(file_get_contents($usersFile), true)
    : [];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = trim($_POST['username'] ?? '');
    $password         = $_POST['password'] ?? '';
    $confirmPassword  = $_POST['confirm_password'] ?? '';
    $role             = $_POST['role'] ?? 'user';

    // --------------------------
    // Password match check
    // --------------------------
    if ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    }

    // --------------------------
    // Password strength check
    // --------------------------
    if (!$error && (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))) {
        $error = 'Password must contain at least one capital letter and one number';
    }

    // --------------------------
    // Check duplicate username
    // --------------------------
    if (!$error) {
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                $error = 'Username already exists';
                break;
            }
        }
    }

    // --------------------------
    // Create user
    // --------------------------
    if (!$error) {
        // Determine next integer ID
        $maxId = 0;
        foreach ($users as $u) {
            if (isset($u['id']) && is_numeric($u['id'])) {
                $maxId = max($maxId, (int)$u['id']);
            }
        }
        $newId = $maxId + 1;

        $users[] = [
            'id'            => $newId,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role
        ];

        file_put_contents(
            $usersFile,
            json_encode($users, JSON_PRETTY_PRINT)
        );

        $success = 'User created successfully';
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
                <input class = "signup-input" name="username" placeholder="Username" required>
                <input class = "signup-input" name="password" type="password" placeholder="Password" required>
                <input class = "signup-input" name="confirm_password" type="password" placeholder="Confirm Password" required>
                <select class = "signup-form"name="role">
                    <option value="qa">QA</option>
                    <option value="developer">Developer</option>
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
