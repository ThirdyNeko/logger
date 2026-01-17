<?php
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/* --------------------------------------------------
   Fetch latest user state from DB
-------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT password_hash, first_login
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $_SESSION['user']['id']);
$stmt->execute();
$result = $stmt->get_result();
$userRow = $result->fetch_assoc();
$stmt->close();

if (!$userRow) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['user']['first_login'] = (bool)$userRow['first_login'];

$error = '';
$success = '';

$currentPasswordValue = '';
$newPasswordValue     = '';
$confirmPasswordValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $currentPasswordValue = htmlspecialchars($_POST['current_password'] ?? '');
    $newPasswordValue     = htmlspecialchars($_POST['new_password'] ?? '');
    $confirmPasswordValue = htmlspecialchars($_POST['confirm_password'] ?? '');
}

/* --------------------------------------------------
   Handle password change
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';

    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $error = 'Password must contain at least one capital letter and one number';

    } elseif (!password_verify($currentPassword, $userRow['password_hash'])) {
        $error = 'Current password is incorrect';

    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $update = $conn->prepare("
            UPDATE users
            SET password_hash = ?, first_login = 0
            WHERE id = ?
        ");
        $update->bind_param("si", $newHash, $_SESSION['user']['id']);
        $update->execute();
        $update->close();

        $_SESSION['user']['first_login'] = false;
        $success = 'Password updated successfully';
    }
}

/* --------------------------------------------------
   Role-based redirect
-------------------------------------------------- */
$redirectUrl = 'login.php';

switch ($_SESSION['user']['role'] ?? '') {
    case 'developer':
        $redirectUrl = 'developer_viewer.php';
        break;

    case 'qa':
        $redirectUrl = 'logger_index.php';
        break;
}
?>

<!doctype html>
<html>
<head>
    <title>My Profile</title>
    <link rel="stylesheet" href="css/design.css">
</head>
<body class="profile-body">

    <div class="page-container" style = "padding-top: 100px;">
        <h2>My Profile</h2>
        <h2>Hello <?= htmlspecialchars($_SESSION['user']['username']) ?><br></h2> 
        <div class="profile-card" style = "margin-top: 100px;">
            <h2> Change Password</h2>
            <strong>Username:</strong> <?= htmlspecialchars($_SESSION['user']['username']) ?><br>
            <strong>Role:</strong> 
            <span style="text-transform: uppercase;">
                <?= htmlspecialchars($_SESSION['user']['role']) ?>
            </span>
            </p>
            <?php if ($error): ?>
                <p style="color:red"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <?php if ($success): ?>
                <p style="color:green"><?= htmlspecialchars($success) ?></p>
            <?php endif; ?>

            <form class ="profile-form" method="post">
                <input class = "profile-input" type="password" name="current_password" placeholder="Current password" value="<?= $currentPasswordValue ?>" required>

                <input class = "profile-input" type="password" name="new_password" placeholder="New password" value="<?= $newPasswordValue ?>" required>

                <input class = "profile-input" type="password" name="confirm_password" placeholder="Confirm new password" value="<?= $confirmPasswordValue ?>" required>
          
                <button class = "btn-black" type="submit">Change Password</button>
            </form>

            <button
                class="profile-return-button"
                onclick="handleReturn()"
            >
                Return to Dashboard
            </button>

            <script>
            function handleReturn() {
                const firstLogin = <?= json_encode(!empty($_SESSION['user']['first_login'])) ?>;

                if (firstLogin) {
                    alert("You must change your password before accessing the dashboard.");
                    return;
                }

                window.location.href = "<?= htmlspecialchars($redirectUrl) ?>";
            }
            </script>
        </div>
    </div>
</body>
</html>