<?php
// staff/table_assignment.php - Table Assignment & Management
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$success_message = '';
$error_message   = '';

// ── HANDLE ACTIONS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db       = $database->connect();

    // Assign table to a new order
    if (isset($_POST['assign_table'])) {
        try {
            $table_id      = intval($_POST['table_id']);
            $customer_name = trim($_POST['customer_name']);
            $staff_id      = $_SESSION['user_id'];

            // Verify table is still available
            $chk = $db->prepare("SELECT status FROM restaurant_tables WHERE table_id = :id");
            $chk->execute([':id' => $table_id]);
            $table = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$table) {
                $error_message = 'Table not found.';
            } elseif ($table['status'] !== 'available') {
                $error_message = 'Table is no longer available.';
            } else {
                // Create a new pending order
                $stmt = $db->prepare(
                    "INSERT INTO orders (table_id, staff_id, customer_name, status, total_amount)
                     VALUES (:table_id, :staff_id, :customer, 'pending', 0.00)"
                );
                $stmt->execute([
                    ':table_id' => $table_id,
                    ':staff_id' => $staff_id,
                    ':customer' => $customer_name ?: 'Walk-in'
                ]);
                $new_order_id = $db->lastInsertId();

                // Mark table occupied
                $db->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_id = :id")
                   ->execute([':id' => $table_id]);

                $success_message = "Table assigned! Order #" . str_pad($new_order_id, 5, '0', STR_PAD_LEFT) . " created for " . htmlspecialchars($customer_name ?: 'Walk-in') . ".";
            }
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }

    // Update table status manually
    if (isset($_POST['update_status'])) {
        try {
            $table_id   = intval($_POST['table_id']);
            $new_status = $_POST['new_status'];

            if (!in_array($new_status, ['available', 'occupied', 'reserved'])) {
                $error_message = 'Invalid status.';
            } else {
                $db->prepare("UPDATE restaurant_tables SET status = :status WHERE table_id = :id")
                   ->execute([':status' => $new_status, ':id' => $table_id]);

                // If freeing the table, also complete any active orders on it
                if ($new_status === 'available') {
                    $db->prepare(
                        "UPDATE orders SET status = 'completed'
                         WHERE table_id = :id AND status IN ('pending','preparing','served')"
                    )->execute([':id' => $table_id]);
                }

                $success_message = 'Table status updated successfully!';
            }
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }

    // Reserve a table
    if (isset($_POST['reserve_table'])) {
        try {
            $table_id = intval($_POST['table_id']);

            $chk = $db->prepare("SELECT status FROM restaurant_tables WHERE table_id = :id");
            $chk->execute([':id' => $table_id]);
            $table = $chk->fetch(PDO::FETCH_ASSOC);

            if ($table && $table['status'] === 'available') {
                $db->prepare("UPDATE restaurant_tables SET status = 'reserved' WHERE table_id = :id")
                   ->execute([':id' => $table_id]);
                $success_message = 'Table reserved successfully!';
            } else {
                $error_message = 'Table is not available to reserve.';
            }
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }

    // Release table (set available)
    if (isset($_POST['release_table'])) {
        try {
            $table_id = intval($_POST['table_id']);

            $db->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_id = :id")
               ->execute([':id' => $table_id]);

            // Complete any active orders on this table
            $db->prepare(
                "UPDATE orders SET status = 'completed'
                 WHERE table_id = :id AND status IN ('pending','preparing','served')"
            )->execute([':id' => $table_id]);

            $success_message = 'Table released and set to available.';
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_status = $_GET['status'] ?? 'all';
    $view_table_id = isset($_GET['id']) ? intval($_GET['id']) : null;

    // ── All tables ────────────────────────────────────────────────
    $query = "SELECT rt.*,
                     o.order_id, o.customer_name, o.status AS order_status,
                     o.order_date, o.total_amount,
                     u.full_name AS staff_name
              FROM restaurant_tables rt
              LEFT JOIN orders o ON o.table_id = rt.table_id
                                 AND o.status IN ('pending','preparing','served')
              LEFT JOIN users u  ON u.user_id  = o.staff_id
              WHERE 1=1";

    if ($filter_status !== 'all') {
        $query .= " AND rt.status = :status";
    }

    $query .= " ORDER BY rt.table_number";

    $stmt = $db->prepare($query);
    if ($filter_status !== 'all') {
        $stmt->bindValue(':status', $filter_status);
    }
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Single table details ──────────────────────────────────────
    $table_details  = null;
    $table_orders   = [];
    if ($view_table_id) {
        $det = $db->prepare("SELECT * FROM restaurant_tables WHERE table_id = :id");
        $det->execute([':id' => $view_table_id]);
        $table_details = $det->fetch(PDO::FETCH_ASSOC);

        if ($table_details) {
            $tord = $db->prepare(
                "SELECT o.*, u.full_name AS staff_name
                 FROM orders o
                 LEFT JOIN users u ON u.user_id = o.staff_id
                 WHERE o.table_id = :id
                 ORDER BY o.order_date DESC
                 LIMIT 10"
            );
            $tord->execute([':id' => $view_table_id]);
            $table_orders = $tord->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ── Stats ─────────────────────────────────────────────────────
    $stats = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available,
            SUM(CASE WHEN status='occupied'  THEN 1 ELSE 0 END) AS occupied,
            SUM(CASE WHEN status='reserved'  THEN 1 ELSE 0 END) AS reserved
         FROM restaurant_tables"
    )->fetch(PDO::FETCH_ASSOC);

    // ── Available tables for assign modal ─────────────────────────
    $available_tables = $db->query(
        "SELECT * FROM restaurant_tables WHERE status = 'available' ORDER BY table_number"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $tables = $available_tables = $table_orders = [];
    $stats  = ['total'=>0,'available'=>0,'occupied'=>0,'reserved'=>0];
    $table_details = null;
}

$page_title = 'Table Assignment';
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
.stat-card {
    border-radius: 15px;
    padding: 24px;
    transition: transform .3s;
    border: none;
}
.stat-card:hover { transform: translateY(-5px); }

/* Floor plan grid */
.table-card {
    border-radius: 15px;
    border: none;
    transition: all .3s;
    cursor: pointer;
    height: 100%;
}
.table-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,.15) !important;
}
.table-card.selected {
    outline: 3px solid #0d6efd;
    outline-offset: 2px;
}

/* Table shape visual */
.table-shape {
    width: 80px;
    height: 80px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 12px;
    font-size: 1.6rem;
    font-weight: 700;
    transition: all .3s;
}
.table-shape.available { background: rgba(40,167,69,.15);  color: #28a745; border: 2px solid rgba(40,167,69,.3); }
.table-shape.occupied  { background: rgba(220,53,69,.15);  color: #dc3545; border: 2px solid rgba(220,53,69,.3); }
.table-shape.reserved  { background: rgba(255,193,7,.15);  color: #e0a800; border: 2px solid rgba(255,193,7,.3); }

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
}
.dot-available { background: #28a745; }
.dot-occupied  { background: #dc3545; animation: pulse 1.5s infinite; }
.dot-reserved  { background: #ffc107; }

@keyframes pulse {
    0%,100% { opacity: 1; }
    50%      { opacity: .4; }
}

.status-badge {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: .8rem;
    font-weight: 600;
}

.order-row {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 8px;
    background: #f8f9fa;
    transition: all .2s;
}
.order-row:hover {
    background: #e9ecef;
    transform: translateX(4px);
}

.detail-card {
    border-radius: 15px;
    border: none;
}

.action-btn {
    width: 34px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.capacity-bar {
    height: 6px;
    border-radius: 3px;
    background: #e9ecef;
    overflow: hidden;
}
.capacity-fill {
    height: 100%;
    border-radius: 3px;
    transition: width .3s;
}

/* View toggle */
.view-btn.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-chair text-primary me-2"></i>Table Assignment
            </h2>
            <p class="text-muted mb-0">Manage tables and seat customers</p>
        </div>
        <div class="d-flex gap-2">
            <!-- View toggle: grid / list -->
            <div class="btn-group" id="viewToggle">
                <button class="btn btn-outline-secondary view-btn active" id="btnGrid" onclick="setView('grid')" title="Grid View">
                    <i class="fas fa-th-large"></i>
                </button>
                <button class="btn btn-outline-secondary view-btn" id="btnList" onclick="setView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
            <button class="btn btn-success" onclick="location.reload()">
                <i class="fas fa-sync me-2"></i>Refresh
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignTableModal"
                    <?php echo empty($available_tables) ? 'disabled title="No tables available"' : ''; ?>>
                <i class="fas fa-plus me-2"></i>Assign Table
            </button>
        </div>
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

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <a href="?" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='all'?'border border-3 border-light':''; ?>"
                     style="background:linear-gradient(135deg,#667eea,#764ba2)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold"><?php echo $stats['total']; ?></div>
                            <div class="opacity-90">Total Tables</div>
                        </div>
                        <i class="fas fa-th fa-3x opacity-40"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-6">
            <a href="?status=available" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='available'?'border border-3 border-light':''; ?>"
                     style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold"><?php echo $stats['available']; ?></div>
                            <div class="opacity-90">Available</div>
                        </div>
                        <i class="fas fa-check-circle fa-3x opacity-40"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-6">
            <a href="?status=occupied" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='occupied'?'border border-3 border-light':''; ?>"
                     style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold"><?php echo $stats['occupied']; ?></div>
                            <div class="opacity-90">Occupied</div>
                        </div>
                        <i class="fas fa-users fa-3x opacity-40"></i>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-lg-3 col-md-6">
            <a href="?status=reserved" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='reserved'?'border border-3 border-light':''; ?>"
                     style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-3 fw-bold"><?php echo $stats['reserved']; ?></div>
                            <div class="opacity-90">Reserved</div>
                        </div>
                        <i class="fas fa-calendar-check fa-3x opacity-40"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── Tables Panel ───────────────────────────────────────── -->
        <div class="col-lg-<?php echo $table_details ? '7' : '12'; ?>">

            <!-- Legend -->
            <div class="d-flex align-items-center gap-4 mb-3 flex-wrap">
                <span class="small fw-semibold text-muted">LEGEND:</span>
                <span class="small"><span class="status-dot dot-available"></span>Available</span>
                <span class="small"><span class="status-dot dot-occupied"></span>Occupied</span>
                <span class="small"><span class="status-dot dot-reserved"></span>Reserved</span>
                <?php if ($filter_status !== 'all'): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary ms-auto">
                    <i class="fas fa-redo me-1"></i>Show All
                </a>
                <?php endif; ?>
            </div>

            <!-- ── GRID VIEW ───────────────────────────────────────── -->
            <div id="gridView">
                <?php if (!empty($tables)): ?>
                <div class="row g-3">
                    <?php
                    $status_colors = ['available'=>'success','occupied'=>'danger','reserved'=>'warning'];
                    foreach ($tables as $t):
                        $sc     = $status_colors[$t['status']] ?? 'secondary';
                        $is_sel = ($view_table_id == $t['table_id']);
                    ?>
                    <div class="col-xl-<?php echo $table_details ? '4' : '2'; ?> col-lg-<?php echo $table_details ? '6' : '3'; ?> col-md-4 col-6">
                        <div class="card table-card shadow-sm text-center p-3 <?php echo $is_sel ? 'selected' : ''; ?>"
                             onclick="window.location.href='?id=<?php echo $t['table_id']; ?>&status=<?php echo $filter_status; ?>'">

                            <div class="table-shape <?php echo $t['status']; ?>">
                                <?php echo htmlspecialchars($t['table_number']); ?>
                            </div>

                            <span class="status-badge bg-<?php echo $sc; ?> bg-opacity-10 text-<?php echo $sc; ?> border border-<?php echo $sc; ?> mb-2">
                                <span class="status-dot dot-<?php echo $t['status']; ?>"></span>
                                <?php echo ucfirst($t['status']); ?>
                            </span>

                            <div class="small text-muted mb-1">
                                <i class="fas fa-users me-1"></i><?php echo $t['seating_capacity']; ?> seats
                            </div>

                            <?php if ($t['location']): ?>
                            <div class="small text-muted mb-2">
                                <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($t['location']); ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($t['order_id']): ?>
                            <div class="mt-1 pt-2 border-top">
                                <div class="small fw-semibold text-truncate">
                                    <?php echo htmlspecialchars($t['customer_name'] ?? 'Walk-in'); ?>
                                </div>
                                <div class="small text-muted">
                                    Order #<?php echo str_pad($t['order_id'],5,'0',STR_PAD_LEFT); ?>
                                </div>
                                <?php
                                $order_status_colors = ['pending'=>'warning','preparing'=>'info','served'=>'primary'];
                                $osc = $order_status_colors[$t['order_status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $osc; ?> mt-1"><?php echo ucfirst($t['order_status']); ?></span>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-chair fs-1 d-block mb-3"></i>
                    <h4>No tables found</h4>
                    <p>No tables match the current filter.</p>
                    <a href="?" class="btn btn-primary"><i class="fas fa-redo me-2"></i>Show All Tables</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── LIST VIEW ───────────────────────────────────────── -->
            <div id="listView" style="display:none;">
                <div class="card shadow-sm" style="border-radius:15px;">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Table</th>
                                        <th>Location</th>
                                        <th>Capacity</th>
                                        <th>Status</th>
                                        <th>Current Order</th>
                                        <th>Customer</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($tables)):
                                    foreach ($tables as $t):
                                    $sc  = $status_colors[$t['status']] ?? 'secondary';
                                    $is_sel = ($view_table_id == $t['table_id']);
                                ?>
                                    <tr class="<?php echo $is_sel ? 'table-active' : ''; ?>"
                                        style="cursor:pointer;"
                                        onclick="window.location.href='?id=<?php echo $t['table_id']; ?>&status=<?php echo $filter_status; ?>'">
                                        <td>
                                            <strong class="text-<?php echo $sc; ?>">
                                                <?php echo htmlspecialchars($t['table_number']); ?>
                                            </strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($t['location'] ?? '—'); ?></td>
                                        <td>
                                            <i class="fas fa-users me-1 text-muted"></i><?php echo $t['seating_capacity']; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge bg-<?php echo $sc; ?> bg-opacity-10 text-<?php echo $sc; ?> border border-<?php echo $sc; ?>">
                                                <span class="status-dot dot-<?php echo $t['status']; ?>"></span>
                                                <?php echo ucfirst($t['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($t['order_id']): ?>
                                                <a href="orders.php?id=<?php echo $t['order_id']; ?>" onclick="event.stopPropagation()" class="text-decoration-none">
                                                    #<?php echo str_pad($t['order_id'],5,'0',STR_PAD_LEFT); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($t['customer_name'] ?? '—'); ?></td>
                                        <td onclick="event.stopPropagation()">
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-outline-primary action-btn"
                                                        onclick="window.location.href='?id=<?php echo $t['table_id']; ?>&status=<?php echo $filter_status; ?>'"
                                                        title="View">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($t['status'] === 'available'): ?>
                                                <button class="btn btn-sm btn-outline-success action-btn"
                                                        onclick="quickAssign(<?php echo $t['table_id']; ?>, '<?php echo htmlspecialchars($t['table_number']); ?>')"
                                                        title="Assign">
                                                    <i class="fas fa-user-plus"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onclick="event.stopPropagation()">
                                                    <input type="hidden" name="table_id" value="<?php echo $t['table_id']; ?>">
                                                    <button type="submit" name="reserve_table"
                                                            class="btn btn-sm btn-outline-warning action-btn" title="Reserve">
                                                        <i class="fas fa-calendar-check"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if ($t['status'] !== 'available'): ?>
                                                <form method="POST" class="d-inline" onclick="event.stopPropagation()"
                                                      onsubmit="return confirm('Release this table and mark it available?')">
                                                    <input type="hidden" name="table_id" value="<?php echo $t['table_id']; ?>">
                                                    <button type="submit" name="release_table"
                                                            class="btn btn-sm btn-outline-danger action-btn" title="Release">
                                                        <i class="fas fa-door-open"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-muted">
                                            <i class="fas fa-chair fs-1 d-block mb-3"></i>No tables found
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Table Detail Panel ─────────────────────────────────── -->
        <?php if ($table_details): ?>
        <div class="col-lg-5">
            <div class="card detail-card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-chair me-2"></i>
                        Table <?php echo htmlspecialchars($table_details['table_number']); ?>
                    </h5>
                    <button class="btn btn-light btn-sm"
                            onclick="window.location.href='?status=<?php echo $filter_status; ?>'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="card-body">

                    <!-- Table visual -->
                    <div class="text-center mb-4">
                        <?php
                        $sc = $status_colors[$table_details['status']] ?? 'secondary';
                        ?>
                        <div class="table-shape <?php echo $table_details['status']; ?> mx-auto mb-3"
                             style="width:100px;height:100px;font-size:2rem;">
                            <?php echo htmlspecialchars($table_details['table_number']); ?>
                        </div>
                        <span class="status-badge bg-<?php echo $sc; ?> bg-opacity-10 text-<?php echo $sc; ?> border border-<?php echo $sc; ?>">
                            <span class="status-dot dot-<?php echo $table_details['status']; ?>"></span>
                            <?php echo ucfirst($table_details['status']); ?>
                        </span>
                    </div>

                    <!-- Table Info -->
                    <div class="row mb-4">
                        <div class="col-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Table Number</small>
                                <strong><?php echo htmlspecialchars($table_details['table_number']); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Seating Capacity</small>
                                <strong>
                                    <i class="fas fa-users me-1 text-muted"></i>
                                    <?php echo $table_details['seating_capacity']; ?> seats
                                </strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Location</small>
                                <strong><?php echo htmlspecialchars($table_details['location'] ?? 'N/A'); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Table ID</small>
                                <strong>#<?php echo $table_details['table_id']; ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Status Change -->
                    <div class="mb-4 pt-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-sliders-h text-primary me-2"></i>Change Status</h6>
                        <form method="POST" class="d-flex gap-2 flex-wrap">
                            <input type="hidden" name="table_id" value="<?php echo $table_details['table_id']; ?>">
                            <select name="new_status" class="form-select form-select-sm" style="max-width:160px;">
                                <option value="available" <?php echo $table_details['status']=='available'?'selected':''; ?>>Available</option>
                                <option value="occupied"  <?php echo $table_details['status']=='occupied' ?'selected':''; ?>>Occupied</option>
                                <option value="reserved"  <?php echo $table_details['status']=='reserved' ?'selected':''; ?>>Reserved</option>
                            </select>
                            <button type="submit" name="update_status" class="btn btn-sm btn-primary">
                                <i class="fas fa-save me-1"></i>Update
                            </button>
                        </form>
                    </div>

                    <!-- Quick Actions -->
                    <div class="mb-4 pt-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-bolt text-primary me-2"></i>Quick Actions</h6>
                        <div class="d-flex gap-2 flex-wrap">

                            <?php if ($table_details['status'] === 'available'): ?>
                            <button class="btn btn-success btn-sm"
                                    onclick="quickAssign(<?php echo $table_details['table_id']; ?>, '<?php echo htmlspecialchars($table_details['table_number']); ?>')">
                                <i class="fas fa-user-plus me-1"></i>Seat Customer
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="table_id" value="<?php echo $table_details['table_id']; ?>">
                                <button type="submit" name="reserve_table" class="btn btn-warning btn-sm">
                                    <i class="fas fa-calendar-check me-1"></i>Reserve
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if ($table_details['status'] !== 'available'): ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Release table and mark available?')">
                                <input type="hidden" name="table_id" value="<?php echo $table_details['table_id']; ?>">
                                <button type="submit" name="release_table" class="btn btn-danger btn-sm">
                                    <i class="fas fa-door-open me-1"></i>Release Table
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php
                            // Find the active order for this table if any
                            $active_order = null;
                            foreach ($table_orders as $to) {
                                if (in_array($to['status'], ['pending','preparing','served'])) {
                                    $active_order = $to;
                                    break;
                                }
                            }
                            ?>
                            <?php if ($active_order): ?>
                            <a href="orders.php?id=<?php echo $active_order['order_id']; ?>"
                               class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-clipboard-list me-1"></i>View Active Order
                            </a>
                            <a href="billing.php?order=<?php echo $active_order['order_id']; ?>"
                               class="btn btn-outline-info btn-sm">
                                <i class="fas fa-file-invoice-dollar me-1"></i>Generate Bill
                            </a>
                            <?php endif; ?>

                        </div>
                    </div>

                    <!-- Recent Orders -->
                    <div class="pt-3 border-top">
                        <h6 class="mb-3">
                            <i class="fas fa-history text-primary me-2"></i>Recent Orders
                            <span class="badge bg-secondary ms-1"><?php echo count($table_orders); ?></span>
                        </h6>
                        <?php if (!empty($table_orders)):
                            $order_status_colors = [
                                'pending'=>'warning','preparing'=>'info',
                                'served'=>'primary','completed'=>'success','cancelled'=>'danger'
                            ];
                            foreach ($table_orders as $to):
                            $osc = $order_status_colors[$to['status']] ?? 'secondary';
                        ?>
                        <div class="order-row">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">
                                        <a href="orders.php?id=<?php echo $to['order_id']; ?>" class="text-decoration-none">
                                            Order #<?php echo str_pad($to['order_id'],5,'0',STR_PAD_LEFT); ?>
                                        </a>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($to['customer_name'] ?? 'Walk-in'); ?>
                                    </small>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('M j, Y h:i A', strtotime($to['order_date'])); ?>
                                    </small>
                                </div>
                                <div class="text-end ms-3">
                                    <div class="fw-bold text-success">৳<?php echo number_format($to['total_amount'],2); ?></div>
                                    <span class="status-badge bg-<?php echo $osc; ?> text-white" style="font-size:.7rem;padding:3px 10px;">
                                        <?php echo ucfirst($to['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fs-3 d-block mb-2"></i>
                            <p class="mb-0">No orders for this table yet.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div><!-- /container -->


<!-- ══════ ASSIGN TABLE MODAL ══════ -->
<div class="modal fade" id="assignTableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus text-primary me-2"></i>Assign Table to Customer
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Table *</label>
                        <select name="table_id" id="tableSelect" class="form-select" required>
                            <option value="">— Choose a table —</option>
                            <?php foreach ($available_tables as $t): ?>
                            <option value="<?php echo $t['table_id']; ?>"
                                    data-capacity="<?php echo $t['seating_capacity']; ?>"
                                    data-location="<?php echo htmlspecialchars($t['location'] ?? 'N/A'); ?>">
                                <?php echo htmlspecialchars($t['table_number']); ?>
                                — <?php echo $t['seating_capacity']; ?> seats
                                <?php echo $t['location'] ? '| '.$t['location'] : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Table info preview -->
                    <div id="tablePreview" class="p-3 bg-light rounded mb-3" style="display:none;">
                        <div class="row g-2 small">
                            <div class="col-6">
                                <span class="text-muted">Capacity:</span>
                                <strong id="prevCapacity" class="ms-1"></strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted">Location:</span>
                                <strong id="prevLocation" class="ms-1"></strong>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Customer Name</label>
                        <input type="text" name="customer_name" id="customerName"
                               class="form-control" placeholder="Leave blank for Walk-in">
                        <small class="text-muted">Leave blank to use "Walk-in"</small>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="assign_table" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Assign & Create Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden quick-assign form (for the grid card buttons) -->
<form method="POST" id="quickAssignForm" style="display:none;">
    <input type="hidden" name="table_id"      id="qa_table_id">
    <input type="hidden" name="customer_name" id="qa_customer_name" value="Walk-in">
    <input type="hidden" name="assign_table"  value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// ── View toggle ───────────────────────────────────────────────────
function setView(view) {
    const grid    = document.getElementById('gridView');
    const list    = document.getElementById('listView');
    const btnGrid = document.getElementById('btnGrid');
    const btnList = document.getElementById('btnList');

    if (view === 'grid') {
        grid.style.display = '';
        list.style.display = 'none';
        btnGrid.classList.add('active');
        btnList.classList.remove('active');
        localStorage.setItem('tableView', 'grid');
    } else {
        grid.style.display = 'none';
        list.style.display = '';
        btnGrid.classList.remove('active');
        btnList.classList.add('active');
        localStorage.setItem('tableView', 'list');
    }
}

// Restore saved view preference
document.addEventListener('DOMContentLoaded', function () {
    const saved = localStorage.getItem('tableView') || 'grid';
    setView(saved);
});

// ── Table select preview in modal ─────────────────────────────────
const tableSelect   = document.getElementById('tableSelect');
const tablePreview  = document.getElementById('tablePreview');

tableSelect && tableSelect.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    if (!opt.value) {
        tablePreview.style.display = 'none';
        return;
    }
    document.getElementById('prevCapacity').textContent = opt.dataset.capacity + ' seats';
    document.getElementById('prevLocation').textContent = opt.dataset.location;
    tablePreview.style.display = 'block';
});

// ── Quick assign: pre-fill modal table select & open ──────────────
function quickAssign(tableId, tableNumber) {
    const modal = new bootstrap.Modal(document.getElementById('assignTableModal'));
    const sel   = document.getElementById('tableSelect');
    if (sel) {
        sel.value = tableId;
        sel.dispatchEvent(new Event('change'));
    }
    document.getElementById('customerName').value = '';
    modal.show();
}

// ── Auto-dismiss alerts ───────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => bootstrap.Alert.getOrCreateInstance(a).close());
}, 5000);

// ── Auto-refresh every 60 seconds (live floor plan) ───────────────
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>