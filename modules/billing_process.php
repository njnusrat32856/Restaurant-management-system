<?php
// modules/billing_process.php - Billing Action Processor
// Called via POST from staff/billing.php.
// No HTML output — validates, processes, then redirects on every path.

ob_start();
session_start();

// ── GUARD: staff / admin only ─────────────────────────────────────
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    header('Location: ../auth/login.php');
    exit();
}

// ── GUARD: POST only ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../staff/billing.php');
    exit();
}

require_once '../config/database.php';

// ── HELPERS ───────────────────────────────────────────────────────

/**
 * Redirect back to billing.php carrying a flash message.
 * $type is 'success' or 'error'.
 * Optional $extra appended to the query string (e.g. '&id=12').
 */
function redirect_billing(string $type, string $msg, string $extra = ''): never
{
    $_SESSION['billing_' . $type] = $msg;
    header('Location: ../staff/billing.php' . $extra);
    exit();
}

/** Re-calculate bill totals from scratch using live order_items rows. */
function recalculate_from_items(PDO $db, int $order_id, float $discount, float $tax_rate): array
{
    $stmt = $db->prepare(
        "SELECT SUM(subtotal) AS subtotal
         FROM order_items
         WHERE order_id = :id"
    );
    $stmt->execute([':id' => $order_id]);
    $row      = $stmt->fetch(PDO::FETCH_ASSOC);
    $subtotal = round((float)($row['subtotal'] ?? 0), 2);
    $tax      = round($subtotal * $tax_rate, 2);
    $discount = min($discount, $subtotal);              // cap discount at subtotal
    $total    = round($subtotal + $tax - $discount, 2);

    return compact('subtotal', 'tax', 'discount', 'total');
}

// ── CONSTANTS ─────────────────────────────────────────────────────
const TAX_RATE        = 0.05;
const VALID_METHODS   = ['cash', 'card', 'digital'];
const VALID_STATUSES  = ['pending', 'paid'];

// ── DETERMINE ACTION ──────────────────────────────────────────────
$action = '';
foreach (['generate_bill','mark_paid','update_bill','delete_bill','void_bill'] as $a) {
    if (isset($_POST[$a])) { $action = $a; break; }
}

if ($action === '') {
    redirect_billing('error', 'No valid action received.');
}

