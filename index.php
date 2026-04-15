<?php
// index.php - Restaurant Homepage
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Management System - Home</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    
    <!-- Navigation Bar -->
    <?php include 'includes/navbar.php'; ?>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-overlay"></div>
        <div class="container hero-content">
            <div class="row align-items-center min-vh-100">
                <div class="col-lg-8 mx-auto text-center text-white">
                    <h1 class="display-3 fw-bold mb-4 animate-fade-in">Welcome to Fine Dine</h1>
                    <p class="lead mb-5 animate-fade-in-delay">Experience the perfect blend of technology and hospitality with our modern restaurant management system</p>
                    <div class="d-flex gap-3 justify-content-center animate-fade-in-delay-2">
                        <a href="customer/menu.php" class="btn btn-primary btn-lg px-5 py-3">
                            <i class="fas fa-utensils me-2"></i>View Menu
                        </a>
                        <a href="customer/reservation.php" class="btn btn-outline-light btn-lg px-5 py-3">
                            <i class="fas fa-calendar-check me-2"></i>Book Table
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">Our Services</h2>
                <p class="text-muted">Everything you need for a perfect dining experience</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <div class="feature-icon bg-primary text-white rounded-circle mx-auto mb-3">
                                <i class="fas fa-book-open fa-2x"></i>
                            </div>
                            <h4 class="card-title">Digital Menu</h4>
                            <p class="card-text text-muted">Browse our extensive menu with detailed descriptions and prices. Order with just a few clicks.</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="feature-card card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <div class="feature-icon bg-success text-white rounded-circle mx-auto mb-3">
                                <i class="fas fa-calendar-alt fa-2x"></i>
                            </div>
                            <h4 class="card-title">Table Reservation</h4>
                            <p class="card-text text-muted">Book your table in advance. Choose your preferred time and seating arrangement.</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="feature-card card h-100 border-0 shadow-sm">
                        <div class="card-body text-center p-4">
                            <div class="feature-icon bg-warning text-white rounded-circle mx-auto mb-3">
                                <i class="fas fa-bolt fa-2x"></i>
                            </div>
                            <h4 class="card-title">Fast Service</h4>
                            <p class="card-text text-muted">Real-time order tracking and quick billing. Get your food faster than ever.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats-section py-5 bg-dark text-white">
        <div class="container">
            <div class="row text-center g-4">
                <div class="col-md-3">
                    <div class="stat-item">
                        <i class="fas fa-users fa-3x mb-3 text-primary"></i>
                        <h3 class="display-5 fw-bold counter">5000+</h3>
                        <p class="text-muted">Happy Customers</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <i class="fas fa-utensils fa-3x mb-3 text-success"></i>
                        <h3 class="display-5 fw-bold counter">150+</h3>
                        <p class="text-muted">Menu Items</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <i class="fas fa-chair fa-3x mb-3 text-warning"></i>
                        <h3 class="display-5 fw-bold counter">50+</h3>
                        <p class="text-muted">Tables Available</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <i class="fas fa-star fa-3x mb-3 text-danger"></i>
                        <h3 class="display-5 fw-bold">4.8</h3>
                        <p class="text-muted">Average Rating</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about-section py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <img src="assets/images/restaurant-interior.jpg" alt="Restaurant" class="img-fluid rounded shadow" onerror="this.src='https://via.placeholder.com/600x400?text=Restaurant+Interior'">
                </div>
                <div class="col-lg-6">
                    <h2 class="display-5 fw-bold mb-4">About Our Restaurant</h2>
                    <p class="lead text-muted mb-4">We combine cutting-edge technology with traditional hospitality to deliver an exceptional dining experience.</p>
                    <p class="text-muted mb-4">Our Restaurant Management System streamlines every aspect of your visit, from browsing the menu to making reservations and processing payments. We're committed to making your dining experience as smooth and enjoyable as possible.</p>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Fresh ingredients daily</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Professional staff</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Hygienic environment</li>
                        <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Easy online ordering</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script src="assets/js/main.js"></script>
</body>
</html>