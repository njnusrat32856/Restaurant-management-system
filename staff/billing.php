<?php
// staff/billing.php - Billing Management
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

// ── Flash messages from billing_process.php ──────────────────────
$success_message = '';
$error_message   = '';

if (!empty($_SESSION['billing_success'])) {
    $success_message = $_SESSION['billing_success'];
    unset($_SESSION['billing_success']);
}
if (!empty($_SESSION['billing_error'])) {
    $error_message = $_SESSION['billing_error'];
    unset($_SESSION['billing_error']);
}

// ── FETCH DATA ────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_status = $_GET['status'] ?? 'all';
    $filter_method = $_GET['method'] ?? 'all';
    $search_query  = $_GET['search'] ?? '';
    $view_bill_id  = isset($_GET['id']) ? intval($_GET['id']) : null;

    // Pre-fill from orders.php "Generate Bill" link
    $prefill_order = isset($_GET['order']) ? intval($_GET['order']) : null;

    // ── Build bills query ─────────────────────────────────────────
    $query = "SELECT b.*, o.customer_name, o.order_date, o.status AS order_status,
                     rt.table_number, u.full_name AS staff_name
              FROM billing b
              JOIN orders o               ON o.order_id  = b.order_id
              LEFT JOIN restaurant_tables rt ON rt.table_id = o.table_id
              LEFT JOIN users             u  ON u.user_id  = o.staff_id
              WHERE 1=1";

    if ($filter_status !== 'all') {
        $query .= " AND b.payment_status = :pstatus";
    }
    if ($filter_method !== 'all') {
        $query .= " AND b.payment_method = :method";
    }
    if (!empty($search_query)) {
        $query .= " AND (b.bill_id LIKE :search OR b.order_id LIKE :search OR o.customer_name LIKE :search)";
    }

    $query .= " ORDER BY b.bill_date DESC";

    $stmt = $db->prepare($query);
    if ($filter_status !== 'all') $stmt->bindValue(':pstatus', $filter_status);
    if ($filter_method !== 'all') $stmt->bindValue(':method',  $filter_method);
    if (!empty($search_query)) {
        $sp = "%{$search_query}%";
        $stmt->bindValue(':search', $sp);
    }
    $stmt->execute();
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Single bill details ───────────────────────────────────────
    $bill_details = null;
    $bill_items   = [];
    if ($view_bill_id) {
        $det = $db->prepare(
            "SELECT b.*, o.customer_name, o.order_date, o.status AS order_status,
                    rt.table_number, u.full_name AS staff_name
             FROM billing b
             JOIN orders o               ON o.order_id  = b.order_id
             LEFT JOIN restaurant_tables rt ON rt.table_id = o.table_id
             LEFT JOIN users             u  ON u.user_id  = o.staff_id
             WHERE b.bill_id = :id"
        );
        $det->execute([':id' => $view_bill_id]);
        $bill_details = $det->fetch(PDO::FETCH_ASSOC);

        if ($bill_details) {
            $items = $db->prepare(
                "SELECT oi.*, mi.item_name, mi.price AS unit_price
                 FROM order_items oi
                 JOIN menu_items mi ON mi.item_id = oi.item_id
                 WHERE oi.order_id = :id"
            );
            $items->execute([':id' => $bill_details['order_id']]);
            $bill_items = $items->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ── Stats ─────────────────────────────────────────────────────
    $stats = $db->query(
        "SELECT
            COUNT(*) AS total_bills,
            SUM(CASE WHEN payment_status='paid'    THEN 1 ELSE 0 END) AS paid_bills,
            SUM(CASE WHEN payment_status='pending' THEN 1 ELSE 0 END) AS pending_bills,
            SUM(CASE WHEN payment_status='paid'    THEN total_amount ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN payment_status='pending' THEN total_amount ELSE 0 END) AS pending_revenue,
            SUM(CASE WHEN DATE(bill_date)=CURDATE() AND payment_status='paid' THEN total_amount ELSE 0 END) AS today_revenue
         FROM billing"
    )->fetch(PDO::FETCH_ASSOC);

    // ── Unbilled orders for modal ─────────────────────────────────
    $unbilled = $db->query(
        "SELECT o.order_id, o.customer_name, o.order_date, o.total_amount, o.status,
                rt.table_number
         FROM orders o
         LEFT JOIN billing b             ON b.order_id  = o.order_id
         LEFT JOIN restaurant_tables rt  ON rt.table_id = o.table_id
         WHERE b.bill_id IS NULL
           AND o.status IN ('pending','preparing','served','completed')
         ORDER BY o.order_date DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $bills = $unbilled = [];
    $stats = ['total_bills'=>0,'paid_bills'=>0,'pending_bills'=>0,
              'total_revenue'=>0,'pending_revenue'=>0,'today_revenue'=>0];
}

$page_title = 'Billing';
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

.bill-row {
    cursor: pointer;
    transition: all .2s;
}
.bill-row:hover {
    background: #f8f9fa !important;
    transform: scale(1.005);
}

.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: .8rem;
    font-weight: 600;
}

.method-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
}

