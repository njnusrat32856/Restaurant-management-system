<?php
// admin/index.php - Admin Dashboard
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->connect();
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
    $total_users = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE DATE(order_date) = CURDATE()");
    $today_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE DATE(order_date) = CURDATE() AND status = 'completed'");
    $today_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM menu_items WHERE availability = 'available'");
    $total_menu_items = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE status IN ('pending', 'preparing')");
    $pending_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM reservations WHERE status = 'confirmed' AND reservation_date >= CURDATE()");
    $active_reservations = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $db->query("SELECT o.*, u.full_name, rt.table_number 
                       FROM orders o 
                       LEFT JOIN users u ON o.staff_id = u.user_id 
                       LEFT JOIN restaurant_tables rt ON o.table_id = rt.table_id 
                       ORDER BY o.order_date DESC LIMIT 5");
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT DATE(order_date) as date, SUM(total_amount) as revenue 
                       FROM orders 
                       WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                       AND status = 'completed'
                       GROUP BY DATE(order_date) 
                       ORDER BY date");
    $weekly_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT mi.item_name, SUM(oi.quantity) as total_sold, SUM(oi.subtotal) as revenue
                       FROM order_items oi
                       JOIN menu_items mi ON oi.item_id = mi.item_id
                       JOIN orders o ON oi.order_id = o.order_id
                       WHERE o.status = 'completed'
                       GROUP BY mi.item_id
                       ORDER BY total_sold DESC
                       LIMIT 5");
    $top_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log($error_message);

    $total_users = 0;
    $today_orders = 0;
    $today_revenue = 0;
    $total_menu_items = 0;
    $pending_orders = 0;
    $active_reservations = 0;
    $recent_orders = [];
    $weekly_revenue = [];
    $top_items = [];
}

