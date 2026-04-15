<?php
// modules/reservation_process.php - Reservation Action Processor
// Called via POST from customer/reservation.php.
// No HTML output — validates, processes, then redirects on every path.

ob_start();
session_start();

// ── GUARD: customer only ──────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../auth/login.php');
    exit();
}

// ── GUARD: POST only ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../customer/reservation.php');
    exit();
}

require_once '../config/database.php';

$customer_id = (int)$_SESSION['user_id'];

// ── HELPER ────────────────────────────────────────────────────────
/**
 * Redirect back to reservation.php carrying a flash message.
 * $type    : 'success' | 'error'
 * $extra   : optional query string suffix e.g. '&id=12&status=upcoming'
 */
function redirect_reservation(string $type, string $msg, string $extra = ''): never
{
    $_SESSION['reservation_' . $type] = $msg;
    header('Location: ../customer/reservation.php' . $extra);
    exit();
}

// ── DETERMINE ACTION ──────────────────────────────────────────────
$action = '';
foreach (['make_reservation', 'cancel_reservation', 'update_reservation'] as $a) {
    if (isset($_POST[$a])) { $action = $a; break; }
}

if ($action === '') {
    redirect_reservation('error', 'No valid action received.');
}

// ── DATABASE ──────────────────────────────────────────────────────
try {
    $database = new Database();
    $db       = $database->connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    redirect_reservation('error', 'Database connection failed: ' . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════
// ACTION: make_reservation
// Creates a new reservation for the logged-in customer.
// Source: customer/reservation.php → booking form.
// ══════════════════════════════════════════════════════════════════
if ($action === 'make_reservation') {

    $table_id    = intval($_POST['table_id'] ?? 0);
    $res_date    = trim($_POST['reservation_date'] ?? '');
    $res_time    = trim($_POST['reservation_time'] ?? '');
    $guests      = max(1, intval($_POST['number_of_guests'] ?? 1));
    $special_req = trim($_POST['special_requests'] ?? '');

    // ── Basic input validation ────────────────────────────────────
    if ($table_id <= 0) {
        redirect_reservation('error', 'Please select a table before confirming.');
    }

    if (empty($res_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $res_date)) {
        redirect_reservation('error', 'Invalid reservation date.');
    }

    if (empty($res_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $res_time)) {
        redirect_reservation('error', 'Invalid reservation time.');
    }

    // Date must not be in the past
    if (strtotime($res_date) < strtotime(date('Y-m-d'))) {
        redirect_reservation('error', 'Reservation date cannot be in the past.');
    }

    $db->beginTransaction();
    try {
        // ── 1. Lock & validate table ──────────────────────────────
        $tbl = $db->prepare(
            "SELECT table_id, table_number, seating_capacity, location, status
             FROM restaurant_tables
             WHERE table_id = :id
             FOR UPDATE"
        );
        $tbl->execute([':id' => $table_id]);
        $table = $tbl->fetch(PDO::FETCH_ASSOC);

        if (!$table) {
            $db->rollBack();
            redirect_reservation('error', 'Selected table does not exist.');
        }

        // ── 2. Guest capacity check ───────────────────────────────
        if ($guests > (int)$table['seating_capacity']) {
            $db->rollBack();
            redirect_reservation(
                'error',
                'Number of guests (' . $guests . ') exceeds this table\'s capacity '
                . '(' . $table['seating_capacity'] . ' seats). '
                . 'Please choose a larger table or reduce your guest count.'
            );
        }

        // ── 3. Conflict check: same table, same date, within ±60 min
        $conflict = $db->prepare(
            "SELECT reservation_id
             FROM reservations
             WHERE table_id = :table
               AND reservation_date = :date
               AND status IN ('pending','confirmed')
               AND ABS(TIMESTAMPDIFF(
                       MINUTE,
                       CONCAT(:date2, ' ', :time),
                       CONCAT(reservation_date, ' ', reservation_time)
                   )) < 60"
        );
        $conflict->execute([
            ':table' => $table_id,
            ':date'  => $res_date,
            ':date2' => $res_date,
            ':time'  => $res_time
        ]);

        if ($conflict->fetch()) {
            $db->rollBack();
            redirect_reservation(
                'error',
                'Table ' . $table['table_number'] . ' already has a reservation '
                . 'within one hour of ' . date('h:i A', strtotime($res_time))
                . ' on ' . date('D, d M Y', strtotime($res_date)) . '. '
                . 'Please choose a different time or table.'
            );
        }

        // ── 4. Check customer hasn't already booked this slot ─────
        $self_conflict = $db->prepare(
            "SELECT reservation_id
             FROM reservations
             WHERE customer_id = :cid
               AND reservation_date = :date
               AND status IN ('pending','confirmed')
               AND ABS(TIMESTAMPDIFF(
                       MINUTE,
                       CONCAT(:date2, ' ', :time),
                       CONCAT(reservation_date, ' ', reservation_time)
                   )) < 60"
        );
        $self_conflict->execute([
            ':cid'   => $customer_id,
            ':date'  => $res_date,
            ':date2' => $res_date,
            ':time'  => $res_time
        ]);

        if ($self_conflict->fetch()) {
            $db->rollBack();
            redirect_reservation(
                'error',
                'You already have a reservation around that time. '
                . 'Please check your upcoming reservations.'
            );
        }

        // ── 5. Insert reservation ─────────────────────────────────
        $ins = $db->prepare(
            "INSERT INTO reservations
             (customer_id, table_id, reservation_date, reservation_time,
              number_of_guests, status, special_requests, created_at)
             VALUES (:cid, :tid, :date, :time, :guests, 'pending', :req, NOW())"
        );
        $ins->execute([
            ':cid'    => $customer_id,
            ':tid'    => $table_id,
            ':date'   => $res_date,
            ':time'   => $res_time,
            ':guests' => $guests,
            ':req'    => $special_req ?: null
        ]);
        $new_id = (int)$db->lastInsertId();

        // ── 6. Mark table reserved (only if currently available) ──
        $db->prepare(
            "UPDATE restaurant_tables
             SET status = 'reserved'
             WHERE table_id = :id AND status = 'available'"
        )->execute([':id' => $table_id]);

        $db->commit();

        $friendly_date = date('D, d M Y', strtotime($res_date));
        $friendly_time = date('h:i A',    strtotime($res_time));

        redirect_reservation(
            'success',
            'Reservation #' . str_pad($new_id, 4, '0', STR_PAD_LEFT)
            . ' made for ' . $friendly_date . ' at ' . $friendly_time
            . ' — Table ' . $table['table_number']
            . ($table['location'] ? ' (' . $table['location'] . ')' : '')
            . '. We\'ll confirm your booking shortly!',
            '?id=' . $new_id . '&status=upcoming'
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_reservation('error', 'Reservation could not be saved: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// ACTION: cancel_reservation
// Cancels a customer's own pending/confirmed reservation.
// Source: customer/reservation.php → hidden cancel form.
// ══════════════════════════════════════════════════════════════════
if ($action === 'cancel_reservation') {

    $res_id = intval($_POST['reservation_id'] ?? 0);

    if ($res_id <= 0) {
        redirect_reservation('error', 'Invalid reservation ID.');
    }

    $db->beginTransaction();
    try {
        // ── 1. Lock & verify ownership ────────────────────────────
        $chk = $db->prepare(
            "SELECT r.reservation_id, r.table_id, r.status,
                    r.reservation_date, r.reservation_time
             FROM reservations r
             WHERE r.reservation_id = :id
               AND r.customer_id    = :cid
             FOR UPDATE"
        );
        $chk->execute([':id' => $res_id, ':cid' => $customer_id]);
        $res = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            $db->rollBack();
            redirect_reservation('error', 'Reservation not found or does not belong to you.');
        }

        if (!in_array($res['status'], ['pending', 'confirmed'])) {
            $db->rollBack();
            redirect_reservation(
                'error',
                'Reservation #' . str_pad($res_id, 4, '0', STR_PAD_LEFT)
                . ' cannot be cancelled — it is already ' . $res['status'] . '.'
            );
        }

        // ── 2. Cancel it ──────────────────────────────────────────
        $db->prepare(
            "UPDATE reservations
             SET status = 'cancelled'
             WHERE reservation_id = :id"
        )->execute([':id' => $res_id]);

        // ── 3. Free the table only if no other active reservation
        //       is using it (a table may have multiple future slots) ─
        $other = $db->prepare(
            "SELECT reservation_id
             FROM reservations
             WHERE table_id        = :tid
               AND status          IN ('pending','confirmed')
               AND reservation_id  != :rid"
        );
        $other->execute([':tid' => $res['table_id'], ':rid' => $res_id]);

        if (!$other->fetch()) {
            $db->prepare(
                "UPDATE restaurant_tables
                 SET status = 'available'
                 WHERE table_id = :id AND status = 'reserved'"
            )->execute([':id' => $res['table_id']]);
        }

        $db->commit();

        redirect_reservation(
            'success',
            'Reservation #' . str_pad($res_id, 4, '0', STR_PAD_LEFT)
            . ' (' . date('D, d M Y', strtotime($res['reservation_date']))
            . ' at ' . date('h:i A', strtotime($res['reservation_time']))
            . ') has been cancelled.',
            '?status=past'
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_reservation('error', 'Cancellation failed: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════
// ACTION: update_reservation
// Edits date, time, guest count, or special requests on a
// pending (not yet confirmed) reservation owned by this customer.
// Source: customer/reservation.php → edit modal form.
// ══════════════════════════════════════════════════════════════════
if ($action === 'update_reservation') {

    $res_id      = intval($_POST['reservation_id'] ?? 0);
    $table_id    = intval($_POST['table_id']        ?? 0);
    $res_date    = trim($_POST['reservation_date']  ?? '');
    $res_time    = trim($_POST['reservation_time']  ?? '');
    $guests      = max(1, intval($_POST['number_of_guests'] ?? 1));
    $special_req = trim($_POST['special_requests']  ?? '');

    if ($res_id <= 0) {
        redirect_reservation('error', 'Invalid reservation ID.');
    }

    if ($table_id <= 0) {
        redirect_reservation('error', 'Please select a table.', '?id=' . $res_id);
    }

    if (empty($res_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $res_date)) {
        redirect_reservation('error', 'Invalid reservation date.', '?id=' . $res_id);
    }

    if (empty($res_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $res_time)) {
        redirect_reservation('error', 'Invalid reservation time.', '?id=' . $res_id);
    }

    if (strtotime($res_date) < strtotime(date('Y-m-d'))) {
        redirect_reservation('error', 'Reservation date cannot be in the past.', '?id=' . $res_id);
    }

    $db->beginTransaction();
    try {
        // ── 1. Lock & verify ownership + editability ──────────────
        $chk = $db->prepare(
            "SELECT r.reservation_id, r.table_id AS old_table_id,
                    r.status, r.reservation_date, r.reservation_time
             FROM reservations r
             WHERE r.reservation_id = :id
               AND r.customer_id    = :cid
             FOR UPDATE"
        );
        $chk->execute([':id' => $res_id, ':cid' => $customer_id]);
        $res = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            $db->rollBack();
            redirect_reservation('error', 'Reservation not found or does not belong to you.');
        }

        // Only pending reservations can be self-edited; confirmed ones
        // need staff involvement.
        if ($res['status'] !== 'pending') {
            $db->rollBack();
            redirect_reservation(
                'error',
                'Only pending reservations can be edited. '
                . 'Your reservation is currently ' . $res['status'] . '. '
                . 'Please contact us to make changes.',
                '?id=' . $res_id
            );
        }

        // ── 2. Validate new table capacity ────────────────────────
        $tbl = $db->prepare(
            "SELECT table_id, table_number, seating_capacity, location, status
             FROM restaurant_tables
             WHERE table_id = :id
             FOR UPDATE"
        );
        $tbl->execute([':id' => $table_id]);
        $table = $tbl->fetch(PDO::FETCH_ASSOC);

        if (!$table) {
            $db->rollBack();
            redirect_reservation('error', 'Selected table does not exist.', '?id=' . $res_id);
        }

        if ($guests > (int)$table['seating_capacity']) {
            $db->rollBack();
            redirect_reservation(
                'error',
                'Number of guests (' . $guests . ') exceeds this table\'s capacity '
                . '(' . $table['seating_capacity'] . ' seats).',
                '?id=' . $res_id
            );
        }

        // ── 3. Conflict check on new slot (exclude this reservation) ─
        $conflict = $db->prepare(
            "SELECT reservation_id
             FROM reservations
             WHERE table_id        = :table
               AND reservation_date = :date
               AND reservation_id  != :rid
               AND status          IN ('pending','confirmed')
               AND ABS(TIMESTAMPDIFF(
                       MINUTE,
                       CONCAT(:date2, ' ', :time),
                       CONCAT(reservation_date, ' ', reservation_time)
                   )) < 60"
        );
        $conflict->execute([
            ':table' => $table_id,
            ':date'  => $res_date,
            ':rid'   => $res_id,
            ':date2' => $res_date,
            ':time'  => $res_time
        ]);

        if ($conflict->fetch()) {
            $db->rollBack();
            redirect_reservation(
                'error',
                'Table ' . $table['table_number']
                . ' has a conflicting reservation around '
                . date('h:i A', strtotime($res_time))
                . ' on ' . date('D, d M Y', strtotime($res_date)) . '.',
                '?id=' . $res_id
            );
        }

        // ── 4. Update reservation row ─────────────────────────────
        $db->prepare(
            "UPDATE reservations
             SET table_id         = :tid,
                 reservation_date = :date,
                 reservation_time = :time,
                 number_of_guests = :guests,
                 special_requests = :req
             WHERE reservation_id = :id"
        )->execute([
            ':tid'    => $table_id,
            ':date'   => $res_date,
            ':time'   => $res_time,
            ':guests' => $guests,
            ':req'    => $special_req ?: null,
            ':id'     => $res_id
        ]);

        // ── 5. Table status housekeeping ──────────────────────────
        $old_table_id = (int)$res['old_table_id'];

        // If table changed, check if old table still has active reservations
        if ($old_table_id !== $table_id) {
            $still_used = $db->prepare(
                "SELECT reservation_id
                 FROM reservations
                 WHERE table_id = :tid
                   AND status   IN ('pending','confirmed')
                   AND reservation_id != :rid"
            );
            $still_used->execute([':tid' => $old_table_id, ':rid' => $res_id]);

            if (!$still_used->fetch()) {
                $db->prepare(
                    "UPDATE restaurant_tables
                     SET status = 'available'
                     WHERE table_id = :id AND status = 'reserved'"
                )->execute([':id' => $old_table_id]);
            }

            // Mark new table reserved if currently available
            $db->prepare(
                "UPDATE restaurant_tables
                 SET status = 'reserved'
                 WHERE table_id = :id AND status = 'available'"
            )->execute([':id' => $table_id]);
        }

        $db->commit();

        redirect_reservation(
            'success',
            'Reservation #' . str_pad($res_id, 4, '0', STR_PAD_LEFT)
            . ' updated: ' . date('D, d M Y', strtotime($res_date))
            . ' at ' . date('h:i A', strtotime($res_time))
            . ' — Table ' . $table['table_number']
            . ($table['location'] ? ' (' . $table['location'] . ')' : '')
            . ' for ' . $guests . ' guest' . ($guests != 1 ? 's' : '') . '.',
            '?id=' . $res_id . '&status=upcoming'
        );

    } catch (PDOException $e) {
        $db->rollBack();
        redirect_reservation('error', 'Update failed: ' . $e->getMessage(), '?id=' . $res_id);
    }
}

// ── Fallback (should never reach here) ───────────────────────────
redirect_reservation('error', 'Unrecognised action.');