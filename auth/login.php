<?php
session_name('QA_LOGGER_SESSION');
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../repo/user_repo.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $userRepo = new UserRepository(qa_db());

    // 🔐 Fetch user
    $user = $userRepo->findByUsername($username);

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
                header('Location: ../index.php');
                break;

            case 'qa':
                header('Location: ../qa/qa.php');
                break;

            case 'admin':
                header('Location: ../admin/admin.php');
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
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap-icons/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">

<div class="container vh-100 d-flex justify-content-center align-items-center">
    <div class="card shadow-sm" style="width: 100%; max-width: 400px;">
        <div class="card-body">

            <h3 class="text-center mb-4">QA Logger</h3>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        placeholder="Username"
                        required
                    >
                </div>

                <div class="input-group mb-3">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Password"
                        required
                    >
                    <span class="input-group-text" id="togglePassword" style="cursor: pointer;">
                        <i class="bi bi-eye"></i>
                    </span>
                </div>

                <script>
                const passwordInput = document.getElementById('password');
                const togglePassword = document.getElementById('togglePassword');
                const icon = togglePassword.querySelector('i');

                togglePassword.addEventListener('click', () => {
                    // Toggle input type
                    const type = passwordInput.type === 'password' ? 'text' : 'password';
                    passwordInput.type = type;

                    // Toggle icon
                    icon.classList.toggle('bi-eye');
                    icon.classList.toggle('bi-eye-slash');
                });
                </script>

                <button type="submit" class="btn btn-outline-dark w-100">
                    Login
                </button>
            </form>

        </div>
    </div>
</div>

</body>


</html>
