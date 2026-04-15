<?php
// includes/navbar.php - Enhanced Navigation Bar Component

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'guest';
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? '';
?>

<?php
$cart_count = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
?>

<?php
$notification_count = 0; // query from DB here
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top shadow-sm">
    <div class="container-fluid container-lg">
        <!-- Brand Logo -->
        <a class="navbar-brand d-flex align-items-center" href="<?php echo $base_url ?? '/'; ?>rms-project/index.php">
            <i class="fas fa-utensils text-primary me-2 fs-4"></i>
            <span class="fw-bold">Fine Dine <span class="text-primary">RMS</span></span>
        </a>
        
        <!-- Mobile Toggle Button -->
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Menu -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                
                <!-- Home Link -->
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>" 
                       href="<?php echo $base_url ?? '/'; ?>index.php">
                        <i class="fas fa-home me-1"></i>
                        <span>Home</span>
                    </a>
                </li>
                
                <!-- Menu Link -->
                <!-- <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page == 'menu.php') ? 'active' : ''; ?>" 
                       href="<?php echo $base_url ?? '/'; ?>customer/menu.php">
                        <i class="fas fa-book-open me-1"></i>
                        <span>Menu</span>
                    </a>
                </li> -->
                
                <!-- Reservations Link -->
                <!-- <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page == 'reservation.php') ? 'active' : ''; ?>" 
                       href="<?php echo $base_url ?? '/'; ?>customer/reservation.php">
                        <i class="fas fa-calendar-check me-1"></i>
                        <span>Reservations</span>
                    </a>
                </li> -->
                
                <!-- About Link -->
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $base_url ?? '/'; ?>rms-project/index.php#about">
                        <i class="fas fa-info-circle me-1"></i>
                        <span>About</span>
                    </a>
                </li>
                
                <!-- Contact Link -->
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo $base_url ?? '/'; ?>rms-project/index.php#contact">
                        <i class="fas fa-phone me-1"></i>
                        <span>Contact</span>
                    </a>
                </li>
                
                <!-- Divider for Desktop -->
                <li class="nav-item d-none d-lg-block">
                    <span class="nav-link px-1">|</span>
                </li>
                
                <?php if($is_logged_in): ?>
                    <!-- LOGGED IN USER MENU -->
                    
                    <!-- Cart Icon (for customers) -->
                    <?php if($user_role == 'customer'): ?>
                    <li class="nav-item position-relative">
                        <a class="nav-link" href="<?php echo $base_url ?? '/'; ?>customer/cart.php" title="Shopping Cart">
                            <i class="fas fa-shopping-cart fs-5"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="cartCount" style="<?php echo $cart_count > 0 ? '' : 'display:none;'; ?>">
                                <?php echo $cart_count; ?>
                            </span>
                            
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Notifications -->
                    <li class="nav-item dropdown position-relative">
                        <a class="nav-link" href="#" id="notificationDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                            <i class="fas fa-bell fs-5"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-white" id="notificationCount" style="<?php echo $notification_count > 0 ? '' : 'display:none;'; ?>">
                                <?php echo $notification_count; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                            <li class="dropdown-header d-flex justify-content-between align-items-center">
                                <span class="fw-bold">Notifications</span>
                                <span class="badge bg-primary rounded-pill">0</span>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-item-text text-center text-muted py-3">
                                <i class="fas fa-inbox fs-3 mb-2 d-block"></i>
                                No new notifications
                            </li>
                        </ul>
                    </li>
                    
                    <!-- User Profile Dropdown -->
                    <li class="nav-item dropdown ms-lg-2">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar bg-primary text-white rounded-circle me-2 d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="d-none d-lg-block">
                                <small class="d-block text-white" style="font-size: 0.75rem;">Welcome back</small>
                                <span class="fw-semibold"><?php echo htmlspecialchars($username); ?></span>
                            </div>
                        </a>
                        
                        <ul class="dropdown-menu dropdown-menu-end user-dropdown" aria-labelledby="userDropdown">
                            <!-- User Info Header -->
                            <li class="dropdown-header">
                                <div class="d-flex align-items-center">
                                    <div class="user-avatar-large bg-primary text-white rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <i class="fas fa-user fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($full_name ?: $username); ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-circle text-success" style="font-size: 0.5rem;"></i>
                                            <?php echo ucfirst($user_role); ?>
                                        </small>
                                    </div>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            
                            <!-- Role-Based Menu Items -->
                            <?php if($user_role == 'admin'): ?>
                                <!-- Admin Menu -->
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>admin/dashboard.php">
                                        <i class="fas fa-tachometer-alt me-2 text-primary"></i>
                                        Dashboard
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>admin/menu_management.php">
                                        <i class="fas fa-utensils me-2 text-success"></i>
                                        Manage Menu
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>admin/staff_management.php">
                                        <i class="fas fa-users-cog me-2 text-info"></i>
                                        Manage Staff
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>admin/reports.php">
                                        <i class="fas fa-chart-bar me-2 text-warning"></i>
                                        Reports
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>admin/table_management.php">
                                        <i class="fas fa-chair me-2 text-secondary"></i>
                                        Manage Tables
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                
                            <?php elseif($user_role == 'staff'): ?>
                                <!-- Staff Menu -->
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>staff/index.php">
                                        <i class="fas fa-tasks me-2 text-primary"></i>
                                        Staff Panel
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>staff/orders.php">
                                        <i class="fas fa-clipboard-list me-2 text-success"></i>
                                        Manage Orders
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>staff/billing.php">
                                        <i class="fas fa-receipt me-2 text-info"></i>
                                        Billing
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>staff/table_assignment.php">
                                        <i class="fas fa-chair me-2 text-warning"></i>
                                        Table Assignment
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                
                            <?php else: ?>
                                <!-- Customer Menu -->
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>customer/my_orders.php">
                                        <i class="fas fa-receipt me-2 text-primary"></i>
                                        My Orders
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>customer/reservation.php">
                                        <i class="fas fa-calendar-alt me-2 text-success"></i>
                                        My Reservations
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>customer/cart.php">
                                        <i class="fas fa-shopping-cart me-2 text-info"></i>
                                        Shopping Cart
                                    </a>
                                </li>
                                <li>
                                <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>customer/profile.php">
                                    <i class="fas fa-user-edit me-2 text-secondary"></i>
                                    Edit Profile
                                </a>
                            </li>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            
                            <!-- Common Menu Items -->
                            
                            <li>
                                <a class="dropdown-item" href="<?php echo $base_url ?? '/'; ?>customer/settings.php">
                                    <i class="fas fa-cog me-2 text-secondary"></i>
                                    Settings
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            
                            <!-- Logout -->
                            <li>
                                <a class="dropdown-item text-danger" href="<?php echo $base_url ?? '/'; ?>auth/logout.php" onclick="return confirm('Are you sure you want to logout?');">
                                    <i class="fas fa-sign-out-alt me-2"></i>
                                    Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                    
                <?php else: ?>
                    <!-- GUEST USER MENU -->
                    
                    <!-- Login Button -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $base_url ?? '/'; ?>rms-project/auth/login.php">
                            <i class="fas fa-sign-in-alt me-1"></i>
                            <span>Login</span>
                        </a>
                    </li>
                    
                    <!-- Register Button -->
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-primary btn-sm px-3" href="<?php echo $base_url ?? '/'; ?>rms-project/auth/register.php">
                            <i class="fas fa-user-plus me-1"></i>
                            <span>Register</span>
                        </a>
                    </li>
                <?php endif; ?>
                
            </ul>
        </div>
    </div>
