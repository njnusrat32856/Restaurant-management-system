<?php
// auth/login.php - User Login Page
session_start();

// If user is already logged in, redirect to appropriate dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    switch($role) {
        case 'admin':
            header('Location: ../admin/dashboard.php');
            break;
        case 'staff':
            header('Location: ../staff/index.php');
            break;
        case 'customer':
             header('Location: ../customer/menu.php');
            
            break;
        default:
            header('Location: ../index.php');
    }
    exit();
}

// Include database configuration
require_once '../config/database.php';

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    // Validation
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password';
    } else {
        try {
            // Create database connection
            $database = new Database();
            $db = $database->connect();
            
            // Prepare SQL statement
            $query = "SELECT user_id, username, password, full_name, email, role, status 
                     FROM users 
                     WHERE (username = :username OR email = :username) 
                     AND status = 'active'
                     LIMIT 1";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Password is correct, create session
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    // Set remember me cookie if checked
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), '/'); // 30 days
                        
                        // Store token in database (you'll need to create a remember_tokens table)
                        // For now, we'll skip this implementation
                    }
                    
                    // Log the login (optional - create login_logs table)
                    $log_query = "INSERT INTO login_logs (user_id, login_time, ip_address) 
                                 VALUES (:user_id, NOW(), :ip)";
                    try {
                        $log_stmt = $db->prepare($log_query);
                        $log_stmt->bindParam(':user_id', $user['user_id']);
                        $log_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
                        $log_stmt->execute();
                    } catch(PDOException $e) {
                        // Login log table doesn't exist, skip logging
                    }
                    
                    // Redirect based on role
                    switch($user['role']) {
                        case 'admin':
                            header('Location: ../admin/dashboard.php');
                            break;
                        case 'staff':
                            header('Location: ../staff/index.php');
                            break;
                        case 'customer':
                            header('Location: ../customer/menu.php');
                            break;
                        default:
                            header('Location: ../index.php');
                    }
                    exit();
                } else {
                    $error_message = 'Invalid username or password';
                }
            } else {
                $error_message = 'Invalid username or password';
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error. Please try again later.';
            error_log('Login Error: ' . $e->getMessage());
        }
    }
}

// Check for success message from registration
if (isset($_GET['registered']) && $_GET['registered'] == 'success') {
    $success_message = 'Registration successful! Please login with your credentials.';
}

// Check for logout message
if (isset($_GET['logout']) && $_GET['logout'] == 'success') {
    $success_message = 'You have been logged out successfully.';
}

$page_title = 'Login';
$base_url = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Fine Dine RMS</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .login-header h2 {
            margin: 15px 0 5px 0;
            font-weight: 700;
        }
        
        .login-header p {
            margin: 0;
            opacity: 0.9;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 25px 0;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }
        
        .divider span {
            padding: 0 15px;
            color: #6c757d;
            font-size: 14px;
        }
        
        .social-login {
            display: flex;
            gap: 10px;
        }
        
        .social-btn {
            flex: 1;
            padding: 10px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            background: white;
            transition: all 0.3s ease;
        }
        
        .social-btn:hover {
            border-color: #667eea;
            transform: translateY(-2px);
        }
        
        .password-toggle {
            cursor: pointer;
            color: #6c757d;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        .back-to-home {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            font-size: 14px;
        }
        
        .demo-credentials strong {
            color: #667eea;
        }
    </style>
</head>
<body>
    
    <!-- Back to Home Button -->
    <a href="../index.php" class="btn btn-light btn-sm back-to-home shadow">
        <i class="fas fa-arrow-left me-2"></i>Back to Home
    </a>
    
    <div class="login-container">
        <div class="login-card">
            
            <!-- Login Header -->
            <div class="login-header">
                <i class="fas fa-utensils fa-3x mb-3"></i>
                <h2>Welcome Back!</h2>
                <p>Sign in to continue to Fine Dine RMS</p>
            </div>
            
            <!-- Login Body -->
            <div class="login-body">
                
                <!-- Success Message -->
                <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Error Message -->
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Login Form -->
                <form method="POST"  data-validate="true" id="loginForm">
                    
                    <!-- Username/Email -->
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="fas fa-user me-1"></i>Username or Email
                        </label>
                        <input type="text" 
                               class="form-control form-control-lg" 
                               id="username" 
                               name="username" 
                               placeholder="Enter username or email"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               required
                               autofocus>
                    </div>
                    
                    <!-- Password -->
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-1"></i>Password
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control form-control-lg" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter password"
                                   required>
                            <span class="input-group-text password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Remember Me & Forgot Password -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                            <label class="form-check-label" for="remember_me">
                                Remember me
                            </label>
                        </div>
                        <a href="forgot-password.php" class="text-decoration-none">
                            Forgot Password?
                        </a>
                    </div>
                    
                    <!-- Login Button -->
                    <button type="submit" class="btn btn-primary btn-login w-100 btn-lg">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                    </button>
                    
                </form>
                
                <!-- Divider -->
                <div class="divider">
                    <span>OR</span>
                </div>
                
                <!-- Social Login (Optional - Can be removed if not needed) -->
                <div class="social-login">
                    <button class="social-btn" title="Login with Google">
                        <i class="fab fa-google text-danger"></i>
                    </button>
                    <button class="social-btn" title="Login with Facebook">
                        <i class="fab fa-facebook text-primary"></i>
                    </button>
                    <button class="social-btn" title="Login with Twitter">
                        <i class="fab fa-twitter text-info"></i>
                    </button>
                </div>
                
                <!-- Register Link -->
                <div class="text-center mt-4">
                    <p class="mb-0">
                        Don't have an account? 
                        <a href="register.php" class="text-decoration-none fw-semibold">
                            Sign Up Now
                        </a>
                    </p>
                </div>
                
                <!-- Demo Credentials (Remove in production) -->
                <!-- <div class="demo-credentials">
                    <div class="fw-bold mb-2">
                        <i class="fas fa-info-circle me-1"></i>Demo Credentials:
                    </div>
                    <div class="mb-1">
                        <strong>Admin:</strong> admin / admin123
                    </div>
                    <div class="mb-1">
                        <strong>Staff:</strong> staff / staff123
                    </div>
                    <div>
                        <strong>Customer:</strong> customer / customer123
                    </div>
                </div> -->
                
            </div>
        </div>
        
        <!-- Copyright -->
        <div class="text-center mt-4 text-white">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Fine Dine RMS. All rights reserved.</p>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Validation JS -->
    <script src="../assets/js/validation.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Form loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing In...';
        });
        
        // Social login buttons (placeholder - implement actual OAuth)
        document.querySelectorAll('.social-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                alert('Social login not implemented yet. This is a demo feature.');
            });
        });
    </script>
</body>
</html>