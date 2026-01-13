<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$usersFile = __DIR__ . '/auth/users.json';
$users = json_decode(file_get_contents($usersFile), true) ?? [];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } else {
        foreach ($users as &$u) {
            if ($u['id'] === $_SESSION['user']['id']) {

                if (!password_verify($currentPassword, $u['password_hash'])) {
                    $error = 'Current password is incorrect';
                    break;
                }

                $u['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);

                file_put_contents(
                    $usersFile,
                    json_encode($users, JSON_PRETTY_PRINT)
                );

                $success = 'Password updated successfully';
                break;
            }
        }
        unset($u);
    }
}
?>
<!doctype html>
<html>
<head>
    <title>My Profile</title>
</head>
<body>
<br>
<button onclick="window.location.href='logger_index.php'"
style = "
        background:#FFFFFF;
        border:1px solid #000000;
        color:#000000;
        padding: 8px 14px;
        border-radius:4px;
        cursor:pointer;
    "
>Return to Logger</button>

<h2>My Profile</h2>

<p>
    <strong>Username:</strong> <?= htmlspecialchars($_SESSION['user']['username']) ?><br>
    <strong>Role:</strong> <?= htmlspecialchars($_SESSION['user']['role']) ?>
</p>

<?php if ($error): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p style="color:green"><?= htmlspecialchars($success) ?></p>
<?php endif; ?>

<form method="post">
    <input type="password" name="current_password" placeholder="Current password" required>
    <br><br>
    <input type="password" name="new_password" placeholder="New password" required>
    <br><br>
    <input type="password" name="confirm_password" placeholder="Confirm new password" required>
    <br><br>
    <button type="submit">Change Password</button>
</form>

</body>
</html>