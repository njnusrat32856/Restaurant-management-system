<?php
// customer/my_orders.php - Order History
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$customer_name = $_SESSION['full_name'] ?? '';

// ── HANDLE REORDER ────────────────────────────────────────────────
// Copies all items from a past order back into the session cart.
if (isset($_GET['reorder']) && is_numeric($_GET['reorder'])) {
    $reorder_id = intval($_GET['reorder']);
    try {
        $database = new Database();
        $db       = $database->connect();

        // Verify this order belongs to this customer
        $chk = $db->prepare(
            "SELECT order_id FROM orders
             WHERE order_id = :id AND customer_name = :name"
        );
        $chk->execute([':id' => $reorder_id, ':name' => $customer_name]);

        if ($chk->fetch()) {
            // Fetch items that are still available in the menu
            $items = $db->prepare(
                "SELECT oi.item_id, mi.item_name, mi.price, oi.quantity,
                        oi.special_instructions
                 FROM order_items oi
                 JOIN menu_items mi ON mi.item_id = oi.item_id
                 WHERE oi.order_id = :id
                   AND mi.availability = 'available'"
            );
            $items->execute([':id' => $reorder_id]);
            $reorder_items = $items->fetchAll(PDO::FETCH_ASSOC);

            if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

            foreach ($reorder_items as $ri) {
                $iid = $ri['item_id'];
                if (isset($_SESSION['cart'][$iid])) {
                    $_SESSION['cart'][$iid]['quantity'] += $ri['quantity'];
                } else {
                    $_SESSION['cart'][$iid] = [
                        'item_id'   => $iid,
                        'item_name' => $ri['item_name'],
                        'price'     => (float)$ri['price'],
                        'quantity'  => $ri['quantity'],
                        'note'      => $ri['special_instructions'] ?? ''
                    ];
                }
            }
            $added = count($reorder_items);
            $_SESSION['order_success_msg'] = $added > 0
                ? $added . ' item' . ($added != 1 ? 's' : '') . ' from Order #'
                  . str_pad($reorder_id, 5, '0', STR_PAD_LEFT) . ' added to your cart.'
                : 'No available items could be added (some may no longer be on the menu).';
        }
    } catch (PDOException $e) {
        // silently ignore, just redirect
    }
    header('Location: my_orders.php');
    exit();
}

// ── FETCH DATA ────────────────────────────────────────────────────
$success_message = '';
$error_message   = '';

if (!empty($_SESSION['order_success_msg'])) {
    $success_message = $_SESSION['order_success_msg'];
    unset($_SESSION['order_success_msg']);
}

