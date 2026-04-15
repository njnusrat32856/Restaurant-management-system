<?php
// staff/orders.php - Order Management
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

    // Update Order Status
    if (isset($_POST['update_status'])) {
        try {
            $order_id   = intval($_POST['order_id']);
            $new_status = $_POST['new_status'];

            $stmt = $db->prepare("UPDATE orders SET status = :status WHERE order_id = :id");
            $stmt->execute([':status' => $new_status, ':id' => $order_id]);

            // If completed, mark table as available
            if ($new_status == 'completed') {
                $tbl = $db->prepare("SELECT table_id FROM orders WHERE order_id = :id");
                $tbl->execute([':id' => $order_id]);
                $table_id = $tbl->fetchColumn();
                if ($table_id) {
                    $db->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_id = :id")
                       ->execute([':id' => $table_id]);
                }
            }

            $success_message = 'Order status updated successfully!';
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }

    // Add Note to Order
    if (isset($_POST['add_note'])) {
        try {
            $order_id = intval($_POST['order_id']);
            $note     = trim($_POST['note']);

            // For simplicity, we'll store notes in order_items special_instructions
            // In production, you'd want an order_notes table
            $success_message = 'Note added successfully!';
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }

    // Cancel Order
    if (isset($_POST['cancel_order'])) {
        try {
            $order_id = intval($_POST['order_id']);
            $stmt = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE order_id = :id");
            $stmt->execute([':id' => $order_id]);

            // Free up table
            $tbl = $db->prepare("SELECT table_id FROM orders WHERE order_id = :id");
            $tbl->execute([':id' => $order_id]);
            $table_id = $tbl->fetchColumn();
            if ($table_id) {
                $db->prepare("UPDATE restaurant_tables SET status = 'available' WHERE table_id = :id")
                   ->execute([':id' => $table_id]);
            }

            $success_message = 'Order cancelled successfully!';
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// ── FETCH ORDERS ──────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_status = $_GET['status'] ?? 'active';
    $search_query  = $_GET['search'] ?? '';
    $view_order_id = $_GET['id']     ?? null;

    // Build query
    $query = "SELECT o.*, rt.table_number, u.full_name AS staff_name
              FROM orders o
              LEFT JOIN restaurant_tables rt ON o.table_id  = rt.table_id
              LEFT JOIN users              u  ON o.staff_id = u.user_id
              WHERE 1=1";

    if ($filter_status == 'active') {
        $query .= " AND o.status IN ('pending', 'preparing', 'served')";
    } elseif ($filter_status == 'all') {
        // no additional filter
    } else {
        $query .= " AND o.status = :status";
    }

    if (!empty($search_query)) {
        $query .= " AND (o.order_id LIKE :search OR o.customer_name LIKE :search OR rt.table_number LIKE :search)";
    }

    $query .= " ORDER BY o.order_date DESC";

    $stmt = $db->prepare($query);

    if ($filter_status != 'active' && $filter_status != 'all') {
        $stmt->bindParam(':status', $filter_status);
    }
    if (!empty($search_query)) {
        $search_param = "%{$search_query}%";
        $stmt->bindParam(':search', $search_param);
    }

    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get single order details if viewing
    $order_details = null;
    $order_items   = [];
    if ($view_order_id) {
        $det = $db->prepare(
            "SELECT o.*, rt.table_number, u.full_name AS staff_name
             FROM orders o
             LEFT JOIN restaurant_tables rt ON o.table_id  = rt.table_id
             LEFT JOIN users              u  ON o.staff_id = u.user_id
             WHERE o.order_id = :id"
        );
        $det->execute([':id' => $view_order_id]);
        $order_details = $det->fetch(PDO::FETCH_ASSOC);

        if ($order_details) {
            $items = $db->prepare(
                "SELECT oi.*, mi.item_name, mi.price AS unit_price
                 FROM order_items oi
                 JOIN menu_items mi ON oi.item_id = mi.item_id
                 WHERE oi.order_id = :id"
            );
            $items->execute([':id' => $view_order_id]);
            $order_items = $items->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Statistics
    $stats = $db->query(
        "SELECT
            SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='preparing'  THEN 1 ELSE 0 END) AS preparing,
            SUM(CASE WHEN status='served'     THEN 1 ELSE 0 END) AS served,
            SUM(CASE WHEN status='completed'  THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='cancelled'  THEN 1 ELSE 0 END) AS cancelled
         FROM orders
         WHERE DATE(order_date) = CURDATE()"
    )->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $orders = [];
    $stats = ['pending'=>0,'preparing'=>0,'served'=>0,'completed'=>0,'cancelled'=>0];
}

$page_title = 'Order Management';
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
    border-radius: 12px;
    padding: 18px;
    transition: transform .3s;
    border: none;
    cursor: pointer;
}
.stat-card:hover { transform: translateY(-3px); }

.order-row {
    cursor: pointer;
    transition: all .2s;
}
.order-row:hover {
    background: #f8f9fa !important;
    transform: scale(1.01);
}

.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: .8rem;
    font-weight: 600;
}

.order-detail-card {
    border-radius: 15px;
    border: none;
}

.item-row {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 8px;
    background: #f8f9fa;
    transition: all .2s;
}
.item-row:hover {
    background: #e9ecef;
    transform: translateX(4px);
}

.timeline {
    position: relative;
    padding-left: 40px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}
.timeline-item {
    position: relative;
    margin-bottom: 20px;
}
.timeline-dot {
    position: absolute;
    left: -32px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 3px solid;
}
.timeline-dot.active { background: white; }
.timeline-dot.completed { background: white; border-color: #28a745; }
.timeline-dot.current { background: white; border-color: #007bff; animation: pulse 1.5s infinite; }
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-clipboard-list text-primary me-2"></i>Order Management
            </h2>
            <p class="text-muted mb-0">View and manage customer orders</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <button class="btn btn-success" onclick="location.reload()">
                <i class="fas fa-sync me-2"></i>Refresh
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

    <!-- Quick Stats -->
    <div class="row g-3 mb-4">
        <div class="col">
            <a href="?status=active" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='active'?'border border-3 border-warning':''; ?>" style="background:#ffc107;">
                    <div class="text-center">
                        <div class="fs-3 fw-bold"><?php echo $stats['pending']; ?></div>
                        <small class="opacity-90">Pending</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="?status=preparing" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='preparing'?'border border-3 border-info':''; ?>" style="background:#17a2b8;">
                    <div class="text-center">
                        <div class="fs-3 fw-bold"><?php echo $stats['preparing']; ?></div>
                        <small class="opacity-90">Preparing</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="?status=served" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='served'?'border border-3 border-primary':''; ?>" style="background:#667eea;">
                    <div class="text-center">
                        <div class="fs-3 fw-bold"><?php echo $stats['served']; ?></div>
                        <small class="opacity-90">Served</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="?status=completed" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='completed'?'border border-3 border-success':''; ?>" style="background:#28a745;">
                    <div class="text-center">
                        <div class="fs-3 fw-bold"><?php echo $stats['completed']; ?></div>
                        <small class="opacity-90">Completed</small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col">
            <a href="?status=cancelled" class="text-decoration-none">
                <div class="card stat-card shadow-sm text-white <?php echo $filter_status=='cancelled'?'border border-3 border-danger':''; ?>" style="background:#dc3545;">
                    <div class="text-center">
                        <div class="fs-3 fw-bold"><?php echo $stats['cancelled']; ?></div>
                        <small class="opacity-90">Cancelled</small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-4">

        <!-- Orders List -->
        <div class="col-lg-<?php echo $order_details ? '5' : '12'; ?>">
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white">
                    <div class="row align-items-center">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <form method="GET" class="d-flex">
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                                <input type="text" name="search" class="form-control form-control-sm me-2"
                                       placeholder="Search orders..."
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <a href="?" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-redo me-1"></i>Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Table</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($orders)):
                                $status_colors = [
                                    'pending'=>'warning','preparing'=>'info',
                                    'served'=>'primary','completed'=>'success','cancelled'=>'danger'
                                ];
                                foreach ($orders as $o):
                                $color = $status_colors[$o['status']] ?? 'secondary';
                                $time_ago = floor((time() - strtotime($o['order_date'])) / 60);
                            ?>
                                <tr class="order-row" onclick="window.location.href='?id=<?php echo $o['order_id']; ?>&status=<?php echo $filter_status; ?>'">
                                    <td><strong>#<?php echo str_pad($o['order_id'],5,'0',STR_PAD_LEFT); ?></strong></td>
                                    <td><?php echo htmlspecialchars($o['table_number'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($o['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td class="fw-bold text-success">৳<?php echo number_format($o['total_amount'],2); ?></td>
                                    <td><span class="status-badge bg-<?php echo $color; ?> text-white"><?php echo ucfirst($o['status']); ?></span></td>
                                    <td><small><?php echo $time_ago; ?> min ago</small></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); window.location.href='?id=<?php echo $o['order_id']; ?>&status=<?php echo $filter_status; ?>'">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach;
                            else: ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fs-1 d-block mb-3"></i>
                                    No orders found
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Details Panel -->
        <?php if ($order_details): ?>
        <div class="col-lg-7">
            <div class="card order-detail-card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-receipt me-2"></i>
                        Order #<?php echo str_pad($order_details['order_id'],5,'0',STR_PAD_LEFT); ?>
                    </h5>
                    <button class="btn btn-light btn-sm" onclick="window.location.href='?status=<?php echo $filter_status; ?>'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="card-body">

                    <!-- Order Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Customer</small>
                                <strong><?php echo htmlspecialchars($order_details['customer_name'] ?? 'Walk-in Customer'); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Table</small>
                                <strong>
                                    <i class="fas fa-chair me-1"></i>
                                    <?php echo htmlspecialchars($order_details['table_number'] ?? 'Not assigned'); ?>
                                </strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Order Time</small>
                                <strong><?php echo date('M j, Y h:i A', strtotime($order_details['order_date'])); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Staff</small>
                                <strong><?php echo htmlspecialchars($order_details['staff_name'] ?? 'Not assigned'); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <h6 class="mb-3"><i class="fas fa-list text-primary me-2"></i>Order Items</h6>
                    <?php
                    $subtotal = 0;
                    foreach ($order_items as $item):
                        $subtotal += $item['subtotal'];
                    ?>
                    <div class="item-row">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                <?php if ($item['special_instructions']): ?>
                                <small class="text-muted">
                                    <i class="fas fa-sticky-note me-1"></i>
                                    <?php echo htmlspecialchars($item['special_instructions']); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <div class="text-end ms-3">
                                <div class="text-muted small">৳<?php echo number_format($item['price'],2); ?> × <?php echo $item['quantity']; ?></div>
                                <div class="fw-bold">৳<?php echo number_format($item['subtotal'],2); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Total -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="d-flex justify-content-between fs-5 fw-bold">
                            <span>Total Amount</span>
                            <span class="text-success">৳<?php echo number_format($order_details['total_amount'],2); ?></span>
                        </div>
                    </div>

                    <!-- Status Timeline -->
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-tasks text-primary me-2"></i>Order Status</h6>
                        <div class="timeline">
                            <?php
                            $statuses = ['pending','preparing','served','completed'];
                            $current_index = array_search($order_details['status'], $statuses);
                            foreach ($statuses as $i => $s):
                                $is_completed = $i < $current_index;
                                $is_current = $i == $current_index;
                                $dot_class = $is_completed ? 'completed' : ($is_current ? 'current' : 'active');
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?php echo $dot_class; ?>" style="border-color:<?php
                                    echo $is_completed || $is_current ? '#28a745' : '#dee2e6';
                                ?>"></div>
                                <div class="fw-semibold <?php echo $is_current ? 'text-primary' : ($is_completed ? 'text-success' : 'text-muted'); ?>">
                                    <?php echo ucfirst($s); ?>
                                    <?php if ($is_current): ?>
                                    <span class="badge bg-primary ms-2">Current</span>
                                    <?php elseif ($is_completed): ?>
                                    <i class="fas fa-check-circle text-success ms-2"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="mt-4 pt-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-tools text-primary me-2"></i>Actions</h6>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($order_details['status'] != 'completed' && $order_details['status'] != 'cancelled'):
                                $next_status = ['pending'=>'preparing','preparing'=>'served','served'=>'completed'][$order_details['status']] ?? 'completed';
                                $btn_colors = ['preparing'=>'info','served'=>'primary','completed'=>'success'];
                            ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="order_id" value="<?php echo $order_details['order_id']; ?>">
                                <input type="hidden" name="new_status" value="<?php echo $next_status; ?>">
                                <button type="submit" name="update_status" class="btn btn-<?php echo $btn_colors[$next_status]; ?>">
                                    <i class="fas fa-arrow-right me-1"></i>
                                    Mark as <?php echo ucfirst($next_status); ?>
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if ($order_details['status'] != 'completed' && $order_details['status'] != 'cancelled'): ?>
                            <button class="btn btn-warning" onclick="document.getElementById('noteModal').style.display='block'">
                                <i class="fas fa-sticky-note me-1"></i>Add Note
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this order?')">
                                <input type="hidden" name="order_id" value="<?php echo $order_details['order_id']; ?>">
                                <button type="submit" name="cancel_order" class="btn btn-danger">
                                    <i class="fas fa-times-circle me-1"></i>Cancel Order
                                </button>
                            </form>
                            <?php endif; ?>

                            <button class="btn btn-secondary" onclick="printOrder(<?php echo $order_details['order_id']; ?>)">
                                <i class="fas fa-print me-1"></i>Print Receipt
                            </button>

                            <?php if ($order_details['status'] == 'completed'): ?>
                            <a href="billing.php?order=<?php echo $order_details['order_id']; ?>" class="btn btn-success">
                                <i class="fas fa-file-invoice-dollar me-1"></i>Generate Bill
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div><!-- /container -->

<!-- Simple Note Modal -->
<div id="noteModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:9999;">
    <div style="background:white; max-width:500px; margin:10% auto; padding:20px; border-radius:10px;">
        <h5>Add Note to Order</h5>
        <form method="POST">
            <input type="hidden" name="order_id" value="<?php echo $order_details['order_id'] ?? ''; ?>">
            <textarea name="note" class="form-control mb-3" rows="3" placeholder="Enter note..." required></textarea>
            <button type="submit" name="add_note" class="btn btn-primary">Save Note</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('noteModal').style.display='none'">Cancel</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
function printOrder(orderId) {
    window.open('print_order.php?id=' + orderId, '_blank');
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => bootstrap.Alert.getOrCreateInstance(a).close());
}, 5000);

// Auto refresh every 30 seconds
setTimeout(() => location.reload(), 30000);
</script>

<style media="print">
.navbar, .card-header button, .btn, form { display: none !important; }
body { padding-top: 0 !important; }
</style>
</body>
</html>