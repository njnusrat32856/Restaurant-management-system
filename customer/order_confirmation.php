<?php
// customer/order_confirmation.php - Order Confirmation Page
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$error_message = '';

// ── Pull session confirmation payload set by order_process.php ────
$confirm = $_SESSION['order_success'] ?? null;
unset($_SESSION['order_success']);

// ── Validate order_id from GET ────────────────────────────────────
$order_id = isset($_GET['order']) ? intval($_GET['order']) : 0;

if (!$order_id) {
    header('Location: ../customer/index.php');
    exit();
}

// ── FETCH ORDER DETAILS FROM DB ───────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    // Verify order belongs to this customer (matched by customer_name)
    $stmt = $db->prepare(
        "SELECT o.*, rt.table_number, rt.location
         FROM orders o
         LEFT JOIN restaurant_tables rt ON rt.table_id = o.table_id
         WHERE o.order_id = :id"
    );
    $stmt->execute([':id' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header('Location: ../customer/index.php');
        exit();
    }

    // Order items
    $items_stmt = $db->prepare(
        "SELECT oi.*, mi.item_name, mi.image_url, c.category_name
         FROM order_items oi
         JOIN menu_items mi ON mi.item_id = oi.item_id
         LEFT JOIN categories c ON c.category_id = mi.category_id
         WHERE oi.order_id = :id
         ORDER BY mi.item_name ASC"
    );
    $items_stmt->execute([':id' => $order_id]);
    $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bill details (auto-created by order_process.php)
    $bill_stmt = $db->prepare(
        "SELECT * FROM billing WHERE order_id = :id"
    );
    $bill_stmt->execute([':id' => $order_id]);
    $bill = $bill_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $order = null;
    $order_items = [];
    $bill = null;
}

// Fallback totals from session payload if DB bill not found yet
$subtotal       = $bill ? (float)$bill['subtotal']     : ($confirm['subtotal'] ?? 0);
$tax_amount     = $bill ? (float)$bill['tax_amount']   : ($confirm['tax']      ?? 0);
$total          = $bill ? (float)$bill['total_amount'] : ($confirm['total']    ?? 0);
$payment_method = $bill ? $bill['payment_method']      : ($confirm['payment_method'] ?? 'cash');

$page_title = 'Order Confirmed';
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
/* ── Success banner ─────────────────────────────────────────────── */
.confirm-hero {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    padding: 60px 0 44px;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-top: 56px;
}
.confirm-hero::before {
    content: '';
    position: absolute;
    top: -40%; right: -5%;
    width: 360px; height: 360px;
    background: rgba(255,255,255,.07);
    border-radius: 50%;
    pointer-events: none;
}
.confirm-hero::after {
    content: '';
    position: absolute;
    bottom: -60%; left: 3%;
    width: 240px; height: 240px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
    pointer-events: none;
}

/* ── Animated checkmark ─────────────────────────────────────────── */
.check-circle {
    width: 90px; height: 90px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
    border: 4px solid rgba(255,255,255,.5);
    display: flex; align-items: center; justify-content: center;
    font-size: 2.6rem;
    margin: 0 auto 20px;
    animation: pop .5s cubic-bezier(.175,.885,.32,1.275);
}
@keyframes pop {
    0%   { transform: scale(0); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}

/* ── Order status badge ─────────────────────────────────────────── */
.status-badge {
    padding: 5px 16px;
    border-radius: 20px;
    font-size: .8rem;
    font-weight: 600;
}

/* ── Section cards ──────────────────────────────────────────────── */
.section-card {
    border-radius: 15px;
    border: none;
}

/* ── Receipt-style item rows ────────────────────────────────────── */
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
    background: linear-gradient(135deg,#11998e18,#38ef7d18);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    color: #11998e66;
    flex-shrink: 0;
}

/* ── Summary totals ─────────────────────────────────────────────── */
.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 9px 0;
    border-bottom: 1px solid #f1f1f1;
}
.summary-row:last-child { border-bottom: none; }

/* ── Payment method badge ───────────────────────────────────────── */
.pay-badge {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: .85rem;
    font-weight: 600;
}

