<?php
// customer/menu.php - Menu & Ordering
ob_start();
session_start();

require_once '../config/database.php';

$success_message = '';
$error_message   = '';

// ── HANDLE ADD TO CART ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../auth/login.php');
        exit();
    }

    $item_id  = intval($_POST['item_id']);
    $quantity = max(1, intval($_POST['quantity']));
    $note     = trim($_POST['note'] ?? '');

    try {
        $database = new Database();
        $db       = $database->connect();

        $chk = $db->prepare(
            "SELECT item_id, item_name, price
             FROM menu_items
             WHERE item_id = :id AND availability = 'available'"
        );
        $chk->execute([':id' => $item_id]);
        $item = $chk->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

            if (isset($_SESSION['cart'][$item_id])) {
                $_SESSION['cart'][$item_id]['quantity'] += $quantity;
                if ($note) $_SESSION['cart'][$item_id]['note'] = $note;
            } else {
                $_SESSION['cart'][$item_id] = [
                    'item_id'   => $item_id,
                    'item_name' => $item['item_name'],
                    'price'     => (float)$item['price'],
                    'quantity'  => $quantity,
                    'note'      => $note
                ];
            }
            $success_message = '"' . htmlspecialchars($item['item_name']) . '" added to cart!';
        } else {
            $error_message = 'This item is no longer available.';
        }
    } catch (PDOException $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// ── HANDLE CLEAR CART ─────────────────────────────────────────────
if (isset($_GET['clear_cart'])) {
    $_SESSION['cart'] = [];
    header('Location: menu.php');
    exit();
}

// ── HANDLE REMOVE SINGLE ITEM ─────────────────────────────────────
if (isset($_GET['remove']) && isset($_SESSION['cart'])) {
    $remove_id = intval($_GET['remove']);
    unset($_SESSION['cart'][$remove_id]);
    header('Location: menu.php');
    exit();
}

// ── FETCH MENU DATA ───────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
    $search_query    = trim($_GET['search'] ?? '');

    // All active categories with item counts
    $categories = $db->query(
        "SELECT c.*, COUNT(mi.item_id) AS item_count
         FROM categories c
         LEFT JOIN menu_items mi ON mi.category_id = c.category_id
                                 AND mi.availability = 'available'
         WHERE c.status = 'active'
         GROUP BY c.category_id
         ORDER BY c.category_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Menu items query
    $query = "SELECT mi.*, c.category_name
              FROM menu_items mi
              LEFT JOIN categories c ON c.category_id = mi.category_id
              WHERE mi.availability = 'available'";

    if ($filter_category > 0) {
        $query .= " AND mi.category_id = :cat";
    }
    if (!empty($search_query)) {
        $query .= " AND (mi.item_name LIKE :search OR mi.description LIKE :search
                         OR c.category_name LIKE :search)";
    }
    $query .= " ORDER BY c.category_name ASC, mi.item_name ASC";

    $stmt = $db->prepare($query);
    if ($filter_category > 0)  $stmt->bindValue(':cat',    $filter_category, PDO::PARAM_INT);
    if (!empty($search_query)) $stmt->bindValue(':search', "%{$search_query}%");
    $stmt->execute();
    $menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by category for section rendering
    $grouped = [];
    foreach ($menu_items as $item) {
        $grouped[$item['category_name']][] = $item;
    }

    // Total item count across all categories (for "All" pill)
    $total_available = $db->query(
        "SELECT COUNT(*) FROM menu_items WHERE availability = 'available'"
    )->fetchColumn();

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $categories = $menu_items = $grouped = [];
    $total_available = 0;
}

// ── CART TOTALS ───────────────────────────────────────────────────
$cart       = $_SESSION['cart'] ?? [];
$cart_count = array_sum(array_column($cart, 'quantity'));
$cart_subtotal = 0;
foreach ($cart as $ci) {
    $cart_subtotal += $ci['price'] * $ci['quantity'];
}
$cart_tax   = round($cart_subtotal * 0.05, 2);
$cart_total = $cart_subtotal + $cart_tax;

