<?php
// admin/menu_management.php - Menu Management System
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
    
    // Add New Item
    if (isset($_POST['add_item'])) {
        try {
            $category_id = $_POST['category_id'];
            $item_name = trim($_POST['item_name']);
            $description = trim($_POST['description']);
            $price = floatval($_POST['price']);
            $availability = $_POST['availability'];
            
            // Handle image upload
            $image_url = 'default-food.jpg';
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $upload_dir = '../assets/images/menu/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        $image_url = 'menu/' . $new_filename;
                    }
                }
            }
            
            $query = "INSERT INTO menu_items (category_id, item_name, description, price, image_url, availability) 
                     VALUES (:category_id, :item_name, :description, :price, :image_url, :availability)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->bindParam(':item_name', $item_name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':image_url', $image_url);
            $stmt->bindParam(':availability', $availability);
            
            if ($stmt->execute()) {
                $success_message = 'Menu item added successfully!';
            } else {
                $error_message = 'Failed to add menu item.';
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Update Item
    if (isset($_POST['update_item'])) {
        try {
            $item_id = $_POST['item_id'];
            $category_id = $_POST['category_id'];
            $item_name = trim($_POST['item_name']);
            $description = trim($_POST['description']);
            $price = floatval($_POST['price']);
            $availability = $_POST['availability'];
            
            // Get current image
            $stmt = $db->prepare("SELECT image_url FROM menu_items WHERE item_id = :item_id");
            $stmt->bindParam(':item_id', $item_id);
            $stmt->execute();
            $current_item = $stmt->fetch(PDO::FETCH_ASSOC);
            $image_url = $current_item['image_url'];
            
            // Handle new image upload
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $upload_dir = '../assets/images/menu/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                        // Delete old image if not default
                        if ($image_url != 'default-food.jpg' && file_exists($upload_dir . basename($image_url))) {
                            unlink($upload_dir . basename($image_url));
                        }
                        $image_url = 'menu/' . $new_filename;
                    }
                }
            }
            
            $query = "UPDATE menu_items 
                     SET category_id = :category_id, item_name = :item_name, description = :description, 
                         price = :price, image_url = :image_url, availability = :availability 
                     WHERE item_id = :item_id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':item_id', $item_id);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->bindParam(':item_name', $item_name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':image_url', $image_url);
            $stmt->bindParam(':availability', $availability);
            
            if ($stmt->execute()) {
                $success_message = 'Menu item updated successfully!';
            } else {
                $error_message = 'Failed to update menu item.';
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Delete Item
    if (isset($_POST['delete_item'])) {
        try {
            $item_id = $_POST['item_id'];
            
            // Get image to delete
            $stmt = $db->prepare("SELECT image_url FROM menu_items WHERE item_id = :item_id");
            $stmt->bindParam(':item_id', $item_id);
            $stmt->execute();
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $query = "DELETE FROM menu_items WHERE item_id = :item_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':item_id', $item_id);
            
            if ($stmt->execute()) {
                // Delete image file if not default
                if ($item && $item['image_url'] != 'default-food.jpg') {
                    $image_path = '../assets/images/' . $item['image_url'];
                    if (file_exists($image_path)) {
                        unlink($image_path);
                    }
                }
                $success_message = 'Menu item deleted successfully!';
            } else {
                $error_message = 'Failed to delete menu item.';
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
    
    // Toggle Availability
    if (isset($_POST['toggle_availability'])) {
        try {
            $item_id = $_POST['item_id'];
            $new_status = $_POST['new_status'];
            
            $query = "UPDATE menu_items SET availability = :status WHERE item_id = :item_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':item_id', $item_id);
            $stmt->bindParam(':status', $new_status);
            
            if ($stmt->execute()) {
                $success_message = 'Availability updated successfully!';
            }
            
        } catch(PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch categories and menu items
try {
    $database = new Database();
    $db = $database->connect();
    
    // Get categories
    $stmt = $db->query("SELECT * FROM categories WHERE status = 'active' ORDER BY category_name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get menu items with category info
    $filter_category = $_GET['category'] ?? 'all';
    $search_query = $_GET['search'] ?? '';
    
    $query = "SELECT mi.*, c.category_name 
             FROM menu_items mi 
             LEFT JOIN categories c ON mi.category_id = c.category_id 
             WHERE 1=1";
    
    if ($filter_category != 'all') {
        $query .= " AND mi.category_id = :category_id";
    }
    
    if (!empty($search_query)) {
        $query .= " AND (mi.item_name LIKE :search OR mi.description LIKE :search)";
    }
    
    $query .= " ORDER BY mi.item_name";
    
    $stmt = $db->prepare($query);
    
    if ($filter_category != 'all') {
        $stmt->bindParam(':category_id', $filter_category);
    }
    
    if (!empty($search_query)) {
        $search_param = "%{$search_query}%";
        $stmt->bindParam(':search', $search_param);
    }
    
    $stmt->execute();
    $menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $categories = [];
    $menu_items = [];
}

$page_title = 'Menu Management';
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
        .menu-item-card {
            transition: all 0.3s ease;
            height: 100%;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .menu-item-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .menu-item-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        
        .badge-status {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 8px 12px;
            font-size: 0.75rem;
        }
        
        .price-tag {
            font-size: 1.5rem;
            font-weight: 700;
            color: #28a745;
        }
        
        .filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .search-box {
            border-radius: 10px;
            border: none;
            padding: 12px 20px;
        }
        
        .category-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            background: #e9ecef;
            color: #495057;
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
                    <i class="fas fa-utensils text-primary me-2"></i>
                    Menu Management
                </h2>
                <p class="text-muted mb-0">Manage your restaurant menu items</p>
            </div>
            <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fas fa-plus me-2"></i>Add New Item
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
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="row align-items-center">
                <div class="col-md-6 mb-3 mb-md-0">
                    <form method="GET" class="d-flex">
                        <input type="text" name="search" class="form-control search-box me-2" 
                               placeholder="Search menu items..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="btn btn-light">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
                <div class="col-md-6">
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="?category=all" class="btn <?php echo $filter_category == 'all' ? 'btn-light' : 'btn-outline-light'; ?>">
                            All Items
                        </a>
                        <?php foreach($categories as $cat): ?>
                        <a href="?category=<?php echo $cat['category_id']; ?>" 
                           class="btn <?php echo $filter_category == $cat['category_id'] ? 'btn-light' : 'btn-outline-light'; ?>">
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Menu Items Grid -->
        <div class="row g-4">
            <?php if (!empty($menu_items)): ?>
                <?php foreach($menu_items as $item): ?>
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="card menu-item-card shadow-sm">
                        <div class="position-relative">
                            <img src="../assets/images/<?php echo htmlspecialchars($item['image_url'] ?? 'default-food.jpg'); ?>" 
                                 class="menu-item-image" 
                                 alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                 onerror="this.src='https://via.placeholder.com/400x200?text=No+Image'">
                            <span class="badge <?php echo $item['availability'] == 'available' ? 'bg-success' : 'bg-danger'; ?> badge-status">
                                <?php echo ucfirst($item['availability']); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <span class="category-badge mb-2 d-inline-block">
                                <i class="fas fa-tag me-1"></i>
                                <?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?>
                            </span>
                            <h5 class="card-title mb-2"><?php echo htmlspecialchars($item['item_name']); ?></h5>
                            <p class="card-text text-muted small" style="height: 60px; overflow: hidden;">
                                <?php echo htmlspecialchars(substr($item['description'], 0, 100)); ?>
                                <?php echo strlen($item['description']) > 100 ? '...' : ''; ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="price-tag">৳<?php echo number_format($item['price'], 2); ?></span>
                                <div>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $item['availability'] == 'available' ? 'unavailable' : 'available'; ?>">
                                        <button type="submit" name="toggle_availability" 
                                                class="btn btn-sm <?php echo $item['availability'] == 'available' ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                                title="Toggle Availability">
                                            <i class="fas fa-<?php echo $item['availability'] == 'available' ? 'eye-slash' : 'eye'; ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary btn-sm flex-fill" 
                                        onclick='editItem(<?php echo json_encode($item); ?>)'>
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                                <button class="btn btn-danger btn-sm flex-fill" 
                                        onclick="deleteItem(<?php echo $item['item_id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')">
                                    <i class="fas fa-trash me-1"></i>Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="fas fa-utensils fs-1 text-muted mb-3 d-block"></i>
                        <h4 class="text-muted">No menu items found</h4>
                        <p class="text-muted">Start by adding your first menu item</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                            <i class="fas fa-plus me-2"></i>Add First Item
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    
    <!-- Add Item Modal -->
    <div class="modal fade" id="addItemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle text-primary me-2"></i>
                        Add New Menu Item
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" data-validate="true">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Item Name *</label>
                                <input type="text" name="item_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category *</label>
                                <select name="category_id" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Price (৳) *</label>
                                <input type="number" name="price" class="form-control" 
                                       step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Availability *</label>
                                <select name="availability" class="form-select" required>
                                    <option value="available">Available</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Image</label>
                                <input type="file" name="image" class="form-control" accept="image/*">
                                <small class="text-muted">Accepted formats: JPG, PNG, GIF (Max 2MB)</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_item" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Item Modal -->
    <div class="modal fade" id="editItemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit text-primary me-2"></i>
                        Edit Menu Item
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="editItemForm" data-validate="true">
                    <input type="hidden" name="item_id" id="edit_item_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Item Name *</label>
                                <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category *</label>
                                <select name="category_id" id="edit_category_id" class="form-select" required>
                                    <option value="">Select Category</option>
                                    <?php foreach($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Price (৳) *</label>
                                <input type="number" name="price" id="edit_price" class="form-control" 
                                       step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Availability *</label>
                                <select name="availability" id="edit_availability" class="form-select" required>
                                    <option value="available">Available</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Change Image (Leave empty to keep current)</label>
                                <input type="file" name="image" class="form-control" accept="image/*">
                                <small class="text-muted">Accepted formats: JPG, PNG, GIF (Max 2MB)</small>
                            </div>
                            <div class="col-12" id="current_image_preview"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_item" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Item
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Form (Hidden) -->
    <form method="POST" id="deleteForm">
        <input type="hidden" name="item_id" id="delete_item_id">
        <input type="hidden" name="delete_item" value="1">
    </form>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <!-- <script src="../assets/js/validation.js"></script> -->
    
    <script>
        // Edit Item Function
        function editItem(item) {
            document.getElementById('edit_item_id').value = item.item_id;
            document.getElementById('edit_item_name').value = item.item_name;
            document.getElementById('edit_category_id').value = item.category_id;
            document.getElementById('edit_price').value = item.price;
            document.getElementById('edit_availability').value = item.availability;
            document.getElementById('edit_description').value = item.description || '';
            
            // Show current image
            const imagePreview = document.getElementById('current_image_preview');
            imagePreview.innerHTML = `
                <div class="text-center">
                    <p class="mb-2"><strong>Current Image:</strong></p>
                    <img src="../assets/images/${item.image_url}" 
                         class="img-thumbnail" 
                         style="max-height: 150px;"
                         onerror="this.src='https://via.placeholder.com/150?text=No+Image'">
                </div>
            `;
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('editItemModal'));
            modal.show();
        }
        
        // Delete Item Function
        function deleteItem(itemId, itemName) {
            if (typeof RMS !== 'undefined' && RMS.confirmModal) {
                RMS.confirmModal({
                    title: 'Delete Menu Item',
                    message: `Are you sure you want to delete "<strong>${itemName}</strong>"? This action cannot be undone.`,
                    confirmText: 'Delete',
                    confirmClass: 'btn-danger',
                    onConfirm: function() {
                        document.getElementById('delete_item_id').value = itemId;
                        document.getElementById('deleteForm').submit();
                    }
                });
            } else {
                if (confirm(`Are you sure you want to delete "${itemName}"?`)) {
                    document.getElementById('delete_item_id').value = itemId;
                    document.getElementById('deleteForm').submit();
                }
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
    </script>
</body>
</html>