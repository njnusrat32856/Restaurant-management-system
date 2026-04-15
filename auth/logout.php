<?php
// auth/logout.php - User Logout Handler
session_start();

// Store user info before destroying session (for logging purposes)
$user_id = $_SESSION['user_id'] ?? null;

// Log the logout (optional - requires login_logs table)
if ($user_id) {
    try {
        require_once '../config/database.php';
        $database = new Database();
        $db = $database->connect();
        
        $query = "UPDATE login_logs 
                 SET logout_time = NOW() 
                 WHERE user_id = :user_id 
                 AND logout_time IS NULL 
                 ORDER BY login_time DESC 
                 LIMIT 1";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
    } catch(PDOException $e) {
        // Login logs table doesn't exist, skip logging
        error_log('Logout logging error: ' . $e->getMessage());
    }
}

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
    unset($_COOKIE['remember_token']);
}

// Destroy session
$_SESSION = array();

// Destroy session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page with success message
header('Location: login.php?logout=success');
exit();
?>