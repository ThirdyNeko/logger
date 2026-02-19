<?php
session_name('QA_LOGGER_SESSION');

require_once __DIR__ . '/../auth/require_login.php';
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../repo/user_repo.php';
require_once __DIR__ . '/../viewer_repo/remarks.php';
require_once __DIR__ . '/../viewer_repo/users.php';
require_once __DIR__ . '/../viewer_repo/programs.php';

if (!isset($_SESSION['user'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$db = qa_db();

/* ==========================
   INPUT STATE
========================== */

$selectedProgram = $_GET['program'] ?? '';
$status          = $_GET['status'] ?? ''; // resolved | pending
$fromDate        = $_GET['from_date'] ?? '';
$toDate          = $_GET['to_date'] ?? '';

// Force remarks to only show the logged-in user
$username = $_SESSION['user']['username']; 


/* ==========================
   PAGINATION
========================== */

$perPage = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page'])
    ? max(1, (int)$_GET['page'])
    : 1;

$offset = ($page - 1) * $perPage;

$result = loadRemarksPaginated(
    $db,
    $selectedProgram ?: null,
    $username ?: null,
    $status !== '' ? $status : null,
    $fromDate ?: null,
    $toDate ?: null,
    $perPage,
    $offset
);

$remarks = $result['data'];
$totalRemarks = $result['total'];
$totalPages = ceil($totalRemarks / $perPage);

/* ==========================
   PROGRAM LIST
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
            <a href="qa_live_viewer.php" class="btn btn-outline-secondary btn-sm">Live Viewer</a>
            <a href="../profile.php" class="btn btn-outline-secondary btn-sm">Profile</a>
            <a href="qa.php" class="btn btn-outline-secondary btn-sm">Logs</a>
            <a href="remarks.php" class="btn btn-dark btn-sm">Remarks</a>
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
            <h4 class="mb-0">QA Remarks</h4>

            <div class="d-flex gap-2">
                <!-- Filter Button -->
                <button class="btn btn-secondary"
                        data-bs-toggle="modal"
                        data-bs-target="#filterModal">
                    Filters
                </button>

                <!-- Refresh -->
                <a href="remarks.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </div>

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
                                <th>Iteration</th>
                                <th>Remark Name</th>
                                <th>Status</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($remarks)): ?>
                            <?php foreach ($remarks as $row): ?>
                                <tr class="clickable-row"
                                    onclick="window.location='qa_viewer.php?user=<?= urlencode($row['program_name']) ?>&session=<?= urlencode($row['session_id']) ?>&iteration=<?= (int)$row['iteration'] ?>'">
                                    <td><?= htmlspecialchars($row['program_name']) ?></td>
                                    <td><?= htmlspecialchars($row['session_id']) ?></td>
                                    <td><?= (int)$row['iteration'] ?></td>
                                    <td><?= htmlspecialchars($row['remark_name']) ?></td>
                                    <td>
                                        <?php if ($row['resolved']): ?>
                                            <span class="badge bg-success">Resolved</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('Y-m-d', strtotime($row['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center p-4 text-muted">
                                    No remarks found
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">

                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($_GET + ['page' => $page - 1]) ?>">
                        Previous
                    </a>
                </li>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query($_GET + ['page' => $p]) ?>">
                            <?= $p ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($_GET + ['page' => $page + 1]) ?>">
                        Next
                    </a>
                </li>

            </ul>
        </nav>
        <?php endif; ?>
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
                <h5 class="modal-title">Filter Remarks</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body row g-3">

                <!-- Program -->
                <div class="col-md-6">
                    <label class="form-label">Program</label>
                    <input type="text"
                           name="program"
                           class="form-control"
                           value="<?= htmlspecialchars($selectedProgram) ?>">
                </div>

                <!-- From Date -->
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date"
                           name="from_date"
                           class="form-control"
                           value="<?= htmlspecialchars($fromDate) ?>">
                </div>

                <!-- To Date -->
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date"
                           name="to_date"
                           class="form-control"
                           value="<?= htmlspecialchars($toDate) ?>">
                </div>

                <!-- Status -->
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>
                            Resolved
                        </option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>
                            Pending
                        </option>
                    </select>
                </div>

            </div>
            
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-danger"
                        onclick="window.location='remarks.php'">
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
<script src="../scripts/bootstrap.bundle.min.js"></script>

</body>
</html>
