<?php
// customer/index.php - Customer Dashboard
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$error_message = '';

// ── FETCH CUSTOMER DATA ───────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    $customer_id = $_SESSION['user_id'];

    // Customer profile
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // Recent orders (last 5)
    $recent_orders = $db->prepare(
        "SELECT o.*, rt.table_number
         FROM orders o
         LEFT JOIN restaurant_tables rt ON rt.table_id = o.table_id
         WHERE o.customer_name = :name
         ORDER BY o.order_date DESC
         LIMIT 5"
    );
    $recent_orders->execute([':name' => $customer['full_name']]);
    $recent_orders = $recent_orders->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming reservations (confirmed or pending, future dates)
    $upcoming_res = $db->prepare(
        "SELECT r.*, rt.table_number, rt.seating_capacity, rt.location
         FROM reservations r
         LEFT JOIN restaurant_tables rt ON rt.table_id = r.table_id
         WHERE r.customer_id = :id
           AND r.status IN ('pending','confirmed')
           AND r.reservation_date >= CURDATE()
         ORDER BY r.reservation_date ASC, r.reservation_time ASC
         LIMIT 5"
    );
    $upcoming_res->execute([':id' => $customer_id]);
    $upcoming_reservations = $upcoming_res->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM orders
             WHERE customer_name = :name) AS total_orders,
            (SELECT COUNT(*) FROM orders
             WHERE customer_name = :name2 AND status = 'completed') AS completed_orders,
            (SELECT COALESCE(SUM(total_amount),0) FROM orders
             WHERE customer_name = :name3 AND status = 'completed') AS total_spent,
            (SELECT COUNT(*) FROM reservations
             WHERE customer_id = :id) AS total_reservations"
    );
    $stats->execute([
        ':name'  => $customer['full_name'],
        ':name2' => $customer['full_name'],
        ':name3' => $customer['full_name'],
        ':id'    => $customer_id
    ]);
    $stats = $stats->fetch(PDO::FETCH_ASSOC);

    // Featured menu items (4 available items)
    $featured = $db->query(
        "SELECT mi.*, c.category_name
         FROM menu_items mi
         LEFT JOIN categories c ON c.category_id = mi.category_id
         WHERE mi.availability = 'available'
         ORDER BY RAND()
         LIMIT 4"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $customer      = ['full_name' => $_SESSION['full_name'] ?? 'Customer', 'email' => ''];
    $recent_orders = $upcoming_reservations = $featured = [];
    $stats = ['total_orders'=>0,'completed_orders'=>0,'total_spent'=>0,'total_reservations'=>0];
}

// Greeting based on time of day
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
$first_name = explode(' ', trim($customer['full_name']))[0];

$page_title = 'My Dashboard';
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
/* ── Welcome Banner ─────────────────────────────────────────────── */
.welcome-banner {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 20px;
    padding: 40px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.welcome-banner::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 300px;
    height: 300px;
    background: rgba(255,255,255,.08);
    border-radius: 50%;
}
.welcome-banner::after {
    content: '';
    position: absolute;
    bottom: -60%;
    right: 10%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
}

/* ── Stat Cards ─────────────────────────────────────────────────── */
.stat-card {
    border-radius: 15px;
    padding: 22px;
    transition: transform .3s;
    border: none;
    height: 100%;
}
.stat-card:hover { transform: translateY(-5px); }

/* ── Quick Action Cards ─────────────────────────────────────────── */
.action-card {
    border-radius: 15px;
    border: none;
    transition: all .3s;
    cursor: pointer;
    text-decoration: none;
    display: block;
    height: 100%;
}
.action-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 30px rgba(0,0,0,.12) !important;
}
.action-icon {
    width: 60px;
    height: 60px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 12px;
}

