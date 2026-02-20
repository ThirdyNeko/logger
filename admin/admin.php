<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/../auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../repo/user_repo.php';
require_once __DIR__ . '/../viewer_repo/dashboard.php';
require_once __DIR__ . '/../viewer_repo/users.php';
require_once __DIR__ . '/../viewer_repo/programs.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../auth/login.php');
    exit;
}

$userRepo = new UserRepository(qa_db()); // $pdo = PDO connection from config

/* --------------------------------------------------
   Fetch latest user state from DB
-------------------------------------------------- */
$userRow = $userRepo->findByUsername($_SESSION['user']['username']);

if (!$userRow) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

$_SESSION['user']['first_login'] = (bool)$userRow['first_login'];

$db = qa_db();

/* ==========================
   INPUT STATE
========================== */
$selectedProgram     = $_GET['user'] ?? '';
$fromDate            = $_GET['from_date'] ?? '';
$toDate              = $_GET['to_date'] ?? '';
$userId            = $_GET['user_id'] ?? '';

/* ==========================
   DETERMINE CURRENT PAGE
========================== */

$result = loadSessionNamesForViewer(
    $db,
    $selectedProgram ?: null,
    $fromDate ?: null,
    $toDate ?: null,
    $userId ?: null
);

$sessionNames = $result['sessions'];
$baseQuery = $result['baseQuery'];

