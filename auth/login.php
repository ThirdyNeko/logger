<?php
session_name('QA_LOGGER_SESSION');
session_start();

require __DIR__ . '/../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // 🔐 Fetch user
    $stmt = $conn->prepare("
        SELECT id, username, password_hash, role, first_login
        FROM users
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();

    // ✅ Verify credentials
    if ($user && password_verify($password, $user['password_hash'])) {

        $_SESSION['user'] = [
            'id'       => $user['id'],
            'username' => $user['username'],
            'role'     => $user['role']
        ];

        // ✅ FIRST LOGIN CHECK
        if ((int)$user['first_login'] === 1) {

            header('Location: ../profile.php');
            exit;
        }

        // 🔁 ROLE-BASED REDIRECT
        switch ($user['role']) {
            case 'developer':
                header('Location: ../developer_viewer.php');
                break;

            case 'qa':
                header('Location: ../log_session_viewer.php');
                break;

            case 'admin':
                header('Location: ../auth/create_user.php');
                break;

            default:
                header('Location: ../login.php');
        }

        exit;
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

        </div>
    </div>
</body>
</html>