/* ── Menu Item Cards ────────────────────────────────────────────── */
.menu-card {
    border-radius: 15px;
    border: none;
    transition: all .3s;
    overflow: hidden;
    height: 100%;
}
.menu-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 28px rgba(0,0,0,.12) !important;
}
.menu-card img {
    height: 160px;
    object-fit: cover;
    width: 100%;
}
.menu-card .img-placeholder {
    height: 160px;
    background: linear-gradient(135deg,#667eea22,#764ba222);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: #667eea;
}

/* ── Order & Reservation rows ───────────────────────────────────── */
.history-row {
    padding: 14px;
    border-radius: 10px;
    margin-bottom: 8px;
    background: #f8f9fa;
    transition: all .2s;
}
.history-row:hover {
    background: #e9ecef;
    transform: translateX(4px);
}
.history-row:last-child { margin-bottom: 0; }

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 600;
}

/* ── Avatar ─────────────────────────────────────────────────────── */
.avatar-circle {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
    border: 3px solid rgba(255,255,255,.4);
    flex-shrink: 0;
}

/* ── Section cards ──────────────────────────────────────────────── */
.section-card {
    border-radius: 15px;
    border: none;
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Welcome Banner -->
    <div class="welcome-banner shadow mb-4">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <div class="avatar-circle">
                <?php echo strtoupper(substr($first_name, 0, 1)); ?>
            </div>
            <div class="flex-grow-1">
                <p class="mb-1 opacity-75" style="font-size:.95rem;">
                    <i class="fas fa-sun me-1"></i><?php echo $greeting; ?>
                </p>
                <h2 class="fw-bold mb-1"><?php echo htmlspecialchars($first_name); ?>!</h2>
                <p class="mb-0 opacity-80">
                    Welcome back to Fine Dine. What would you like to do today?
                </p>
            </div>
            <div class="text-end d-none d-md-block">
                <div class="opacity-75 small">Member since</div>
                <div class="fw-semibold">
                    <?php echo isset($customer['created_at']) ? date('M Y', strtotime($customer['created_at'])) : 'N/A'; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['total_orders']; ?></div>
                        <div class="opacity-90 small">Total Orders</div>
                    </div>
                    <i class="fas fa-shopping-bag fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['completed_orders']; ?></div>
                        <div class="opacity-90 small">Completed</div>
                    </div>
                    <i class="fas fa-check-circle fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['total_reservations']; ?></div>
                        <div class="opacity-90 small">Reservations</div>
                    </div>
                    <i class="fas fa-calendar-check fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold">৳<?php echo number_format($stats['total_spent'], 0); ?></div>
                        <div class="opacity-90 small">Total Spent</div>
                    </div>
                    <i class="fas fa-coins fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <h5 class="fw-bold mb-3">
                <i class="fas fa-bolt text-primary me-2"></i>Quick Actions
            </h5>
        </div>
        <div class="col-6 col-md-3">
            <a href="../customer/menu.php" class="action-card card shadow-sm p-4 text-center text-decoration-none">
                <div class="action-icon bg-primary bg-opacity-10 mx-auto">
                    <i class="fas fa-book-open text-primary"></i>
                </div>
                <div class="fw-semibold">Browse Menu</div>
                <small class="text-muted">View all dishes</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="../customer/reservation.php" class="action-card card shadow-sm p-4 text-center text-decoration-none">
                <div class="action-icon bg-success bg-opacity-10 mx-auto">
                    <i class="fas fa-calendar-plus text-success"></i>
                </div>
                <div class="fw-semibold">Book a Table</div>
                <small class="text-muted">Make a reservation</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="../customer/my_orders.php" class="action-card card shadow-sm p-4 text-center text-decoration-none">
                <div class="action-icon bg-warning bg-opacity-10 mx-auto">
                    <i class="fas fa-receipt text-warning"></i>
                </div>
                <div class="fw-semibold">My Orders</div>
                <small class="text-muted">Order history</small>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="../customer/my_reservations.php" class="action-card card shadow-sm p-4 text-center text-decoration-none">
                <div class="action-icon bg-info bg-opacity-10 mx-auto">
                    <i class="fas fa-calendar-alt text-info"></i>
                </div>
                <div class="fw-semibold">My Reservations</div>
                <small class="text-muted">Upcoming bookings</small>
            </a>
        </div>
    </div>

    <div class="row g-4">

        <!--Left Column -->
        <div class="col-lg-8">

            <!-- Upcoming Reservations -->
            <div class="card section-card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"
                     style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-calendar-check text-success me-2"></i>Upcoming Reservations
                    </h6>
                    <a href="../customer/reservation.php" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-plus me-1"></i>New Reservation
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($upcoming_reservations)): ?>
                        <?php
                        $res_status_colors = [
                            'pending'   => 'warning',
                            'confirmed' => 'success',
                            'cancelled' => 'danger',
                            'completed' => 'secondary'
                        ];
                        foreach ($upcoming_reservations as $r):
                            $rsc = $res_status_colors[$r['status']] ?? 'secondary';
                            $res_date = new DateTime($r['reservation_date'] . ' ' . $r['reservation_time']);
                            $now      = new DateTime();
                            $diff     = $now->diff($res_date);
                            $days_left = (int)$diff->format('%r%a');
                        ?>
                        <div class="history-row">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="fw-semibold">
                                            <i class="fas fa-calendar me-1 text-muted"></i>
                                            <?php echo date('D, d M Y', strtotime($r['reservation_date'])); ?>
                                        </span>
                                        <span class="text-muted">at</span>
                                        <span class="fw-semibold">
                                            <?php echo date('h:i A', strtotime($r['reservation_time'])); ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-users me-1"></i><?php echo $r['number_of_guests']; ?> guests
                                        <?php if ($r['table_number']): ?>
                                        &nbsp;·&nbsp;
                                        <i class="fas fa-chair me-1"></i>Table <?php echo htmlspecialchars($r['table_number']); ?>
                                        <?php endif; ?>
                                        <?php if ($r['location']): ?>
                                        &nbsp;·&nbsp;
                                        <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($r['location']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($r['special_requests']): ?>
                                    <div class="small text-muted mt-1">
                                        <i class="fas fa-sticky-note me-1"></i>
                                        <?php echo htmlspecialchars($r['special_requests']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge bg-<?php echo $rsc; ?> text-white d-block mb-1">
                                        <?php echo ucfirst($r['status']); ?>
                                    </span>
                                    <?php if ($days_left >= 0): ?>
                                    <small class="text-<?php echo $days_left == 0 ? 'danger fw-bold' : 'muted'; ?>">
                                        <?php echo $days_left == 0 ? 'Today!' : 'In '.$days_left.' day'.($days_left==1?'':'s'); ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-calendar-times fs-3 d-block mb-2 opacity-50"></i>
                        <p class="mb-2">No upcoming reservations.</p>
                        <a href="../customer/reservation.php" class="btn btn-sm btn-success">
                            <i class="fas fa-calendar-plus me-1"></i>Book a Table
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="card section-card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"
                     style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-history text-primary me-2"></i>Recent Orders
                    </h6>
                    <a href="../customer/my_orders.php" class="btn btn-sm btn-outline-primary">
                        View All
                    </a>
                </div>
                <div class="card-body">
                    <?php if (!empty($recent_orders)):
                        $order_status_colors = [
                            'pending'   => 'warning',
                            'preparing' => 'info',
                            'served'    => 'primary',
                            'completed' => 'success',
                            'cancelled' => 'danger'
                        ];
                        $order_status_icons = [
                            'pending'   => 'fa-clock',
                            'preparing' => 'fa-fire',
                            'served'    => 'fa-concierge-bell',
                            'completed' => 'fa-check-circle',
                            'cancelled' => 'fa-times-circle'
                        ];
                        foreach ($recent_orders as $o):
                            $osc = $order_status_colors[$o['status']] ?? 'secondary';
                            $osi = $order_status_icons[$o['status']]  ?? 'fa-circle';
                    ?>
                    <div class="history-row">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <div class="fw-semibold">
                                    Order #<?php echo str_pad($o['order_id'],5,'0',STR_PAD_LEFT); ?>
                                </div>
                                <div class="small text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php echo date('D, d M Y · h:i A', strtotime($o['order_date'])); ?>
                                    <?php if ($o['table_number']): ?>
                                    &nbsp;·&nbsp;
                                    <i class="fas fa-chair me-1"></i>Table <?php echo htmlspecialchars($o['table_number']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success mb-1">
                                    ৳<?php echo number_format($o['total_amount'],2); ?>
                                </div>
                                <span class="status-badge bg-<?php echo $osc; ?> text-white">
                                    <i class="fas <?php echo $osi; ?> me-1"></i><?php echo ucfirst($o['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-shopping-bag fs-3 d-block mb-2 opacity-50"></i>
                        <p class="mb-2">You have no orders yet.</p>
                        <a href="../customer/menu.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-utensils me-1"></i>Browse Menu
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Right Column -->
        <div class="col-lg-4">

            <!-- Profile Card -->
            <div class="card section-card shadow-sm mb-4">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-user text-primary me-2"></i>My Profile
                    </h6>
                </div>
                <div class="card-body text-center">
                    <div class="avatar-circle mx-auto mb-3"
                         style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;">
                        <?php echo strtoupper(substr($first_name, 0, 1)); ?>
                    </div>
                    <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($customer['full_name']); ?></h6>
                    <small class="text-muted d-block mb-3">
                        <?php echo htmlspecialchars($customer['email']); ?>
                    </small>
                    <?php if (!empty($customer['phone'])): ?>
                    <small class="text-muted d-block mb-3">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($customer['phone']); ?>
                    </small>
                    <?php endif; ?>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <a href="../customer/profile.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-user-edit me-1"></i>Edit Profile
                        </a>
                        <a href="../customer/settings.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-cog me-1"></i>Settings
                        </a>
                    </div>
                </div>
            </div>

            <!-- Featured Menu Items -->
            <div class="card section-card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"
                     style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-star text-warning me-2"></i>Featured Today
                    </h6>
                    <a href="../customer/menu.php" class="btn btn-sm btn-outline-warning">
                        Full Menu
                    </a>
                </div>
                <div class="card-body p-3">
                    <?php if (!empty($featured)): ?>
                    <div class="row g-2">
                        <?php foreach ($featured as $item): ?>
                        <div class="col-6">
                            <div class="menu-card card shadow-sm">
                                <?php
                                $img_path = '../assets/images/' . ($item['image_url'] ?? '');
                                ?>
                                <?php if (!empty($item['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($img_path); ?>"
                                     alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                     onerror="this.parentElement.innerHTML='<div class=\'img-placeholder\'><i class=\'fas fa-utensils\'></i></div>'">
                                <?php else: ?>
                                <div class="img-placeholder">
                                    <i class="fas fa-utensils"></i>
                                </div>
                                <?php endif; ?>
                                <div class="card-body p-2">
                                    <div class="fw-semibold small text-truncate">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </div>
                                    <div class="text-muted" style="font-size:.7rem;">
                                        <?php echo htmlspecialchars($item['category_name'] ?? ''); ?>
                                    </div>
                                    <div class="fw-bold text-success small mt-1">
                                        ৳<?php echo number_format($item['price'],2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="../customer/menu.php" class="btn btn-warning w-100 mt-3 btn-sm">
                        <i class="fas fa-utensils me-1"></i>Order Now
                    </a>
                    <?php else: ?>
                    <div class="text-center py-3 text-muted">
                        <i class="fas fa-utensils fs-3 d-block mb-2 opacity-50"></i>
                        <p class="mb-0 small">Menu items not available.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div><!-- /container -->

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => bootstrap.Alert.getOrCreateInstance(a).close());
}, 5000);
</script>
</body>
</html>