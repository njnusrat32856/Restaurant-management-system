<?php
// auth/register.php - User Registration Page
session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Include database configuration
require_once '../config/database.php';

$error_message = '';
$success_message = '';
$form_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Store form data for repopulation on error
    $form_data = $_POST;
    
    // Sanitize and validate input
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms_accepted = isset($_POST['terms']);
    
    // Validation
    $errors = [];
    
    if (empty($full_name) || strlen($full_name) < 2) {
        $errors[] = 'Full name must be at least 2 characters';
    }
    
    if (empty($username) || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, and underscores';
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }
    
    if (empty($phone) || strlen($phone) < 8) {
        $errors[] = 'Please enter a valid phone number';
    }
    
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    } 
    // elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    //     $errors[] = 'Password must contain uppercase, lowercase, and numbers';
    // }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    if (!$terms_accepted) {
        $errors[] = 'You must accept the terms and conditions';
    }
    
    // If no validation errors, proceed with registration
    if (empty($errors)) {
        try {
            $database = new Database();
            $db = $database->connect();
            
            // Check if username already exists
            $check_query = "SELECT user_id FROM users WHERE username = :username OR email = :email";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':username', $username);
            $check_stmt->bindParam(':email', $email);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $errors[] = 'Username or email already exists';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user
                $insert_query = "INSERT INTO users (username, password, full_name, email, phone, role, status, created_at) 
                                VALUES (:username, :password, :full_name, :email, :phone, 'customer', 'active', NOW())";
                
                $stmt = $db->prepare($insert_query);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $phone);
                
                if ($stmt->execute()) {
                    // Registration successful
                    header('Location: login.php?registered=success');
                    exit();
                } else {
                    $errors[] = 'Registration failed. Please try again.';
                }
            }
            
        } catch(PDOException $e) {
            $errors[] = 'Database error. Please try again later.';
            error_log('Registration Error: ' . $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
    }
}

$page_title = 'Register';
$base_url = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Fine Dine RMS</title>
    
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
            padding: 40px 20px;
        }
        
        .register-container {
            max-width: 550px;
            width: 100%;
        }
        
        .register-card {
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
        
        .register-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .register-header h2 {
            margin: 15px 0 5px 0;
            font-weight: 700;
        }
        
        .register-body {
            padding: 40px 30px;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.15);
        }
        
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
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
        
        .form-text {
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    
    <!-- Back to Home Button -->
    <a href="../index.php" class="btn btn-light btn-sm back-to-home shadow">
        <i class="fas fa-arrow-left me-2"></i>Back to Home
    </a>
    
    <div class="register-container">
        <div class="register-card">
            
            <!-- Register Header -->
            <div class="register-header">
                <i class="fas fa-user-plus fa-3x mb-3"></i>
                <h2>Create Account</h2>
                <p>Join Fine Dine RMS today!</p>
            </div>
            
            <!-- Register Body -->
            <div class="register-body">
                
                <!-- Error Message -->
                <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Registration Form -->
                <form method="POST" action="" data-validate="true" id="registerForm">
                    
                    <!-- Full Name -->
                    <div class="mb-3">
                        <label for="full_name" class="form-label">
                            <i class="fas fa-user me-1"></i>Full Name *
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="full_name" 
                               name="full_name" 
                               placeholder="Enter your full name"
                               value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>"
                               data-validate-name
                               minlength="2"
                               maxlength="100"
                               required>
                    </div>
                    
                    <!-- Username -->
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="fas fa-at me-1"></i>Username *
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               placeholder="Choose a username"
                               value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>"
                               data-validate-username
                               minlength="3"
                               maxlength="30"
                               required>
                        <div class="form-text">Only letters, numbers, and underscores allowed</div>
                    </div>
                    
                    <!-- Email -->
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-1"></i>Email Address *
                        </label>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               placeholder="Enter your email"
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                               required>
                    </div>
                    
                    <!-- Phone -->
                    <div class="mb-3">
                        <label for="phone" class="form-label">
                            <i class="fas fa-phone me-1"></i>Phone Number *
                        </label>
                        <input type="tel" 
                               class="form-control" 
                               id="phone" 
                               name="phone" 
                               placeholder="+880 1XXX-XXXXXX"
                               value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                               data-country="bd"
                               required>
                    </div>
                    
                    <!-- Password -->
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-1"></i>Password *
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Create a strong password"
                                   data-strength-meter="passwordStrength"
                                   minlength="8"
                                   required>
                            <span class="input-group-text password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                <i class="fas fa-eye" id="toggleIcon1"></i>
                            </span>
                        </div>
                        <div id="passwordStrength"></div>
                        <div class="form-text">Must be at least 8 characters with uppercase, lowercase, and numbers</div>
                    </div>
                    
                    <!-- Confirm Password -->
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">
                            <i class="fas fa-lock me-1"></i>Confirm Password *
                        </label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   placeholder="Re-enter your password"
                                   data-confirm-password="password"
                                   required>
                            <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                <i class="fas fa-eye" id="toggleIcon2"></i>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Terms and Conditions -->
                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   id="terms" 
                                   name="terms" 
                                   required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="../pages/terms-conditions.php" target="_blank">Terms & Conditions</a> 
                                and <a href="../pages/privacy-policy.php" target="_blank">Privacy Policy</a>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Register Button -->
                    <button type="submit" class="btn btn-primary btn-register w-100 btn-lg mb-3">
                        <i class="fas fa-user-plus me-2"></i>Create Account
                    </button>
                    
                    <!-- Login Link -->
                    <div class="text-center">
                        <p class="mb-0">
                            Already have an account? 
                            <a href="login.php" class="text-decoration-none fw-semibold">
                                Sign In
                            </a>
                        </p>
                    </div>
                    
                </form>
                
            </div>
        </div>
        
        <!-- Copyright -->
        <div class="text-center mt-4 text-white">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Fine Dine RMS. All rights reserved.</p>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    
    
    <!-- Custom JavaScript -->
    <script>
        // Toggle password visibility
        function togglePassword(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);
            
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
        
        // Form loading state
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const form = this;
            
            // Check if form is valid
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating Account...';
        });
        
        // Auto-dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 10000);
    </script>
</body>
</html>