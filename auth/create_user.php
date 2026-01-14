<?php
session_start();

$usersFile = __DIR__ . '/users.json';
$users = file_exists($usersFile)
    ? json_decode(file_get_contents($usersFile), true)
    : [];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'user';

    // Check duplicate username
    foreach ($users as $u) {
        if ($u['username'] === $username) {
            $error = 'Username already exists';
            break;
        }
    }

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
</head>
<body>

<h2>Create User</h2>

<?php if ($error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p style="color:green"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<form method="post">
    <input name="username" placeholder="Username" required>
    <br><br>
    <input name="password" type="password" placeholder="Password" required>
    <br><br>
    <select name="role">
        <option value="qa">QA</option>
        <option value="developer">Developer</option>
    </select>
    <br><br>
    <button type="submit">Create User</button>
</form>

<p>
    <a href="login.php">Back to login</a>
</p>

</body>
</html>