/* ==========================
   PROGRAM LIST (FROM LOGS)
========================== */
$programs = loadPrograms($db);

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>QA Logger – Sessions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="../bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="../css/datatables.min.css">

    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: #ffffff;
        }
        .sidebar .user-box {
            border-bottom: 1px solid #e5e5e5;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }
        tr.clickable-row {
            cursor: pointer;
        }
    </style>
</head>
<body>

<div class="d-flex vh-100">

    <!-- =====================
         SIDEBAR
    ====================== -->
    <aside class="sidebar d-flex flex-column border-end p-3" style="height: 100vh;">
        <div class="user-box mb-3">
            <div class="fw-bold text-center text-uppercase">
                Hello <?= htmlspecialchars($_SESSION['user']['username']) ?>
            </div>
        </div>

        <!-- Top buttons -->
        <div class="d-grid gap-2">
            <a href="create_user.php" class="btn btn-outline-dark btn-sm">Create User</a>
            <button id="archiveBtn" class="btn btn-outline-danger btn-sm">
                Archive Logs
            </button>
        </div>

        <!-- Spacer pushes logout to the bottom -->
        <div class="mt-auto">
            <a href="../auth/logger_logout.php" class="btn btn-danger btn-sm w-100">Logout</a>
        </div>
    </aside>

    <!-- =====================
         MAIN CONTENT
    ====================== -->
    <main class="flex-fill p-4 overflow-auto" style="height:100%;">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Sessions</h4>

            <div class="d-flex gap-2">
                <!-- Filters Modal -->
                <button class="btn btn-secondary"
                        data-bs-toggle="modal"
                        data-bs-target="#filterModal">
                    Filters
                </button>

                <!-- Refresh Logs -->
                <form method="GET" class="m-0">
                    <!-- Preserve existing filter values as hidden inputs -->
                    <input type="hidden" name="user" value="<?= htmlspecialchars($selectedProgram) ?>">
                    <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                    <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($userId) ?>">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- =====================
            SESSIONS TABLE
        ====================== -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <script src="../scripts/jquery-4.0.0.min.js"></script>
                    <script src="../scripts/datatables.min.js"></script>
                    <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        new DataTable('#logs');
                    });
                    </script>
                <table id="logs" class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Program</th>
                            <th>Session</th>
                            <th>User ID</th>
                            <th>Last Updated</th> <!-- New column -->
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($sessionNames)): ?>
                        <?php foreach ($sessionNames as $session): ?>
                            <tr class="clickable-row"
                                onclick="window.location='admin_viewer.php?user=<?= urlencode($session['program_name'] ?? '') ?>&session=<?= urlencode($session['session_id'] ?? '') ?>'">
                                <td><?= htmlspecialchars($session['program_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($session['session_id']) ?></td>
                                <td><?= htmlspecialchars($session['user_id'] ?? '-') ?></td>
                                <td>
                                    <?= !empty($session['last_updated'])
                                        ? date('Y-m-d H:i:s', strtotime($session['last_updated']))
                                        : '-' ?>
                                </td>

                                <!-- Print Icon -->
                                <td onclick="event.stopPropagation();">
                                    <a href="#"
                                        onclick="printSession('<?= urlencode($session['program_name'] ?? '') ?>','<?= urlencode($session['session_id'] ?? '') ?>'); return false;"
                                        class="text-decoration-none">
                                            <i class="bi bi-printer"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted p-4">
                                No sessions found
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>        
            </div>
        </div>
    </main>
</div>

<!-- =====================
     FILTER MODAL
====================== -->
<form method="GET">
<div class="modal fade" id="filterModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">Filter Sessions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body row g-3">

                <div class="col-md-6">
                    <label class="form-label">Program</label>
                    <input type="text" name="user" class="form-control"
                           value="<?= htmlspecialchars($selectedProgram) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control"
                           value="<?= htmlspecialchars($fromDate) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control"
                           value="<?= htmlspecialchars($toDate) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">User ID</label>
                    <input type="text" name="user_id" class="form-control"
                           value="<?= htmlspecialchars($userId) ?>">
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="window.location='admin.php'">
                    Clear Filters
                </button>
                <button type="submit" class="btn btn-primary">
                    Apply Filters
                </button>
            </div>

        </div>
    </div>
</div>
</form>

<!------------------
 CONFIRMATION MODAL 
------------------->
<div class="modal fade" id="archiveConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title text-danger">Confirm Archive</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                Are you sure you want to archive the current logs?
                <br><br>
                <small class="text-muted">
                    This will move the current logs into an archive table.
                </small>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmArchiveBtn">
                    Yes, Archive
                </button>
            </div>

        </div>
    </div>
</div>


<!-- =====================
     ARCHIVE RESULT MODAL
====================== -->

<div class="modal fade" id="archiveResultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="archiveModalTitle">Archive Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body" id="archiveModalBody"></div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Close
                </button>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="../scripts/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const archiveBtn = document.getElementById('archiveBtn');
    const confirmBtn = document.getElementById('confirmArchiveBtn');

    const confirmModal = new bootstrap.Modal(document.getElementById('archiveConfirmModal'));
    const resultModal = new bootstrap.Modal(document.getElementById('archiveResultModal'));

    const resultTitle = document.getElementById('archiveModalTitle');
    const resultBody = document.getElementById('archiveModalBody');

    // 1️⃣ Open confirmation modal
    archiveBtn.addEventListener('click', function () {
        confirmModal.show();
    });

    // 2️⃣ When admin confirms
    confirmBtn.addEventListener('click', async function () {

        confirmBtn.disabled = true;
        confirmBtn.textContent = "Archiving...";

        try {
            const response = await fetch('archive_logs.php', {
                method: 'POST'
            });

            const result = await response.json();

            confirmModal.hide();

            if (result.success) {
                resultTitle.textContent = "Success";
                resultBody.innerHTML = `
                    <div class="text-success">
                        Logs archived successfully.
                    </div>
                `;
            } else {
                resultTitle.textContent = "Failed";
                resultBody.innerHTML = `
                    <div class="text-danger">
                        Archive failed. Please try again.
                    </div>
                `;
            }

        } catch (error) {
            confirmModal.hide();

            resultTitle.textContent = "Error";
            resultBody.innerHTML = `
                <div class="text-danger">
                    An unexpected error occurred.
                </div>
            `;
        }

        confirmBtn.disabled = false;
        confirmBtn.textContent = "Yes, Archive";

        resultModal.show();
    });

});
</script>

<script>
function printSession(user, session) {
    const url = `../viewer_repo/print_session.php?user=${user}&session=${session}&iteration=summary`;

    let iframe = document.getElementById('printFrame');

    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'printFrame';
        iframe.style.position = 'absolute';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        document.body.appendChild(iframe);
    }

    iframe.onload = function() {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    };

    iframe.src = url;
}
</script>


</body>
</html>