try {
    $database = new Database();
    $db       = $database->connect();

    // ── Filters ───────────────────────────────────────────────────
    $filter_status = $_GET['status'] ?? 'all';
    $view_order_id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $search_query  = trim($_GET['search'] ?? '');

    // ── Orders list query ─────────────────────────────────────────
    $query = "SELECT o.order_id, o.order_date, o.status, o.total_amount,
                     rt.table_number, rt.location,
                     b.payment_status, b.payment_method,
                     COUNT(oi.order_item_id) AS item_count,
                     SUM(oi.quantity)        AS total_qty
              FROM orders o
              LEFT JOIN restaurant_tables rt ON rt.table_id  = o.table_id
              LEFT JOIN billing           b  ON b.order_id   = o.order_id
              LEFT JOIN order_items       oi ON oi.order_id  = o.order_id
              WHERE o.customer_name = :name";

    if ($filter_status !== 'all') {
        $query .= " AND o.status = :status";
    }
    if (!empty($search_query)) {
        $query .= " AND (o.order_id LIKE :search
                         OR DATE(o.order_date) LIKE :search)";
    }

    $query .= " GROUP BY o.order_id, o.order_date, o.status, o.total_amount,
                         rt.table_number, rt.location,
                         b.payment_status, b.payment_method
               ORDER BY o.order_date DESC";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':name', $customer_name);
    if ($filter_status !== 'all')  $stmt->bindValue(':status', $filter_status);
    if (!empty($search_query)) {
        $sp = "%{$search_query}%";
        $stmt->bindValue(':search', $sp);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Single order detail ───────────────────────────────────────
    $order_detail = null;
    $order_items  = [];
    $bill_detail  = null;

    if ($view_order_id) {
        $det = $db->prepare(
            "SELECT o.*, rt.table_number, rt.location, rt.seating_capacity
             FROM orders o
             LEFT JOIN restaurant_tables rt ON rt.table_id = o.table_id
             WHERE o.order_id = :id AND o.customer_name = :name"
        );
        $det->execute([':id' => $view_order_id, ':name' => $customer_name]);
        $order_detail = $det->fetch(PDO::FETCH_ASSOC);

        if ($order_detail) {
            $items_stmt = $db->prepare(
                "SELECT oi.*, mi.item_name, mi.image_url,
                        c.category_name,
                        mi.availability
                 FROM order_items oi
                 JOIN menu_items mi ON mi.item_id  = oi.item_id
                 LEFT JOIN categories c ON c.category_id = mi.category_id
                 WHERE oi.order_id = :id
                 ORDER BY mi.item_name ASC"
            );
            $items_stmt->execute([':id' => $view_order_id]);
            $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            $bill_stmt = $db->prepare(
                "SELECT * FROM billing WHERE order_id = :id"
            );
            $bill_stmt->execute([':id' => $view_order_id]);
            $bill_detail = $bill_stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    // ── Stats ─────────────────────────────────────────────────────
    $stats = $db->prepare(
        "SELECT
            COUNT(*)                                               AS total_orders,
            SUM(CASE WHEN status = 'completed'  THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'pending'
                       OR status = 'preparing'
                       OR status = 'served'    THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(SUM(CASE WHEN status != 'cancelled'
                              THEN total_amount ELSE 0 END), 0)    AS total_spent
         FROM orders
         WHERE customer_name = :name"
    );
    $stats->execute([':name' => $customer_name]);
    $stats = $stats->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $orders = [];
    $stats  = ['total_orders'=>0,'completed'=>0,'active'=>0,'cancelled'=>0,'total_spent'=>0];
}

// ── Status helper ─────────────────────────────────────────────────
function order_badge(string $status): string {
    $map = [
        'pending'   => ['warning', 'dark',  'fa-clock',           'Pending'],
        'preparing' => ['info',    'dark',  'fa-fire',            'Preparing'],
        'served'    => ['primary', 'white', 'fa-concierge-bell',  'Served'],
        'completed' => ['success', 'white', 'fa-check-circle',    'Completed'],
        'cancelled' => ['danger',  'white', 'fa-times-circle',    'Cancelled'],
    ];
    [$bg, $text, $icon, $label] = $map[$status] ?? ['secondary','white','fa-circle', ucfirst($status)];
    return "<span class=\"status-badge bg-{$bg} text-{$text}\">
                <i class=\"fas {$icon} me-1\"></i>{$label}
            </span>";
}

function pay_badge(string $status): string {
    if ($status === 'paid') {
        return '<span class="pay-badge bg-success text-white"><i class="fas fa-check me-1"></i>Paid</span>';
    }
    return '<span class="pay-badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>';
}

$page_title = 'My Orders';
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
/* ── Hero ───────────────────────────────────────────────────────── */
.orders-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 60px 0 44px;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-top: 56px;
}
.orders-hero::before {
    content: '';
    position: absolute;
    top: -40%; right: -5%;
    width: 360px; height: 360px;
    background: rgba(255,255,255,.07);
    border-radius: 50%;
    pointer-events: none;
}
.orders-hero::after {
    content: '';
    position: absolute;
    bottom: -60%; left: 3%;
    width: 240px; height: 240px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
    pointer-events: none;
}

/* ── Stat cards ─────────────────────────────────────────────────── */
.stat-card {
    border-radius: 15px;
    padding: 22px 24px;
    border: none;
    transition: transform .3s;
}
.stat-card:hover { transform: translateY(-4px); }

/* ── Order row ──────────────────────────────────────────────────── */
.order-row {
    cursor: pointer;
    transition: all .2s;
    border-radius: 10px;
}
.order-row:hover {
    background: #f8f9fa !important;
    transform: translateX(3px);
}
.order-row.active-row {
    background: linear-gradient(135deg,#667eea12,#764ba212) !important;
    border-left: 3px solid #667eea !important;
}

/* ── Status / payment badges ────────────────────────────────────── */
.status-badge {
    padding: 4px 14px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
    white-space: nowrap;
}
.pay-badge {
    padding: 3px 12px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 600;
}

/* ── Detail panel ───────────────────────────────────────────────── */
.detail-card {
    border-radius: 15px;
    border: none;
    position: sticky;
    top: 80px;
}

/* ── Item rows ──────────────────────────────────────────────────── */
.item-row {
    padding: 12px;
    border-radius: 8px;
    background: #f8f9fa;
    margin-bottom: 8px;
    transition: all .2s;
}
.item-row:hover { background: #e9ecef; transform: translateX(3px); }
.item-row:last-child { margin-bottom: 0; }

.item-thumb {
    width: 52px; height: 52px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
}
.item-thumb-placeholder {
    width: 52px; height: 52px;
    border-radius: 8px;
    background: linear-gradient(135deg,#667eea18,#764ba218);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    color: #667eea66;
    flex-shrink: 0;
}

/* ── Summary rows ───────────────────────────────────────────────── */
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f1f1f1;
}
.summary-row:last-child { border-bottom: none; }

/* ── Timeline ───────────────────────────────────────────────────── */
.timeline { position: relative; padding-left: 38px; }
.timeline::before {
    content: '';
    position: absolute;
    left: 14px; top: 0; bottom: 0;
    width: 2px; background: #dee2e6;
}
.tl-item { position: relative; margin-bottom: 20px; }
.tl-item:last-child { margin-bottom: 0; }
.tl-dot {
    position: absolute;
    left: -31px; top: 3px;
    width: 12px; height: 12px;
    border-radius: 50%;
    border: 3px solid;
}
.tl-dot.done    { background: #28a745; border-color: #28a745; }
.tl-dot.current { background: white;   border-color: #667eea;
                  animation: pulse 1.5s infinite; }
.tl-dot.future  { background: white;   border-color: #dee2e6; }
.tl-dot.cancelled { background: #dc3545; border-color: #dc3545; }

@keyframes pulse {
    0%,100% { box-shadow: 0 0 0 0   rgba(102,126,234,.5); }
    50%     { box-shadow: 0 0 0 6px rgba(102,126,234,0);  }
}

/* ── Print ──────────────────────────────────────────────────────── */
@media print {
    .no-print, nav, footer { display: none !important; }
    .detail-card { position: static; }
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="orders-hero">
    <div class="container position-relative">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <p class="mb-1 opacity-75 small text-uppercase fw-semibold">
                    <i class="fas fa-user me-2"></i>
                    <?php echo htmlspecialchars($customer_name); ?>
                </p>
                <h1 class="display-5 fw-bold mb-2">My Orders</h1>
                <p class="lead opacity-80 mb-0">
                    Track your orders, view receipts and reorder your favourites.
                </p>
            </div>
            <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                <i class="fas fa-receipt text-white opacity-20" style="font-size:7rem;"></i>
            </div>
        </div>
    </div>
</section>

<!-- ── Body ──────────────────────────────────────────────────────── -->
<div class="container-fluid py-4">

    <!-- Alerts -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <a href="../customer/cart.php" class="btn btn-sm btn-success ms-3">
            <i class="fas fa-shopping-cart me-1"></i>View Cart
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ── Stat Cards ──────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['total_orders']; ?></div>
                        <div class="opacity-90 small">Total Orders</div>
                    </div>
                    <i class="fas fa-receipt fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['completed']; ?></div>
                        <div class="opacity-90 small">Completed</div>
                    </div>
                    <i class="fas fa-check-circle fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['active']; ?></div>
                        <div class="opacity-90 small">Active</div>
                    </div>
                    <i class="fas fa-fire fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#1e3c72,#2a5298)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold">
                            ৳<?php echo number_format($stats['total_spent'], 0); ?>
                        </div>
                        <div class="opacity-90 small">Total Spent</div>
                    </div>
                    <i class="fas fa-coins fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── LEFT: Orders list ────────────────────────────────────── -->
        <div class="col-lg-<?php echo $order_detail ? '5' : '12'; ?>">
            <div class="card shadow-sm" style="border-radius:15px;">

                <!-- Header + filter + search -->
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-4">
                            <h6 class="fw-bold mb-0">
                                <i class="fas fa-list text-primary me-2"></i>
                                Orders
                                <span class="badge bg-secondary rounded-pill ms-1">
                                    <?php echo count($orders); ?>
                                </span>
                            </h6>
                        </div>
                        <div class="col-md-5">
                            <form method="GET" class="d-flex gap-1">
                                <?php if ($filter_status !== 'all'): ?>
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                                <?php endif; ?>
                                <input type="text" name="search" class="form-control form-control-sm"
                                       placeholder="Search by order # or date…"
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary px-2">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php if (!empty($search_query)): ?>
                                <a href="my_orders.php<?php echo $filter_status !== 'all' ? '?status='.$filter_status : ''; ?>"
                                   class="btn btn-sm btn-outline-secondary px-2" title="Clear">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <div class="col-md-3">
                            <a href="../customer/menu.php"
                               class="btn btn-sm btn-success w-100">
                                <i class="fas fa-plus me-1"></i>New Order
                            </a>
                        </div>
                    </div>

                    <!-- Status filter tabs -->
                    <div class="mt-3 d-flex gap-1 flex-wrap">
                        <?php
                        $tabs = [
                            'all'       => ['All',       'secondary'],
                            'pending'   => ['Pending',   'warning'],
                            'preparing' => ['Preparing', 'info'],
                            'served'    => ['Served',    'primary'],
                            'completed' => ['Completed', 'success'],
                            'cancelled' => ['Cancelled', 'danger'],
                        ];
                        foreach ($tabs as $val => [$label, $color]):
                            $active = $filter_status === $val ? 'active' : 'outline';
                            $href   = 'my_orders.php?status=' . $val
                                     . (!empty($search_query) ? '&search='.urlencode($search_query) : '');
                        ?>
                        <a href="<?php echo $href; ?>"
                           class="btn btn-<?php echo $active === 'active' ? $color : 'outline-'.$color; ?> btn-sm">
                            <?php echo $label; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Orders list -->
                <div class="card-body p-0">
                    <?php if (!empty($orders)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Order</th>
                                    <th>Date</th>
                                    <th class="d-none d-md-table-cell">Items</th>
                                    <th>Status</th>
                                    <th class="text-end pe-3">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                            <?php $is_active = $view_order_id == $o['order_id']; ?>
                                <tr class="order-row <?php echo $is_active ? 'active-row' : ''; ?>"
                                    onclick="window.location='my_orders.php?id=<?php echo $o['order_id']; ?>&status=<?php echo urlencode($filter_status); ?><?php echo !empty($search_query) ? '&search='.urlencode($search_query) : ''; ?>'">
                                    <td class="ps-3">
                                        <div class="fw-bold">
                                            #<?php echo str_pad($o['order_id'], 5, '0', STR_PAD_LEFT); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php if ($o['table_number']): ?>
                                            <i class="fas fa-chair me-1"></i>Table <?php echo htmlspecialchars($o['table_number']); ?>
                                            <?php else: ?>
                                            <i class="fas fa-box me-1"></i>Takeaway
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold">
                                            <?php echo date('d M Y', strtotime($o['order_date'])); ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?php echo date('h:i A', strtotime($o['order_date'])); ?>
                                        </div>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <span class="badge bg-light text-dark border">
                                            <?php echo $o['total_qty'] ?? 0; ?> item<?php echo ($o['total_qty'] ?? 0) != 1 ? 's' : ''; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo order_badge($o['status']); ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="fw-bold text-success">
                                            ৳<?php echo number_format($o['total_amount'], 2); ?>
                                        </div>
                                        <?php if ($o['payment_status']): ?>
                                        <div><?php echo pay_badge($o['payment_status']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php else: ?>
                    <!-- Empty state -->
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-receipt fs-1 d-block mb-3 opacity-30"></i>
                        <h5>No orders found</h5>
                        <p class="small mb-4">
                            <?php if ($filter_status !== 'all' || !empty($search_query)): ?>
                            Try a different filter or search term.
                            <?php else: ?>
                            You haven't placed any orders yet.
                            <?php endif; ?>
                        </p>
                        <a href="../customer/menu.php" class="btn btn-primary">
                            <i class="fas fa-utensils me-2"></i>Browse Menu
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- ── RIGHT: Order detail panel ────────────────────────────── -->
        <?php if ($order_detail): ?>
        <div class="col-lg-7">
            <div class="card detail-card shadow-sm">

                <!-- Detail header -->
                <div class="card-header bg-white d-flex justify-content-between align-items-center"
                     style="border-radius:15px 15px 0 0 !important;">
                    <div>
                        <h6 class="fw-bold mb-0">
                            <i class="fas fa-file-alt text-primary me-2"></i>
                            Order #<?php echo str_pad($order_detail['order_id'], 5, '0', STR_PAD_LEFT); ?>
                        </h6>
                        <small class="text-muted">
                            <?php echo date('D, d M Y · h:i A', strtotime($order_detail['order_date'])); ?>
                        </small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php echo order_badge($order_detail['status']); ?>
                        <a href="my_orders.php?status=<?php echo urlencode($filter_status); ?><?php echo !empty($search_query) ? '&search='.urlencode($search_query) : ''; ?>"
                           class="btn btn-sm btn-outline-secondary no-print" title="Close">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>

                <div class="card-body">

                    <!-- ── Items ─────────────────────────────────────── -->
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-utensils text-success me-2"></i>
                        Items Ordered
                        <span class="badge bg-secondary ms-1"><?php echo count($order_items); ?></span>
                    </h6>

                    <?php foreach ($order_items as $oi): ?>
                    <div class="item-row d-flex align-items-center gap-3">

                        <!-- Thumbnail -->
                        <?php if (!empty($oi['image_url'])): ?>
                        <img src="../assets/images/<?php echo htmlspecialchars($oi['image_url']); ?>"
                             class="item-thumb"
                             alt="<?php echo htmlspecialchars($oi['item_name']); ?>"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="item-thumb-placeholder" style="display:none;">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <?php else: ?>
                        <div class="item-thumb-placeholder">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <?php endif; ?>

                        <!-- Info -->
                        <div class="flex-grow-1 min-width-0">
                            <div class="fw-semibold text-truncate">
                                <?php echo htmlspecialchars($oi['item_name']); ?>
                                <?php if ($oi['availability'] !== 'available'): ?>
                                <span class="badge bg-secondary ms-1" style="font-size:.65rem;">
                                    Off Menu
                                </span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($oi['category_name'] ?? ''); ?>
                                &nbsp;·&nbsp;৳<?php echo number_format($oi['price'], 2); ?> each
                            </small>
                            <?php if (!empty($oi['special_instructions'])): ?>
                            <div class="small text-muted fst-italic mt-1">
                                <i class="fas fa-sticky-note me-1"></i>
                                <?php echo htmlspecialchars($oi['special_instructions']); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Qty + subtotal -->
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-success">
                                ৳<?php echo number_format($oi['subtotal'], 2); ?>
                            </div>
                            <small class="text-muted">× <?php echo $oi['quantity']; ?></small>
                        </div>

                    </div>
                    <?php endforeach; ?>

                    <!-- ── Order info ─────────────────────────────────── -->
                    <div class="row g-3 mt-2 pt-3 border-top">
                        <div class="col-6">
                            <small class="text-muted d-block">Table</small>
                            <strong>
                                <?php if ($order_detail['table_number']): ?>
                                Table <?php echo htmlspecialchars($order_detail['table_number']); ?>
                                <?php if ($order_detail['location']): ?>
                                <span class="text-muted fw-normal small">
                                    — <?php echo htmlspecialchars($order_detail['location']); ?>
                                </span>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted fw-normal">Takeaway</span>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Payment Method</small>
                            <?php if ($bill_detail): ?>
                            <?php
                            $pm_map = [
                                'cash'    => ['fa-money-bill',    'success', 'Cash'],
                                'card'    => ['fa-credit-card',   'primary', 'Card'],
                                'digital' => ['fa-mobile-alt',    'info',    'Digital'],
                            ];
                            [$pm_icon, $pm_color, $pm_label] = $pm_map[$bill_detail['payment_method']] ?? ['fa-circle','secondary','—'];
                            ?>
                            <span class="pay-badge bg-<?php echo $pm_color; ?> bg-opacity-10
                                         text-<?php echo $pm_color; ?> border border-<?php echo $pm_color; ?>">
                                <i class="fas <?php echo $pm_icon; ?> me-1"></i><?php echo $pm_label; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ── Bill summary ───────────────────────────────── -->
                    <?php if ($bill_detail): ?>
                    <div class="mt-3 pt-3 border-top">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-file-invoice-dollar text-primary me-2"></i>
                            Bill Summary
                        </h6>
                        <div class="summary-row">
                            <span class="text-muted">Subtotal</span>
                            <span>৳<?php echo number_format($bill_detail['subtotal'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">Tax (5%)</span>
                            <span>৳<?php echo number_format($bill_detail['tax_amount'], 2); ?></span>
                        </div>
                        <?php if ($bill_detail['discount_amount'] > 0): ?>
                        <div class="summary-row">
                            <span class="text-muted">Discount</span>
                            <span class="text-danger">
                                −৳<?php echo number_format($bill_detail['discount_amount'], 2); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-row">
                            <span class="fw-bold">Total</span>
                            <span class="fw-bold text-success fs-5">
                                ৳<?php echo number_format($bill_detail['total_amount'], 2); ?>
                            </span>
                        </div>
                        <div class="mt-2">
                            <?php echo pay_badge($bill_detail['payment_status']); ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="mt-3 pt-3 border-top">
                        <div class="summary-row">
                            <span class="fw-bold">Order Total</span>
                            <span class="fw-bold text-success fs-5">
                                ৳<?php echo number_format($order_detail['total_amount'], 2); ?>
                            </span>
                        </div>
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Bill not yet generated by staff.
                        </small>
                    </div>
                    <?php endif; ?>

                    <!-- ── Order status timeline ──────────────────────── -->
                    <div class="mt-3 pt-3 border-top">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-tasks text-success me-2"></i>Order Progress
                        </h6>
                        <?php
                        $status_flow = ['pending','preparing','served','completed'];
                        $cur_status  = $order_detail['status'];
                        $cur_idx     = array_search($cur_status, $status_flow);
                        $is_cancelled = $cur_status === 'cancelled';

                        $tl_steps = [
                            'pending'   => ['fa-clock',           'Order Received',   'Logged and awaiting kitchen.'],
                            'preparing' => ['fa-fire',            'Kitchen Preparing','Being freshly prepared.'],
                            'served'    => ['fa-concierge-bell',  'Food Served',      'Delivered to your table.'],
                            'completed' => ['fa-check-circle',    'Completed',        'Order fully completed.'],
                        ];
                        ?>
                        <div class="timeline">
                        <?php foreach ($tl_steps as $step_key => [$icon, $title, $desc]):
                            $step_idx = array_search($step_key, $status_flow);
                            if ($is_cancelled) {
                                $dot = ($step_idx < $cur_idx || $cur_idx === false) ? 'done' : 'future';
                            } elseif ($cur_idx !== false) {
                                if ($step_idx < $cur_idx)       $dot = 'done';
                                elseif ($step_idx === $cur_idx) $dot = 'current';
                                else                            $dot = 'future';
                            } else {
                                $dot = 'future';
                            }
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot <?php echo $dot; ?>"></div>
                            <div class="fw-semibold small <?php echo $dot === 'future' ? 'text-muted' : ''; ?>">
                                <i class="fas <?php echo $icon; ?> me-1
                                   <?php echo $dot==='done'||$dot==='current' ? 'text-success' : 'text-muted'; ?>"></i>
                                <?php echo $title; ?>
                                <?php if ($dot === 'current'): ?>
                                <span class="badge bg-primary ms-1" style="font-size:.65rem;">Now</span>
                                <?php elseif ($dot === 'done'): ?>
                                <i class="fas fa-check text-success ms-1" style="font-size:.75rem;"></i>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted"><?php echo $desc; ?></small>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($is_cancelled): ?>
                        <div class="tl-item">
                            <div class="tl-dot cancelled"></div>
                            <div class="fw-semibold small text-danger">
                                <i class="fas fa-times-circle me-1"></i>Cancelled
                            </div>
                            <small class="text-muted">This order was cancelled.</small>
                        </div>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- ── Actions ────────────────────────────────────── -->
                    <div class="mt-3 pt-3 border-top no-print">
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-tools text-primary me-2"></i>Actions
                        </h6>
                        <div class="d-flex flex-wrap gap-2">

                            <!-- Reorder -->
                            <a href="my_orders.php?reorder=<?php echo $order_detail['order_id']; ?>"
                               class="btn btn-success btn-sm"
                               onclick="return confirm('Add items from this order to your cart?')">
                                <i class="fas fa-redo me-1"></i>Reorder
                            </a>

                            <!-- View confirmation page if active -->
                            <?php if (in_array($order_detail['status'], ['pending','preparing','served'])): ?>
                            <a href="order_confirmation.php?order=<?php echo $order_detail['order_id']; ?>"
                               class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-eye me-1"></i>Track Order
                            </a>
                            <?php endif; ?>

                            <!-- Print receipt -->
                            <button class="btn btn-outline-secondary btn-sm"
                                    onclick="window.print()">
                                <i class="fas fa-print me-1"></i>Print
                            </button>

                        </div>
                    </div>

                </div><!-- /card-body -->
            </div><!-- /detail-card -->
        </div>
        <?php endif; ?>

    </div><!-- /row -->

</div><!-- /container-fluid -->

<?php include '../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// Auto-dismiss success alerts
setTimeout(() => {
    document.querySelectorAll('.alert-success').forEach(a =>
        bootstrap.Alert.getOrCreateInstance(a).close()
    );
}, 5000);
</script>
</body>
</html>