// ── DATABASE ──────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    redirect_billing('error', 'Database connection failed: ' . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════
// ACTION: generate_bill
// Creates a new billing record for an unbilled order.
// Source: staff/billing.php → Generate Bill modal.
// ══════════════════════════════════════════════════════════════════
if ($action === 'generate_bill') {
    $order_id       = intval($_POST['order_id'] ?? 0);
    $discount       = max(0.0, (float)($_POST['discount_amount'] ?? 0));
    $payment_method = in_array($_POST['payment_method'] ?? '', VALID_METHODS)
                      ? $_POST['payment_method'] : 'cash';

    if ($order_id <= 0) {
        redirect_billing('error', 'Invalid order ID.');
    }

    $db->beginTransaction();
    try {
        // ── 1. Lock & validate order ──────────────────────────────
        $chk = $db->prepare(
            "SELECT o.order_id, o.status, o.table_id, o.total_amount
             FROM orders o
             WHERE o.order_id = :id
             FOR UPDATE"                 // row-lock to prevent race
        );
        $chk->execute([':id' => $order_id]);
        $order = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $db->rollBack();
            redirect_billing('error', "Order #$order_id not found.");
        }

        if ($order['status'] === 'cancelled') {
            $db->rollBack();
            redirect_billing('error', "Cannot bill a cancelled order (#$order_id).");
        }

        // ── 2. Check for duplicate ────────────────────────────────
        $dup = $db->prepare(
            "SELECT bill_id FROM billing WHERE order_id = :id"
        );
        $dup->execute([':id' => $order_id]);
        if ($dup->fetch()) {
            $db->rollBack();
            redirect_billing(
                'error',
                "Order #$order_id already has a bill. Edit or delete it instead.",
                '?search=' . $order_id
            );
        }

        // ── 3. Calculate totals from live order_items ─────────────
        $t = recalculate_from_items($db, $order_id, $discount, TAX_RATE);

        // ── 4. Insert billing record ──────────────────────────────
        $ins = $db->prepare(
            "INSERT INTO billing
             (order_id, subtotal, tax_amount, discount_amount,
              total_amount, payment_method, payment_status, bill_date)
             VALUES (:oid, :sub, :tax, :disc, :total, :method, 'pending', NOW())"
        );
        $ins->execute([
            ':oid'    => $order_id,
            ':sub'    => $t['subtotal'],
            ':tax'    => $t['tax'],
            ':disc'   => $t['discount'],
            ':total'  => $t['total'],
            ':method' => $payment_method
        ]);
        $bill_id = (int)$db->lastInsertId();

        // ── 5. Advance order → completed ─────────────────────────
        $db->prepare(
            "UPDATE orders SET status = 'completed'
             WHERE order_id = :id AND status != 'completed'"
        )->execute([':id' => $order_id]);

        // ── 6. Free the table ─────────────────────────────────────
        if ($order['table_id']) {
            $db->prepare(
                "UPDATE restaurant_tables SET status = 'available'
                 WHERE table_id = :id"
            )->execute([':id' => $order['table_id']]);
        }

        $db->commit();

        redirect_billing(
            'success',
            "Bill #$bill_id generated for Order #$order_id. "
            . "Total: ৳" . number_format($t['total'], 2) . ".",
            '?id=' . $bill_id
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_billing('error', 'Bill generation failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// ACTION: mark_paid
// Marks an existing pending bill as paid.
// Also updates payment method if one was submitted.
// ══════════════════════════════════════════════════════════════════
if ($action === 'mark_paid') {
    $bill_id        = intval($_POST['bill_id'] ?? 0);
    $payment_method = in_array($_POST['payment_method'] ?? '', VALID_METHODS)
                      ? $_POST['payment_method'] : null;

    if ($bill_id <= 0) {
        redirect_billing('error', 'Invalid bill ID.');
    }

    $db->beginTransaction();
    try {
        // Validate bill exists and is still pending
        $chk = $db->prepare(
            "SELECT bill_id, payment_status, payment_method
             FROM billing WHERE bill_id = :id
             FOR UPDATE"
        );
        $chk->execute([':id' => $bill_id]);
        $bill = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$bill) {
            $db->rollBack();
            redirect_billing('error', "Bill #$bill_id not found.");
        }

        if ($bill['payment_status'] === 'paid') {
            $db->rollBack();
            redirect_billing('error', "Bill #$bill_id is already paid.");
        }

        $new_method = $payment_method ?? $bill['payment_method'];

        $db->prepare(
            "UPDATE billing
             SET payment_status = 'paid', payment_method = :method
             WHERE bill_id = :id"
        )->execute([':method' => $new_method, ':id' => $bill_id]);

        $db->commit();

        redirect_billing(
            'success',
            "Bill #$bill_id marked as paid. Method: " . ucfirst($new_method) . ".",
            '?id=' . $bill_id
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_billing('error', 'Failed to mark bill paid: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// ACTION: update_bill
// Edits discount and/or payment method on an unpaid bill
// and recalculates totals live from order_items.
// ══════════════════════════════════════════════════════════════════
if ($action === 'update_bill') {
    $bill_id        = intval($_POST['bill_id'] ?? 0);
    $discount       = max(0.0, (float)($_POST['discount_amount'] ?? 0));
    $payment_method = in_array($_POST['payment_method'] ?? '', VALID_METHODS)
                      ? $_POST['payment_method'] : null;

    if ($bill_id <= 0) {
        redirect_billing('error', 'Invalid bill ID.');
    }

    $db->beginTransaction();
    try {
        $chk = $db->prepare(
            "SELECT b.bill_id, b.payment_status, b.payment_method, b.order_id
             FROM billing b
             WHERE b.bill_id = :id
             FOR UPDATE"
        );
        $chk->execute([':id' => $bill_id]);
        $bill = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$bill) {
            $db->rollBack();
            redirect_billing('error', "Bill #$bill_id not found.");
        }

        if ($bill['payment_status'] === 'paid') {
            $db->rollBack();
            redirect_billing('error', "Bill #$bill_id is already paid and cannot be edited.");
        }

        // Recalculate from live order_items
        $t = recalculate_from_items($db, $bill['order_id'], $discount, TAX_RATE);

        $new_method = $payment_method ?? $bill['payment_method'];

        $db->prepare(
            "UPDATE billing
             SET subtotal         = :sub,
                 tax_amount       = :tax,
                 discount_amount  = :disc,
                 total_amount     = :total,
                 payment_method   = :method
             WHERE bill_id = :id"
        )->execute([
            ':sub'    => $t['subtotal'],
            ':tax'    => $t['tax'],
            ':disc'   => $t['discount'],
            ':total'  => $t['total'],
            ':method' => $new_method,
            ':id'     => $bill_id
        ]);

        $db->commit();

        redirect_billing(
            'success',
            "Bill #$bill_id updated. "
            . "Discount: ৳" . number_format($t['discount'], 2)
            . " · Total: ৳" . number_format($t['total'], 2) . ".",
            '?id=' . $bill_id
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_billing('error', 'Bill update failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// ACTION: delete_bill
// Hard-deletes a PENDING bill and restores the order to 'served'
// so staff can re-generate it.  Paid bills cannot be deleted —
// use void_bill instead.
// ══════════════════════════════════════════════════════════════════
if ($action === 'delete_bill') {
    $bill_id = intval($_POST['bill_id'] ?? 0);

    if ($bill_id <= 0) {
        redirect_billing('error', 'Invalid bill ID.');
    }

    $db->beginTransaction();
    try {
        $chk = $db->prepare(
            "SELECT b.bill_id, b.payment_status, b.order_id, o.table_id
             FROM billing b
             JOIN orders o ON o.order_id = b.order_id
             WHERE b.bill_id = :id
             FOR UPDATE"
        );
        $chk->execute([':id' => $bill_id]);
        $bill = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$bill) {
            $db->rollBack();
            redirect_billing('error', "Bill #$bill_id not found.");
        }

        if ($bill['payment_status'] === 'paid') {
            $db->rollBack();
            redirect_billing(
                'error',
                "Bill #$bill_id is already paid and cannot be deleted. "
                . "Use Void instead if this was a mistake."
            );
        }

        // ── Restore order → served so it can be re-billed ────────
        $db->prepare(
            "UPDATE orders SET status = 'served'
             WHERE order_id = :id"
        )->execute([':id' => $bill['order_id']]);

        // ── Mark table occupied again (order is active again) ─────
        if ($bill['table_id']) {
            $db->prepare(
                "UPDATE restaurant_tables SET status = 'occupied'
                 WHERE table_id = :id"
            )->execute([':id' => $bill['table_id']]);
        }

        // ── Delete the bill (CASCADE handles nothing here — billing
        //    has no child tables, but billing → orders is ON DELETE CASCADE
        //    only the other way; safe to delete directly). ─────────
        $db->prepare(
            "DELETE FROM billing WHERE bill_id = :id"
        )->execute([':id' => $bill_id]);

        $db->commit();

        redirect_billing(
            'success',
            "Bill #$bill_id deleted. Order #" . $bill['order_id']
            . " restored to 'Served' — you can now re-generate the bill."
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_billing('error', 'Bill deletion failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// ACTION: void_bill
// Soft-voids a PAID bill by recording a void reason in a session
// flash and marking both the bill and order as cancelled.
// Used when a payment was taken in error.
// Admin-only.
// ══════════════════════════════════════════════════════════════════
if ($action === 'void_bill') {
    // Admin only
    if ($_SESSION['role'] !== 'admin') {
        redirect_billing('error', 'Only admins can void a paid bill.');
    }

    $bill_id     = intval($_POST['bill_id'] ?? 0);
    $void_reason = trim($_POST['void_reason'] ?? '');

    if ($bill_id <= 0) {
        redirect_billing('error', 'Invalid bill ID.');
    }

    if ($void_reason === '') {
        redirect_billing(
            'error',
            'A void reason is required.',
            '?id=' . $bill_id
        );
    }

    $db->beginTransaction();
    try {
        $chk = $db->prepare(
            "SELECT b.bill_id, b.payment_status, b.order_id,
                    b.total_amount, o.table_id
             FROM billing b
             JOIN orders o ON o.order_id = b.order_id
             WHERE b.bill_id = :id
             FOR UPDATE"
        );
        $chk->execute([':id' => $bill_id]);
        $bill = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$bill) {
            $db->rollBack();
            redirect_billing('error', "Bill #$bill_id not found.");
        }

        // ── Void: flip payment_status back to pending, record reason
        //    (we re-use discount_amount note convention via session;
        //     in production you'd add a void_reason column — here we
        //     store it in the flash and reset the bill to pending
        //     so staff can decide next steps). ─────────────────────
        $db->prepare(
            "UPDATE billing
             SET payment_status = 'pending',
                 total_amount   = 0.00
             WHERE bill_id = :id"
        )->execute([':id' => $bill_id]);

        // Mark order cancelled
        $db->prepare(
            "UPDATE orders SET status = 'cancelled'
             WHERE order_id = :id"
        )->execute([':id' => $bill['order_id']]);

        // Free the table
        if ($bill['table_id']) {
            $db->prepare(
                "UPDATE restaurant_tables SET status = 'available'
                 WHERE table_id = :id"
            )->execute([':id' => $bill['table_id']]);
        }

        $db->commit();

        redirect_billing(
            'success',
            "Bill #$bill_id voided (৳" . number_format($bill['total_amount'], 2)
            . "). Reason: $void_reason. Order #" . $bill['order_id'] . " cancelled.",
            '?id=' . $bill_id
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_billing('error', 'Void failed: ' . $e->getMessage());
    }
}

// ── Fallback (should never reach here) ───────────────────────────
redirect_billing('error', 'Unrecognised action.');