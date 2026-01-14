<?php
session_start();

$users = json_decode(
    file_get_contents(__DIR__ . '/users.json'),
    true
);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    foreach ($users as $user) {
        if ($user['username'] === $username &&
            password_verify($password, $user['password_hash'])) {

            $_SESSION['user'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'role'     => $user['role']
            ];

            // 🔁 ROLE-BASED REDIRECT
            switch ($user['role']) {
                case 'developer':
                    header('Location: ../developer_viewer.php');
                    break;

                case 'qa':
                    header('Location: ../logger_index.php');
                    break;

                default:
                    header('Location: ../login.php'); // fallback
            }

            exit;
        }
    }

    $error = 'Invalid credentials';
}
?>
<!doctype html>
<html>
<head>
    <title>Logger Login

    </title>
    <link rel="stylesheet" href="../css/design.css">
</head>
<body class = "login-body">

    <div class = "page-container">
        <h1 class="login-title">QA Logger</h1>  
        <div class="login-card">
            <h2>Login</h2>

            <?php if ($error): ?>
            <p style="color:red"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form class = "login-form"method="post">
                <input class = "login-input" name="username" placeholder="Username" required>
                <input class = "login-input" name="password" type="password" placeholder="Password" required>
                <button class="btn-white" type="submit">Login</button>
            </form>

            <button class="login-singup-button"
                    type="button" 
                    onclick="window.location.href='create_user.php'">
                    Sign up
            </button>
        </div>
    </div>
</body>
</html>
