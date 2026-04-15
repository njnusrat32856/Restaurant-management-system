<?php
// auth/forgot-password.php - Password Reset Request Page
session_start();

// If user is already logged in, redirect
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

require_once '../config/database.php';

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address';
    } else {
        try {
            $database = new Database();
            $db = $database->connect();
            
            // Check if email exists
            $query = "SELECT user_id, username, email, full_name FROM users WHERE email = :email AND status = 'active'";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Generate password reset token
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store token in database (you'll need to create password_resets table)
                // For now, we'll just show a success message
                
                /* 
                CREATE TABLE password_resets (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    expiry DATETIME NOT NULL,
                    used TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                );
                */
                
                try {
                    $reset_query = "INSERT INTO password_resets (user_id, token, expiry) 
                                   VALUES (:user_id, :token, :expiry)";
                    $reset_stmt = $db->prepare($reset_query);
                    $reset_stmt->bindParam(':user_id', $user['user_id']);
                    $reset_stmt->bindParam(':token', $token);
                    $reset_stmt->bindParam(':expiry', $expiry);
                    $reset_stmt->execute();
                    
                    // Send email with reset link (implement email sending here)
                    $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/rms-project/auth/reset-password.php?token=" . $token;
                    
                    // For demo purposes, we'll just show the link
                    $success_message = "Password reset instructions have been sent to your email. 
                                       <br><br><strong>Demo Reset Link:</strong><br>
                                       <a href='{$reset_link}' class='text-white'>{$reset_link}</a>";
                    
                } catch(PDOException $e) {
                    // Table doesn't exist
                    $success_message = "If an account exists with this email, you will receive password reset instructions shortly.";
                }
                
            } else {
                // Don't reveal if email exists or not (security)
                $success_message = "If an account exists with this email, you will receive password reset instructions shortly.";
            }
            
        } catch(PDOException $e) {
            $error_message = 'An error occurred. Please try again later.';
            error_log('Forgot Password Error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Fine Dine RMS</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .forgot-password-card {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .btn-reset {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .back-btn {
            position: fixed;
            top: 20px;
            left: 20px;
        }
    </style>
</head>
<body>
    
    <a href="login.php" class="btn btn-light btn-sm back-btn shadow">
        <i class="fas fa-arrow-left me-2"></i>Back to Login
    </a>
    
    <div class="forgot-password-card">
        <div class="card-header">
            <i class="fas fa-key fa-3x mb-3"></i>
            <h2>Forgot Password?</h2>
            <p>Enter your email to reset your password</p>
        </div>
        
        <div class="card-body p-4">
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success_message; ?>
            </div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
            </div>
            <?php else: ?>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-4">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope me-1"></i>Email Address
                    </label>
                    <input type="email" 
                           class="form-control form-control-lg" 
                           id="email" 
                           name="email" 
                           placeholder="Enter your registered email"
                           required 
                           autofocus>
                </div>
                
                <button type="submit" class="btn btn-primary btn-reset w-100 btn-lg mb-3">
                    <i class="fas fa-paper-plane me-2"></i>Send Reset Link
                </button>
                
                <div class="text-center">
                    <a href="login.php" class="text-decoration-none">
                        Remember your password? Sign In
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>