$page_title = 'Our Menu';
$base_url   = '../';
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
/* ── Hero ───────────────────────────────────────────────────────── */
.menu-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 70px 0 50px;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-top: 56px; /* navbar offset */
}
.menu-hero::before {
    content: '';
    position: absolute;
    top: -40%;
    right: -5%;
    width: 380px;
    height: 380px;
    background: rgba(255,255,255,.07);
    border-radius: 50%;
    pointer-events: none;
}
.menu-hero::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 3%;
    width: 260px;
    height: 260px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
    pointer-events: none;
}

/* ── Category pills ─────────────────────────────────────────────── */
.category-pills {
    display: flex;
    gap: 10px;
    flex-wrap: nowrap;
    overflow-x: auto;
    padding-bottom: 6px;
    scrollbar-width: none;
}
.category-pills::-webkit-scrollbar { display: none; }

.cat-pill {
    border-radius: 25px;
    padding: 8px 20px;
    font-weight: 600;
    font-size: .85rem;
    white-space: nowrap;
    border: 2px solid #dee2e6;
    background: #fff;
    color: #495057;
    text-decoration: none;
    transition: all .25s;
    flex-shrink: 0;
}
.cat-pill:hover {
    border-color: #667eea;
    color: #667eea;
    transform: translateY(-2px);
}
.cat-pill.active {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 4px 15px rgba(102,126,234,.4);
}

/* ── Category section heading ───────────────────────────────────── */
.cat-heading {
    position: relative;
    padding-bottom: 10px;
    margin-bottom: 20px;
}
.cat-heading::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 48px;
    height: 3px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 2px;
}

/* ── Menu item card ─────────────────────────────────────────────── */
.menu-card {
    border-radius: 15px;
    border: none;
    overflow: hidden;
    height: 100%;
    transition: all .3s;
}
.menu-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 30px rgba(0,0,0,.13) !important;
}
.menu-card .card-img-wrap {
    overflow: hidden;
    height: 185px;
}
.menu-card .card-img-wrap img {
    width: 100%;
    height: 185px;
    object-fit: cover;
    transition: transform .4s;
}
.menu-card:hover .card-img-wrap img { transform: scale(1.07); }