.bill-detail-card {
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

.action-btn {
    width: 34px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}

.receipt-divider {
    border-top: 2px dashed #dee2e6;
    margin: 12px 0;
}

@media print {
    .no-print { display: none !important; }
    body { padding-top: 0 !important; }
    .bill-detail-card { box-shadow: none !important; }
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
                <i class="fas fa-receipt text-primary me-2"></i>Billing Management
            </h2>
            <p class="text-muted mb-0">Generate and manage customer bills</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary no-print" onclick="window.print()">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateBillModal">
                <i class="fas fa-plus me-2"></i>Generate Bill
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
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['total_bills']; ?></div>
                        <div class="opacity-90">Total Bills</div>
                    </div>
                    <i class="fas fa-file-invoice-dollar fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['paid_bills']; ?></div>
                        <div class="opacity-90">Paid</div>
                    </div>
                    <i class="fas fa-check-circle fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['pending_bills']; ?></div>
                        <div class="opacity-90">Pending</div>
                    </div>
                    <i class="fas fa-clock fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#11998e,#28a745)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold">৳<?php echo number_format($stats['today_revenue'], 0); ?></div>
                        <div class="opacity-90">Today's Revenue</div>
                    </div>
                    <i class="fas fa-coins fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── Bills Table ────────────────────────────────────────── -->
        <div class="col-lg-<?php echo $bill_details ? '5' : '12'; ?>">
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <div class="row align-items-center g-2">
                        <div class="col-md-4">
                            <form method="GET" class="d-flex">
                                <?php if ($view_bill_id): ?>
                                <input type="hidden" name="id" value="<?php echo $view_bill_id; ?>">
                                <?php endif; ?>
                                <input type="text" name="search" class="form-control form-control-sm me-2"
                                       placeholder="Search bills..."
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex gap-2 flex-wrap justify-content-md-end">
                                <a href="?" class="btn btn-sm <?php echo ($filter_status=='all' && $filter_method=='all') ? 'btn-secondary' : 'btn-outline-secondary'; ?>">All</a>
                                <a href="?status=paid" class="btn btn-sm <?php echo $filter_status=='paid' ? 'btn-success' : 'btn-outline-success'; ?>">Paid</a>
                                <a href="?status=pending" class="btn btn-sm <?php echo $filter_status=='pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">Pending</a>
                                <a href="?method=cash" class="btn btn-sm <?php echo $filter_method=='cash' ? 'btn-dark' : 'btn-outline-dark'; ?>">Cash</a>
                                <a href="?method=card" class="btn btn-sm <?php echo $filter_method=='card' ? 'btn-primary' : 'btn-outline-primary'; ?>">Card</a>
                                <a href="?method=digital" class="btn btn-sm <?php echo $filter_method=='digital' ? 'btn-info' : 'btn-outline-info'; ?>">Digital</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Bill #</th>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Table</th>
                                    <th>Total</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($bills)):
                                $method_colors = ['cash'=>'success','card'=>'primary','digital'=>'info'];
                                $method_icons  = ['cash'=>'fa-money-bill','card'=>'fa-credit-card','digital'=>'fa-mobile-alt'];
                                foreach ($bills as $b):
                                $is_paid = $b['payment_status'] === 'paid';
                                $mc = $method_colors[$b['payment_method']] ?? 'secondary';
                                $mi = $method_icons[$b['payment_method']]  ?? 'fa-receipt';
                            ?>
                                <tr class="bill-row <?php echo ($view_bill_id == $b['bill_id']) ? 'table-active' : ''; ?>"
                                    onclick="window.location.href='?id=<?php echo $b['bill_id']; ?>&status=<?php echo $filter_status; ?>&method=<?php echo $filter_method; ?>'">
                                    <td><strong>#<?php echo str_pad($b['bill_id'],4,'0',STR_PAD_LEFT); ?></strong></td>
                                    <td>
                                        <a href="orders.php?id=<?php echo $b['order_id']; ?>"
                                           onclick="event.stopPropagation()" class="text-decoration-none">
                                            #<?php echo str_pad($b['order_id'],5,'0',STR_PAD_LEFT); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($b['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td>
                                        <?php if ($b['table_number']): ?>
                                            <span class="badge bg-light text-dark border">T<?php echo $b['table_number']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-success">৳<?php echo number_format($b['total_amount'],2); ?></td>
                                    <td>
                                        <span class="method-badge bg-<?php echo $mc; ?> bg-opacity-10 text-<?php echo $mc; ?> border border-<?php echo $mc; ?>">
                                            <i class="fas <?php echo $mi; ?> me-1"></i><?php echo ucfirst($b['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge bg-<?php echo $is_paid ? 'success' : 'warning'; ?> text-<?php echo $is_paid ? 'white' : 'dark'; ?>">
                                            <?php echo $is_paid ? 'Paid' : 'Pending'; ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('M j, h:i A', strtotime($b['bill_date'])); ?></small></td>
                                    <td onclick="event.stopPropagation()">
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-outline-primary action-btn"
                                                    onclick="window.location.href='?id=<?php echo $b['bill_id']; ?>&status=<?php echo $filter_status; ?>'"
                                                    title="View">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (!$is_paid): ?>
                                            <form method="POST" class="d-inline" onclick="event.stopPropagation()">
                                                <input type="hidden" name="bill_id" value="<?php echo $b['bill_id']; ?>">
                                                <button type="submit" name="mark_paid"
                                                        class="btn btn-sm btn-outline-success action-btn" title="Mark Paid">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                            <button class="btn btn-sm btn-outline-danger action-btn"
                                                    onclick="deleteBill(<?php echo $b['bill_id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-receipt fs-1 d-block mb-3"></i>
                                        No bills found
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Revenue footer -->
                <div class="card-footer bg-white" style="border-radius:0 0 15px 15px !important;">
                    <div class="d-flex justify-content-between flex-wrap gap-2 small text-muted">
                        <span><i class="fas fa-list me-1"></i><?php echo count($bills); ?> bill(s) shown</span>
                        <div class="d-flex gap-3">
                            <span class="text-success fw-semibold">
                                <i class="fas fa-check me-1"></i>Collected: ৳<?php echo number_format($stats['total_revenue'],2); ?>
                            </span>
                            <span class="text-warning fw-semibold">
                                <i class="fas fa-clock me-1"></i>Pending: ৳<?php echo number_format($stats['pending_revenue'],2); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Bill Detail / Receipt Panel ───────────────────────── -->
        <?php if ($bill_details): ?>
        <div class="col-lg-7">
            <div class="card bill-detail-card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-receipt me-2"></i>
                        Bill #<?php echo str_pad($bill_details['bill_id'],4,'0',STR_PAD_LEFT); ?>
                    </h5>
                    <div class="d-flex gap-2 no-print">
                        <button class="btn btn-light btn-sm" onclick="window.print()" title="Print Receipt">
                            <i class="fas fa-print me-1"></i>Print
                        </button>
                        <button class="btn btn-light btn-sm"
                                onclick="window.location.href='?status=<?php echo $filter_status; ?>&method=<?php echo $filter_method; ?>'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Restaurant header -->
                    <div class="text-center mb-3">
                        <h5 class="fw-bold mb-1">
                            <i class="fas fa-utensils text-primary me-2"></i>Fine Dine RMS
                        </h5>
                        <small class="text-muted d-block">123 Restaurant Street, Khilgaon, Dhaka 1212</small>
                        <small class="text-muted">Tel: +880 1234-567890</small>
                    </div>

                    <div class="receipt-divider"></div>

                    <!-- Bill meta -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Bill No.</small>
                                <strong>#<?php echo str_pad($bill_details['bill_id'],4,'0',STR_PAD_LEFT); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Customer</small>
                                <strong><?php echo htmlspecialchars($bill_details['customer_name'] ?? 'Walk-in Customer'); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Table</small>
                                <strong>
                                    <i class="fas fa-chair me-1"></i>
                                    <?php echo $bill_details['table_number'] ? 'Table '.$bill_details['table_number'] : 'N/A'; ?>
                                </strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <small class="text-muted d-block">Order No.</small>
                                <strong>
                                    <a href="orders.php?id=<?php echo $bill_details['order_id']; ?>">
                                        #<?php echo str_pad($bill_details['order_id'],5,'0',STR_PAD_LEFT); ?>
                                    </a>
                                </strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Order Date</small>
                                <strong><?php echo date('M j, Y h:i A', strtotime($bill_details['order_date'])); ?></strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Bill Date</small>
                                <strong><?php echo date('M j, Y h:i A', strtotime($bill_details['bill_date'])); ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <h6 class="mb-3"><i class="fas fa-list text-primary me-2"></i>Ordered Items</h6>
                    <?php foreach ($bill_items as $item): ?>
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

                    <!-- Totals -->
                    <div class="receipt-divider"></div>
                    <div class="mt-2">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Subtotal</span>
                            <span>৳<?php echo number_format($bill_details['subtotal'],2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Tax (5% VAT)</span>
                            <span>৳<?php echo number_format($bill_details['tax_amount'],2); ?></span>
                        </div>
                        <?php if ($bill_details['discount_amount'] > 0): ?>
                        <div class="d-flex justify-content-between mb-2 text-danger">
                            <span>Discount</span>
                            <span>-৳<?php echo number_format($bill_details['discount_amount'],2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="receipt-divider"></div>
                        <div class="d-flex justify-content-between fs-5 fw-bold">
                            <span>Total Amount</span>
                            <span class="text-success">৳<?php echo number_format($bill_details['total_amount'],2); ?></span>
                        </div>
                    </div>

                    <!-- Payment Info -->
                    <div class="mt-4 pt-3 border-top">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted d-block mb-1">Payment Method</small>
                                <?php
                                $pm  = $bill_details['payment_method'];
                                $method_colors = ['cash'=>'success','card'=>'primary','digital'=>'info'];
                                $method_icons  = ['cash'=>'fa-money-bill','card'=>'fa-credit-card','digital'=>'fa-mobile-alt'];
                                $pmc = $method_colors[$pm] ?? 'secondary';
                                $pmi = $method_icons[$pm]  ?? 'fa-receipt';
                                ?>
                                <span class="method-badge bg-<?php echo $pmc; ?> bg-opacity-10 text-<?php echo $pmc; ?> border border-<?php echo $pmc; ?>">
                                    <i class="fas <?php echo $pmi; ?> me-1"></i><?php echo ucfirst($pm); ?>
                                </span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block mb-1">Payment Status</small>
                                <?php $is_paid = $bill_details['payment_status'] === 'paid'; ?>
                                <span class="status-badge bg-<?php echo $is_paid ? 'success' : 'warning'; ?> text-<?php echo $is_paid ? 'white' : 'dark'; ?>">
                                    <i class="fas fa-<?php echo $is_paid ? 'check' : 'clock'; ?> me-1"></i>
                                    <?php echo $is_paid ? 'Paid' : 'Pending'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <?php if ($bill_details['staff_name']): ?>
                    <div class="mt-3 pt-3 border-top">
                        <small class="text-muted">
                            <i class="fas fa-user-tie me-1"></i>
                            Served by: <strong><?php echo htmlspecialchars($bill_details['staff_name']); ?></strong>
                        </small>
                    </div>
                    <?php endif; ?>

                    <!-- Receipt footer message -->
                    <div class="text-center mt-4 pt-3 border-top">
                        <p class="text-muted mb-1 small">Thank you for dining with us!</p>
                        <p class="text-muted mb-0 small">We look forward to seeing you again.</p>
                    </div>

                    <!-- Actions -->
                    <div class="mt-4 pt-3 border-top no-print">
                        <h6 class="mb-3"><i class="fas fa-tools text-primary me-2"></i>Actions</h6>
                        <div class="d-flex flex-wrap gap-2">

                            <?php if (!$is_paid): ?>
                            <!-- Mark Paid -->
                            <form method="POST" action="../modules/billing_process.php" style="display:inline;">
                                <input type="hidden" name="bill_id" value="<?php echo $bill_details['bill_id']; ?>">
                                <input type="hidden" name="payment_method" value="<?php echo htmlspecialchars($bill_details['payment_method']); ?>">
                                <button type="submit" name="mark_paid" class="btn btn-success">
                                    <i class="fas fa-check me-1"></i>Mark as Paid
                                </button>
                            </form>

                            <!-- Edit / Update Bill -->
                            <button class="btn btn-outline-primary"
                                    onclick="openUpdateModal(
                                        <?php echo $bill_details['bill_id']; ?>,
                                        <?php echo (float)$bill_details['discount_amount']; ?>,
                                        '<?php echo htmlspecialchars($bill_details['payment_method']); ?>'
                                    )">
                                <i class="fas fa-edit me-1"></i>Edit Bill
                            </button>

                            <!-- Delete -->
                            <button class="btn btn-danger"
                                    onclick="deleteBill(<?php echo $bill_details['bill_id']; ?>)">
                                <i class="fas fa-trash me-1"></i>Delete
                            </button>

                            <?php else: ?>
                            <!-- Void (paid bills — admin only) -->
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                            <button class="btn btn-outline-danger"
                                    onclick="openVoidModal(<?php echo $bill_details['bill_id']; ?>)">
                                <i class="fas fa-ban me-1"></i>Void Bill
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>

                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div><!-- /container -->

<!-- ══════ GENERATE BILL MODAL ══════ -->
<div class="modal fade" id="generateBillModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice-dollar text-primary me-2"></i>Generate New Bill
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../modules/billing_process.php">
                <div class="modal-body">

                    <?php if (empty($unbilled)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        No unbilled active orders at the moment.
                    </div>
                    <?php else: ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Order *</label>
                        <select name="order_id" id="orderSelect" class="form-select" required>
                            <option value="">— Choose an order —</option>
                            <?php foreach ($unbilled as $o): ?>
                            <option value="<?php echo $o['order_id']; ?>"
                                    data-subtotal="<?php echo $o['total_amount']; ?>"
                                    data-customer="<?php echo htmlspecialchars($o['customer_name'] ?? 'Walk-in'); ?>"
                                    data-table="<?php echo $o['table_number'] ? 'Table '.$o['table_number'] : 'N/A'; ?>"
                                    data-status="<?php echo ucfirst($o['status']); ?>"
                                    <?php echo ($prefill_order == $o['order_id']) ? 'selected' : ''; ?>>
                                #<?php echo str_pad($o['order_id'],5,'0',STR_PAD_LEFT); ?>
                                — <?php echo htmlspecialchars($o['customer_name'] ?? 'Walk-in'); ?>
                                <?php echo $o['table_number'] ? ' | Table '.$o['table_number'] : ''; ?>
                                | <?php echo ucfirst($o['status']); ?>
                                | ৳<?php echo number_format($o['total_amount'],2); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Live Summary -->
                    <div id="billSummary" class="p-3 bg-light rounded mb-3" style="display:none;">
                        <div class="row g-2 small">
                            <div class="col-6">
                                <span class="text-muted">Customer:</span>
                                <strong id="sumCustomer" class="ms-1"></strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted">Table:</span>
                                <strong id="sumTable" class="ms-1"></strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted">Subtotal:</span>
                                <strong id="sumSubtotal" class="ms-1"></strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted">Tax (5%):</span>
                                <strong id="sumTax" class="ms-1"></strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted">Discount:</span>
                                <strong id="sumDiscount" class="ms-1 text-danger"></strong>
                            </div>
                            <div class="col-6 border-top pt-2 mt-1">
                                <span class="text-muted">Grand Total:</span>
                                <strong id="sumTotal" class="ms-1 text-success fs-6"></strong>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Discount Amount (৳)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-tag"></i></span>
                                <input type="number" name="discount_amount" id="discountInput"
                                       class="form-control" min="0" step="0.01" value="0"
                                       placeholder="0.00">
                            </div>
                            <small class="text-muted">Enter 0 for no discount</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Method *</label>
                            <div class="d-flex gap-3 mt-2 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method"
                                           id="pmCash" value="cash" checked>
                                    <label class="form-check-label" for="pmCash">
                                        <i class="fas fa-money-bill me-1 text-success"></i>Cash
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method"
                                           id="pmCard" value="card">
                                    <label class="form-check-label" for="pmCard">
                                        <i class="fas fa-credit-card me-1 text-primary"></i>Card
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_method"
                                           id="pmDigital" value="digital">
                                    <label class="form-check-label" for="pmDigital">
                                        <i class="fas fa-mobile-alt me-1 text-info"></i>Digital
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if (!empty($unbilled)): ?>
                    <button type="submit" name="generate_bill" class="btn btn-primary">
                        <i class="fas fa-receipt me-2"></i>Generate Bill
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form method="POST" action="../modules/billing_process.php" id="deleteBillForm" style="display:none;">
    <input type="hidden" name="bill_id" id="del_bill_id">
    <input type="hidden" name="delete_bill" value="1">
</form>

<!-- ══════ UPDATE BILL MODAL ══════ -->
<div class="modal fade" id="updateBillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit text-primary me-2"></i>Edit Bill
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../modules/billing_process.php">
                <input type="hidden" name="bill_id" id="upd_bill_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Discount Amount (৳)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-tag"></i></span>
                            <input type="number" name="discount_amount" id="upd_discount"
                                   class="form-control" min="0" step="0.01" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <div class="d-flex gap-3 flex-wrap mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method"
                                       id="updPmCash" value="cash">
                                <label class="form-check-label" for="updPmCash">
                                    <i class="fas fa-money-bill me-1 text-success"></i>Cash
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method"
                                       id="updPmCard" value="card">
                                <label class="form-check-label" for="updPmCard">
                                    <i class="fas fa-credit-card me-1 text-primary"></i>Card
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method"
                                       id="updPmDigital" value="digital">
                                <label class="form-check-label" for="updPmDigital">
                                    <i class="fas fa-mobile-alt me-1 text-info"></i>Digital
                                </label>
                            </div>
                        </div>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Totals are recalculated from live order items on save.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_bill" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════ VOID BILL MODAL (admin only) ══════ -->
<div class="modal fade" id="voidBillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-ban me-2"></i>Void Paid Bill
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../modules/billing_process.php">
                <input type="hidden" name="bill_id" id="void_bill_id">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Voiding a paid bill will cancel the order and mark the bill as unpaid.
                        This action cannot be undone. Admin use only.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Void Reason *</label>
                        <textarea name="void_reason" class="form-control" rows="3"
                                  placeholder="State the reason for voiding this bill…"
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="void_bill" class="btn btn-danger">
                        <i class="fas fa-ban me-2"></i>Confirm Void
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// ── Live bill summary ─────────────────────────────────────────────
const orderSelect   = document.getElementById('orderSelect');
const discountInput = document.getElementById('discountInput');
const summaryBox    = document.getElementById('billSummary');

function updateSummary() {
    const opt = orderSelect ? orderSelect.options[orderSelect.selectedIndex] : null;
    if (!opt || !opt.value) {
        summaryBox && (summaryBox.style.display = 'none');
        return;
    }
    const subtotal = parseFloat(opt.dataset.subtotal) || 0;
    const tax      = subtotal * 0.05;
    const discount = Math.min(parseFloat(discountInput.value) || 0, subtotal);
    const total    = subtotal + tax - discount;

    document.getElementById('sumCustomer').textContent = opt.dataset.customer;
    document.getElementById('sumTable').textContent    = opt.dataset.table;
    document.getElementById('sumSubtotal').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('sumTax').textContent      = '৳' + tax.toFixed(2);
    document.getElementById('sumDiscount').textContent = discount > 0 ? '-৳' + discount.toFixed(2) : '—';
    document.getElementById('sumTotal').textContent    = '৳' + total.toFixed(2);
    summaryBox.style.display = 'block';
}

orderSelect   && orderSelect.addEventListener('change', updateSummary);
discountInput && discountInput.addEventListener('input',  updateSummary);

// Auto-open modal if coming from orders.php "Generate Bill" button
<?php if ($prefill_order && !empty($unbilled)): ?>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('generateBillModal')).show();
    updateSummary();
});
<?php endif; ?>

// ── Open Update Bill modal ────────────────────────────────────────
function openUpdateModal(billId, discount, method) {
    document.getElementById('upd_bill_id').value  = billId;
    document.getElementById('upd_discount').value = discount;
    const radio = document.querySelector(`input[name="payment_method"][value="${method}"]`);
    if (radio) radio.checked = true;
    else document.getElementById('updPmCash').checked = true;
    new bootstrap.Modal(document.getElementById('updateBillModal')).show();
}

// ── Open Void modal ───────────────────────────────────────────────
function openVoidModal(billId) {
    document.getElementById('void_bill_id').value = billId;
    new bootstrap.Modal(document.getElementById('voidBillModal')).show();
}

// ── Delete bill ───────────────────────────────────────────────────
function deleteBill(id) {
    const msg = `Are you sure you want to delete Bill #${id}?<br><small class="text-muted">The order will be restored to Served status.</small>`;
    if (typeof RMS !== 'undefined' && RMS.confirmModal) {
        RMS.confirmModal({
            title: 'Delete Bill',
            message: msg,
            confirmText: 'Delete',
            confirmClass: 'btn-danger',
            onConfirm: () => {
                document.getElementById('del_bill_id').value = id;
                document.getElementById('deleteBillForm').submit();
            }
        });
    } else {
        if (confirm(`Delete Bill #${id}? The order will be restored to Served.`)) {
            document.getElementById('del_bill_id').value = id;
            document.getElementById('deleteBillForm').submit();
        }
    }
}

// ── Auto-dismiss alerts ───────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => bootstrap.Alert.getOrCreateInstance(a).close());
}, 5000);
</script>
</body>
</html>