$page_title = 'Admin Dashboard';
$base_url = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Fine Dine RMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .stat-card {
            border-radius: 15px;
            padding: 25px;
            height: 100%;
            transition: all 0.3s ease;
            border: none;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            opacity: 0.1;
            font-size: 80px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .stat-trend {
            font-size: 0.85rem;
            margin-top: 10px;
        }
        
        .quick-action-card {
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid #e9ecef;
            cursor: pointer;
        }
        
        .quick-action-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .quick-action-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            padding: 20px;
        }
        
        .table-hover tbody tr {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .table-hover tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.01);
        }
        
        .badge-status {
            padding: 6px 12px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
        }
        
        .top-item {
            padding: 15px;
            border-radius: 10px;
            background: #f8f9fa;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .top-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
    </style>
</head>
<body>

    <?php include '../includes/navbar.php'; ?>
    
    <div class="container-fluid py-4">
        
        <div class="welcome-section">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-2">
                        <i class="fas fa-hand-wave me-2"></i>
                        Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
                    </h2>
                    <p class="mb-0 opacity-90">
                        Here's what's happening in your restaurant today.
                    </p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <div class="fs-5 mb-1">
                        <i class="fas fa-calendar-day me-2"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>
                    <div class="fs-6 opacity-90">
                        <i class="fas fa-clock me-2"></i>
                        <span id="currentTime"><?php echo date('h:i A'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-value"><?php echo $today_orders; ?></div>
                    <div class="stat-label" style="color: rgba(255,255,255,0.9);">Orders Today</div>
                    <div class="stat-trend text-white-50">
                        <i class="fas fa-chart-line me-1"></i>
                        <span>Active operations</span>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-value">৳<?php echo number_format($today_revenue, 2); ?></div>
                    <div class="stat-label" style="color: rgba(255,255,255,0.9);">Today's Revenue</div>
                    <div class="stat-trend text-white-50">
                        <i class="fas fa-arrow-up me-1"></i>
                        <span>Total earnings</span>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $pending_orders; ?></div>
                    <div class="stat-label" style="color: rgba(255,255,255,0.9);">Pending Orders</div>
                    <div class="stat-trend text-white-50">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <span>Needs attention</span>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.2);">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-value"><?php echo $active_reservations; ?></div>
                    <div class="stat-label" style="color: rgba(255,255,255,0.9);">Active Reservations</div>
                    <div class="stat-trend text-white-50">
                        <i class="fas fa-users me-1"></i>
                        <span>Upcoming bookings</span>
                    </div>
                </div>
            </div>
            
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <h4 class="mb-3">
                    <i class="fas fa-bolt text-warning me-2"></i>
                    Quick Actions
                </h4>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card quick-action-card" onclick="window.location.href='menu_management.php'">
                    <div class="quick-action-icon text-primary">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <h6 class="mb-0">Manage Menu</h6>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card quick-action-card" onclick="window.location.href='staff_management.php'">
                    <div class="quick-action-icon text-success">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h6 class="mb-0">Staff Management</h6>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card quick-action-card" onclick="window.location.href='table_management.php'">
                    <div class="quick-action-icon text-info">
                        <i class="fas fa-chair"></i>
                    </div>
                    <h6 class="mb-0">Tables</h6>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card quick-action-card" onclick="window.location.href='reports.php'">
                    <div class="quick-action-icon text-warning">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h6 class="mb-0">Reports</h6>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card quick-action-card" onclick="window.location.href='inventory.php'">
                    <div class="quick-action-icon text-danger">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <h6 class="mb-0">Inventory</h6>
                </div>
            </div>
            
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card quick-action-card" onclick="window.location.href='../customer/menu.php'">
                    <div class="quick-action-icon text-secondary">
                        <i class="fas fa-eye"></i>
                    </div>
                    <h6 class="mb-0">View Menu</h6>
                </div>
            </div>
            
        </div>
        
        <!-- Charts and Tables Row -->
        <div class="row g-4">
            
            <!-- Revenue Chart -->
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line text-primary me-2"></i>
                            Weekly Revenue
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Selling Items -->
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">
                            <i class="fas fa-fire text-danger me-2"></i>
                            Top Selling Items
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($top_items)): ?>
                            <?php foreach($top_items as $index => $item): ?>
                            <div class="top-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-primary me-2">#<?php echo $index + 1; ?></span>
                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-success">৳<?php echo number_format($item['revenue'], 2); ?></div>
                                        <small class="text-muted"><?php echo $item['total_sold']; ?> sold</small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">
                                <i class="fas fa-inbox fs-3 d-block mb-2"></i>
                                No sales data available
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-receipt text-info me-2"></i>
                            Recent Orders
                        </h5>
                        <a href="../staff/orders.php" class="btn btn-sm btn-outline-primary">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Table</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date & Time</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_orders)): ?>
                                        <?php foreach($recent_orders as $order): ?>
                                        <tr>
                                            <td><strong>#<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td>
                                                <i class="fas fa-chair me-1"></i>
                                                <?php echo htmlspecialchars($order['table_number'] ?? 'N/A'); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Walk-in'); ?></td>
                                            <td><strong class="text-success">৳<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <?php
                                                $status_colors = [
                                                    'pending' => 'warning',
                                                    'preparing' => 'info',
                                                    'served' => 'primary',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                $color = $status_colors[$order['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $color; ?> badge-status">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo date('M j, Y', strtotime($order['order_date'])); ?>
                                                    <br>
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('h:i A', strtotime($order['order_date'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewOrder(<?php echo $order['order_id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="fas fa-inbox fs-3 d-block mb-2"></i>
                                                No recent orders
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
        
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="../assets/js/main.js"></script>

    <script>
        // Update current time every second
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        setInterval(updateTime, 1000);
        
        // Revenue Chart
        const revenueData = <?php echo json_encode($weekly_revenue); ?>;
        
        const labels = revenueData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        
        const data = revenueData.map(item => parseFloat(item.revenue));
        
        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Revenue (৳)',
                    data: data,
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: 'rgb(102, 126, 234)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        callbacks: {
                            label: function(context) {
                                return 'Revenue: ৳' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '৳' + value;
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        
        // View Order Function
        function viewOrder(orderId) {
            
            window.location.href = '../staff/orders.php?id=' + orderId;
        }
        
        // Auto-refresh dashboard every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>