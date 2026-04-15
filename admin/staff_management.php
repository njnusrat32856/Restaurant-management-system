<?php
// admin/staff_management.php - Staff Management System
ob_start();
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $database = new Database();
    $db = $database->connect();
    
    // Add New Staff
    if (isset($_POST['add_staff'])) {
        try {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $role = $_POST['role'];
            $status = $_POST['status'];
            
            // Check if username or email already exists
            $check_query = "SELECT user_id FROM users WHERE username = :username OR email = :email";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error_message = 'Username or email already exists!';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $query = "INSERT INTO users (username, password, full_name, email, phone, role, status, created_at) 
                         VALUES (:username, :password, :full_name, :email, :phone, :role, :status, NOW())";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':status', $status);
                
                if ($stmt->execute()) {
                    $success_message = 'Staff member added successfully!';
                } else {
                    $error_message = 'Failed to add staff member.';
                }
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Update Staff
    if (isset($_POST['update_staff'])) {
        try {
            $user_id = $_POST['user_id'];
            $username = trim($_POST['username']);
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $role = $_POST['role'];
            $status = $_POST['status'];
            
            // Check if username or email exists for other users
            $check_query = "SELECT user_id FROM users 
                           WHERE (username = :username OR email = :email) 
                           AND user_id != :user_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->bindParam(':user_id', $user_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error_message = 'Username or email already exists!';
            } else {
                $query = "UPDATE users 
                         SET username = :username, full_name = :full_name, email = :email, 
                             phone = :phone, role = :role, status = :status 
                         WHERE user_id = :user_id";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':status', $status);
                
                if ($stmt->execute()) {
                    $success_message = 'Staff member updated successfully!';
                } else {
                    $error_message = 'Failed to update staff member.';
                }
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Reset Password
    if (isset($_POST['reset_password'])) {
        try {
            $user_id = $_POST['user_id'];
            $new_password = $_POST['new_password'];
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $query = "UPDATE users SET password = :password WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':user_id', $user_id);
            
            if ($stmt->execute()) {
                $success_message = 'Password reset successfully!';
            } else {
                $error_message = 'Failed to reset password.';
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Delete Staff
    if (isset($_POST['delete_staff'])) {
        try {
            $user_id = $_POST['user_id'];
            
            // Prevent deleting own account
            if ($user_id == $_SESSION['user_id']) {
                $error_message = 'You cannot delete your own account!';
            } else {
                $query = "DELETE FROM users WHERE user_id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                
                if ($stmt->execute()) {
                    $success_message = 'Staff member deleted successfully!';
                } else {
                    $error_message = 'Failed to delete staff member.';
                }
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Toggle Status
    if (isset($_POST['toggle_status'])) {
        try {
            $user_id = $_POST['user_id'];
            $new_status = $_POST['new_status'];
            
            // Prevent deactivating own account
            if ($user_id == $_SESSION['user_id']) {
                $error_message = 'You cannot deactivate your own account!';
            } else {
                $query = "UPDATE users SET status = :status WHERE user_id = :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $new_status);
                $stmt->bindParam(':user_id', $user_id);
                
                if ($stmt->execute()) {
                    $success_message = 'Status updated successfully!';
                }
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch staff members
try {
    $database = new Database();
    $db = $database->connect();
    
    $filter_role = $_GET['role'] ?? 'all';
    $filter_status = $_GET['status'] ?? 'all';
    $search_query = $_GET['search'] ?? '';
    
    $query = "SELECT * FROM users WHERE role IN ('admin', 'staff')";
    
    if ($filter_role != 'all') {
        $query .= " AND role = :role";
    }
    
    if ($filter_status != 'all') {
        $query .= " AND status = :status";
    }
    
    if (!empty($search_query)) {
        $query .= " AND (username LIKE :search OR full_name LIKE :search OR email LIKE :search)";
    }
    
    $query .= " ORDER BY created_at DESC";
    
    $stmt = $db->prepare($query);
    
    if ($filter_role != 'all') {
        $stmt->bindParam(':role', $filter_role);
    }
    
    if ($filter_status != 'all') {
        $stmt->bindParam(':status', $filter_status);
    }
    
    if (!empty($search_query)) {
        $search_param = "%{$search_query}%";
        $stmt->bindParam(':search', $search_param);
    }
    
    $stmt->execute();
    $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_query = "SELECT 
                    COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_count,
                    COUNT(CASE WHEN role = 'staff' THEN 1 END) as staff_count,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_count
                    FROM users WHERE role IN ('admin', 'staff')";
    $stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $staff_members = [];
    $stats = ['admin_count' => 0, 'staff_count' => 0, 'active_count' => 0, 'inactive_count' => 0];
}

$page_title = 'Staff Management';
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
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        .stat-card {
            border-radius: 15px;
            padding: 25px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .staff-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
        }
        
        .role-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
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
    
    <div class="container-fluid py-4">
        
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">
                    <i class="fas fa-users-cog text-primary me-2"></i>
                    Staff Management
                </h2>
                <p class="text-muted mb-0">Manage employees and their access</p>
            </div>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                <i class="fas fa-user-plus me-2"></i>Add Staff Member
            </button>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['admin_count']; ?></div>
                            <div class="opacity-90">Administrators</div>
                        </div>
                        <div>
                            <i class="fas fa-user-shield fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['staff_count']; ?></div>
                            <div class="opacity-90">Staff Members</div>
                        </div>
                        <div>
                            <i class="fas fa-users fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['active_count']; ?></div>
                            <div class="opacity-90">Active Users</div>
                        </div>
                        <div>
                            <i class="fas fa-user-check fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="card stat-card shadow-sm" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fs-4 fw-bold"><?php echo $stats['inactive_count']; ?></div>
                            <div class="opacity-90">Inactive Users</div>
                        </div>
                        <div>
                            <i class="fas fa-user-times fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="row align-items-center">
                <div class="col-md-5 mb-3 mb-md-0">
                    <form method="GET" class="d-flex">
                        <input type="text" name="search" class="form-control me-2" 
                               placeholder="Search by name, username, or email..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="btn btn-light">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
                <div class="col-md-7">
                    <div class="d-flex gap-2 flex-wrap justify-content-md-end">
                        <a href="?" class="btn <?php echo ($filter_role == 'all' && $filter_status == 'all') ? 'btn-light' : 'btn-outline-light'; ?>">
                            All
                        </a>
                        <a href="?role=admin" class="btn <?php echo $filter_role == 'admin' ? 'btn-light' : 'btn-outline-light'; ?>">
                            Admins
                        </a>
                        <a href="?role=staff" class="btn <?php echo $filter_role == 'staff' ? 'btn-light' : 'btn-outline-light'; ?>">
                            Staff
                        </a>
                        <a href="?status=active" class="btn <?php echo $filter_status == 'active' ? 'btn-light' : 'btn-outline-light'; ?>">
                            Active
                        </a>
                        <a href="?status=inactive" class="btn <?php echo $filter_status == 'inactive' ? 'btn-light' : 'btn-outline-light'; ?>">
                            Inactive
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Staff Table -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Staff Member</th>
                                <th>Username</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($staff_members)): ?>
                                <?php foreach($staff_members as $staff): ?>
                                <?php
                                // Generate avatar color based on name
                                $colors = ['#667eea', '#11998e', '#f093fb', '#fa709a', '#ff6b6b', '#4ecdc4'];
                                $color = $colors[ord($staff['full_name'][0]) % count($colors)];
                                $initials = strtoupper(substr($staff['full_name'], 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="staff-avatar me-3" style="background: <?php echo $color; ?>;">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                                                <?php if ($staff['user_id'] == $_SESSION['user_id']): ?>
                                                <small class="text-muted">(You)</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <i class="fas fa-at text-muted me-1"></i>
                                        <?php echo htmlspecialchars($staff['username']); ?>
                                    </td>
                                    <td>
                                        <div><i class="fas fa-envelope text-muted me-1"></i><?php echo htmlspecialchars($staff['email']); ?></div>
                                        <div><i class="fas fa-phone text-muted me-1"></i><?php echo htmlspecialchars($staff['phone'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td>
                                        <span class="role-badge <?php echo $staff['role'] == 'admin' ? 'bg-primary' : 'bg-success'; ?> text-white">
                                            <i class="fas fa-<?php echo $staff['role'] == 'admin' ? 'user-shield' : 'user'; ?> me-1"></i>
                                            <?php echo ucfirst($staff['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $staff['user_id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $staff['status'] == 'active' ? 'inactive' : 'active'; ?>">
                                            <button type="submit" name="toggle_status" 
                                                    class="badge <?php echo $staff['status'] == 'active' ? 'bg-success' : 'bg-danger'; ?> border-0"
                                                    style="cursor: pointer;"
                                                    <?php echo $staff['user_id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                                <?php echo ucfirst($staff['status']); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="fas fa-calendar me-1"></i>
                                            <?php echo date('M j, Y', strtotime($staff['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-primary action-btn" 
                                                    onclick='editStaff(<?php echo json_encode($staff); ?>)'
                                                    title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning action-btn" 
                                                    onclick="resetPassword(<?php echo $staff['user_id']; ?>, '<?php echo htmlspecialchars($staff['full_name']); ?>')"
                                                    title="Reset Password">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php if ($staff['user_id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-sm btn-danger action-btn" 
                                                    onclick="deleteStaff(<?php echo $staff['user_id']; ?>, '<?php echo htmlspecialchars($staff['full_name']); ?>')"
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-users fs-1 d-block mb-3"></i>
                                        <h5>No staff members found</h5>
                                        <p>Add your first staff member to get started</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Add Staff Modal -->
    <div class="modal fade" id="addStaffModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus text-primary me-2"></i>
                        Add New Staff Member
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" data-validate="true">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" 
                                       data-validate-name required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" class="form-control" 
                                       data-validate-username minlength="3" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control" 
                                       data-country="bd" placeholder="+880 1XXX-XXXXXX">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password *</label>
                                <input type="password" name="password" class="form-control" 
                                       id="add_password" minlength="8" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role *</label>
                                <select name="role" class="form-select" required>
                                    <option value="">Select Role</option>
                                    <option value="admin">Administrator</option>
                                    <option value="staff">Staff Member</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select name="status" class="form-select" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_staff" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Staff Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Staff Modal -->
    <div class="modal fade" id="editStaffModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit text-primary me-2"></i>
                        Edit Staff Member
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editStaffForm" data-validate="true">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" id="edit_username" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email *</label>
                                <input type="email" name="email" id="edit_email" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" id="edit_phone" class="form-control" data-country="bd">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Role *</label>
                                <select name="role" id="edit_role" class="form-select" required>
                                    <option value="admin">Administrator</option>
                                    <option value="staff">Staff Member</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status *</label>
                                <select name="status" id="edit_status" class="form-select" required>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_staff" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Staff Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key text-warning me-2"></i>
                        Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" data-validate="true">
                    <input type="hidden" name="user_id" id="reset_user_id">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Resetting password for: <strong id="reset_staff_name"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password *</label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="new_password" 
                                       class="form-control" minlength="8" required
                                       placeholder="Enter new password">
                                <button class="btn btn-outline-secondary" type="button" 
                                        onclick="togglePasswordVisibility('new_password', 'reset_toggle_icon')">
                                    <i class="fas fa-eye" id="reset_toggle_icon"></i>
                                </button>
                            </div>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                    onclick="generatePassword()">
                                <i class="fas fa-random me-1"></i>Generate Strong Password
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="reset_password" class="btn btn-warning">
                            <i class="fas fa-key me-2"></i>Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Forms -->
    <form method="POST" id="deleteForm" style="display: none;">
        <input type="hidden" name="user_id" id="delete_user_id">
        <input type="hidden" name="delete_staff" value="1">
    </form>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <!-- <script src="../assets/js/validation.js"></script> -->
    
    <script>
        // Edit Staff Function
        function editStaff(staff) {
            document.getElementById('edit_user_id').value = staff.user_id;
            document.getElementById('edit_full_name').value = staff.full_name;
            document.getElementById('edit_username').value = staff.username;
            document.getElementById('edit_email').value = staff.email;
            document.getElementById('edit_phone').value = staff.phone || '';
            document.getElementById('edit_role').value = staff.role;
            document.getElementById('edit_status').value = staff.status;
            
            const modal = new bootstrap.Modal(document.getElementById('editStaffModal'));
            modal.show();
        }
        
        // Reset Password Function
        function resetPassword(userId, staffName) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_staff_name').textContent = staffName;
            document.getElementById('new_password').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
            modal.show();
        }
        
        // Delete Staff Function
        function deleteStaff(userId, staffName) {
            if (typeof RMS !== 'undefined' && RMS.confirmModal) {
                RMS.confirmModal({
                    title: 'Delete Staff Member',
                    message: `Are you sure you want to delete <strong>${staffName}</strong>? This action cannot be undone and will remove all associated data.`,
                    confirmText: 'Delete',
                    confirmClass: 'btn-danger',
                    onConfirm: function() {
                        document.getElementById('delete_user_id').value = userId;
                        document.getElementById('deleteForm').submit();
                    }
                });
            } else {
                if (confirm(`Are you sure you want to delete ${staffName}? This action cannot be undone.`)) {
                    document.getElementById('delete_user_id').value = userId;
                    document.getElementById('deleteForm').submit();
                }
            }
        }
        
        // Toggle Password Visibility
        function togglePasswordVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Generate Random Password
        function generatePassword() {
            const length = 12;
            const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            let password = '';
            
            // Ensure at least one of each type
            password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)];
            password += 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)];
            password += '0123456789'[Math.floor(Math.random() * 10)];
            password += '!@#$%^&*'[Math.floor(Math.random() * 8)];
            
            // Fill the rest randomly
            for (let i = password.length; i < length; i++) {
                password += charset[Math.floor(Math.random() * charset.length)];
            }
            
            // Shuffle the password
            password = password.split('').sort(() => Math.random() - 0.5).join('');
            
            document.getElementById('new_password').value = password;
            document.getElementById('new_password').type = 'text';
            document.getElementById('reset_toggle_icon').classList.remove('fa-eye');
            document.getElementById('reset_toggle_icon').classList.add('fa-eye-slash');
            
            // Copy to clipboard
            navigator.clipboard.writeText(password).then(() => {
                if (typeof RMS !== 'undefined' && RMS.showToast) {
                    RMS.showToast('Password generated and copied to clipboard!', 'success');
                } else {
                    alert('Password generated and copied to clipboard!');
                }
            });
        }
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Prevent self-deletion warning
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const deleteBtn = this.querySelector('[name="delete_staff"]');
                const userId = this.querySelector('[name="user_id"]')?.value;
                
                if (deleteBtn && userId == <?php echo $_SESSION['user_id']; ?>) {
                    e.preventDefault();
                    if (typeof RMS !== 'undefined' && RMS.showToast) {
                        RMS.showToast('You cannot delete your own account!', 'danger');
                    } else {
                        alert('You cannot delete your own account!');
                    }
                }
            });
        });
    </script>
</body>
</html>