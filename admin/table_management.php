<?php
// admin/table_management.php - Table Management System
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db       = $database->connect();

    // ---------- ADD TABLE ----------
    if (isset($_POST['add_table'])) {
        try {
            $table_number    = trim($_POST['table_number']);
            $seating_capacity = intval($_POST['seating_capacity']);
            $location        = trim($_POST['location']);
            $status          = $_POST['status'];

            // Check duplicate table number
            $chk = $db->prepare("SELECT table_id FROM restaurant_tables WHERE table_number = :t");
            $chk->bindParam(':t', $table_number);
            $chk->execute();
            if ($chk->rowCount() > 0) {
                $error_message = "Table number '{$table_number}' already exists!";
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO restaurant_tables (table_number, seating_capacity, status, location)
                     VALUES (:t, :c, :s, :l)"
                );
                $stmt->bindParam(':t', $table_number);
                $stmt->bindParam(':c', $seating_capacity);
                $stmt->bindParam(':s', $status);
                $stmt->bindParam(':l', $location);
                $stmt->execute()
                    ? $success_message = "Table '{$table_number}' added successfully!"
                    : $error_message   = 'Failed to add table.';
            }
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }

    // ---------- UPDATE TABLE ----------
    if (isset($_POST['update_table'])) {
        try {
            $table_id        = intval($_POST['table_id']);
            $table_number    = trim($_POST['table_number']);
            $seating_capacity = intval($_POST['seating_capacity']);
            $location        = trim($_POST['location']);
            $status          = $_POST['status'];

            $chk = $db->prepare(
                "SELECT table_id FROM restaurant_tables WHERE table_number = :t AND table_id != :id"
            );
            $chk->bindParam(':t', $table_number);
            $chk->bindParam(':id', $table_id);
            $chk->execute();
            if ($chk->rowCount() > 0) {
                $error_message = "Table number '{$table_number}' already exists!";
            } else {
                $stmt = $db->prepare(
                    "UPDATE restaurant_tables
                     SET table_number = :t, seating_capacity = :c, status = :s, location = :l
                     WHERE table_id = :id"
                );
                $stmt->bindParam(':t',  $table_number);
                $stmt->bindParam(':c',  $seating_capacity);
                $stmt->bindParam(':s',  $status);
                $stmt->bindParam(':l',  $location);
                $stmt->bindParam(':id', $table_id);
                $stmt->execute()
                    ? $success_message = "Table '{$table_number}' updated successfully!"
                    : $error_message   = 'Failed to update table.';
            }
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }

    // ---------- DELETE TABLE ----------
    if (isset($_POST['delete_table'])) {
        try {
            $table_id = intval($_POST['table_id']);
            $stmt = $db->prepare("DELETE FROM restaurant_tables WHERE table_id = :id");
            $stmt->bindParam(':id', $table_id);
            $stmt->execute()
                ? $success_message = 'Table deleted successfully!'
                : $error_message   = 'Failed to delete table.';
        } catch (PDOException $e) {
            $error_message = 'Cannot delete: table may have active orders or reservations.';
        }
    }

    // ---------- TOGGLE STATUS ----------
    if (isset($_POST['update_status'])) {
        try {
            $table_id  = intval($_POST['table_id']);
            $new_status = $_POST['new_status'];
            $stmt = $db->prepare(
                "UPDATE restaurant_tables SET status = :s WHERE table_id = :id"
            );
            $stmt->bindParam(':s',  $new_status);
            $stmt->bindParam(':id', $table_id);
            $stmt->execute()
                ? $success_message = 'Table status updated!'
                : $error_message   = 'Failed to update status.';
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// ---------- FETCH DATA ----------
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_status   = $_GET['status']   ?? 'all';
    $filter_location = $_GET['location'] ?? 'all';
    $search_query    = $_GET['search']   ?? '';

    $query = "SELECT * FROM restaurant_tables WHERE 1=1";
    if ($filter_status   != 'all') $query .= " AND status = :status";
    if ($filter_location != 'all') $query .= " AND location = :location";
    if (!empty($search_query))     $query .= " AND table_number LIKE :search";
    $query .= " ORDER BY table_number";

    $stmt = $db->prepare($query);
    if ($filter_status   != 'all') $stmt->bindParam(':status',   $filter_status);
    if ($filter_location != 'all') $stmt->bindParam(':location', $filter_location);
    if (!empty($search_query)) {
        $sp = "%{$search_query}%";
        $stmt->bindParam(':search', $sp);
    }
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = $db->query(
        "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available,
            SUM(CASE WHEN status='occupied'  THEN 1 ELSE 0 END) as occupied,
            SUM(CASE WHEN status='reserved'  THEN 1 ELSE 0 END) as reserved,
            SUM(seating_capacity) as total_seats
         FROM restaurant_tables"
    )->fetch(PDO::FETCH_ASSOC);

    // Distinct locations for filter
    $locations = $db->query(
        "SELECT DISTINCT location FROM restaurant_tables WHERE location != '' ORDER BY location"
    )->fetchAll(PDO::FETCH_COLUMN);

    // All tables for floor plan (no filter)
    $all_tables = $db->query(
        "SELECT * FROM restaurant_tables ORDER BY table_number"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $tables = $all_tables = [];
    $stats = ['total'=>0,'available'=>0,'occupied'=>0,'reserved'=>0,'total_seats'=>0];
    $locations = [];
}

$page_title = 'Table Management';
$base_url   = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Fine Dine RMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
<style>
/* ── Stat Cards ── */
.stat-card {
    border-radius: 15px;
    padding: 22px;
    transition: transform .3s;
    border: none;
}
.stat-card:hover { transform: translateY(-5px); }

/* ── Floor Plan ── */
#floorPlan {
    background: #f0f2f5;
    border-radius: 16px;
    min-height: 420px;
    position: relative;
    overflow: hidden;
    border: 2px dashed #ced4da;
}
.floor-table {
    position: absolute;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all .25s ease;
    user-select: none;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
    border: 3px solid transparent;
}
.floor-table:hover {
    transform: scale(1.08);
    box-shadow: 0 8px 24px rgba(0,0,0,.2);
    z-index: 10;
}
.floor-table.available  { background:#d4edda; border-color:#28a745; color:#155724; }
.floor-table.occupied   { background:#f8d7da; border-color:#dc3545; color:#721c24; }
.floor-table.reserved   { background:#fff3cd; border-color:#ffc107; color:#856404; }
.floor-table .t-number  { font-size:1rem; font-weight:700; }
.floor-table .t-seats   { font-size:.7rem; opacity:.85; }
.floor-table .t-status  { font-size:.65rem; font-weight:600; text-transform:uppercase; }

/* ── Table Cards (grid view) ── */
.table-card {
    border-radius: 15px;
    overflow: hidden;
    transition: all .3s;
    border: none;
    height: 100%;
}
.table-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,.15) !important;
}
.table-card .card-header {
    padding: 20px;
    border: none;
}
.table-icon {
    width: 64px; height: 64px;
    border-radius: 14px;
    background: rgba(255,255,255,.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.75rem;
}
.seat-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin: 2px;
}

/* ── Filter Bar ── */
.filter-bar {
    background: linear-gradient(135deg,#667eea,#764ba2);
    border-radius: 15px;
    padding: 24px 28px;
    color: white;
}

/* ── Legend ── */
.legend-dot {
    width: 14px; height: 14px;
    border-radius: 4px;
    display: inline-block;
}

/* ── View Toggle ── */
.view-toggle .btn { border-radius: 8px !important; }

/* ── Action Btns ── */
.action-btn {
    width: 34px; height: 34px;
    padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px;
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-chair text-primary me-2"></i>Table Management
            </h2>
            <p class="text-muted mb-0">Manage restaurant tables and seating</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addTableModal">
            <i class="fas fa-plus me-2"></i>Add New Table
        </button>
    </div>

    <!-- Alerts -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['total']; ?></div>
                        <div class="opacity-90">Total Tables</div>
                        <small class="opacity-75"><?php echo $stats['total_seats']; ?> seats total</small>
                    </div>
                    <i class="fas fa-border-all fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['available']; ?></div>
                        <div class="opacity-90">Available</div>
                        <small class="opacity-75">Ready for guests</small>
                    </div>
                    <i class="fas fa-check-circle fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['occupied']; ?></div>
                        <div class="opacity-90">Occupied</div>
                        <small class="opacity-75">Currently in use</small>
                    </div>
                    <i class="fas fa-utensils fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['reserved']; ?></div>
                        <div class="opacity-90">Reserved</div>
                        <small class="opacity-75">Upcoming bookings</small>
                    </div>
                    <i class="fas fa-calendar-check fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Floor Plan -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-map-marked-alt text-primary me-2"></i>Floor Plan</h5>
            <div class="d-flex gap-3 align-items-center">
                <span><span class="legend-dot bg-success me-1"></span>Available</span>
                <span><span class="legend-dot bg-danger me-1"></span>Occupied</span>
                <span><span class="legend-dot bg-warning me-1"></span>Reserved</span>
                <button class="btn btn-sm btn-outline-secondary" onclick="randomizeFloor()">
                    <i class="fas fa-random me-1"></i>Shuffle Layout
                </button>
            </div>
        </div>
        <div class="card-body">
            <div id="floorPlan"></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar mb-4">
        <div class="row align-items-center">
            <div class="col-md-4 mb-3 mb-md-0">
                <form method="GET" class="d-flex">
                    <input type="text" name="search" class="form-control me-2"
                           placeholder="Search table number..."
                           value="<?php echo htmlspecialchars($search_query); ?>">
                    <button type="submit" class="btn btn-light">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
            </div>
            <div class="col-md-8">
                <div class="d-flex gap-2 flex-wrap justify-content-md-end">
                    <a href="?" class="btn <?php echo ($filter_status=='all' && $filter_location=='all') ? 'btn-light' : 'btn-outline-light'; ?>">All</a>
                    <a href="?status=available" class="btn <?php echo $filter_status=='available' ? 'btn-light' : 'btn-outline-light'; ?>"><i class="fas fa-check-circle me-1"></i>Available</a>
                    <a href="?status=occupied"  class="btn <?php echo $filter_status=='occupied'  ? 'btn-light' : 'btn-outline-light'; ?>"><i class="fas fa-utensils me-1"></i>Occupied</a>
                    <a href="?status=reserved"  class="btn <?php echo $filter_status=='reserved'  ? 'btn-light' : 'btn-outline-light'; ?>"><i class="fas fa-calendar me-1"></i>Reserved</a>
                    <?php foreach($locations as $loc): ?>
                    <a href="?location=<?php echo urlencode($loc); ?>"
                       class="btn <?php echo $filter_location==$loc ? 'btn-light' : 'btn-outline-light'; ?>">
                        <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($loc); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- View Toggle + Grid -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="fas fa-table text-primary me-2"></i>
            Tables
            <span class="badge bg-primary ms-2"><?php echo count($tables); ?></span>
        </h5>
        <div class="view-toggle btn-group">
            <button class="btn btn-outline-primary active" id="btnGrid" onclick="setView('grid')">
                <i class="fas fa-th-large"></i>
            </button>
            <button class="btn btn-outline-primary" id="btnList" onclick="setView('list')">
                <i class="fas fa-list"></i>
            </button>
        </div>
    </div>

    <!-- Grid View -->
    <div id="gridView">
        <div class="row g-4">
        <?php if (!empty($tables)): ?>
            <?php foreach($tables as $t):
                $grad = $t['status']=='available'
                    ? 'linear-gradient(135deg,#11998e,#38ef7d)'
                    : ($t['status']=='occupied'
                        ? 'linear-gradient(135deg,#eb3349,#f45c43)'
                        : 'linear-gradient(135deg,#f7971e,#ffd200)');
            ?>
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="card table-card shadow-sm">
                    <div class="card-header text-white" style="background:<?php echo $grad; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="table-icon">
                                <i class="fas fa-chair"></i>
                            </div>
                            <span class="badge bg-white text-dark px-3 py-2 rounded-pill fw-semibold">
                                <?php echo ucfirst($t['status']); ?>
                            </span>
                        </div>
                        <h4 class="mt-3 mb-0 fw-bold">Table <?php echo htmlspecialchars($t['table_number']); ?></h4>
                        <small class="opacity-90">
                            <i class="fas fa-map-marker-alt me-1"></i>
                            <?php echo htmlspecialchars($t['location'] ?: 'No location'); ?>
                        </small>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <div class="fw-bold fs-5">
                                    <i class="fas fa-users text-primary me-1"></i>
                                    <?php echo $t['seating_capacity']; ?> Seats
                                </div>
                                <div class="mt-1">
                                    <?php for($i=0;$i<min($t['seating_capacity'],8);$i++): ?>
                                    <span class="seat-dot <?php echo $t['status']=='occupied' ? 'bg-danger' : ($t['status']=='reserved' ? 'bg-warning' : 'bg-success'); ?>"></span>
                                    <?php endfor; ?>
                                    <?php if($t['seating_capacity']>8): ?>
                                    <small class="text-muted">+<?php echo $t['seating_capacity']-8; ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small">ID</div>
                                <div class="fw-bold">#<?php echo str_pad($t['table_id'],3,'0',STR_PAD_LEFT); ?></div>
                            </div>
                        </div>

                        <!-- Quick Status Change -->
                        <div class="mb-3">
                            <label class="form-label small text-muted">Quick Status Change</label>
                            <form method="POST" class="d-flex gap-1">
                                <input type="hidden" name="table_id" value="<?php echo $t['table_id']; ?>">
                                <?php foreach(['available','occupied','reserved'] as $s):
                                    $active = $t['status']==$s;
                                    $cls = $s=='available' ? 'success' : ($s=='occupied' ? 'danger' : 'warning');
                                ?>
                                <button type="submit" name="update_status"
                                        value="1"
                                        onclick="document.querySelector('input[name=new_status]').value='<?php echo $s; ?>'"
                                        class="btn btn-sm <?php echo $active ? "btn-{$cls}" : "btn-outline-{$cls}"; ?> flex-fill">
                                    <?php echo ucfirst($s); ?>
                                </button>
                                <?php endforeach; ?>
                                <input type="hidden" name="new_status" value="">
                            </form>
                        </div>

                        <!-- Actions -->
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill"
                                    onclick='editTable(<?php echo json_encode($t); ?>)'>
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                            <button class="btn btn-danger btn-sm flex-fill"
                                    onclick="deleteTable(<?php echo $t['table_id']; ?>, '<?php echo htmlspecialchars($t['table_number']); ?>')">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5 text-muted">
                <i class="fas fa-chair fs-1 d-block mb-3"></i>
                <h4>No tables found</h4>
                <p>Add your first table to get started</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTableModal">
                    <i class="fas fa-plus me-2"></i>Add First Table
                </button>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- List View (hidden by default) -->
    <div id="listView" style="display:none;">
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Table</th>
                                <th>Location</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th>Quick Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($tables as $t):
                            $cls = $t['status']=='available' ? 'success' : ($t['status']=='occupied' ? 'danger' : 'warning');
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold">Table <?php echo htmlspecialchars($t['table_number']); ?></div>
                                    <small class="text-muted">#<?php echo str_pad($t['table_id'],3,'0',STR_PAD_LEFT); ?></small>
                                </td>
                                <td><i class="fas fa-map-marker-alt text-muted me-1"></i><?php echo htmlspecialchars($t['location'] ?: '—'); ?></td>
                                <td><i class="fas fa-users text-primary me-1"></i><?php echo $t['seating_capacity']; ?> seats</td>
                                <td><span class="badge bg-<?php echo $cls; ?> px-3 py-2"><?php echo ucfirst($t['status']); ?></span></td>
                                <td>
                                    <form method="POST" class="d-flex gap-1">
                                        <input type="hidden" name="table_id"  value="<?php echo $t['table_id']; ?>">
                                        <input type="hidden" name="new_status" value="">
                                        <?php foreach(['available','occupied','reserved'] as $s):
                                            $a = $t['status']==$s;
                                            $c = $s=='available'?'success':($s=='occupied'?'danger':'warning');
                                        ?>
                                        <button type="submit" name="update_status" value="1"
                                                onclick="this.form.querySelector('[name=new_status]').value='<?php echo $s; ?>'"
                                                class="btn btn-sm <?php echo $a ? "btn-{$c}" : "btn-outline-{$c}"; ?>">
                                            <?php echo ucfirst($s[0]); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </form>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-primary btn-sm action-btn"
                                                onclick='editTable(<?php echo json_encode($t); ?>)' title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm action-btn"
                                                onclick="deleteTable(<?php echo $t['table_id']; ?>, '<?php echo htmlspecialchars($t['table_number']); ?>')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div><!-- /container -->

<!-- ══════════════════ ADD MODAL ══════════════════ -->
<div class="modal fade" id="addTableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle text-primary me-2"></i>Add New Table</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-validate="true">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Table Number *</label>
                            <input type="text" name="table_number" class="form-control"
                                   placeholder="e.g. T1, A01" required maxlength="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Seating Capacity *</label>
                            <input type="number" name="seating_capacity" class="form-control"
                                   min="1" max="20" placeholder="e.g. 4" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control"
                                   list="locationList" placeholder="e.g. Window Side">
                            <datalist id="locationList">
                                <option value="Window Side">
                                <option value="Center">
                                <option value="Corner">
                                <option value="Private Room">
                                <option value="Bar Area">
                                <option value="Outdoor">
                                <option value="Rooftop">
                                <option value="VIP Section">
                            </datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="occupied">Occupied</option>
                                <option value="reserved">Reserved</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_table" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Add Table
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════ EDIT MODAL ══════════════════ -->
<div class="modal fade" id="editTableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit text-primary me-2"></i>Edit Table</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-validate="true">
                <input type="hidden" name="table_id" id="edit_table_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Table Number *</label>
                            <input type="text" name="table_number" id="edit_table_number"
                                   class="form-control" required maxlength="10">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Seating Capacity *</label>
                            <input type="number" name="seating_capacity" id="edit_seating_capacity"
                                   class="form-control" min="1" max="20" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" id="edit_location"
                                   class="form-control" list="locationList">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status *</label>
                            <select name="status" id="edit_status" class="form-select" required>
                                <option value="available">Available</option>
                                <option value="occupied">Occupied</option>
                                <option value="reserved">Reserved</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_table" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Table
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="table_id"    id="del_table_id">
    <input type="hidden" name="delete_table" value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="../assets/js/validation.js"></script>
<script>
// ── Floor Plan Builder ──────────────────────────────────────────────
const floorData = <?php echo json_encode($all_tables); ?>;

function buildFloor(positions) {
    const plan = document.getElementById('floorPlan');
    plan.innerHTML = '';

    if (!floorData.length) {
        plan.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-muted"><div class="text-center"><i class="fas fa-chair fs-1 mb-2 d-block"></i>No tables to display</div></div>';
        return;
    }

    const W = plan.offsetWidth  || 800;
    const H = plan.offsetHeight || 420;

    floorData.forEach((t, i) => {
        const pos = positions ? positions[i] : null;
        const size = t.seating_capacity <= 2 ? 80 : t.seating_capacity <= 4 ? 95 : t.seating_capacity <= 6 ? 110 : 125;
        const x  = pos ? pos.x : Math.max(10, Math.min(W - size - 10, 40 + (i % 5) * (W / 5)));
        const y  = pos ? pos.y : Math.max(10, Math.min(H - size - 10, 30 + Math.floor(i / 5) * 130));

        const el = document.createElement('div');
        el.className = `floor-table ${t.status}`;
        el.style.cssText = `left:${x}px;top:${y}px;width:${size}px;height:${size}px;`;
        el.innerHTML = `
            <i class="fas fa-chair mb-1" style="font-size:1.3rem;"></i>
            <div class="t-number">T${t.table_number}</div>
            <div class="t-seats"><i class="fas fa-user me-1"></i>${t.seating_capacity}</div>
            <div class="t-status">${t.status}</div>`;
        el.title = `Table ${t.table_number} | ${t.seating_capacity} seats | ${t.status}`;
        el.onclick = () => editTable(t);
        plan.appendChild(el);
    });
}

function randomizeFloor() {
    const plan = document.getElementById('floorPlan');
    const W = plan.offsetWidth  || 800;
    const H = plan.offsetHeight || 420;
    const positions = floorData.map(() => ({
        x: Math.random() * (W - 130) + 10,
        y: Math.random() * (H - 130) + 10
    }));
    buildFloor(positions);
}

window.addEventListener('load', () => buildFloor(null));
window.addEventListener('resize', () => buildFloor(null));

// ── View Toggle ────────────────────────────────────────────────────
function setView(v) {
    document.getElementById('gridView').style.display = v === 'grid' ? '' : 'none';
    document.getElementById('listView').style.display = v === 'list' ? '' : 'none';
    document.getElementById('btnGrid').classList.toggle('active', v === 'grid');
    document.getElementById('btnList').classList.toggle('active', v === 'list');
}

// ── Edit Table ─────────────────────────────────────────────────────
function editTable(t) {
    document.getElementById('edit_table_id').value          = t.table_id;
    document.getElementById('edit_table_number').value      = t.table_number;
    document.getElementById('edit_seating_capacity').value  = t.seating_capacity;
    document.getElementById('edit_location').value          = t.location || '';
    document.getElementById('edit_status').value            = t.status;
    new bootstrap.Modal(document.getElementById('editTableModal')).show();
}

// ── Delete Table ───────────────────────────────────────────────────
function deleteTable(id, number) {
    const msg = `Are you sure you want to delete <strong>Table ${number}</strong>?<br><small class="text-muted">This cannot be undone. Active orders/reservations may prevent deletion.</small>`;
    if (typeof RMS !== 'undefined' && RMS.confirmModal) {
        RMS.confirmModal({
            title: 'Delete Table',
            message: msg,
            confirmText: 'Delete',
            confirmClass: 'btn-danger',
            onConfirm: () => {
                document.getElementById('del_table_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        });
    } else {
        if (confirm(`Delete Table ${number}?`)) {
            document.getElementById('del_table_id').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
}

// ── Quick Status (card buttons need hidden input trick) ───────────
document.querySelectorAll('form button[name=update_status]').forEach(btn => {
    btn.addEventListener('click', function () {
        const status = this.getAttribute('onclick').match(/'([^']+)'/)[1];
        this.closest('form').querySelector('[name=new_status]').value = status;
    });
});

// ── Auto-dismiss alerts ────────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        bootstrap.Alert.getOrCreateInstance(a).close();
    });
}, 5000);
</script>
</body>
</html>