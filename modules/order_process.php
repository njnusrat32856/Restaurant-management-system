<?php
// modules/order_process.php - Cart → Order Processing
// Called via POST from customer/cart.php (checkout form).
// No HTML output — redirects on all exit paths.

ob_start();
session_start();

// ── GUARD: customer only ──────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

// ── GUARD: POST only ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['place_order'])) {
    header('Location: ../customer/menu.php');
    exit();
}

// ── GUARD: cart must not be empty ────────────────────────────────
if (empty($_SESSION['cart'])) {
    $_SESSION['order_error'] = 'Your cart is empty. Please add items before placing an order.';
    header('Location: ../customer/menu.php');
    exit();
}

require_once '../config/database.php';

// ── COLLECT POST DATA ─────────────────────────────────────────────
$customer_id    = $_SESSION['user_id'];
$customer_name  = $_SESSION['full_name']  ?? 'Walk-in';
$table_id       = !empty($_POST['table_id'])  ? intval($_POST['table_id'])  : null;
$payment_method = in_array($_POST['payment_method'] ?? '', ['cash','card','digital'])
                  ? $_POST['payment_method'] : 'cash';
$notes          = trim($_POST['order_notes'] ?? '');

// ── PROCESS ───────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();

    // ── 1. Validate every cart item is still available ────────────
    $cart       = $_SESSION['cart'];
    $item_ids   = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($item_ids), '?'));

    $chk = $db->prepare(
        "SELECT item_id, item_name, price, availability
         FROM menu_items
         WHERE item_id IN ($placeholders)"
    );
    $chk->execute($item_ids);
    $db_items = $chk->fetchAll(PDO::FETCH_ASSOC);
    $db_items_map = [];
    foreach ($db_items as $di) {
        $db_items_map[$di['item_id']] = $di;
    }

    $unavailable = [];
    foreach ($cart as $item_id => $ci) {
        if (!isset($db_items_map[$item_id])
            || $db_items_map[$item_id]['availability'] !== 'available') {
            $unavailable[] = $ci['item_name'];
        }
    }

    if (!empty($unavailable)) {
        $_SESSION['order_error'] = 'The following item(s) are no longer available: '
                                   . implode(', ', $unavailable)
                                   . '. Please remove them from your cart and try again.';
        header('Location: ../customer/cart.php');
        exit();
    }

    // ── 2. Validate table if provided ─────────────────────────────
    if ($table_id !== null) {
        $tbl = $db->prepare(
            "SELECT table_id FROM restaurant_tables
             WHERE table_id = :id AND status IN ('available','reserved')"
        );
        $tbl->execute([':id' => $table_id]);
        if (!$tbl->fetch()) {
            $_SESSION['order_error'] = 'The selected table is no longer available.';
            header('Location: ../customer/cart.php');
            exit();
        }
    }

    // ── 3. Calculate totals using live DB prices ──────────────────
    $subtotal = 0.0;
    $line_items = [];

    foreach ($cart as $item_id => $ci) {
        $db_item  = $db_items_map[$item_id];
        $price    = (float)$db_item['price'];    // always use DB price, not session
        $qty      = max(1, (int)$ci['quantity']);
        $line_sub = round($price * $qty, 2);
        $subtotal += $line_sub;

        $line_items[] = [
            'item_id'  => (int)$item_id,
            'quantity' => $qty,
            'price'    => $price,
            'subtotal' => $line_sub,
            'note'     => trim($ci['note'] ?? '')
        ];
    }

    $tax_rate    = 0.05;
    $tax_amount  = round($subtotal * $tax_rate, 2);
    $total       = round($subtotal + $tax_amount, 2);

    // ── 4. Begin transaction ──────────────────────────────────────
    $db->beginTransaction();

    try {
        // ── 5. Insert order ───────────────────────────────────────
        $ins_order = $db->prepare(
            "INSERT INTO orders
             (table_id, staff_id, customer_name, status, total_amount, order_date)
             VALUES (:table_id, NULL, :cname, 'pending', :total, NOW())"
        );
        $ins_order->execute([
            ':table_id' => $table_id,
            ':cname'    => $customer_name,
            ':total'    => $total
        ]);
        $order_id = (int)$db->lastInsertId();

        // ── 6. Insert order_items ─────────────────────────────────
        $ins_item = $db->prepare(
            "INSERT INTO order_items
             (order_id, item_id, quantity, price, subtotal, special_instructions)
             VALUES (:oid, :iid, :qty, :price, :sub, :note)"
        );
        foreach ($line_items as $li) {
            $ins_item->execute([
                ':oid'   => $order_id,
                ':iid'   => $li['item_id'],
                ':qty'   => $li['quantity'],
                ':price' => $li['price'],
                ':sub'   => $li['subtotal'],
                ':note'  => $li['note'] ?: null
            ]);
        }

        // ── 7. Mark table occupied if one was selected ────────────
        if ($table_id !== null) {
            $db->prepare(
                "UPDATE restaurant_tables SET status = 'occupied'
                 WHERE table_id = :id"
            )->execute([':id' => $table_id]);
        }

        // ── 8. Auto-generate a pending bill ──────────────────────
        $ins_bill = $db->prepare(
            "INSERT INTO billing
             (order_id, subtotal, tax_amount, discount_amount,
              total_amount, payment_method, payment_status)
             VALUES (:oid, :sub, :tax, 0.00, :total, :method, 'pending')"
        );
        $ins_bill->execute([
            ':oid'    => $order_id,
            ':sub'    => $subtotal,
            ':tax'    => $tax_amount,
            ':total'  => $total,
            ':method' => $payment_method
        ]);

        // ── 9. Commit ─────────────────────────────────────────────
        $db->commit();

    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;   // re-throw — caught by outer catch
    }

    // ── 10. Clear cart, store confirmation in session ─────────────
    $_SESSION['cart'] = [];
    $_SESSION['order_success'] = [
        'order_id'       => $order_id,
        'total'          => $total,
        'subtotal'       => $subtotal,
        'tax'            => $tax_amount,
        'payment_method' => $payment_method,
        'item_count'     => array_sum(array_column($line_items, 'quantity'))
    ];

    // ── 11. Redirect to confirmation page ─────────────────────────
    header('Location: ../customer/order_confirmation.php?order=' . $order_id);
    exit();

} catch (PDOException $e) {
    $_SESSION['order_error'] = 'Order could not be placed. Please try again. ('
                               . $e->getMessage() . ')';
    header('Location: ../customer/cart.php');
    exit();
}