<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/viewer_repo/dashboard.php';
require_once __DIR__ . '/repo/user_repo.php';
require_once __DIR__ . '/viewer_repo/users.php';
require_once __DIR__ . '/viewer_repo/programs.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$userRepo = new UserRepository(qa_db()); // $pdo = PDO connection from config

/* --------------------------------------------------
   Fetch latest user state from DB
-------------------------------------------------- */
$userRow = $userRepo->findByUsername($_SESSION['user']['username']);

if (!$userRow) {
    session_destroy();
    header('Location: ' . BASE_URL . 'auth/login.php');
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
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="css/datatables.min.css">

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
        /* Change cursor for clickable rows */
        #logs tbody tr.clickable-row {
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
            <a href="developer_live_viewer.php" class="btn btn-outline-secondary btn-sm">Live Viewer</a>
            <a href="profile.php" class="btn btn-outline-secondary btn-sm">Profile</a>
            <a href="index.php" class="btn btn-dark btn-sm">Logs</a>
            <a href="remarks.php" class="btn btn-outline-secondary btn-sm">Remarks</a>
        </div>

        <!-- Spacer pushes logout to the bottom -->
        <div class="mt-auto">
            <a href="auth/logger_logout.php" class="btn btn-danger btn-sm w-100">Logout</a>
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
                    <script src="scripts/jquery-4.0.0.min.js"></script>
                    <script src="scripts/datatables.min.js"></script>
                    <script>
                    document.addEventListener('DOMContentLoaded', function () {

                        const table = new DataTable('#logs', {
                            processing: true,
                            serverSide: true,
                            ajax: { 
                                url: 'viewer_repo/session_server.php',
                                type: 'POST',
                                data: function(d) {
                                    d.user = document.querySelector('[name="user"]').value;
                                    d.user_id = document.querySelector('[name="user_id"]').value;
                                }
                            },
                            pageLength: 25,
                            searching: false,
                            ordering: false
                        });

                        // Attach row click and print icon after each draw
                        table.on('draw', function() {
                            document.querySelectorAll('#logs tbody tr').forEach(row => {

                                const cells = row.querySelectorAll('td');
                                if(cells.length < 2) return; // ignore empty rows

                                const program = cells[0].textContent.trim();
                                const session = cells[1].textContent.trim();

                                // Row click
                                row.classList.add('clickable-row');
                                row.onclick = function(e) {
                                    if (!e.target.closest('.print-session')) { // ignore clicks on print icon
                                        window.location.href = `iteration_viewer.php?user=${encodeURIComponent(program)}&session=${encodeURIComponent(session)}`;
                                    }
                                };

                                // Print icon click
                                const printIcon = row.querySelector('a.print-session');
                                if (printIcon) {
                                    printIcon.onclick = function(e) {
                                        e.stopPropagation(); // stop row click
                                        printSession(program, session);
                                        return false;
                                    };
                                }

                            });
                        });

                    });
                    </script>
                <table id="logs" class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Program</th>
                            <th>Session</th>
                            <th>User ID</th>
                            <th>Last Updated</th> <!-- New column -->
                            <th></th> <!-- For print icon -->
                        </tr>
                    </thead>
                    <tbody>
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
                <button type="button" class="btn btn-danger" onclick="window.location='index.php'">
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

<!-- Bootstrap JS -->
<script src="scripts/bootstrap.bundle.min.js"></script>
<script>
function printSession(user, session) {
    const url = `viewer_repo/print_session.php?user=${user}&session=${session}&iteration=summary`;

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
