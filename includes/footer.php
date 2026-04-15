<?php
// includes/footer.php - Enhanced Footer Component

// Get current year for copyright
$current_year = date('Y');

// Restaurant contact information
$restaurant_info = [
    'name' => 'Fine Dine RMS',
    'address' => '123 Restaurant Street, Khilgaon, Dhaka 1212',
    'phone' => '+880 1234-567890',
    'email' => 'info@finedine.com',
    'hours' => [
        'weekday' => '10:00 AM - 11:00 PM',
        'weekend' => '9:00 AM - 12:00 AM'
    ]
];

// Social media links
$social_links = [
    'facebook' => 'https://facebook.com/finedine',
    'twitter' => 'https://twitter.com/finedine',
    'instagram' => 'https://instagram.com/finedine',
    'linkedin' => 'https://linkedin.com/company/finedine',
    'youtube' => 'https://youtube.com/finedine'
];
?>

<footer class="footer bg-dark text-white pt-5 pb-3 mt-auto">
    <div class="container">
        <div class="row g-4">
            
            <!-- About Column -->
            <div class="col-lg-4 col-md-6">
                <div class="footer-section">
                    <h5 class="mb-4 fw-bold d-flex align-items-center">
                        <i class="fas fa-utensils text-primary me-2 fs-4"></i>
                        <?php echo $restaurant_info['name']; ?>
                    </h5>
                    <p class="text-white mb-4">
                        A modern restaurant management system providing seamless dining experiences through innovative technology and exceptional hospitality. Experience the future of dining with us.
                    </p>
                    
                    <!-- Social Media Links -->
                    <div class="social-links mb-3">
                        <a href="<?php echo $social_links['facebook']; ?>" target="_blank" rel="noopener" class="text-white me-2" title="Facebook">
                            <i class="fab fa-facebook fa-lg"></i>
                        </a>
                        <a href="<?php echo $social_links['twitter']; ?>" target="_blank" rel="noopener" class="text-white me-2" title="Twitter">
                            <i class="fab fa-twitter fa-lg"></i>
                        </a>
                        <a href="<?php echo $social_links['instagram']; ?>" target="_blank" rel="noopener" class="text-white me-2" title="Instagram">
                            <i class="fab fa-instagram fa-lg"></i>
                        </a>
                        <a href="<?php echo $social_links['linkedin']; ?>" target="_blank" rel="noopener" class="text-white me-2" title="LinkedIn">
                            <i class="fab fa-linkedin fa-lg"></i>
                        </a>
                        <a href="<?php echo $social_links['youtube']; ?>" target="_blank" rel="noopener" class="text-white" title="YouTube">
                            <i class="fab fa-youtube fa-lg"></i>
                        </a>
                    </div>
                    
                    <!-- App Download Badges -->
                    <div class="app-badges mt-3">
                        <a href="#" class="d-inline-block me-2 mb-2">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg" alt="Get it on Google Play" style="height: 40px;">
                        </a>
                        <a href="#" class="d-inline-block mb-2">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/3/3c/Download_on_the_App_Store_Badge.svg" alt="Download on the App Store" style="height: 40px;">
                        </a>
                    </div>
                </div>
            </div>

            <!-- Quick Links Column -->
            <div class="col-lg-2 col-md-6">
                <div class="footer-section">
                    <h5 class="mb-4 fw-bold">Quick Links</h5>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2">
                            <a href="<?php echo $base_url ?? '/'; ?>rms-project/index.php" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Home
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="<?php echo $base_url ?? '/'; ?>customer/menu.php" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Menu
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="<?php echo $base_url ?? '/'; ?>customer/reservation.php" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Reservations
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="<?php echo $base_url ?? '/'; ?>index.php#about" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>About Us
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="<?php echo $base_url ?? '/'; ?>pages/careers.php" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Careers
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="<?php echo $base_url ?? '/'; ?>pages/blog.php" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Blog
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Services Column -->
            <div class="col-lg-3 col-md-6">
                <div class="footer-section">
                    <h5 class="mb-4 fw-bold">Our Services</h5>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2">
                            <a href="#" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Dine In
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="#" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Take Away
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="#" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Home Delivery
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="#" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Catering Services
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="#" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Private Events
                            </a>
                        </li>
                        <li class="mb-2">
                            <a href="#" class="text-white">
                                <i class="fas fa-chevron-right me-2 small text-primary"></i>Corporate Catering
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Contact Column -->
            <div class="col-lg-3 col-md-6">
                <div class="footer-section">
                    <h5 class="mb-4 fw-bold">Contact Us</h5>
                    <ul class="list-unstyled contact-info">
                        <li class="mb-3 d-flex align-items-start">
                            <i class="fas fa-map-marker-alt me-3 text-primary mt-1"></i>
                            <span class="text-white">
                                <?php echo $restaurant_info['address']; ?>
                            </span>
                        </li>
                        <li class="mb-3 d-flex align-items-center">
                            <i class="fas fa-phone me-3 text-primary"></i>
                            <a href="tel:<?php echo str_replace([' ', '-'], '', $restaurant_info['phone']); ?>" class="text-white">
                                <?php echo $restaurant_info['phone']; ?>
                            </a>
                        </li>
                        <li class="mb-3 d-flex align-items-center">
                            <i class="fas fa-envelope me-3 text-primary"></i>
                            <a href="mailto:<?php echo $restaurant_info['email']; ?>" class="text-white">
                                <?php echo $restaurant_info['email']; ?>
                            </a>
                        </li>
                        <li class="mb-3">
                            <div class="d-flex align-items-start">
                                <i class="fas fa-clock me-3 text-primary mt-1"></i>
                                <div class="text-white">
                                    <div><strong>Mon - Fri:</strong> <?php echo $restaurant_info['hours']['weekday']; ?></div>
                                    <div><strong>Sat - Sun:</strong> <?php echo $restaurant_info['hours']['weekend']; ?></div>
                                </div>
                            </div>
                        </li>
                    </ul>
                    
                    <!-- Newsletter Subscription -->
                    <div class="newsletter-form mt-4">
                        <h6 class="fw-bold mb-2">Newsletter</h6>
                        <form id="newsletterForm" class="d-flex gap-2">
                            <input type="email" class="form-control form-control-sm" placeholder="Your email" required>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
        </div>

        <!-- Divider -->
        <hr class="my-4 border-secondary opacity-25">

        <!-- Bottom Footer -->
        <div class="row align-items-center">
            <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                <p class="text-white mb-0 small">
                    &copy; <?php echo $current_year; ?> <?php echo $restaurant_info['name']; ?>. All rights reserved.
                </p>
            </div>
            <div class="col-md-6">
                <ul class="list-inline text-center text-md-end mb-0">
                    <li class="list-inline-item">
                        <a href="<?php echo $base_url ?? '/'; ?>pages/privacy-policy.php" class="text-white small">Privacy Policy</a>
                    </li>
                    <li class="list-inline-item">|</li>
                    <li class="list-inline-item">
                        <a href="<?php echo $base_url ?? '/'; ?>pages/terms-conditions.php" class="text-white small">Terms & Conditions</a>
                    </li>
                    <li class="list-inline-item">|</li>
                    <li class="list-inline-item">
                        <a href="<?php echo $base_url ?? '/'; ?>pages/refund-policy.php" class="text-white small">Refund Policy</a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Credits -->
        <div class="row mt-3">
            <div class="col-12 text-center">
                <p class="text-white mb-0 small">
                    Developed by 
                    <a href="#" class="text-primary fw-semibold">Nusrat Jahan</a>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Back to Top Button -->
    <button id="backToTop" class="btn btn-primary btn-floating" title="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>
