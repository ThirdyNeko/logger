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

            header('Location: ../logger_index.php');
            exit;
        }
    }

    $error = 'Invalid credentials';
}
?>
<!doctype html>
<html>
<head><title>Logger Login</title></head>
<body>
<h2>Login</h2>
<?php if ($error): ?>
<p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<form method="post">
  <input name="username" placeholder="Username" required>
  <input name="password" type="password" placeholder="Password" required>
  <button type="submit" style="
        background:#000000;
        border:1px solid #000000;
        color:#FFFFFF;
        padding: 2px 10px;
        border-radius:4px;
        cursor:pointer;
    ">Login</button>
</form>

<br>

<button type="button" 
        onclick="window.location.href='create_user.php'"
        style="
        background:#FFFFFF;
        border:1px solid #000000;
        color:#000000;
        padding: 2px 10px;
        border-radius:4px;
        cursor:pointer;"
        >Sign up</button>

</body>
</html>
