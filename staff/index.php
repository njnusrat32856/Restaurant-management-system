<?php
// staff/index.php - Staff Dashboard
ob_start();
session_start();

// Check if user is logged in and is staff or admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$success_message = '';
$error_message   = '';

// Handle quick actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db       = $database->connect();

    // ── UPDATE ORDER STATUS ───────────────────────────────────────
    if (isset($_POST['update_status'])) {
        try {
            $order_id   = intval($_POST['order_id']);
            $new_status = $_POST['new_status'];

            $stmt = $db->prepare(
                "UPDATE orders SET status = :status WHERE order_id = :id"
            );
            $stmt->execute([':status' => $new_status, ':id' => $order_id]);
            $success_message = 'Order status updated successfully!';
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }

    // ── ASSIGN TABLE ──────────────────────────────────────────────
    if (isset($_POST['assign_table'])) {
        try {
            $order_id = intval($_POST['order_id']);
            $table_id = intval($_POST['table_id']);

            $stmt = $db->prepare(
                "UPDATE orders SET table_id = :table WHERE order_id = :id"
            );
            $stmt->execute([':table' => $table_id, ':id' => $order_id]);

            // Update table status to occupied
            $db->prepare("UPDATE restaurant_tables SET status = 'occupied' WHERE table_id = :id")
               ->execute([':id' => $table_id]);

            $success_message = 'Table assigned successfully!';
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── FETCH DATA ────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    // Today's statistics
    $stats = $db->query(
        "SELECT
            COUNT(*)                                              AS total_orders,
            SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='preparing'  THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN status='served'     THEN 1 ELSE 0 END) AS served,
            SUM(CASE WHEN status='completed'  THEN 1 ELSE 0 END) AS completed,
            COALESCE(SUM(total_amount), 0)                        AS today_revenue
         FROM orders
         WHERE DATE(order_date) = CURDATE()"
    )->fetch(PDO::FETCH_ASSOC);

    // Active orders (pending, preparing, served)
    $active_orders = $db->query(
        "SELECT o.*, rt.table_number, u.full_name AS staff_name
         FROM orders o
         LEFT JOIN restaurant_tables rt ON o.table_id  = rt.table_id
         LEFT JOIN users              u  ON o.staff_id = u.user_id
         WHERE o.status IN ('pending', 'preparing', 'served')
         ORDER BY o.order_date DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Recent completed orders
    $recent_completed = $db->query(
        "SELECT o.*, rt.table_number, u.full_name AS staff_name
         FROM orders o
         LEFT JOIN restaurant_tables rt ON o.table_id  = rt.table_id
         LEFT JOIN users              u  ON o.staff_id = u.user_id
         WHERE o.status = 'completed' AND DATE(o.order_date) = CURDATE()
         ORDER BY o.order_date DESC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Available tables
    $available_tables = $db->query(
        "SELECT * FROM restaurant_tables WHERE status = 'available' ORDER BY table_number"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Occupied tables
    $occupied_tables = $db->query(
        "SELECT rt.*, o.order_id, o.customer_name, o.total_amount, o.order_date
         FROM restaurant_tables rt
         LEFT JOIN orders o ON rt.table_id = o.table_id
                            AND o.status IN ('pending', 'preparing', 'served')
         WHERE rt.status = 'occupied'
         ORDER BY rt.table_number"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Staff's today orders (if staff)
    if ($_SESSION['role'] == 'staff') {
        $my_orders = $db->prepare(
            "SELECT COUNT(*) AS my_orders,
                    COALESCE(SUM(total_amount), 0) AS my_revenue
             FROM orders
             WHERE staff_id = :id AND DATE(order_date) = CURDATE()"
        );
        $my_orders->execute([':id' => $_SESSION['user_id']]);
        $my_stats = $my_orders->fetch(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $stats = ['total_orders'=>0,'pending'=>0,'preparing'=>0,'served'=>0,'completed'=>0,'today_revenue'=>0];
    $active_orders = $recent_completed = $available_tables = $occupied_tables = [];
    $my_stats = ['my_orders'=>0,'my_revenue'=>0];
}

$page_title = 'Staff Dashboard';
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

.order-card {
    border-radius: 12px;
    border-left: 4px solid;
    transition: all .2s;
    cursor: pointer;
}
.order-card:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.order-card.pending   { border-color: #ffc107; }
.order-card.preparing { border-color: #17a2b8; }
.order-card.served    { border-color: #667eea; }
.order-card.completed { border-color: #28a745; }

.table-card {
    border-radius: 12px;
    padding: 16px;
    transition: all .3s;
    cursor: pointer;
}
.table-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,.12);
}
.table-card.available { background: #d4edda; border: 2px solid #28a745; }
.table-card.occupied  { background: #f8d7da; border: 2px solid #dc3545; }

.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: .8rem;
    font-weight: 600;
}

.quick-action-btn {
    border-radius: 10px;
    padding: 12px;
    font-weight: 500;
    transition: all .3s;
}
.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
}

.welcome-banner {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 15px;
    color: white;
    padding: 30px;
    margin-bottom: 30px;
}

.time-badge {
    background: rgba(255,255,255,.2);
    padding: 8px 16px;
    border-radius: 20px;
    font-size: .85rem;
}

.pulse {
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .6; }
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <!-- Welcome Banner -->
    <div class="welcome-banner shadow-sm">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-2">
                    <i class="fas fa-hand-wave me-2"></i>
                    Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
                </h2>
                <p class="mb-0 opacity-90">
                    <?php
                    $hour = date('H');
                    $greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
                    echo $greeting;
                    ?> — Ready to serve our customers today?
                </p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <div class="fs-5 mb-2">
                    <i class="fas fa-calendar-day me-2"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>
                <div class="fs-6 opacity-90">
                    <i class="fas fa-clock me-2"></i>
                    <span id="currentTime"><?php echo date('h:i:s A'); ?></span>
                </div>
            </div>
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
        <?php if ($_SESSION['role'] == 'staff'): ?>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#f093fb,#f5576c)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $my_stats['my_orders']; ?></div>
                        <div class="opacity-90">My Orders Today</div>
                    </div>
                    <i class="fas fa-user-check fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#fa709a,#fee140)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold">৳<?php echo number_format($my_stats['my_revenue'], 0); ?></div>
                        <div class="opacity-90">My Revenue Today</div>
                    </div>
                    <i class="fas fa-dollar-sign fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#ffc107,#ff9800)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold pulse"><?php echo $stats['pending']; ?></div>
                        <div class="opacity-90">Pending Orders</div>
                    </div>
                    <i class="fas fa-clock fa-3x opacity-40"></i>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#17a2b8,#00bcd4)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['preparing']; ?></div>
                        <div class="opacity-90">Preparing</div>
                    </div>
                    <i class="fas fa-fire fa-3x opacity-40"></i>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['served']; ?></div>
                        <div class="opacity-90">Served</div>
                    </div>
                    <i class="fas fa-utensils fa-3x opacity-40"></i>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#28a745,#4caf50)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['completed']; ?></div>
                        <div class="opacity-90">Completed</div>
                    </div>
                    <i class="fas fa-check-circle fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <h5 class="mb-3"><i class="fas fa-bolt text-warning me-2"></i>Quick Actions</h5>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="orders.php" class="btn btn-primary w-100 quick-action-btn">
                <i class="fas fa-clipboard-list d-block fs-3 mb-2"></i>
                Manage Orders
            </a>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="billing.php" class="btn btn-success w-100 quick-action-btn">
                <i class="fas fa-receipt d-block fs-3 mb-2"></i>
                Generate Bill
            </a>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="table_assignment.php" class="btn btn-info w-100 quick-action-btn">
                <i class="fas fa-chair d-block fs-3 mb-2"></i>
                Table Assignment
            </a>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="../customer/menu.php" class="btn btn-warning w-100 quick-action-btn">
                <i class="fas fa-book-open d-block fs-3 mb-2"></i>
                View Menu
            </a>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <button class="btn btn-secondary w-100 quick-action-btn" onclick="window.print()">
                <i class="fas fa-print d-block fs-3 mb-2"></i>
                Print Orders
            </button>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <a href="../customer/reservation.php" class="btn btn-dark w-100 quick-action-btn">
                <i class="fas fa-calendar-check d-block fs-3 mb-2"></i>
                Reservations
            </a>
        </div>
    </div>

    <div class="row g-4">

        <!-- Active Orders -->
        <div class="col-lg-8">
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-tasks text-primary me-2"></i>
                        Active Orders
                        <?php if (count($active_orders) > 0): ?>
                        <span class="badge bg-danger ms-2"><?php echo count($active_orders); ?></span>
                        <?php endif; ?>
                    </h5>
                    <a href="orders.php" class="btn btn-sm btn-outline-primary">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($active_orders)): ?>
                        <?php foreach ($active_orders as $order):
                            $time_diff = time() - strtotime($order['order_date']);
                            $minutes = floor($time_diff / 60);
                        ?>
                        <div class="order-card <?php echo $order['status']; ?> card mb-3 shadow-sm"
                             onclick="window.location.href='orders.php?id=<?php echo $order['order_id']; ?>'">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-2">
                                        <div class="fw-bold fs-5">#<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i><?php echo $minutes; ?> min ago
                                        </small>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-muted small">Table</div>
                                        <div class="fw-semibold">
                                            <i class="fas fa-chair me-1"></i>
                                            <?php echo htmlspecialchars($order['table_number'] ?? 'Not assigned'); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-muted small">Customer</div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($order['customer_name'] ?? 'Walk-in'); ?></div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="text-muted small">Amount</div>
                                        <div class="fw-bold text-success">৳<?php echo number_format($order['total_amount'], 2); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <form method="POST" class="d-flex gap-1" onclick="event.stopPropagation()">
                                            <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                            <?php
                                            $statuses = ['pending' => 'preparing', 'preparing' => 'served', 'served' => 'completed'];
                                            $next_status = $statuses[$order['status']] ?? 'completed';
                                            $btn_colors = ['preparing'=>'info', 'served'=>'primary', 'completed'=>'success'];
                                            $btn_icons = ['preparing'=>'fire', 'served'=>'utensils', 'completed'=>'check'];
                                            ?>
                                            <button type="submit" name="update_status" value="1"
                                                    class="btn btn-sm btn-<?php echo $btn_colors[$next_status]; ?> flex-fill"
                                                    onclick="this.form.new_status.value='<?php echo $next_status; ?>'">
                                                <i class="fas fa-<?php echo $btn_icons[$next_status]; ?> me-1"></i>
                                                <?php echo ucfirst($next_status); ?>
                                            </button>
                                            <input type="hidden" name="new_status" value="">
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fs-1 d-block mb-3"></i>
                            <h5>No active orders</h5>
                            <p>All orders have been completed</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Completed -->
            <?php if (!empty($recent_completed)): ?>
            <div class="card shadow-sm mt-4" style="border-radius:15px;">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-check-double text-success me-2"></i>
                        Recent Completed Orders
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Order</th>
                                    <th>Table</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_completed as $order): ?>
                                <tr style="cursor:pointer;" onclick="window.location.href='orders.php?id=<?php echo $order['order_id']; ?>'">
                                    <td><strong>#<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                    <td><?php echo htmlspecialchars($order['table_number'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td class="text-success fw-bold">৳<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td><small><?php echo date('h:i A', strtotime($order['order_date'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar: Tables -->
        <div class="col-lg-4">
            <!-- Occupied Tables -->
            <div class="card shadow-sm mb-4" style="border-radius:15px;">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-chair text-danger me-2"></i>
                        Occupied Tables
                        <span class="badge bg-danger ms-2"><?php echo count($occupied_tables); ?></span>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($occupied_tables)): ?>
                        <?php foreach ($occupied_tables as $table): ?>
                        <div class="table-card occupied mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-bold fs-5">Table <?php echo htmlspecialchars($table['table_number']); ?></div>
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($table['customer_name'] ?? 'Customer'); ?>
                                    </small>
                                    <?php if ($table['order_id']): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Order #<?php echo str_pad($table['order_id'], 5, '0', STR_PAD_LEFT); ?></small>
                                        <div class="fw-bold text-success">৳<?php echo number_format($table['total_amount'], 2); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <i class="fas fa-users text-danger"></i>
                                    <div class="small text-muted"><?php echo $table['seating_capacity']; ?> seats</div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted text-center py-3 mb-0">
                            <i class="fas fa-check-circle text-success fs-3 d-block mb-2"></i>
                            No occupied tables
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Available Tables -->
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white">
                    <h6 class="mb-0">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        Available Tables
                        <span class="badge bg-success ms-2"><?php echo count($available_tables); ?></span>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($available_tables)): ?>
                        <div class="row g-2">
                        <?php foreach ($available_tables as $table): ?>
                            <div class="col-4">
                                <div class="table-card available text-center">
                                    <div class="fw-bold">T<?php echo htmlspecialchars($table['table_number']); ?></div>
                                    <small class="text-muted">
                                        <i class="fas fa-users"></i>
                                        <?php echo $table['seating_capacity']; ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center py-3 mb-0">
                            <i class="fas fa-times-circle text-danger fs-3 d-block mb-2"></i>
                            No tables available
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// Update time every second
function updateTime() {
    const now = new Date();
    const time = now.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    document.getElementById('currentTime').textContent = time;
}
setInterval(updateTime, 1000);

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        bootstrap.Alert.getOrCreateInstance(a).close();
    });
}, 5000);

// Auto-refresh every 30 seconds to get latest orders
setTimeout(() => location.reload(), 30000);
</script>

<style media="print">
.navbar, .welcome-banner, .quick-action-btn, button { display: none !important; }
body { padding-top: 0 !important; }
</style>
</body>
</html>