</nav>

<!-- Additional Styles for Navbar -->


<!-- JavaScript for Navbar Functionality -->
 <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="../assets/js/main.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        const navbar = document.querySelector('.navbar-collapse');
        const toggler = document.querySelector('.navbar-toggler');
        
        if (navbar && navbar.classList.contains('show')) {
            if (!navbar.contains(event.target) && !toggler.contains(event.target)) {
                bootstrap.Collapse.getOrCreateInstance(navbar).hide();
            }
        }
    });
    
    // Load cart count (if customer is logged in)
    // <?php if($is_logged_in && $user_role == 'customer'): ?>
    // updateCartCount();
    // <?php endif; ?>
    
    // Load notification count
    // <?php if($is_logged_in): ?>
    // updateNotificationCount();
    // <?php endif; ?>
});


// function updateCartCount() {

//     const cartCount = 0; // Replace with actual cart count
//     const cartBadge = document.getElementById('cartCount');
//     if (cartBadge) {
//         cartBadge.textContent = cartCount;
//         cartBadge.style.display = cartCount > 0 ? 'display:none' : '';
//     }
// }

// Update notification count
// function updateNotificationCount() {
    
//     const notificationCount = 0; // Replace with actual notification count
//     const notificationBadge = document.getElementById('notificationCount');
//     if (notificationBadge) {
//         notificationBadge.textContent = notificationCount;
//         notificationBadge.style.display = notificationCount > 0 ? '' : 'display:none';
//     }
// }
</script>
