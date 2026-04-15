<?php
// customer/reservation.php - Table Reservation
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

// ── Flash messages from reservation_process.php ───────────────────
$success_message = '';
$error_message   = '';

if (!empty($_SESSION['reservation_success'])) {
    $success_message = $_SESSION['reservation_success'];
    unset($_SESSION['reservation_success']);
}
if (!empty($_SESSION['reservation_error'])) {
    $error_message = $_SESSION['reservation_error'];
    unset($_SESSION['reservation_error']);
}

$customer_id = $_SESSION['user_id'];

// ── FETCH DATA ────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_status = $_GET['status'] ?? 'upcoming';
    $view_res_id   = isset($_GET['id']) ? intval($_GET['id']) : null;

    // Available tables for the booking form
    $available_tables = $db->query(
        "SELECT table_id, table_number, seating_capacity, location
         FROM restaurant_tables
         WHERE status IN ('available','reserved')
         ORDER BY seating_capacity ASC, table_number ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Customer's reservations
    $query = "SELECT r.*, rt.table_number, rt.seating_capacity, rt.location
              FROM reservations r
              LEFT JOIN restaurant_tables rt ON rt.table_id = r.table_id
              WHERE r.customer_id = :cid";

    if ($filter_status === 'upcoming') {
        $query .= " AND r.reservation_date >= CURDATE()
                    AND r.status IN ('pending','confirmed')";
    } elseif ($filter_status === 'past') {
        $query .= " AND (r.reservation_date < CURDATE()
                         OR r.status IN ('completed','cancelled'))";
    }
    // 'all' — no extra filter

    $query .= " ORDER BY r.reservation_date ASC, r.reservation_time ASC";

    $stmt = $db->prepare($query);
    $stmt->execute([':cid' => $customer_id]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Single reservation detail
    $res_details = null;
    if ($view_res_id) {
        $det = $db->prepare(
            "SELECT r.*, rt.table_number, rt.seating_capacity, rt.location
             FROM reservations r
             LEFT JOIN restaurant_tables rt ON rt.table_id = r.table_id
             WHERE r.reservation_id = :id AND r.customer_id = :cid"
        );
        $det->execute([':id' => $view_res_id, ':cid' => $customer_id]);
        $res_details = $det->fetch(PDO::FETCH_ASSOC);
    }

    // Stats
    $stats = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN reservation_date >= CURDATE()
                     AND status IN ('pending','confirmed') THEN 1 ELSE 0 END) AS upcoming
         FROM reservations
         WHERE customer_id = :cid"
    );
    $stats->execute([':cid' => $customer_id]);
    $stats = $stats->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $reservations = $available_tables = [];
    $res_details  = null;
    $stats = ['total'=>0,'pending'=>0,'confirmed'=>0,'cancelled'=>0,'upcoming'=>0];
}

// Pre-fill date = today + 1
$default_date = date('Y-m-d', strtotime('+1 day'));
$min_date     = date('Y-m-d');

$page_title = 'Table Reservation';
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
.res-hero {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    padding: 60px 0 44px;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-top: 56px;
}
.res-hero::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -5%;
    width: 360px;
    height: 360px;
    background: rgba(255,255,255,.08);
    border-radius: 50%;
    pointer-events: none;
}
.res-hero::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 3%;
    width: 240px;
    height: 240px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
    pointer-events: none;
}

/* ── Stat cards ─────────────────────────────────────────────────── */
.stat-card {
    border-radius: 15px;
    padding: 22px;
    transition: transform .3s;
    border: none;
}
.stat-card:hover { transform: translateY(-5px); }

/* ── Booking form card ──────────────────────────────────────────── */
.booking-card {
    border-radius: 15px;
    border: none;
}

/* ── Table option cards ─────────────────────────────────────────── */
.table-option {
    border: 2px solid #dee2e6;
    border-radius: 12px;
    padding: 14px;
    cursor: pointer;
    transition: all .2s;
    text-align: center;
    position: relative;
}
.table-option:hover {
    border-color: #11998e;
    background: #11998e0d;
    transform: translateY(-2px);
}
.table-option.selected {
    border-color: #11998e;
    background: #11998e12;
    box-shadow: 0 0 0 3px #11998e33;
}
.table-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.table-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: #11998e15;
    color: #11998e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    margin: 0 auto 8px;
}
.table-option.selected .table-icon {
    background: #11998e;
    color: #fff;
}