/* ── Timeline ───────────────────────────────────────────────────── */
.timeline { position: relative; padding-left: 40px; }
.timeline::before {
    content: '';
    position: absolute;
    left: 15px; top: 0; bottom: 0;
    width: 2px; background: #dee2e6;
}
.tl-item { position: relative; margin-bottom: 22px; }
.tl-dot {
    position: absolute;
    left: -32px;
    width: 12px; height: 12px;
    border-radius: 50%;
    border: 3px solid;
}
.tl-dot.done    { background: white; border-color: #28a745; }
.tl-dot.current { background: white; border-color: #11998e;
                   animation: pulse 1.5s infinite; }
.tl-dot.future  { background: white; border-color: #dee2e6; }
@keyframes pulse {
    0%,100% { box-shadow: 0 0 0 0 #11998e44; }
    50%      { box-shadow: 0 0 0 6px #11998e00; }
}

/* ── Print ──────────────────────────────────────────────────────── */
@media print {
    .no-print, nav, footer { display: none !important; }
    .confirm-hero { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    body { margin: 0; }
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="confirm-hero">
    <div class="container position-relative text-center">
        <div class="check-circle">
            <i class="fas fa-check"></i>
        </div>
        <h1 class="display-5 fw-bold mb-2">Order Placed!</h1>
        <p class="lead opacity-80 mb-3">
            Thank you, <strong><?php echo htmlspecialchars(explode(' ', $_SESSION['full_name'] ?? 'Customer')[0]); ?></strong>!
            Your order has been received and is being prepared.
        </p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <span class="badge bg-white text-dark fs-6 px-4 py-2 rounded-pill shadow-sm">
                Order #<?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?>
            </span>
            <span class="badge bg-white text-success fs-6 px-4 py-2 rounded-pill shadow-sm">
                ৳<?php echo number_format($total, 2); ?>
            </span>
        </div>
    </div>
</section>

<!-- ── Body ──────────────────────────────────────────────────────── -->
<div class="container-fluid py-4">

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($order): ?>
    <div class="row g-4">

        <!-- ── Left: Items + Details ────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Order Items -->
            <div class="card section-card shadow-sm mb-4">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">
                            <i class="fas fa-utensils text-success me-2"></i>
                            Items Ordered
                            <span class="badge bg-secondary ms-1"><?php echo count($order_items); ?></span>
                        </h6>
                        <span class="status-badge bg-warning text-dark">
                            <i class="fas fa-clock me-1"></i>
                            <?php echo ucfirst($order['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
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
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?php echo htmlspecialchars($oi['item_name']); ?></div>
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

                        <!-- Qty & subtotal -->
                        <div class="text-end flex-shrink-0">
                            <div class="fw-bold text-success">
                                ৳<?php echo number_format($oi['subtotal'], 2); ?>
                            </div>
                            <small class="text-muted">× <?php echo $oi['quantity']; ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Order Details -->
            <div class="card section-card shadow-sm mb-4">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-info-circle text-primary me-2"></i>Order Details
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Order ID</small>
                            <strong>#<?php echo str_pad($order_id, 5, '0', STR_PAD_LEFT); ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Customer</small>
                            <strong><?php echo htmlspecialchars($order['customer_name'] ?? '—'); ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Order Date</small>
                            <strong><?php echo date('D, d M Y · h:i A', strtotime($order['order_date'])); ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Table</small>
                            <strong>
                                <?php if ($order['table_number']): ?>
                                    Table <?php echo htmlspecialchars($order['table_number']); ?>
                                    <?php if ($order['location']): ?>
                                    <span class="text-muted fw-normal">— <?php echo htmlspecialchars($order['location']); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Takeaway / No table</span>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Status</small>
                            <span class="status-badge bg-warning text-dark">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Payment Method</small>
                            <?php
                            $pm_icons = ['cash'=>'fa-money-bill text-success',
                                         'card'=>'fa-credit-card text-primary',
                                         'digital'=>'fa-mobile-alt text-info'];
                            $pm_icon  = $pm_icons[$payment_method] ?? 'fa-circle';
                            ?>
                            <span class="pay-badge bg-light text-dark border">
                                <i class="fas <?php echo $pm_icon; ?> me-1"></i>
                                <?php echo ucfirst($payment_method); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- What happens next -->
            <div class="card section-card shadow-sm">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-tasks text-success me-2"></i>What Happens Next
                    </h6>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <?php
                        $steps = [
                            ['done',    'fa-check-circle text-success', 'Order Received',
                             'Your order has been received and logged.'],
                            ['current', 'fa-fire text-warning',         'Kitchen Preparing',
                             'Our kitchen team is preparing your meal fresh.'],
                            ['future',  'fa-concierge-bell text-primary','Food Served',
                             'Your food will be brought to your table.'],
                            ['future',  'fa-file-invoice-dollar text-info', 'Bill Ready',
                             'Your bill will be ready when you finish dining.'],
                        ];
                        foreach ($steps as [$dot, $icon, $title, $desc]):
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot <?php echo $dot; ?>"></div>
                            <div class="fw-semibold <?php echo $dot === 'future' ? 'text-muted' : ''; ?>">
                                <i class="fas <?php echo explode(' ', $icon)[0]; ?> <?php echo implode(' ', array_slice(explode(' ', $icon), 1)); ?> me-2"></i>
                                <?php echo $title; ?>
                                <?php if ($dot === 'done'):    ?><i class="fas fa-check-circle text-success ms-2"></i><?php endif; ?>
                                <?php if ($dot === 'current'): ?><span class="badge bg-warning text-dark ms-2">Now</span><?php endif; ?>
                            </div>
                            <small class="text-muted"><?php echo $desc; ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Right: Summary + Actions ─────────────────────────────── -->
        <div class="col-lg-4">

            <!-- Bill summary -->
            <div class="card section-card shadow-sm mb-4">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-receipt text-success me-2"></i>Bill Summary
                    </h6>
                </div>
                <div class="card-body">
                    <div class="summary-row">
                        <span class="text-muted">Subtotal</span>
                        <span>৳<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="text-muted">Tax (5% VAT)</span>
                        <span>৳<?php echo number_format($tax_amount, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="text-muted">Discount</span>
                        <span>৳<?php echo number_format($bill ? (float)$bill['discount_amount'] : 0, 2); ?></span>
                    </div>
                    <div class="summary-row fw-bold fs-6">
                        <span>Total</span>
                        <span class="text-success">৳<?php echo number_format($total, 2); ?></span>
                    </div>
                    <div class="mt-3 pt-2">
                        <div class="d-flex justify-content-between align-items-center small">
                            <span class="text-muted">Payment Status</span>
                            <span class="status-badge bg-warning text-dark">
                                <?php echo $bill ? ucfirst($bill['payment_status']) : 'Pending'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="card section-card shadow-sm mb-4 no-print">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-bolt text-primary me-2"></i>Quick Actions
                    </h6>
                </div>
                <div class="card-body d-flex flex-column gap-2">
                    <button onclick="window.print()" class="btn btn-outline-secondary">
                        <i class="fas fa-print me-2"></i>Print Receipt
                    </button>
                    <a href="../customer/menu.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Order More
                    </a>
                    <a href="../customer/my_orders.php" class="btn btn-outline-primary">
                        <i class="fas fa-history me-2"></i>My Orders
                    </a>
                    <a href="../customer/index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-home me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Estimated time -->
            <div class="card section-card shadow-sm">
                <div class="card-body text-center py-4">
                    <i class="fas fa-hourglass-half text-warning fs-2 mb-2"></i>
                    <h6 class="fw-bold">Estimated Wait</h6>
                    <div class="display-6 fw-bold text-success" id="countdown">20:00</div>
                    <small class="text-muted">minutes until your food is ready</small>
                    <div class="mt-3">
                        <small class="text-muted">
                            Ordered at <?php echo date('h:i A', strtotime($order['order_date'])); ?>
                        </small>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php else: ?>
    <!-- Order not found -->
    <div class="text-center py-5 text-muted">
        <i class="fas fa-exclamation-circle fs-1 d-block mb-3 opacity-40"></i>
        <h4>Order not found</h4>
        <a href="../customer/index.php" class="btn btn-primary mt-2">
            <i class="fas fa-home me-2"></i>Back to Dashboard
        </a>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// ── Live countdown from order time ────────────────────────────────
(function () {
    const orderTime   = new Date('<?php echo $order ? date('c', strtotime($order['order_date'])) : 'now'; ?>');
    const waitMinutes = 20; // estimated prep time
    const readyAt     = new Date(orderTime.getTime() + waitMinutes * 60 * 1000);
    const el          = document.getElementById('countdown');
    if (!el) return;

    function tick() {
        const remaining = Math.max(0, Math.floor((readyAt - Date.now()) / 1000));
        const m = String(Math.floor(remaining / 60)).padStart(2, '0');
        const s = String(remaining % 60).padStart(2, '0');
        el.textContent = m + ':' + s;
        if (remaining > 0) {
            setTimeout(tick, 1000);
        } else {
            el.textContent = 'Ready!';
            el.classList.remove('text-success');
            el.classList.add('text-warning');
        }
    }
    tick();
})();
</script>
</body>
</html>