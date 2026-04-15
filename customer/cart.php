<?php
// customer/cart.php - Cart Review & Checkout
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$success_message = '';
$error_message   = '';

// Pull flash messages set by order_process.php
if (!empty($_SESSION['order_error'])) {
    $error_message = $_SESSION['order_error'];
    unset($_SESSION['order_error']);
}

// ── HANDLE CART UPDATES ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_cart'])) {
    $quantities = $_POST['quantities'] ?? [];
    $notes      = $_POST['notes']      ?? [];

    foreach ($quantities as $item_id => $qty) {
        $item_id = intval($item_id);
        $qty     = max(0, intval($qty));
        if (isset($_SESSION['cart'][$item_id])) {
            if ($qty === 0) {
                unset($_SESSION['cart'][$item_id]);
            } else {
                $_SESSION['cart'][$item_id]['quantity'] = $qty;
                $_SESSION['cart'][$item_id]['note']     = trim($notes[$item_id] ?? '');
            }
        }
    }
    $success_message = 'Cart updated.';
}

// ── HANDLE REMOVE SINGLE ITEM ─────────────────────────────────────
if (isset($_GET['remove'])) {
    $remove_id = intval($_GET['remove']);
    unset($_SESSION['cart'][$remove_id]);
    header('Location: cart.php');
    exit();
}

// ── HANDLE CLEAR CART ─────────────────────────────────────────────
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    header('Location: cart.php');
    exit();
}

// ── FETCH LIVE PRICES + AVAILABILITY ─────────────────────────────
$cart      = $_SESSION['cart'] ?? [];
$cart_live = [];    // enriched with live DB data

try {
    $database = new Database();
    $db       = $database->connect();

    if (!empty($cart)) {
        $ids  = array_keys($cart);
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT mi.item_id, mi.item_name, mi.price, mi.availability,
                    mi.image_url, c.category_name
             FROM menu_items mi
             LEFT JOIN categories c ON c.category_id = mi.category_id
             WHERE mi.item_id IN ($ph)"
        );
        $stmt->execute($ids);
        $db_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $db_map = [];
        foreach ($db_rows as $r) $db_map[$r['item_id']] = $r;

        foreach ($cart as $item_id => $ci) {
            $db_item = $db_map[$item_id] ?? null;
            $cart_live[] = [
                'item_id'      => $item_id,
                'item_name'    => $db_item['item_name']    ?? $ci['item_name'],
                'price'        => $db_item ? (float)$db_item['price'] : (float)$ci['price'],
                'quantity'     => (int)$ci['quantity'],
                'note'         => $ci['note'] ?? '',
                'availability' => $db_item['availability'] ?? 'unavailable',
                'image_url'    => $db_item['image_url']    ?? '',
                'category'     => $db_item['category_name'] ?? '',
                'changed'      => $db_item && (float)$db_item['price'] !== (float)$ci['price']
            ];
        }
    }

    // Available tables for checkout
    $tables = $db->query(
        "SELECT table_id, table_number, seating_capacity, location
         FROM restaurant_tables
         WHERE status = 'available'
         ORDER BY seating_capacity ASC, table_number ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $tables = [];
}

// ── TOTALS ────────────────────────────────────────────────────────
$subtotal = 0.0;
$all_available = true;
foreach ($cart_live as $ci) {
    if ($ci['availability'] !== 'available') { $all_available = false; continue; }
    $subtotal += $ci['price'] * $ci['quantity'];
}
$subtotal  = round($subtotal, 2);
$tax       = round($subtotal * 0.05, 2);
$total     = $subtotal + $tax;
$item_count = array_sum(array_column($cart_live, 'quantity'));