</footer>

<!-- Footer Styles -->


<!-- Footer JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Back to Top Button
    const backToTopBtn = document.getElementById('backToTop');
    
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            backToTopBtn.classList.add('show');
        } else {
            backToTopBtn.classList.remove('show');
        }
    });
    
    backToTopBtn.addEventListener('click', function() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
    
    // Newsletter Form Submission
    const newsletterForm = document.getElementById('newsletterForm');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = this.querySelector('input[type="email"]').value;
            
            // Show success message
            if (typeof RMS !== 'undefined' && RMS.showToast) {
                RMS.showToast('Thank you for subscribing to our newsletter!', 'success');
                this.reset();
            } else {
                alert('Thank you for subscribing to our newsletter!');
                this.reset();
            }
            
            // Here you would typically send the email to your backend
            // Example: fetch('/api/newsletter/subscribe', { method: 'POST', body: JSON.stringify({ email }) })
        });
    }
    
    // Add smooth scroll to all anchor links in footer
    document.querySelectorAll('.footer a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#') {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
});
</script>

<!-- Bootstrap JS (if not already included) -->
<?php if (!isset($bootstrap_js_included)): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

<!-- Custom JS (if not already included) -->
<?php if (!isset($custom_js_included) && file_exists('assets/js/main.js')): ?>
<script src="<?php echo $base_url ?? '/'; ?>assets/js/main.js"></script>
<?php endif; ?>

</body>
</html>