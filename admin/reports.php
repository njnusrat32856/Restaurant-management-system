<?php
// admin/reports.php - Reports & Analytics
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

// ── Date Range ────────────────────────────────────────────────────
$period     = $_GET['period']     ?? 'this_month';
$date_from  = $_GET['date_from']  ?? '';
$date_to    = $_GET['date_to']    ?? '';

switch ($period) {
    case 'today':
        $from = date('Y-m-d');
        $to   = date('Y-m-d');
        break;
    case 'yesterday':
        $from = date('Y-m-d', strtotime('-1 day'));
        $to   = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'this_week':
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = date('Y-m-d');
        break;
    case 'last_week':
        $from = date('Y-m-d', strtotime('monday last week'));
        $to   = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'this_month':
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        break;
    case 'last_month':
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to   = date('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'this_year':
        $from = date('Y-01-01');
        $to   = date('Y-m-d');
        break;
    case 'custom':
        $from = $date_from ?: date('Y-m-01');
        $to   = $date_to   ?: date('Y-m-d');
        break;
    default:
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
}

try {
    $database = new Database();
    $db       = $database->connect();

    // ── Summary KPIs ──────────────────────────────────────────────
    $kpi = $db->prepare(
        "SELECT
            COUNT(*)                                              AS total_orders,
            COALESCE(SUM(total_amount),0)                        AS total_revenue,
            COALESCE(AVG(total_amount),0)                        AS avg_order,
            SUM(CASE WHEN status='completed'  THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='cancelled'  THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN status='pending'    THEN 1 ELSE 0 END) AS pending
         FROM orders
         WHERE DATE(order_date) BETWEEN :f AND :t"
    );
    $kpi->execute([':f' => $from, ':t' => $to]);
    $summary = $kpi->fetch(PDO::FETCH_ASSOC);

    // ── Previous Period (for % change) ────────────────────────────
    $days_diff = (strtotime($to) - strtotime($from)) / 86400 + 1;
    $prev_from = date('Y-m-d', strtotime($from) - $days_diff * 86400);
    $prev_to   = date('Y-m-d', strtotime($from) - 86400);

    $prev_kpi = $db->prepare(
        "SELECT COALESCE(SUM(total_amount),0) AS total_revenue,
                COUNT(*) AS total_orders
         FROM orders
         WHERE DATE(order_date) BETWEEN :f AND :t AND status='completed'"
    );
    $prev_kpi->execute([':f' => $prev_from, ':t' => $prev_to]);
    $prev = $prev_kpi->fetch(PDO::FETCH_ASSOC);

    $rev_change = $prev['total_revenue'] > 0
        ? (($summary['total_revenue'] - $prev['total_revenue']) / $prev['total_revenue']) * 100
        : 0;
    $ord_change = $prev['total_orders'] > 0
        ? (($summary['total_orders'] - $prev['total_orders']) / $prev['total_orders']) * 100
        : 0;

    // ── Daily Revenue (line chart) ────────────────────────────────
    $daily = $db->prepare(
        "SELECT DATE(order_date) AS d,
                COALESCE(SUM(total_amount),0) AS revenue,
                COUNT(*) AS orders
         FROM orders
         WHERE DATE(order_date) BETWEEN :f AND :t
         GROUP BY DATE(order_date)
         ORDER BY d"
    );
    $daily->execute([':f' => $from, ':t' => $to]);
    $daily_data = $daily->fetchAll(PDO::FETCH_ASSOC);

    // ── Revenue by Category (doughnut) ────────────────────────────
    $cat_rev = $db->prepare(
        "SELECT c.category_name,
                COALESCE(SUM(oi.subtotal),0) AS revenue,
                SUM(oi.quantity) AS qty
         FROM order_items oi
         JOIN menu_items mi ON oi.item_id   = mi.item_id
         JOIN categories  c  ON mi.category_id = c.category_id
         JOIN orders       o  ON oi.order_id    = o.order_id
         WHERE DATE(o.order_date) BETWEEN :f AND :t
         GROUP BY c.category_id
         ORDER BY revenue DESC"
    );
    $cat_rev->execute([':f' => $from, ':t' => $to]);
    $category_data = $cat_rev->fetchAll(PDO::FETCH_ASSOC);

    // ── Top 10 Menu Items ─────────────────────────────────────────
    $top_items = $db->prepare(
        "SELECT mi.item_name, c.category_name,
                SUM(oi.quantity)  AS total_qty,
                SUM(oi.subtotal)  AS total_rev,
                AVG(mi.price)     AS unit_price
         FROM order_items oi
         JOIN menu_items mi ON oi.item_id    = mi.item_id
         JOIN categories  c  ON mi.category_id = c.category_id
         JOIN orders       o  ON oi.order_id    = o.order_id
         WHERE DATE(o.order_date) BETWEEN :f AND :t
         GROUP BY mi.item_id
         ORDER BY total_qty DESC
         LIMIT 10"
    );
    $top_items->execute([':f' => $from, ':t' => $to]);
    $top_items_data = $top_items->fetchAll(PDO::FETCH_ASSOC);

    // ── Orders by Status (bar) ────────────────────────────────────
    $status_data = $db->prepare(
        "SELECT status, COUNT(*) AS cnt
         FROM orders
         WHERE DATE(order_date) BETWEEN :f AND :t
         GROUP BY status"
    );
    $status_data->execute([':f' => $from, ':t' => $to]);
    $order_status = $status_data->fetchAll(PDO::FETCH_ASSOC);

    // ── Hourly Orders (heatmap-style bar) ─────────────────────────
    $hourly = $db->prepare(
        "SELECT HOUR(order_date) AS hr, COUNT(*) AS cnt
         FROM orders
         WHERE DATE(order_date) BETWEEN :f AND :t
         GROUP BY HOUR(order_date)
         ORDER BY hr"
    );
    $hourly->execute([':f' => $from, ':t' => $to]);
    $hourly_data = $hourly->fetchAll(PDO::FETCH_ASSOC);

    // Build full 24-hour array
    $hours_arr = array_fill(0, 24, 0);
    foreach ($hourly_data as $h) {
        $hours_arr[(int)$h['hr']] = (int)$h['cnt'];
    }

    // ── Table Utilisation ─────────────────────────────────────────
    $table_util = $db->prepare(
        "SELECT rt.table_number,
                COUNT(o.order_id) AS total_orders,
                COALESCE(SUM(o.total_amount),0) AS total_rev
         FROM restaurant_tables rt
         LEFT JOIN orders o ON rt.table_id = o.table_id
                            AND DATE(o.order_date) BETWEEN :f AND :t
         GROUP BY rt.table_id
         ORDER BY total_orders DESC
         LIMIT 8"
    );
    $table_util->execute([':f' => $from, ':t' => $to]);
    $table_data = $table_util->fetchAll(PDO::FETCH_ASSOC);

    // ── Staff Performance ─────────────────────────────────────────
    $staff_perf = $db->prepare(
        "SELECT u.full_name,
                COUNT(o.order_id)              AS total_orders,
                COALESCE(SUM(o.total_amount),0) AS total_rev
         FROM users u
         LEFT JOIN orders o ON u.user_id = o.staff_id
                            AND DATE(o.order_date) BETWEEN :f AND :t
         WHERE u.role = 'staff'
         GROUP BY u.user_id
         ORDER BY total_orders DESC
         LIMIT 5"
    );
    $staff_perf->execute([':f' => $from, ':t' => $to]);
    $staff_data = $staff_perf->fetchAll(PDO::FETCH_ASSOC);

    // ── Recent Transactions ───────────────────────────────────────
    $recent_tx = $db->prepare(
        "SELECT o.order_id, o.order_date, o.total_amount, o.status,
                o.customer_name, rt.table_number, u.full_name AS staff_name
         FROM orders o
         LEFT JOIN restaurant_tables rt ON o.table_id  = rt.table_id
         LEFT JOIN users              u  ON o.staff_id  = u.user_id
         WHERE DATE(o.order_date) BETWEEN :f AND :t
         ORDER BY o.order_date DESC
         LIMIT 15"
    );
    $recent_tx->execute([':f' => $from, ':t' => $to]);
    $transactions = $recent_tx->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $summary = ['total_orders'=>0,'total_revenue'=>0,'avg_order'=>0,'completed'=>0,'cancelled'=>0,'pending'=>0];
    $daily_data = $category_data = $top_items_data = $order_status = [];
    $hours_arr = array_fill(0,24,0);
    $table_data = $staff_data = $transactions = [];
    $rev_change = $ord_change = 0;
}

$page_title = 'Reports & Analytics';
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* KPI Cards */
.kpi-card {
    border-radius: 16px;
    padding: 24px;
    border: none;
    transition: transform .3s;
    position: relative;
    overflow: hidden;
}
.kpi-card:hover { transform: translateY(-4px); }
.kpi-card .kpi-icon {
    width: 56px; height: 56px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem;
    background: rgba(255,255,255,.2);
}
.kpi-card .kpi-val  { font-size: 2rem; font-weight: 800; }
.kpi-card .kpi-lbl  { font-size: .9rem; opacity: .9; }
.kpi-card .kpi-trend {
    font-size: .8rem;
    margin-top: 6px;
}
.kpi-card .kpi-bg-icon {
    position: absolute;
    right: -10px; bottom: -10px;
    font-size: 5rem;
    opacity: .08;
}

/* Period Selector */
.period-btn { border-radius: 8px !important; font-size: .85rem; }
.period-btn.active { background: #667eea !important; border-color: #667eea !important; color: white !important; }

/* Chart Cards */
.chart-card { border-radius: 16px; border: none; }
.chart-card .card-header {
    background: white;
    border-bottom: 1px solid #f0f0f0;
    border-radius: 16px 16px 0 0 !important;
    padding: 18px 22px;
}

/* Top Items Table */
.item-rank {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 700; color: white;
}
.rank-1 { background: #ffd700; }
.rank-2 { background: #c0c0c0; }
.rank-3 { background: #cd7f32; }
.rank-other { background: #6c757d; }

/* Progress bars */
.progress { border-radius: 10px; }
.progress-bar { border-radius: 10px; }

/* Transactions */
.tx-row:hover { background: #f8f9fa; }

/* Export Bar */
.export-bar {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 14px;
    padding: 20px 24px;
}

/* Hourly heatmap colors */
.heat-low    { background: #e8f5e9; }
.heat-mid    { background: #a5d6a7; }
.heat-high   { background: #388e3c; color: white; }
.heat-peak   { background: #1b5e20; color: white; }
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-chart-bar text-primary me-2"></i>Reports & Analytics</h2>
            <p class="text-muted mb-0">
                Showing data from
                <strong><?php echo date('M j, Y', strtotime($from)); ?></strong> to
                <strong><?php echo date('M j, Y', strtotime($to));   ?></strong>
            </p>
        </div>
        <!-- Export Bar -->
        <div class="export-bar d-flex gap-2 align-items-center">
            <span class="text-white fw-semibold me-2"><i class="fas fa-download me-1"></i>Export:</span>
            <button class="btn btn-light btn-sm" onclick="exportCSV()">
                <i class="fas fa-file-csv me-1 text-success"></i>CSV
            </button>
            <button class="btn btn-light btn-sm" onclick="exportPDF()">
                <i class="fas fa-file-pdf me-1 text-danger"></i>PDF
            </button>
            <button class="btn btn-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1 text-primary"></i>Print
            </button>
        </div>
    </div>

    <!-- Period Selector -->
    <div class="card shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-lg-8 mb-2 mb-lg-0">
                    <div class="d-flex gap-2 flex-wrap">
                        <?php
                        $periods = [
                            'today'      => 'Today',
                            'yesterday'  => 'Yesterday',
                            'this_week'  => 'This Week',
                            'last_week'  => 'Last Week',
                            'this_month' => 'This Month',
                            'last_month' => 'Last Month',
                            'this_year'  => 'This Year',
                            'custom'     => 'Custom',
                        ];
                        foreach ($periods as $key => $label):
                        ?>
                        <a href="?period=<?php echo $key; ?>"
                           class="btn btn-outline-primary btn-sm period-btn <?php echo $period == $key ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <form method="GET" class="d-flex gap-2 align-items-center" id="customRangeForm">
                        <input type="hidden" name="period" value="custom">
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?php echo $from; ?>" max="<?php echo date('Y-m-d'); ?>">
                        <span class="text-muted">—</span>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?php echo $to; ?>"   max="<?php echo date('Y-m-d'); ?>">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-4 mb-4">

        <!-- Total Revenue -->
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="kpi-icon mb-3"><i class="fas fa-dollar-sign"></i></div>
                <div class="kpi-val">৳<?php echo number_format($summary['total_revenue'],0); ?></div>
                <div class="kpi-lbl">Total Revenue</div>
                <div class="kpi-trend">
                    <i class="fas fa-<?php echo $rev_change >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                    <?php echo number_format(abs($rev_change),1); ?>% vs prev. period
                </div>
                <i class="fas fa-dollar-sign kpi-bg-icon"></i>
            </div>
        </div>

        <!-- Total Orders -->
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="kpi-icon mb-3"><i class="fas fa-receipt"></i></div>
                <div class="kpi-val"><?php echo number_format($summary['total_orders']); ?></div>
                <div class="kpi-lbl">Total Orders</div>
                <div class="kpi-trend">
                    <i class="fas fa-<?php echo $ord_change >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                    <?php echo number_format(abs($ord_change),1); ?>% vs prev. period
                </div>
                <i class="fas fa-receipt kpi-bg-icon"></i>
            </div>
        </div>

        <!-- Avg Order Value -->
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="kpi-icon mb-3"><i class="fas fa-chart-line"></i></div>
                <div class="kpi-val">৳<?php echo number_format($summary['avg_order'],0); ?></div>
                <div class="kpi-lbl">Avg. Order Value</div>
                <div class="kpi-trend">
                    <i class="fas fa-info-circle"></i> Per transaction
                </div>
                <i class="fas fa-chart-line kpi-bg-icon"></i>
            </div>
        </div>

        <!-- Completion Rate -->
        <?php
        $completion_rate = $summary['total_orders'] > 0
            ? ($summary['completed'] / $summary['total_orders']) * 100 : 0;
        ?>
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card shadow-sm text-white"
                 style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                <div class="kpi-icon mb-3"><i class="fas fa-check-double"></i></div>
                <div class="kpi-val"><?php echo number_format($completion_rate,1); ?>%</div>
                <div class="kpi-lbl">Completion Rate</div>
                <div class="kpi-trend">
                    <i class="fas fa-times-circle"></i>
                    <?php echo $summary['cancelled']; ?> cancelled orders
                </div>
                <i class="fas fa-check-double kpi-bg-icon"></i>
            </div>
        </div>

    </div>

    <!-- Charts Row 1 -->
    <div class="row g-4 mb-4">

        <!-- Daily Revenue Line Chart -->
        <div class="col-lg-8">
            <div class="card chart-card shadow-sm h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-chart-area text-primary me-2"></i>Revenue Over Time</h6>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleChartType('revenueChart', 'line')">Line</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleChartType('revenueChart', 'bar')">Bar</button>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Category Doughnut -->
        <div class="col-lg-4">
            <div class="card chart-card shadow-sm h-100">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-chart-pie text-warning me-2"></i>Revenue by Category</h6>
                </div>
                <div class="card-body d-flex flex-column">
                    <canvas id="categoryChart" height="200"></canvas>
                    <div class="mt-3" id="categoryLegend"></div>
                </div>
            </div>
        </div>

    </div>

    <!-- Charts Row 2 -->
    <div class="row g-4 mb-4">

        <!-- Hourly Orders -->
        <div class="col-lg-6">
            <div class="card chart-card shadow-sm">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-clock text-info me-2"></i>Busiest Hours</h6>
                </div>
                <div class="card-body">
                    <canvas id="hourlyChart" height="160"></canvas>
                </div>
            </div>
        </div>

        <!-- Order Status Bar -->
        <div class="col-lg-6">
            <div class="card chart-card shadow-sm">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-tasks text-success me-2"></i>Orders by Status</h6>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" height="160"></canvas>
                </div>
            </div>
        </div>

    </div>

    <!-- Tables & Staff Row -->
    <!-- <div class="row g-4 mb-4">

        
        <div class="col-lg-6">
            <div class="card chart-card shadow-sm">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-chair text-danger me-2"></i>Table Utilisation</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($table_data)): ?>
                    <?php $max_t = max(array_column($table_data,'total_orders')) ?: 1; ?>
                    <?php foreach ($table_data as $t): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-semibold">Table <?php echo htmlspecialchars($t['table_number']); ?></span>
                            <span class="text-muted small"><?php echo $t['total_orders']; ?> orders &nbsp; ৳<?php echo number_format($t['total_rev'],0); ?></span>
                        </div>
                        <div class="progress" style="height:10px;">
                            <div class="progress-bar bg-primary"
                                 style="width:<?php echo ($t['total_orders']/$max_t)*100; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <p class="text-muted text-center py-4"><i class="fas fa-inbox fs-3 d-block mb-2"></i>No data</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        
        <div class="col-lg-6">
            <div class="card chart-card shadow-sm">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-user-tie text-purple me-2"></i>Staff Performance</h6>
                </div>
                <div class="card-body">
                    <?php if (!empty($staff_data)): ?>
                    <?php $max_s = max(array_column($staff_data,'total_orders')) ?: 1; ?>
                    <?php foreach ($staff_data as $idx => $s): ?>
                    <div class="d-flex align-items-center mb-3">
                        <div class="item-rank rank-<?php echo $idx < 3 ? $idx+1 : 'other'; ?> me-3">
                            <?php echo $idx+1; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="fw-semibold"><?php echo htmlspecialchars($s['full_name']); ?></span>
                                <span class="text-muted small"><?php echo $s['total_orders']; ?> orders</span>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar <?php echo ['bg-warning','bg-secondary','bg-info','bg-primary','bg-success'][$idx % 5]; ?>"
                                     style="width:<?php echo ($s['total_orders']/$max_s)*100; ?>%"></div>
                            </div>
                        </div>
                        <div class="ms-3 text-success fw-bold small">৳<?php echo number_format($s['total_rev'],0); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <p class="text-muted text-center py-4"><i class="fas fa-inbox fs-3 d-block mb-2"></i>No data</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div> -->

    <!-- Top Menu Items -->
    <div class="card chart-card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-fire text-danger me-2"></i>Top Selling Menu Items</h6>
            <span class="badge bg-primary"><?php echo count($top_items_data); ?> items</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="50">Rank</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Unit Price</th>
                            <th>Qty Sold</th>
                            <th>Revenue</th>
                            <th>Revenue Share</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($top_items_data)):
                        $total_item_rev = array_sum(array_column($top_items_data,'total_rev'));
                        foreach ($top_items_data as $idx => $item):
                        $share = $total_item_rev > 0 ? ($item['total_rev']/$total_item_rev)*100 : 0;
                    ?>
                    <tr class="tx-row">
                        <td>
                            <span class="item-rank rank-<?php echo $idx < 3 ? $idx+1 : 'other'; ?>">
                                <?php echo $idx+1; ?>
                            </span>
                        </td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['category_name']); ?></span></td>
                        <td>৳<?php echo number_format($item['unit_price'],2); ?></td>
                        <td><span class="badge bg-primary"><?php echo number_format($item['total_qty']); ?></span></td>
                        <td class="fw-bold text-success">৳<?php echo number_format($item['total_rev'],2); ?></td>
                        <td style="min-width:140px;">
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:8px;">
                                    <div class="progress-bar bg-success" style="width:<?php echo $share; ?>%"></div>
                                </div>
                                <small><?php echo number_format($share,1); ?>%</small>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach;
                    else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fs-3 d-block mb-2"></i>No sales data for this period
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="card chart-card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-list-alt text-info me-2"></i>Recent Transactions</h6>
            <span class="badge bg-secondary"><?php echo count($transactions); ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order ID</th>
                            <th>Date & Time</th>
                            <th>Table</th>
                            <th>Customer</th>
                            <th>Staff</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($transactions)):
                        $sc = ['completed'=>'success','cancelled'=>'danger','pending'=>'warning','preparing'=>'info','served'=>'primary'];
                        foreach ($transactions as $tx):
                        $c = $sc[$tx['status']] ?? 'secondary';
                    ?>
                    <tr class="tx-row">
                        <td><strong>#<?php echo str_pad($tx['order_id'],5,'0',STR_PAD_LEFT); ?></strong></td>
                        <td>
                            <div><?php echo date('M j, Y', strtotime($tx['order_date'])); ?></div>
                            <small class="text-muted"><?php echo date('h:i A', strtotime($tx['order_date'])); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($tx['table_number'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($tx['customer_name'] ?? 'Walk-in'); ?></td>
                        <td><?php echo htmlspecialchars($tx['staff_name'] ?? '—'); ?></td>
                        <td class="fw-bold text-success">৳<?php echo number_format($tx['total_amount'],2); ?></td>
                        <td><span class="badge bg-<?php echo $c; ?> px-3 py-2"><?php echo ucfirst($tx['status']); ?></span></td>
                    </tr>
                    <?php endforeach;
                    else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fs-3 d-block mb-2"></i>No transactions for this period
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// ── Chart.js defaults ──────────────────────────────────────────────
Chart.defaults.font.family = "'Segoe UI', sans-serif";
Chart.defaults.color       = '#6c757d';

const palette = ['#667eea','#11998e','#f7971e','#eb3349','#764ba2','#38ef7d','#ffd200','#f45c43'];

// ── PHP → JS data ──────────────────────────────────────────────────
const dailyRaw  = <?php echo json_encode($daily_data);    ?>;
const catRaw    = <?php echo json_encode($category_data); ?>;
const statusRaw = <?php echo json_encode($order_status);  ?>;
const hoursRaw  = <?php echo json_encode(array_values($hours_arr)); ?>;

// ── Revenue Chart ──────────────────────────────────────────────────
const revCtx = document.getElementById('revenueChart').getContext('2d');
let revenueChart = new Chart(revCtx, {
    type: 'line',
    data: {
        labels: dailyRaw.map(d => {
            const dt = new Date(d.d);
            return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'});
        }),
        datasets: [
            {
                label: 'Revenue (৳)',
                data: dailyRaw.map(d => parseFloat(d.revenue)),
                borderColor: '#667eea',
                backgroundColor: 'rgba(102,126,234,.12)',
                borderWidth: 3, fill: true, tension: .4,
                pointRadius: 5, pointHoverRadius: 8,
                pointBackgroundColor: '#667eea', pointBorderColor: '#fff', pointBorderWidth: 2,
                yAxisID: 'y'
            },
            {
                label: 'Orders',
                data: dailyRaw.map(d => parseInt(d.orders)),
                borderColor: '#11998e',
                backgroundColor: 'transparent',
                borderWidth: 2, borderDash: [5,5],
                tension: .4, pointRadius: 4,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode:'index', intersect:false },
        plugins: {
            legend: { position:'top' },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.datasetIndex === 0
                        ? ' Revenue: ৳' + ctx.parsed.y.toLocaleString()
                        : ' Orders: '  + ctx.parsed.y
                }
            }
        },
        scales: {
            y:  { beginAtZero:true, ticks:{ callback: v => '৳'+v.toLocaleString() }, grid:{ color:'rgba(0,0,0,.05)' } },
            y1: { beginAtZero:true, position:'right', grid:{ display:false }, ticks:{ stepSize:1 } },
            x:  { grid:{ display:false } }
        }
    }
});

function toggleChartType(id, type) {
    revenueChart.config.type = type;
    revenueChart.update();
}

// ── Category Doughnut ──────────────────────────────────────────────
const catCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(catCtx, {
    type: 'doughnut',
    data: {
        labels: catRaw.map(c => c.category_name),
        datasets: [{
            data: catRaw.map(c => parseFloat(c.revenue)),
            backgroundColor: palette,
            borderWidth: 3,
            borderColor: '#fff',
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true, cutout: '65%',
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => ` ৳${ctx.parsed.toLocaleString()}`
                }
            }
        }
    }
});

// Custom legend
const legendEl = document.getElementById('categoryLegend');
catRaw.forEach((c,i) => {
    const total = catRaw.reduce((a,b) => a + parseFloat(b.revenue), 0);
    const pct   = total > 0 ? ((c.revenue/total)*100).toFixed(1) : 0;
    legendEl.innerHTML += `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center">
                <span style="width:12px;height:12px;border-radius:3px;background:${palette[i]};display:inline-block;" class="me-2"></span>
                <small>${c.category_name}</small>
            </div>
            <small class="fw-bold">${pct}%</small>
        </div>`;
});

// ── Hourly Bar Chart ───────────────────────────────────────────────
const hourLabels = Array.from({length:24},(_,i)=>{
    const h = i % 12 || 12;
    return h + (i < 12 ? 'am' : 'pm');
});
const maxH = Math.max(...hoursRaw, 1);
const hourColors = hoursRaw.map(v => {
    const r = v / maxH;
    if (r > .75) return '#1b5e20';
    if (r > .5)  return '#388e3c';
    if (r > .25) return '#a5d6a7';
    return '#e8f5e9';
});

const hCtx = document.getElementById('hourlyChart').getContext('2d');
new Chart(hCtx, {
    type: 'bar',
    data: {
        labels: hourLabels,
        datasets: [{
            label: 'Orders',
            data: hoursRaw,
            backgroundColor: hourColors,
            borderRadius: 6, borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: { legend:{ display:false }, tooltip:{ callbacks:{ label: ctx => ` ${ctx.parsed.y} orders` } } },
        scales: {
            y: { beginAtZero:true, ticks:{stepSize:1}, grid:{color:'rgba(0,0,0,.04)'} },
            x: { grid:{ display:false } }
        }
    }
});

// ── Status Bar Chart ───────────────────────────────────────────────
const statusColors = {
    pending:'#ffc107', preparing:'#17a2b8',
    served:'#667eea', completed:'#28a745', cancelled:'#dc3545'
};
const sCtx = document.getElementById('statusChart').getContext('2d');
new Chart(sCtx, {
    type: 'bar',
    data: {
        labels: statusRaw.map(s => s.status.charAt(0).toUpperCase()+s.status.slice(1)),
        datasets: [{
            label: 'Orders',
            data: statusRaw.map(s => parseInt(s.cnt)),
            backgroundColor: statusRaw.map(s => statusColors[s.status] || '#6c757d'),
            borderRadius: 8, borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: { legend:{ display:false } },
        scales: {
            y: { beginAtZero:true, ticks:{stepSize:1}, grid:{color:'rgba(0,0,0,.04)'} },
            x: { grid:{ display:false } }
        }
    }
});

// ── Export CSV ─────────────────────────────────────────────────────
function exportCSV() {
    const rows = [
        ['Order ID','Date','Table','Customer','Staff','Amount','Status'],
        <?php foreach($transactions as $tx): ?>
        ['#<?php echo str_pad($tx['order_id'],5,'0',STR_PAD_LEFT); ?>',
         '<?php echo date('Y-m-d H:i', strtotime($tx['order_date'])); ?>',
         '<?php echo addslashes($tx['table_number'] ?? ''); ?>',
         '<?php echo addslashes($tx['customer_name'] ?? 'Walk-in'); ?>',
         '<?php echo addslashes($tx['staff_name'] ?? ''); ?>',
         '<?php echo $tx['total_amount']; ?>',
         '<?php echo $tx['status']; ?>'],
        <?php endforeach; ?>
    ];
    const csv = rows.map(r => r.join(',')).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'report_<?php echo $from; ?>_<?php echo $to; ?>.csv';
    a.click();
}

// ── Export PDF (print-based) ───────────────────────────────────────
function exportPDF() { window.print(); }
</script>

<style media="print">
    .navbar, .export-bar, .period-btn, form, button { display: none !important; }
    body { padding-top: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    canvas { max-width: 100%; }
</style>
</body>
</html>