$page_title = 'My Cart';
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
.cart-card {
    border-radius: 15px;
    border: none;
}
.summary-card {
    border-radius: 15px;
    border: none;
    position: sticky;
    top: 80px;
}
.cart-item-row {
    border-radius: 12px;
    background: #f8f9fa;
    padding: 16px;
    margin-bottom: 12px;
    transition: all .2s;
}
.cart-item-row:hover { background: #f0f0f0; }
.cart-item-row.unavailable { background: #fff5f5; border: 1px solid #ffcccc; }

.item-thumb {
    width: 70px;
    height: 70px;
    border-radius: 10px;
    object-fit: cover;
    flex-shrink: 0;
}
.item-thumb-placeholder {
    width: 70px;
    height: 70px;
    border-radius: 10px;
    background: linear-gradient(135deg,#667eea18,#764ba218);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    color: #667eea66;
    flex-shrink: 0;
}

.qty-input {
    width: 52px;
    text-align: center;
    border: 2px solid #dee2e6;
    border-radius: 7px;
    font-weight: 600;
    padding: 4px;
}
.qty-input:focus { outline: none; border-color: #667eea; }
.qty-btn {
    width: 30px; height: 30px;
    padding: 0; border-radius: 7px;
    display: inline-flex;
    align-items: center; justify-content: center;
    font-size: .8rem;
}

.price-changed { font-size:.72rem; color:#e67e22; }

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f1f1f1;
}
.summary-row:last-child { border-bottom: none; }

.table-option {
    border: 2px solid #dee2e6;
    border-radius: 10px;
    padding: 10px 14px;
    cursor: pointer;
    transition: all .2s;
    margin-bottom: 8px;
}
.table-option:hover   { border-color: #667eea; background: #667eea08; }
.table-option.selected { border-color: #667eea; background: #667eea12;
                          box-shadow: 0 0 0 3px #667eea22; }
.table-option input[type="radio"] { display: none; }
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-shopping-cart text-primary me-2"></i>My Cart
            </h2>
            <p class="text-muted mb-0">
                <?php echo $item_count; ?> item<?php echo $item_count != 1 ? 's' : ''; ?> in your cart
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="../customer/menu.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Continue Shopping
            </a>
            <?php if (!empty($cart_live)): ?>
            <a href="cart.php?clear=1" class="btn btn-outline-danger"
               onclick="return confirm('Clear your entire cart?')">
                <i class="fas fa-trash me-2"></i>Clear Cart
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($cart_live)): ?>

    <form method="POST" id="cartForm">
    <div class="row g-4">

        <!-- ── Cart items ─────────────────────────────────────────── -->
        <div class="col-lg-8">
            <div class="card cart-card shadow-sm">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-list text-primary me-2"></i>Order Items
                    </h6>
                </div>
                <div class="card-body">

                    <?php foreach ($cart_live as $ci):
                        $unavail = $ci['availability'] !== 'available';
                    ?>
                    <div class="cart-item-row <?php echo $unavail ? 'unavailable' : ''; ?>">
                        <div class="d-flex gap-3 align-items-start">

                            <!-- Thumbnail -->
                            <?php if (!empty($ci['image_url'])): ?>
                            <img src="../assets/images/<?php echo htmlspecialchars($ci['image_url']); ?>"
                                 class="item-thumb"
                                 alt="<?php echo htmlspecialchars($ci['item_name']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="item-thumb-placeholder" style="display:none;">
                                <i class="fas fa-utensils"></i>
                            </div>
                            <?php else: ?>
                            <div class="item-thumb-placeholder">
                                <i class="fas fa-utensils"></i>
                            </div>
                            <?php endif; ?>

                            <!-- Details -->
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <div>
                                        <div class="fw-bold">
                                            <?php echo htmlspecialchars($ci['item_name']); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($ci['category']); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold text-success">
                                            ৳<?php echo number_format($ci['price'] * $ci['quantity'], 2); ?>
                                        </div>
                                        <small class="text-muted">
                                            ৳<?php echo number_format($ci['price'], 2); ?> each
                                        </small>
                                        <?php if ($ci['changed']): ?>
                                        <div class="price-changed">
                                            <i class="fas fa-info-circle me-1"></i>Price updated
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($unavail): ?>
                                <div class="alert alert-danger py-1 px-2 mb-2 small">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    This item is no longer available and will be skipped.
                                </div>
                                <?php endif; ?>

                                <!-- Qty + note controls -->
                                <?php if (!$unavail): ?>
                                <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
                                    <div class="d-flex align-items-center gap-1">
                                        <button type="button" class="btn btn-outline-secondary qty-btn"
                                                onclick="changeQty(this,-1)">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="number"
                                               name="quantities[<?php echo $ci['item_id']; ?>]"
                                               class="qty-input"
                                               value="<?php echo $ci['quantity']; ?>"
                                               min="0" max="20">
                                        <button type="button" class="btn btn-outline-secondary qty-btn"
                                                onclick="changeQty(this,1)">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <small class="text-muted ms-1">Set 0 to remove</small>
                                    </div>
                                    <input type="text"
                                           name="notes[<?php echo $ci['item_id']; ?>]"
                                           class="form-control form-control-sm"
                                           style="max-width:220px;"
                                           placeholder="Special instructions…"
                                           value="<?php echo htmlspecialchars($ci['note']); ?>">
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Remove -->
                            <a href="cart.php?remove=<?php echo $ci['item_id']; ?>"
                               class="btn btn-outline-danger btn-sm"
                               style="border-radius:8px;width:34px;height:34px;
                                      display:inline-flex;align-items:center;
                                      justify-content:center;flex-shrink:0;"
                               title="Remove item">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" name="update_cart" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-sync me-1"></i>Update Cart
                        </button>
                        <a href="../customer/menu.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-plus me-1"></i>Add More Items
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Order Summary + Checkout ───────────────────────────── -->
        <div class="col-lg-4">
            <div class="card summary-card shadow-sm">
                <div class="card-header bg-white" style="border-radius:15px 15px 0 0 !important;">
                    <h6 class="fw-bold mb-0">
                        <i class="fas fa-receipt text-success me-2"></i>Order Summary
                    </h6>
                </div>
                <div class="card-body">

                    <!-- Totals -->
                    <div class="mb-3">
                        <div class="summary-row">
                            <span class="text-muted">Subtotal</span>
                            <span>৳<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="text-muted">Tax (5% VAT)</span>
                            <span>৳<?php echo number_format($tax, 2); ?></span>
                        </div>
                        <div class="summary-row fw-bold fs-6">
                            <span>Total</span>
                            <span class="text-success">৳<?php echo number_format($total, 2); ?></span>
                        </div>
                    </div>

                    <!-- Payment method -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">
                            <i class="fas fa-credit-card me-1 text-muted"></i>Payment Method
                        </label>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="payment_method" id="pmCash" value="cash" checked>
                                <label class="form-check-label small" for="pmCash">
                                    <i class="fas fa-money-bill me-1 text-success"></i>Cash
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="payment_method" id="pmCard" value="card">
                                <label class="form-check-label small" for="pmCard">
                                    <i class="fas fa-credit-card me-1 text-primary"></i>Card
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="payment_method" id="pmDigital" value="digital">
                                <label class="form-check-label small" for="pmDigital">
                                    <i class="fas fa-mobile-alt me-1 text-info"></i>Digital
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Table selection -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">
                            <i class="fas fa-chair me-1 text-muted"></i>Table
                            <span class="text-muted fw-normal">(optional — leave blank for takeaway)</span>
                        </label>
                        <?php if (!empty($tables)): ?>
                        <select name="table_id" class="form-select form-select-sm">
                            <option value="">— No table / Takeaway —</option>
                            <?php foreach ($tables as $t): ?>
                            <option value="<?php echo $t['table_id']; ?>">
                                <?php echo htmlspecialchars($t['table_number']); ?>
                                — <?php echo $t['seating_capacity']; ?> seats
                                <?php echo $t['location'] ? '| '.$t['location'] : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            No tables available right now.
                        </div>
                        <input type="hidden" name="table_id" value="">
                        <?php endif; ?>
                    </div>

                    <!-- Order notes -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold small">
                            <i class="fas fa-sticky-note me-1 text-muted"></i>Order Notes
                            <span class="text-muted fw-normal">(optional)</span>
                        </label>
                        <textarea name="order_notes" class="form-control form-control-sm"
                                  rows="2"
                                  placeholder="Any special request for the whole order…"></textarea>
                    </div>

                    <!-- Place order -->
                    <?php if ($all_available): ?>
                    <button type="submit" name="place_order"
                            formaction="../modules/order_process.php"
                            class="btn btn-success btn-lg w-100 mb-2"
                            onclick="return confirm('Place order for ৳<?php echo number_format($total,2); ?>?')">
                        <i class="fas fa-check-circle me-2"></i>Place Order · ৳<?php echo number_format($total, 2); ?>
                    </button>
                    <?php else: ?>
                    <div class="alert alert-warning small mb-2">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Some items are unavailable. Remove them to continue.
                    </div>
                    <button type="submit" name="place_order"
                            formaction="../modules/order_process.php"
                            class="btn btn-success btn-lg w-100 mb-2" disabled>
                        <i class="fas fa-check-circle me-2"></i>Place Order
                    </button>
                    <?php endif; ?>

                    <a href="../customer/menu.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-arrow-left me-1"></i>Back to Menu
                    </a>
                </div>
            </div>
        </div>

    </div>
    </form>

    <?php else: ?>
    <!-- Empty cart state -->
    <div class="text-center py-5 text-muted">
        <i class="fas fa-shopping-cart fs-1 d-block mb-4 opacity-30"></i>
        <h3>Your cart is empty</h3>
        <p class="mb-4">Browse our menu and add some delicious dishes!</p>
        <a href="../customer/menu.php" class="btn btn-primary btn-lg">
            <i class="fas fa-utensils me-2"></i>Browse Menu
        </a>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<?php include '../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
function changeQty(btn, delta) {
    const input = btn.closest('.d-flex').querySelector('.qty-input');
    let val = parseInt(input.value) || 0;
    val = Math.min(20, Math.max(0, val + delta));
    input.value = val;
}

setTimeout(() => {
    document.querySelectorAll('.alert-success').forEach(a =>
        bootstrap.Alert.getOrCreateInstance(a).close()
    );
}, 4000);
</script>
</body>
</html>