.img-placeholder {
    height: 185px;
    background: linear-gradient(135deg, #667eea18, #764ba218);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
    color: #667eea66;
}

.price-badge {
    background: linear-gradient(135deg, #11998e, #38ef7d);
    color: #fff;
    padding: 4px 14px;
    border-radius: 20px;
    font-weight: 700;
    font-size: .9rem;
}

.cat-chip {
    background: #667eea15;
    color: #667eea;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: .72rem;
    font-weight: 600;
}

/* ── Qty +/− control ────────────────────────────────────────────── */
.qty-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.qty-btn {
    width: 30px;
    height: 30px;
    padding: 0;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .8rem;
    flex-shrink: 0;
}
.qty-input {
    width: 46px;
    text-align: center;
    border: 2px solid #dee2e6;
    border-radius: 7px;
    font-weight: 600;
    padding: 3px 4px;
    font-size: .9rem;
}
.qty-input:focus {
    outline: none;
    border-color: #667eea;
}

/* ── Cart sidebar ───────────────────────────────────────────────── */
.cart-card {
    border-radius: 15px;
    border: none;
    position: sticky;
    top: 80px;
}
.cart-item-row {
    padding: 10px 0;
    border-bottom: 1px solid #f1f1f1;
}
.cart-item-row:last-child { border-bottom: none; }

/* ── Floating cart FAB (mobile) ─────────────────────────────────── */
.cart-fab {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1050;
    border-radius: 50px;
    padding: 13px 24px;
    font-weight: 600;
    border: none;
    color: #fff;
    background: linear-gradient(135deg, #667eea, #764ba2);
    box-shadow: 0 6px 20px rgba(102,126,234,.5);
    display: none;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: transform .2s;
}
.cart-fab:hover { transform: translateY(-2px); }
@media (max-width: 991.98px) { .cart-fab { display: flex; } }

/* ── Item detail modal ──────────────────────────────────────────── */
.modal-img {
    width: 100%;
    height: 240px;
    object-fit: cover;
    border-radius: 12px 12px 0 0;
}
.modal-img-placeholder {
    height: 240px;
    background: linear-gradient(135deg, #667eea18, #764ba218);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 5rem;
    color: #667eea44;
    border-radius: 12px 12px 0 0;
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="menu-hero">
    <div class="container position-relative">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <p class="mb-2 opacity-75 small text-uppercase fw-semibold ls-1">
                    <i class="fas fa-utensils me-2"></i>Fine Dine RMS
                </p>
                <h1 class="display-5 fw-bold mb-3">Our Menu</h1>
                <p class="lead opacity-80 mb-4">
                    Freshly prepared dishes made with the finest ingredients.
                    Browse, pick your favourites and order in seconds.
                </p>
                <!-- Search bar -->
                <form method="GET" class="d-flex gap-2" style="max-width:500px;">
                    <?php if ($filter_category > 0): ?>
                    <input type="hidden" name="category" value="<?php echo $filter_category; ?>">
                    <?php endif; ?>
                    <input type="text" name="search"
                           class="form-control form-control-lg shadow-sm"
                           placeholder="Search dishes, ingredients..."
                           value="<?php echo htmlspecialchars($search_query); ?>">
                    <button type="submit" class="btn btn-light btn-lg px-4 shadow-sm">
                        <i class="fas fa-search text-primary"></i>
                    </button>
                    <?php if (!empty($search_query)): ?>
                    <a href="menu.php<?php echo $filter_category > 0 ? '?category='.$filter_category : ''; ?>"
                       class="btn btn-outline-light btn-lg" title="Clear search">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="col-lg-4 d-none d-lg-flex justify-content-center">
                <i class="fas fa-concierge-bell text-white opacity-20" style="font-size:9rem;"></i>
            </div>
        </div>
    </div>
</section>

<!-- ── Page body ──────────────────────────────────────────────────── -->
<div class="container-fluid py-4">

    <!-- Alerts -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── LEFT: Menu ──────────────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Category pills -->
            <div class="mb-4">
                <div class="category-pills">
                    <a href="menu.php<?php echo !empty($search_query) ? '?search='.urlencode($search_query) : ''; ?>"
                       class="cat-pill <?php echo $filter_category == 0 ? 'active' : ''; ?>">
                        <i class="fas fa-th me-1"></i>All
                        <span class="ms-1 opacity-75 fw-normal">(<?php echo $total_available; ?>)</span>
                    </a>
                    <?php foreach ($categories as $cat): ?>
                    <a href="menu.php?category=<?php echo $cat['category_id']; ?><?php echo !empty($search_query) ? '&search='.urlencode($search_query) : ''; ?>"
                       class="cat-pill <?php echo $filter_category == $cat['category_id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cat['category_name']); ?>
                        <span class="ms-1 opacity-75 fw-normal">(<?php echo $cat['item_count']; ?>)</span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Search result notice -->
            <?php if (!empty($search_query)): ?>
            <div class="alert alert-info py-2 mb-3 d-flex align-items-center justify-content-between">
                <span>
                    <i class="fas fa-search me-2"></i>
                    <strong><?php echo count($menu_items); ?></strong> result(s) for
                    "<strong><?php echo htmlspecialchars($search_query); ?></strong>"
                </span>
                <a href="menu.php<?php echo $filter_category > 0 ? '?category='.$filter_category : ''; ?>"
                   class="alert-link text-decoration-none small">
                    <i class="fas fa-times me-1"></i>Clear
                </a>
            </div>
            <?php endif; ?>

            <!-- Menu sections grouped by category -->
            <?php if (!empty($grouped)): ?>

                <?php foreach ($grouped as $cat_name => $items): ?>
                <div class="mb-5" id="cat-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $cat_name))); ?>">
                    <div class="cat-heading">
                        <h4 class="fw-bold mb-0">
                            <?php echo htmlspecialchars($cat_name); ?>
                        </h4>
                        <small class="text-muted"><?php echo count($items); ?> item<?php echo count($items) != 1 ? 's' : ''; ?></small>
                    </div>

                    <div class="row g-3">
                    <?php foreach ($items as $item): ?>
                        <div class="col-sm-6 col-xl-4">
                            <div class="card menu-card shadow-sm">

                                <!-- Image -->
                                <div class="card-img-wrap">
                                    <?php if (!empty($item['image_url'])): ?>
                                    <img src="../assets/images/<?php echo htmlspecialchars($item['image_url']); ?>"
                                         alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                         onerror="this.parentElement.innerHTML='<div class=\'img-placeholder\'><i class=\'fas fa-utensils\'></i></div>'">
                                    <?php else: ?>
                                    <div class="img-placeholder">
                                        <i class="fas fa-utensils"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body d-flex flex-column p-3">

                                    <!-- Name + price -->
                                    <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                                        <h6 class="fw-bold mb-0 flex-grow-1">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                        </h6>
                                        <span class="price-badge flex-shrink-0">
                                            ৳<?php echo number_format($item['price'], 2); ?>
                                        </span>
                                    </div>

                                    <!-- Description -->
                                    <?php if (!empty($item['description'])): ?>
                                    <p class="text-muted small mb-2 flex-grow-1"
                                       style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </p>
                                    <?php else: ?>
                                    <p class="flex-grow-1 mb-2"></p>
                                    <?php endif; ?>

                                    <!-- Meta row -->
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <span class="cat-chip">
                                            <?php echo htmlspecialchars($item['category_name'] ?? ''); ?>
                                        </span>
                                        <button type="button"
                                                class="btn btn-link btn-sm p-0 text-muted text-decoration-none"
                                                onclick='openDetail(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES); ?>)'
                                                title="View details">
                                            <i class="fas fa-info-circle"></i> Details
                                        </button>
                                    </div>

                                    <!-- Add to cart / Login -->
                                    <?php if (isset($_SESSION['user_id'])): ?>
                                    <form method="POST" class="mt-auto">
                                        <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                        <div class="qty-wrap mb-2">
                                            <button type="button" class="btn btn-outline-secondary qty-btn"
                                                    onclick="changeQty(this,-1)">
                                                <i class="fas fa-minus"></i>
                                            </button>
                                            <input type="number" name="quantity"
                                                   class="qty-input" value="1" min="1" max="20">
                                            <button type="button" class="btn btn-outline-secondary qty-btn"
                                                    onclick="changeQty(this,1)">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                        <button type="submit" name="add_to_cart"
                                                class="btn btn-primary btn-sm w-100">
                                            <i class="fas fa-cart-plus me-1"></i>Add to Cart
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <a href="../auth/login.php" class="btn btn-outline-primary btn-sm w-100 mt-auto">
                                        <i class="fas fa-sign-in-alt me-1"></i>Login to Order
                                    </a>
                                    <?php endif; ?>

                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

            <?php else: ?>
            <!-- Empty state -->
            <div class="text-center py-5 text-muted">
                <i class="fas fa-search fs-1 d-block mb-3 opacity-40"></i>
                <h4>No items found</h4>
                <p>
                    <?php if (!empty($search_query)): ?>
                        No dishes matched "<strong><?php echo htmlspecialchars($search_query); ?></strong>".
                        Try a different search term.
                    <?php else: ?>
                        No items are available in this category right now.
                    <?php endif; ?>
                </p>
                <a href="menu.php" class="btn btn-primary">
                    <i class="fas fa-redo me-2"></i>View All Items
                </a>
            </div>
            <?php endif; ?>

        </div><!-- /col-lg-8 -->

        <!-- ── RIGHT: Cart sidebar ──────────────────────────────────── -->
        <div class="col-lg-4 d-none d-lg-block">

            <!-- Cart card -->
            <div class="card cart-card shadow-sm mb-4">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">
                            <i class="fas fa-shopping-cart text-primary me-2"></i>Cart
                        </h6>
                        <?php if ($cart_count > 0): ?>
                        <span class="badge bg-primary rounded-pill"><?php echo $cart_count; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body">
                    <?php if (!empty($cart)): ?>

                    <!-- Items -->
                    <?php foreach ($cart as $ci): ?>
                    <div class="cart-item-row">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small"><?php echo htmlspecialchars($ci['item_name']); ?></div>
                                <small class="text-muted">
                                    ৳<?php echo number_format($ci['price'], 2); ?>
                                    × <?php echo $ci['quantity']; ?>
                                </small>
                                <?php if (!empty($ci['note'])): ?>
                                <div class="small text-muted fst-italic">
                                    <i class="fas fa-sticky-note me-1"></i>
                                    <?php echo htmlspecialchars($ci['note']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end d-flex flex-column align-items-end gap-1">
                                <span class="fw-bold text-success small">
                                    ৳<?php echo number_format($ci['price'] * $ci['quantity'], 2); ?>
                                </span>
                                <a href="menu.php?remove=<?php echo $ci['item_id']; ?>"
                                   class="text-danger small text-decoration-none"
                                   onclick="return confirm('Remove this item?')"
                                   title="Remove">
                                    <i class="fas fa-times"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Totals -->
                    <div class="border-top pt-3 mt-1">
                        <div class="d-flex justify-content-between mb-1 small">
                            <span class="text-muted">Subtotal</span>
                            <span>৳<?php echo number_format($cart_subtotal, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 small">
                            <span class="text-muted">Tax (5%)</span>
                            <span>৳<?php echo number_format($cart_tax, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold mb-3">
                            <span>Total</span>
                            <span class="text-success">৳<?php echo number_format($cart_total, 2); ?></span>
                        </div>
                        <div class="d-flex gap-2 mb-2">
                            <a href="../customer/cart.php" class="btn btn-outline-primary btn-sm flex-fill">
                                <i class="fas fa-shopping-cart me-1"></i>View Cart
                            </a>
                            <a href="../customer/cart.php?checkout=1" class="btn btn-success btn-sm flex-fill">
                                <i class="fas fa-bolt me-1"></i>Checkout
                            </a>
                        </div>
                        <a href="menu.php?clear_cart=1"
                           class="btn btn-outline-danger btn-sm w-100"
                           onclick="return confirm('Clear your entire cart?')">
                            <i class="fas fa-trash me-1"></i>Clear Cart
                        </a>
                    </div>

                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-shopping-cart fs-2 d-block mb-2 opacity-30"></i>
                        <p class="small mb-0">Your cart is empty.</p>
                        <p class="small">Add some dishes!</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Opening Hours -->
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white border-0"
                     style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-clock text-warning me-2"></i>Opening Hours
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2 small">
                        <span class="text-muted">Mon – Fri</span>
                        <span class="fw-semibold">10:00 AM – 11:00 PM</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3 small">
                        <span class="text-muted">Sat – Sun</span>
                        <span class="fw-semibold">9:00 AM – 12:00 AM</span>
                    </div>
                    <a href="../customer/reservation.php" class="btn btn-outline-primary btn-sm w-100">
                        <i class="fas fa-calendar-plus me-1"></i>Make a Reservation
                    </a>
                </div>
            </div>

        </div><!-- /col-lg-4 -->

    </div><!-- /row -->

</div><!-- /container-fluid -->

<!-- ── Floating cart button (mobile) ─────────────────────────────── -->
<?php if ($cart_count > 0): ?>
<button class="cart-fab" onclick="window.location.href='../customer/cart.php'">
    <i class="fas fa-shopping-cart"></i>
    <span><?php echo $cart_count; ?> item<?php echo $cart_count != 1 ? 's' : ''; ?></span>
    <span class="ms-1 opacity-75">· ৳<?php echo number_format($cart_total, 2); ?></span>
</button>
<?php endif; ?>


<!-- ══════ ITEM DETAIL MODAL ══════ -->
<div class="modal fade" id="itemDetailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:15px;border:none;overflow:hidden;">
            <div id="modalImgWrap"></div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                    <h5 class="fw-bold mb-0" id="modalItemName"></h5>
                    <span class="price-badge flex-shrink-0" id="modalItemPrice"></span>
                </div>
                <span class="cat-chip mb-3 d-inline-block" id="modalItemCat"></span>
                <p class="text-muted" id="modalItemDesc"></p>
                <div class="d-flex align-items-center gap-2 mb-2 mt-3">
                    <i class="fas fa-check-circle text-success"></i>
                    <small class="text-success fw-semibold">Available now</small>
                </div>
            </div>
            <?php if (isset($_SESSION['user_id'])): ?>
            <div class="modal-footer border-0 pt-0">
                <form method="POST" class="w-100">
                    <input type="hidden" name="item_id" id="modalItemId">
                    <div class="d-flex gap-2 align-items-center">
                        <div class="qty-wrap">
                            <button type="button" class="btn btn-outline-secondary qty-btn"
                                    onclick="changeQty(this,-1)">
                                <i class="fas fa-minus"></i>
                            </button>
                            <input type="number" name="quantity"
                                   class="qty-input" value="1" min="1" max="20">
                            <button type="button" class="btn btn-outline-secondary qty-btn"
                                    onclick="changeQty(this,1)">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="flex-grow-1">
                            <input type="text" name="note" class="form-control form-control-sm"
                                   placeholder="Special instructions (optional)">
                        </div>
                    </div>
                    <button type="submit" name="add_to_cart" class="btn btn-primary w-100 mt-2">
                        <i class="fas fa-cart-plus me-1"></i>Add to Cart
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
// ── Qty +/− ───────────────────────────────────────────────────────
function changeQty(btn, delta) {
    const input = btn.closest('.qty-wrap').querySelector('.qty-input');
    let val = parseInt(input.value) || 1;
    val = Math.min(20, Math.max(1, val + delta));
    input.value = val;
}

// ── Item detail modal ─────────────────────────────────────────────
function openDetail(item) {
    document.getElementById('modalItemName').textContent  = item.item_name;
    document.getElementById('modalItemPrice').textContent = '৳' + parseFloat(item.price).toFixed(2);
    document.getElementById('modalItemCat').textContent   = item.category_name ?? '';
    document.getElementById('modalItemDesc').textContent  = item.description || 'No description available.';
    if (document.getElementById('modalItemId')) {
        document.getElementById('modalItemId').value = item.item_id;
    }

    const wrap = document.getElementById('modalImgWrap');
    if (item.image_url) {
        wrap.innerHTML = `<img src="../assets/images/${sitem.image_url}"
                               class="modal-img"
                               alt="${item.item_name}"
                               onerror="this.parentElement.innerHTML='<div class=\\'modal-img-placeholder\\'><i class=\\'fas fa-utensils\\'></i></div>'">`;
    } else {
        wrap.innerHTML = `<div class="modal-img-placeholder"><i class="fas fa-utensils"></i></div>`;
    }

    new bootstrap.Modal(document.getElementById('itemDetailModal')).show();
}

// ── Auto-dismiss success alerts ───────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert-success').forEach(a => {
        bootstrap.Alert.getOrCreateInstance(a).close();
    });
}, 4000);

// ── Scroll spy: highlight active category pill ────────────────────
window.addEventListener('scroll', () => {
    const pills = document.querySelectorAll('.cat-pill[href*="category"]');
    // (basic – full scroll spy would need IntersectionObserver per section)
});
</script>
</body>
</html>