/* ── Reservation list rows ──────────────────────────────────────── */
.res-row {
    cursor: pointer;
    transition: all .2s;
}
.res-row:hover {
    background: #f8f9fa !important;
    transform: scale(1.005);
}

/* ── Status badge ───────────────────────────────────────────────── */
.status-badge {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
}

/* ── Detail panel ───────────────────────────────────────────────── */
.detail-card {
    border-radius: 15px;
    border: none;
}

/* ── Info row inside detail ─────────────────────────────────────── */
.info-row {
    padding: 12px 0;
    border-bottom: 1px solid #f1f1f1;
}
.info-row:last-child { border-bottom: none; }

/* ── Timeline ───────────────────────────────────────────────────── */
.timeline { position: relative; padding-left: 40px; }
.timeline::before {
    content: '';
    position: absolute;
    left: 15px; top: 0; bottom: 0;
    width: 2px;
    background: #dee2e6;
}
.timeline-item { position: relative; margin-bottom: 20px; }
.timeline-dot {
    position: absolute;
    left: -32px;
    width: 12px; height: 12px;
    border-radius: 50%;
    border: 3px solid;
}
.timeline-dot.done    { background: white; border-color: #28a745; }
.timeline-dot.current { background: white; border-color: #11998e;
                         animation: pulse 1.5s infinite; }
.timeline-dot.future  { background: white; border-color: #dee2e6; }

@keyframes pulse {
    0%,100% { opacity: 1; box-shadow: 0 0 0 0 #11998e44; }
    50%      { opacity: .8; box-shadow: 0 0 0 6px #11998e00; }
}

/* ── Action btn ─────────────────────────────────────────────────── */
.action-btn {
    width: 34px; height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="res-hero">
    <div class="container position-relative">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <p class="mb-2 opacity-75 small text-uppercase fw-semibold">
                    <i class="fas fa-calendar-check me-2"></i>Fine Dine RMS
                </p>
                <h1 class="display-5 fw-bold mb-2">Table Reservation</h1>
                <p class="lead opacity-80 mb-0">
                    Reserve your table in seconds. Choose your date, time, and seating — we'll take care of the rest.
                </p>
            </div>
            <div class="col-lg-4 d-none d-lg-flex justify-content-center">
                <i class="fas fa-calendar-alt text-white opacity-20" style="font-size:9rem;"></i>
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
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['total']; ?></div>
                        <div class="opacity-90 small">Total</div>
                    </div>
                    <i class="fas fa-calendar fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['upcoming']; ?></div>
                        <div class="opacity-90 small">Upcoming</div>
                    </div>
                    <i class="fas fa-clock fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['pending']; ?></div>
                        <div class="opacity-90 small">Pending</div>
                    </div>
                    <i class="fas fa-hourglass-half fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $stats['cancelled']; ?></div>
                        <div class="opacity-90 small">Cancelled</div>
                    </div>
                    <i class="fas fa-times-circle fa-2x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ── LEFT: Booking form + reservations list ──────────────── -->
        <div class="col-lg-<?php echo $res_details ? '5' : '8'; ?>">

            <!-- Booking form -->
            <div class="card booking-card shadow-sm mb-4">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h5 class="fw-bold mb-0">
                        <i class="fas fa-plus-circle text-success me-2"></i>New Reservation
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="../modules/reservation_process.php" id="bookingForm">

                        <div class="row g-3 mb-4">
                            <!-- Date -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-calendar me-1 text-muted"></i>Date *
                                </label>
                                <input type="date" name="reservation_date" id="resDate"
                                       class="form-control" required
                                       min="<?php echo $min_date; ?>"
                                       value="<?php echo $default_date; ?>">
                            </div>
                            <!-- Time -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-clock me-1 text-muted"></i>Time *
                                </label>
                                <select name="reservation_time" class="form-select" required>
                                    <?php
                                    $slots = ['11:00','11:30','12:00','12:30','13:00','13:30',
                                              '14:00','14:30','18:00','18:30','19:00','19:30',
                                              '20:00','20:30','21:00','21:30','22:00'];
                                    foreach ($slots as $s):
                                    ?>
                                    <option value="<?php echo $s; ?>">
                                        <?php echo date('h:i A', strtotime($s)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Guests -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-users me-1 text-muted"></i>Number of Guests *
                                </label>
                                <input type="number" name="number_of_guests" id="guestCount"
                                       class="form-control" min="1" max="20"
                                       value="2" required>
                                <small class="text-muted" id="guestHint">Select a table to see capacity.</small>
                            </div>
                            <!-- Special requests -->
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-sticky-note me-1 text-muted"></i>Special Requests
                                </label>
                                <input type="text" name="special_requests" class="form-control"
                                       placeholder="e.g. Window seat, birthday cake…">
                            </div>
                        </div>

                        <!-- Table picker -->
                        <label class="form-label fw-semibold mb-3">
                            <i class="fas fa-chair me-1 text-muted"></i>Choose a Table *
                        </label>
                        <input type="hidden" name="table_id" id="selectedTable" required>

                        <?php if (!empty($available_tables)): ?>
                        <div class="row g-2 mb-4" id="tableGrid">
                            <?php foreach ($available_tables as $t): ?>
                            <div class="col-6 col-sm-4 col-lg-<?php echo $res_details ? '6' : '4'; ?>">
                                <div class="table-option"
                                     data-table-id="<?php echo $t['table_id']; ?>"
                                     data-capacity="<?php echo $t['seating_capacity']; ?>"
                                     onclick="selectTable(this)">
                                    <div class="table-icon">
                                        <i class="fas fa-chair"></i>
                                    </div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($t['table_number']); ?></div>
                                    <div class="small text-muted">
                                        <i class="fas fa-users me-1"></i><?php echo $t['seating_capacity']; ?> seats
                                    </div>
                                    <?php if ($t['location']): ?>
                                    <div class="small text-muted">
                                        <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($t['location']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No tables are currently available for reservation.
                        </div>
                        <?php endif; ?>

                        <div id="tableWarning" class="alert alert-warning d-none mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <span id="tableWarningText"></span>
                        </div>

                        <button type="submit" name="make_reservation"
                                class="btn btn-success btn-lg w-100"
                                <?php echo empty($available_tables) ? 'disabled' : ''; ?>>
                            <i class="fas fa-calendar-check me-2"></i>Confirm Reservation
                        </button>
                    </form>
                </div>
            </div>

            <!-- Reservations list -->
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="fw-bold mb-0">
                            <i class="fas fa-list text-primary me-2"></i>My Reservations
                        </h5>
                        <div class="d-flex gap-2">
                            <a href="?" class="btn btn-sm <?php echo $filter_status=='upcoming' ? 'btn-success' : 'btn-outline-success'; ?>">
                                Upcoming
                            </a>
                            <a href="?status=past" class="btn btn-sm <?php echo $filter_status=='past' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                                Past
                            </a>
                            <a href="?status=all" class="btn btn-sm <?php echo $filter_status=='all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                All
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Date & Time</th>
                                    <th>Table</th>
                                    <th>Guests</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($reservations)):
                                $sc = [
                                    'pending'   => 'warning',
                                    'confirmed' => 'success',
                                    'cancelled' => 'danger',
                                    'completed' => 'secondary'
                                ];
                                foreach ($reservations as $r):
                                    $color   = $sc[$r['status']] ?? 'secondary';
                                    $is_past = strtotime($r['reservation_date']) < strtotime('today');
                                    $can_cancel = in_array($r['status'], ['pending','confirmed']) && !$is_past;
                                    $now      = new DateTime();
                                    $res_dt   = new DateTime($r['reservation_date'].' '.$r['reservation_time']);
                                    $diff     = $now->diff($res_dt);
                                    $days_left = (int)$diff->format('%r%a');
                            ?>
                                <tr class="res-row <?php echo $view_res_id == $r['reservation_id'] ? 'table-active' : ''; ?>"
                                    onclick="window.location.href='?id=<?php echo $r['reservation_id']; ?>&status=<?php echo $filter_status; ?>'">
                                    <td><strong>#<?php echo str_pad($r['reservation_id'],4,'0',STR_PAD_LEFT); ?></strong></td>
                                    <td>
                                        <div class="fw-semibold small">
                                            <?php echo date('D, d M Y', strtotime($r['reservation_date'])); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('h:i A', strtotime($r['reservation_time'])); ?>
                                            <?php if ($days_left >= 0 && !$is_past): ?>
                                            · <span class="text-<?php echo $days_left == 0 ? 'danger fw-bold' : 'success'; ?>">
                                                <?php echo $days_left == 0 ? 'Today' : 'In '.$days_left.'d'; ?>
                                              </span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($r['table_number']): ?>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars($r['table_number']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-users me-1 text-muted"></i>
                                        <?php echo $r['number_of_guests']; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge bg-<?php echo $color; ?> text-<?php echo $color == 'warning' ? 'dark' : 'white'; ?>">
                                            <?php echo ucfirst($r['status']); ?>
                                        </span>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-outline-primary action-btn"
                                                    onclick="window.location.href='?id=<?php echo $r['reservation_id']; ?>&status=<?php echo $filter_status; ?>'"
                                                    title="View">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($can_cancel): ?>
                                            <button class="btn btn-sm btn-outline-danger action-btn"
                                                    title="Cancel"
                                                    onclick="cancelRes(<?php echo $r['reservation_id']; ?>)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fas fa-calendar-times fs-1 d-block mb-3 opacity-40"></i>
                                        No reservations found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div><!-- /col left -->

        <!-- ── RIGHT: Detail panel or tips sidebar ─────────────────── -->
        <?php if ($res_details): ?>
        <div class="col-lg-7">
            <div class="card detail-card shadow-sm">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-check me-2"></i>
                        Reservation #<?php echo str_pad($res_details['reservation_id'],4,'0',STR_PAD_LEFT); ?>
                    </h5>
                    <button class="btn btn-light btn-sm"
                            onclick="window.location.href='?status=<?php echo $filter_status; ?>'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="card-body">

                    <?php
                    $sc = ['pending'=>'warning','confirmed'=>'success',
                           'cancelled'=>'danger','completed'=>'secondary'];
                    $color = $sc[$res_details['status']] ?? 'secondary';
                    $is_past = strtotime($res_details['reservation_date']) < strtotime('today');
                    $can_cancel = in_array($res_details['status'],['pending','confirmed']) && !$is_past;
                    ?>

                    <!-- Status badge centred -->
                    <div class="text-center mb-4">
                        <span class="status-badge bg-<?php echo $color; ?> text-<?php echo $color=='warning'?'dark':'white'; ?>"
                              style="font-size:1rem;padding:10px 28px;">
                            <i class="fas fa-circle me-2" style="font-size:.55rem;vertical-align:middle;"></i>
                            <?php echo ucfirst($res_details['status']); ?>
                        </span>
                    </div>

                    <!-- Info rows -->
                    <div class="mb-4">
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-hashtag me-2"></i>Reservation ID</span>
                            <strong>#<?php echo str_pad($res_details['reservation_id'],4,'0',STR_PAD_LEFT); ?></strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-calendar me-2"></i>Date</span>
                            <strong><?php echo date('D, d M Y', strtotime($res_details['reservation_date'])); ?></strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-clock me-2"></i>Time</span>
                            <strong><?php echo date('h:i A', strtotime($res_details['reservation_time'])); ?></strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-chair me-2"></i>Table</span>
                            <strong>
                                <?php echo $res_details['table_number']
                                    ? 'Table '.htmlspecialchars($res_details['table_number'])
                                    : 'Not assigned'; ?>
                            </strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-map-marker-alt me-2"></i>Location</span>
                            <strong><?php echo htmlspecialchars($res_details['location'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-users me-2"></i>Guests</span>
                            <strong><?php echo $res_details['number_of_guests']; ?> people</strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-users me-2"></i>Capacity</span>
                            <strong><?php echo $res_details['seating_capacity'] ?? 'N/A'; ?> seats</strong>
                        </div>
                        <div class="info-row d-flex justify-content-between align-items-center">
                            <span class="text-muted"><i class="fas fa-calendar-plus me-2"></i>Booked on</span>
                            <strong><?php echo date('d M Y, h:i A', strtotime($res_details['created_at'])); ?></strong>
                        </div>
                        <?php if (!empty($res_details['special_requests'])): ?>
                        <div class="info-row">
                            <div class="text-muted mb-1"><i class="fas fa-sticky-note me-2"></i>Special Requests</div>
                            <div class="p-2 bg-light rounded small">
                                <?php echo htmlspecialchars($res_details['special_requests']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Status timeline -->
                    <div class="mb-4 pt-3 border-top">
                        <h6 class="mb-3">
                            <i class="fas fa-tasks text-success me-2"></i>Progress
                        </h6>
                        <?php
                        $flow    = ['pending','confirmed','completed'];
                        $cur_idx = array_search($res_details['status'], $flow);
                        if ($res_details['status'] === 'cancelled') $cur_idx = -1;
                        ?>
                        <div class="timeline">
                            <?php foreach ($flow as $i => $step):
                                if ($res_details['status'] === 'cancelled') {
                                    $dot = 'future';
                                } elseif ($i < $cur_idx) {
                                    $dot = 'done';
                                } elseif ($i == $cur_idx) {
                                    $dot = 'current';
                                } else {
                                    $dot = 'future';
                                }
                            ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?php echo $dot; ?>"></div>
                                <div class="fw-semibold <?php echo $dot=='current'?'text-success':($dot=='done'?'text-success':'text-muted'); ?>">
                                    <?php echo ucfirst($step); ?>
                                    <?php if ($dot === 'current'): ?>
                                    <span class="badge bg-success ms-2">Current</span>
                                    <?php elseif ($dot === 'done'): ?>
                                    <i class="fas fa-check-circle text-success ms-2"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if ($res_details['status'] === 'cancelled'): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot" style="border-color:#dc3545;background:white;"></div>
                                <div class="fw-semibold text-danger">
                                    Cancelled <i class="fas fa-times-circle ms-2"></i>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <?php if ($can_cancel): ?>
                    <div class="pt-3 border-top">
                        <h6 class="mb-3"><i class="fas fa-tools text-success me-2"></i>Actions</h6>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($res_details['status'] === 'pending'): ?>
                            <button class="btn btn-outline-primary"
                                    onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                        'id'       => $res_details['reservation_id'],
                                        'table_id' => $res_details['table_id'],
                                        'date'     => $res_details['reservation_date'],
                                        'time'     => substr($res_details['reservation_time'], 0, 5),
                                        'guests'   => $res_details['number_of_guests'],
                                        'req'      => $res_details['special_requests'] ?? ''
                                    ])); ?>)">
                                <i class="fas fa-edit me-1"></i>Edit Reservation
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-danger"
                                    onclick="cancelRes(<?php echo $res_details['reservation_id']; ?>)">
                                <i class="fas fa-times-circle me-1"></i>Cancel Reservation
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Tips sidebar when no detail is open -->
        <div class="col-lg-4">

            <!-- Tips card -->
            <div class="card shadow-sm mb-4" style="border-radius:15px;">
                <div class="card-header bg-white border-0" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-lightbulb text-warning me-2"></i>Reservation Tips
                    </h6>
                </div>
                <div class="card-body">
                    <?php
                    $tips = [
                        ['fas fa-clock text-primary',       'Book at least 24 hours in advance for guaranteed availability.'],
                        ['fas fa-users text-success',       'Choose a table with capacity ≥ your party size.'],
                        ['fas fa-sticky-note text-warning', 'Use Special Requests for dietary needs, celebrations, or seating preferences.'],
                        ['fas fa-times-circle text-danger', 'Cancel at least 2 hours before your slot to free the table for others.'],
                        ['fas fa-check-circle text-info',   'Reservations are confirmed by staff — watch for the "Confirmed" status.'],
                    ];
                    foreach ($tips as [$icon, $text]):
                    ?>
                    <div class="d-flex gap-3 mb-3">
                        <i class="fas <?php echo explode(' ',$icon)[0]; ?> <?php echo explode(' ',$icon)[1]; ?> mt-1 flex-shrink-0"
                           style="font-size:1rem;"></i>
                        <small class="text-muted"><?php echo $text; ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Opening hours -->
            <div class="card shadow-sm mb-4" style="border-radius:15px;">
                <div class="card-header bg-white border-0" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-clock text-success me-2"></i>Opening Hours
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2 small">
                        <span class="text-muted">Mon – Fri</span>
                        <span class="fw-semibold">10:00 AM – 11:00 PM</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 small">
                        <span class="text-muted">Sat – Sun</span>
                        <span class="fw-semibold">9:00 AM – 12:00 AM</span>
                    </div>
                    <div class="alert alert-info py-2 mb-0 small">
                        <i class="fas fa-info-circle me-1"></i>
                        Kitchen closes 1 hour before closing time.
                    </div>
                </div>
            </div>

            <!-- Contact -->
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white border-0" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-phone text-success me-2"></i>Need Help?
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-2 align-items-center mb-2">
                        <i class="fas fa-phone text-success"></i>
                        <span class="small">+880 1234-567890</span>
                    </div>
                    <div class="d-flex gap-2 align-items-center mb-3">
                        <i class="fas fa-envelope text-success"></i>
                        <span class="small">info@finedine.com</span>
                    </div>
                    <a href="../customer/menu.php" class="btn btn-outline-success btn-sm w-100">
                        <i class="fas fa-utensils me-1"></i>Browse Our Menu
                    </a>
                </div>
            </div>

        </div>
        <?php endif; ?>

    </div><!-- /row -->

</div><!-- /container-fluid -->

<!-- Hidden cancel form -->
<form method="POST" action="../modules/reservation_process.php" id="cancelForm" style="display:none;">
    <input type="hidden" name="reservation_id" id="cancelResId">
    <input type="hidden" name="cancel_reservation" value="1">
</form>

<!-- ══════ EDIT RESERVATION MODAL ══════ -->
<div class="modal fade" id="editResModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit text-success me-2"></i>Edit Reservation
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../modules/reservation_process.php">
                <input type="hidden" name="reservation_id" id="edit_res_id">
                <div class="modal-body">

                    <div class="row g-3 mb-4">
                        <!-- Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-calendar me-1 text-muted"></i>Date *
                            </label>
                            <input type="date" name="reservation_date" id="edit_date"
                                   class="form-control" required
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <!-- Time -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-clock me-1 text-muted"></i>Time *
                            </label>
                            <select name="reservation_time" id="edit_time" class="form-select" required>
                                <?php
                                $slots = ['11:00','11:30','12:00','12:30','13:00','13:30',
                                          '14:00','14:30','18:00','18:30','19:00','19:30',
                                          '20:00','20:30','21:00','21:30','22:00'];
                                foreach ($slots as $s):
                                ?>
                                <option value="<?php echo $s; ?>">
                                    <?php echo date('h:i A', strtotime($s)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Guests -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-users me-1 text-muted"></i>Guests *
                            </label>
                            <input type="number" name="number_of_guests" id="edit_guests"
                                   class="form-control" min="1" max="20" value="2" required>
                        </div>
                        <!-- Special requests -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-sticky-note me-1 text-muted"></i>Special Requests
                            </label>
                            <input type="text" name="special_requests" id="edit_req"
                                   class="form-control"
                                   placeholder="e.g. Window seat, birthday cake…">
                        </div>
                    </div>

                    <!-- Table picker -->
                    <label class="form-label fw-semibold mb-3">
                        <i class="fas fa-chair me-1 text-muted"></i>Table *
                    </label>
                    <input type="hidden" name="table_id" id="edit_table_id" required>
                    <div class="row g-2 mb-2" id="editTableGrid">
                        <?php foreach ($available_tables as $t): ?>
                        <div class="col-6 col-sm-4">
                            <div class="table-option"
                                 data-table-id="<?php echo $t['table_id']; ?>"
                                 data-capacity="<?php echo $t['seating_capacity']; ?>"
                                 onclick="editSelectTable(this)">
                                <div class="table-icon">
                                    <i class="fas fa-chair"></i>
                                </div>
                                <div class="fw-bold"><?php echo htmlspecialchars($t['table_number']); ?></div>
                                <div class="small text-muted">
                                    <i class="fas fa-users me-1"></i><?php echo $t['seating_capacity']; ?> seats
                                </div>
                                <?php if ($t['location']): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($t['location']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="editTableWarning" class="alert alert-warning d-none">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="editTableWarningText"></span>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Only <em>pending</em> reservations can be self-edited.
                        Contact staff for confirmed reservations.
                    </small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_reservation" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// ── Booking form: table picker ────────────────────────────────────
function selectTable(el) {
    document.querySelectorAll('#tableGrid .table-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('selectedTable').value = el.dataset.tableId;
    document.getElementById('guestHint').textContent =
        'This table seats up to ' + el.dataset.capacity + ' guests.';
    checkGuestWarning(
        el, 'guestCount', 'tableWarning', 'tableWarningText'
    );
}

document.getElementById('guestCount').addEventListener('input', function () {
    const sel = document.querySelector('#tableGrid .table-option.selected');
    if (sel) selectTable(sel);
});

document.getElementById('bookingForm').addEventListener('submit', function (e) {
    if (!document.getElementById('selectedTable').value) {
        e.preventDefault();
        document.getElementById('tableWarning').classList.remove('d-none');
        document.getElementById('tableWarningText').textContent =
            'Please select a table before confirming.';
        document.getElementById('tableGrid').scrollIntoView({ behavior:'smooth', block:'center' });
    }
});

// ── Edit modal: table picker ──────────────────────────────────────
function editSelectTable(el) {
    document.querySelectorAll('#editTableGrid .table-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('edit_table_id').value = el.dataset.tableId;
    checkGuestWarning(
        el, 'edit_guests', 'editTableWarning', 'editTableWarningText'
    );
}

document.getElementById('edit_guests').addEventListener('input', function () {
    const sel = document.querySelector('#editTableGrid .table-option.selected');
    if (sel) editSelectTable(sel);
});

// ── Shared capacity warning helper ────────────────────────────────
function checkGuestWarning(tableEl, guestInputId, warningId, warningTextId) {
    const guests   = parseInt(document.getElementById(guestInputId).value) || 1;
    const capacity = parseInt(tableEl.dataset.capacity);
    const warning  = document.getElementById(warningId);
    const wtext    = document.getElementById(warningTextId);
    if (guests > capacity) {
        wtext.textContent = 'You have ' + guests + ' guests but this table seats ' + capacity + '.';
        warning.classList.remove('d-none');
    } else {
        warning.classList.add('d-none');
    }
}

// ── Open edit modal, pre-fill fields ─────────────────────────────
function openEditModal(res) {
    document.getElementById('edit_res_id').value  = res.id;
    document.getElementById('edit_date').value    = res.date;
    document.getElementById('edit_guests').value  = res.guests;
    document.getElementById('edit_req').value     = res.req || '';

    // Select matching time option
    const timeSelect = document.getElementById('edit_time');
    for (let i = 0; i < timeSelect.options.length; i++) {
        if (timeSelect.options[i].value === res.time) {
            timeSelect.selectedIndex = i;
            break;
        }
    }

    // Pre-select the current table card
    document.querySelectorAll('#editTableGrid .table-option').forEach(o => {
        o.classList.remove('selected');
        if (parseInt(o.dataset.tableId) === parseInt(res.table_id)) {
            o.classList.add('selected');
            document.getElementById('edit_table_id').value = res.table_id;
        }
    });

    document.getElementById('editTableWarning').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('editResModal')).show();
}

// ── Cancel reservation ────────────────────────────────────────────
function cancelRes(id) {
    const padded = String(id).padStart(4, '0');
    if (typeof RMS !== 'undefined' && RMS.confirmModal) {
        RMS.confirmModal({
            title:        'Cancel Reservation',
            message:      'Are you sure you want to cancel reservation <strong>#' + padded + '</strong>?',
            confirmText:  'Yes, Cancel',
            confirmClass: 'btn-danger',
            onConfirm: () => {
                document.getElementById('cancelResId').value = id;
                document.getElementById('cancelForm').submit();
            }
        });
    } else {
        if (confirm('Cancel reservation #' + padded + '?\nThis cannot be undone.')) {
            document.getElementById('cancelResId').value = id;
            document.getElementById('cancelForm').submit();
        }
    }
}

// ── Auto-dismiss success alert ────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert-success').forEach(a =>
        bootstrap.Alert.getOrCreateInstance(a).close()
    );
}, 5000);
</script>
</body>
</html>