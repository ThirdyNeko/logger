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

$redirectUrl = '/auth/login.php'; // safe fallback

if (isset($_SESSION['user']['role'])) {
    switch ($_SESSION['user']['role']) {
        case 'developer':
            $redirectUrl = 'developer_viewer.php';
            break;

        case 'qa':
            $redirectUrl = 'logger_index.php';
            break;
        
        default:
            $redirectUrl = '/auth/login.php';
    }
}
?>
<!doctype html>
<html>
<head>
    <title>My Profile</title>
    <link rel="stylesheet" href="css/design.css">
</head>
<body class="profile-body">

    <div class="page-container">
        <h2>My Profile</h2>
        <h2>Hello <?= htmlspecialchars($_SESSION['user']['username']) ?><br></h2> 

        <p>
            <strong>Username:</strong> <?= htmlspecialchars($_SESSION['user']['username']) ?><br>
            <strong>Role:</strong> 
            <span style="text-transform: uppercase;">
                <?= htmlspecialchars($_SESSION['user']['role']) ?>
            </span>
        </p>
        <div class="profile-card">
            <?php if ($error): ?>
                <p style="color:red"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p style="color:green"><?= htmlspecialchars($success) ?></p>
            <?php endif; ?>

            <form class ="profile-form" method="post">
                <input class = "profile-input" type="password" name="current_password" placeholder="Current password" required>
    
                <input class = "profile-input" type="password" name="new_password" placeholder="New password" required>
             
                <input class = "profile-input" type="password" name="confirm_password" placeholder="Confirm new password" required>
          
                <button class = "btn-black" type="submit">Change Password</button>
            </form>
        </div>
        <button class="profile-return-button" onclick="window.location.href='<?= htmlspecialchars($redirectUrl) ?>'">
        Return to Dashboard</button>
    </div>
</body>
</html>