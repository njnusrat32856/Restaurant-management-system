<?php
// admin/inventory.php - Inventory Management System
ob_start();
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

require_once '../config/database.php';

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db       = $database->connect();

    // ── ADD ITEM ──
    if (isset($_POST['add_item'])) {
        try {
            $item_name     = trim($_POST['item_name']);
            $quantity      = intval($_POST['quantity']);
            $unit          = trim($_POST['unit']);
            $reorder_level = intval($_POST['reorder_level']);

            $stmt = $db->prepare(
                "INSERT INTO inventory (item_name, quantity, unit, reorder_level, last_updated)
                 VALUES (:name, :qty, :unit, :reorder, NOW())"
            );
            $stmt->execute([
                ':name'    => $item_name,
                ':qty'     => $quantity,
                ':unit'    => $unit,
                ':reorder' => $reorder_level
            ]);
            $success_message = "Item '{$item_name}' added successfully!";
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }

    // ── UPDATE ITEM ──
    if (isset($_POST['update_item'])) {
        try {
            $inventory_id  = intval($_POST['inventory_id']);
            $item_name     = trim($_POST['item_name']);
            $quantity      = intval($_POST['quantity']);
            $unit          = trim($_POST['unit']);
            $reorder_level = intval($_POST['reorder_level']);

            $stmt = $db->prepare(
                "UPDATE inventory
                 SET item_name = :name, quantity = :qty, unit = :unit,
                     reorder_level = :reorder, last_updated = NOW()
                 WHERE inventory_id = :id"
            );
            $stmt->execute([
                ':id'      => $inventory_id,
                ':name'    => $item_name,
                ':qty'     => $quantity,
                ':unit'    => $unit,
                ':reorder' => $reorder_level
            ]);
            $success_message = "Item '{$item_name}' updated successfully!";
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }

    // ── DELETE ITEM ──
    if (isset($_POST['delete_item'])) {
        try {
            $inventory_id = intval($_POST['inventory_id']);
            $stmt = $db->prepare("DELETE FROM inventory WHERE inventory_id = :id");
            $stmt->execute([':id' => $inventory_id]);
            $success_message = 'Inventory item deleted successfully!';
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }

    // ── ADJUST STOCK ──
        if (isset($_POST['adjust_stock'])) {
        try {
            $inventory_id = intval($_POST['inventory_id']);
            $adjustment   = intval($_POST['adjustment']);
            $action       = $_POST['action']; // 'add' or 'remove'

            $stmt = $db->prepare("SELECT quantity FROM inventory WHERE inventory_id = :id");
            $stmt->execute([':id' => $inventory_id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($current) {
                $new_qty = $action === 'add'
                    ? $current['quantity'] + $adjustment
                    : $current['quantity'] - $adjustment;
                
                if ($new_qty < 0) $new_qty = 0;

                $stmt = $db->prepare(
                    "UPDATE inventory SET quantity = :qty, last_updated = NOW()
                     WHERE inventory_id = :id"
                );
                $stmt->execute([':qty' => $new_qty, ':id' => $inventory_id]);
                $success_message = 'Stock adjusted successfully!';
            }
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── FETCH DATA ──
try {
    $database = new Database();
    $db       = $database->connect();

    $filter_status = $_GET['status'] ?? 'all';
    $search_query  = $_GET['search'] ?? '';

    $query = "SELECT * FROM inventory WHERE 1=1";

    if ($filter_status == 'low_stock') {
        $query .= " AND quantity <= reorder_level";
    } elseif ($filter_status == 'out_of_stock') {
        $query .= " AND quantity = 0";
    } elseif ($filter_status == 'in_stock') {
        $query .= " AND quantity > reorder_level";
    }

    if (!empty($search_query)) {
        $query .= " AND item_name LIKE :search";
    }

    $query .= " ORDER BY item_name";

    $stmt = $db->prepare($query);

    if (!empty($search_query)) {
        $search_param = "%{$search_query}%";
        $stmt->bindParam(':search', $search_param);
    }

    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── STATISTICS ──
    $stats = $db->query(
        "SELECT
            COUNT(*) AS total_items,
            SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
            SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END) AS low_stock,
            SUM(CASE WHEN quantity > reorder_level THEN 1 ELSE 0 END) AS in_stock
         FROM inventory"
    )->fetch(PDO::FETCH_ASSOC);

    // ── LOW STOCK ALERTS ──
        $alerts = $db->query(
        "SELECT * FROM inventory
         WHERE quantity <= reorder_level
         ORDER BY quantity ASC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    $items = $alerts = [];
    $stats = ['total_items'=>0,'out_of_stock'=>0,'low_stock'=>0,'in_stock'=>0];
}

$page_title = 'Inventory Management';
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
.stat-card {
    border-radius: 15px;
    padding: 24px;
    transition: transform .3s;
    border: none;
}
.stat-card:hover { transform: translateY(-5px); }

.inventory-card {
    border-radius: 15px;
    overflow: hidden;
    transition: all .3s;
    border: none;
    height: 100%;
}
.inventory-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,.15) !important;
}

.stock-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: .85rem;
    font-weight: 600;
}

.stock-level {
    height: 8px;
    border-radius: 4px;
    background: #e9ecef;
    overflow: hidden;
    position: relative;
}
.stock-level-fill {
    height: 100%;
    transition: width .3s;
}

.qty-control {
    border-radius: 10px;
    background: #f8f9fa;
    padding: 8px;
}
.qty-control input {
    width: 60px;
    text-align: center;
    border: 2px solid #dee2e6;
    border-radius: 6px;
}
.qty-control .btn {
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 6px;
}

.alert-item {
    border-left: 4px solid;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 10px;
    background: #fff;
    transition: all .2s;
}
.alert-item:hover {
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.alert-item.critical { border-color: #dc3545; }
.alert-item.warning  { border-color: #ffc107; }

.unit-badge {
    background: #e9ecef;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .75rem;
    font-weight: 600;
    color: #495057;
}

.action-btn {
    width: 36px;
    height: 36px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}
</style>
</head>
<body>
<?php include '../includes/navbar.php'; ?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-boxes text-primary me-2"></i>Inventory Management
            </h2>
            <p class="text-muted mb-0">Track and manage stock levels</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="fas fa-plus me-2"></i>Add Inventory Item
        </button>
    </div>

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

    <div class="row g-4 mb-4">
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['total_items']; ?></div>
                        <div class="opacity-90">Total Items</div>
                    </div>
                    <i class="fas fa-boxes fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['in_stock']; ?></div>
                        <div class="opacity-90">In Stock</div>
                    </div>
                    <i class="fas fa-check-circle fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['low_stock']; ?></div>
                        <div class="opacity-90">Low Stock</div>
                    </div>
                    <i class="fas fa-exclamation-triangle fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card stat-card shadow-sm text-white" style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-3 fw-bold"><?php echo $stats['out_of_stock']; ?></div>
                        <div class="opacity-90">Out of Stock</div>
                    </div>
                    <i class="fas fa-times-circle fa-3x opacity-40"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-8">

            <div class="card shadow-sm mb-4" style="border-radius:15px;">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <form method="GET" class="d-flex">
                                <input type="text" name="search" class="form-control me-2"
                                       placeholder="Search items..."
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2 flex-wrap justify-content-md-end">
                                <a href="?" class="btn btn-sm <?php echo $filter_status=='all' ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
                                <a href="?status=in_stock" class="btn btn-sm <?php echo $filter_status=='in_stock' ? 'btn-success' : 'btn-outline-success'; ?>">In Stock</a>
                                <a href="?status=low_stock" class="btn btn-sm <?php echo $filter_status=='low_stock' ? 'btn-warning' : 'btn-outline-warning'; ?>">Low Stock</a>
                                <a href="?status=out_of_stock" class="btn btn-sm <?php echo $filter_status=='out_of_stock' ? 'btn-danger' : 'btn-outline-danger'; ?>">Out of Stock</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
            <?php if (!empty($items)):
                foreach ($items as $item):
                $pct = $item['reorder_level'] > 0
                    ? min(100, ($item['quantity'] / ($item['reorder_level'] * 2)) * 100)
                    : 100;
                $status_color = $item['quantity'] == 0 ? 'danger'
                    : ($item['quantity'] <= $item['reorder_level'] ? 'warning' : 'success');
                $status_text = $item['quantity'] == 0 ? 'Out of Stock'
                    : ($item['quantity'] <= $item['reorder_level'] ? 'Low Stock' : 'In Stock');
            ?>
                <div class="col-md-6">
                    <div class="card inventory-card shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-1"><?php echo htmlspecialchars($item['item_name']); ?></h5>
                                    <span class="unit-badge"><?php echo htmlspecialchars($item['unit']); ?></span>
                                </div>
                                <span class="stock-badge bg-<?php echo $status_color; ?> text-white">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>

                            <div class="d-flex align-items-center mb-3">
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted">Current Stock</small>
                                        <small class="text-muted">Reorder at: <?php echo $item['reorder_level']; ?></small>
                                    </div>
                                    <div class="stock-level">
                                        <div class="stock-level-fill bg-<?php echo $status_color; ?>"
                                             style="width:<?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                                <div class="ms-3 text-end">
                                    <div class="fs-2 fw-bold text-<?php echo $status_color; ?>">
                                        <?php echo $item['quantity']; ?>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" class="qty-control mb-3">
                                <input type="hidden" name="inventory_id" value="<?php echo $item['inventory_id']; ?>">
                                <div class="d-flex align-items-center gap-2">
                                    <button type="submit" name="adjust_stock" onclick="this.form.action.value='remove'"
                                            class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-minus"></i>
                                    </button>
                                    <input type="number" name="adjustment" value="1" min="1" required>
                                    <input type="hidden" name="action" value="add">
                                    <button type="submit" name="adjust_stock" onclick="this.form.action.value='add'"
                                            class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <small class="text-muted ms-2">Quick adjust</small>
                                </div>
                            </form>

                            <div class="d-flex gap-2">
                                <button class="btn btn-primary btn-sm flex-fill"
                                        onclick='editItem(<?php echo json_encode($item); ?>)'>
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                                <button class="btn btn-danger btn-sm flex-fill"
                                        onclick="deleteItem(<?php echo $item['inventory_id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')">
                                    <i class="fas fa-trash me-1"></i>Delete
                                </button>
                            </div>

                            <div class="mt-2 text-end">
                                <small class="text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    Last updated: <?php echo date('M j, g:i A', strtotime($item['last_updated'])); ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach;
            else: ?>
                <div class="col-12 text-center py-5 text-muted">
                    <i class="fas fa-boxes fs-1 d-block mb-3"></i>
                    <h4>No inventory items found</h4>
                    <p>Add your first item to start tracking</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                        <i class="fas fa-plus me-2"></i>Add First Item
                    </button>
                </div>
            <?php endif; ?>
            </div>

        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm" style="border-radius:15px;">
                <div class="card-header bg-white border-0" style="border-radius:15px 15px 0 0 !important;">
                    <h5 class="mb-0">
                        <i class="fas fa-bell text-warning me-2"></i>
                        Stock Alerts
                        <?php if (count($alerts) > 0): ?>
                        <span class="badge bg-danger ms-2"><?php echo count($alerts); ?></span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($alerts)): ?>
                        <?php foreach ($alerts as $a):
                            $is_critical = $a['quantity'] == 0;
                        ?>
                        <div class="alert-item <?php echo $is_critical ? 'critical' : 'warning'; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?php echo htmlspecialchars($a['item_name']); ?></div>
                                    <small class="text-muted">
                                        <i class="fas fa-<?php echo $is_critical ? 'times' : 'exclamation'; ?>-circle me-1"></i>
                                        <?php if ($is_critical): ?>
                                            Out of stock - Order now!
                                        <?php else: ?>
                                            Only <?php echo $a['quantity']; ?> <?php echo $a['unit']; ?> left
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <button class="btn btn-sm btn-<?php echo $is_critical ? 'danger' : 'warning'; ?>"
                                        onclick='editItem(<?php echo json_encode($a); ?>)'
                                        title="Restock">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fs-3 text-success d-block mb-2"></i>
                            <p class="mb-0">All items are well stocked!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mt-4" style="border-radius:15px;">
                <div class="card-header bg-white border-0">
                    <h6 class="mb-0"><i class="fas fa-chart-pie text-info me-2"></i>Quick Stats</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Total Items</span>
                            <span class="fw-bold"><?php echo $stats['total_items']; ?></span>
                        </div>
                    </div>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between">
                            <span class="text-success">In Stock</span>
                            <span class="fw-bold text-success"><?php echo $stats['in_stock']; ?></span>
                        </div>
                    </div>
                    <div class="mb-3 pb-3 border-bottom">
                        <div class="d-flex justify-content-between">
                            <span class="text-warning">Low Stock</span>
                            <span class="fw-bold text-warning"><?php echo $stats['low_stock']; ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between">
                            <span class="text-danger">Out of Stock</span>
                            <span class="fw-bold text-danger"><?php echo $stats['out_of_stock']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div><!-- /container -->

<!-- ══ ADD MODAL ══ -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle text-primary me-2"></i>Add Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-validate="true">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Item Name *</label>
                            <input type="text" name="item_name" class="form-control"
                                   placeholder="e.g. Tomatoes, Rice, Cooking Oil" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" class="form-control"
                                   min="0" placeholder="e.g. 100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit *</label>
                            <input type="text" name="unit" class="form-control"
                                   list="unitList" placeholder="e.g. kg, lbs, pcs" required>
                            <datalist id="unitList">
                                <option value="kg">
                                <option value="lbs">
                                <option value="pcs">
                                <option value="liters">
                                <option value="bottles">
                                <option value="boxes">
                                <option value="bags">
                                <option value="cans">
                            </datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reorder Level *</label>
                            <input type="number" name="reorder_level" class="form-control"
                                   min="0" placeholder="Alert when stock reaches this level" required>
                            <small class="text-muted">You'll be notified when quantity falls to this level</small>
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

<!-- ══ EDIT MODAL ══ -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit text-primary me-2"></i>Edit Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" data-validate="true">
                <input type="hidden" name="inventory_id" id="edit_inventory_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Item Name *</label>
                            <input type="text" name="item_name" id="edit_item_name"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="quantity" id="edit_quantity"
                                   class="form-control" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit *</label>
                            <input type="text" name="unit" id="edit_unit"
                                   class="form-control" list="unitList" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reorder Level *</label>
                            <input type="number" name="reorder_level" id="edit_reorder_level"
                                   class="form-control" min="0" required>
                        </div>
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


<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="inventory_id" id="del_inventory_id">
    <input type="hidden" name="delete_item" value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<!-- <script src="../assets/js/validation.js"></script> -->
<script>
function editItem(item) {
    document.getElementById('edit_inventory_id').value  = item.inventory_id;
    document.getElementById('edit_item_name').value     = item.item_name;
    document.getElementById('edit_quantity').value      = item.quantity;
    document.getElementById('edit_unit').value          = item.unit;
    document.getElementById('edit_reorder_level').value = item.reorder_level;
    new bootstrap.Modal(document.getElementById('editItemModal')).show();
}

function deleteItem(id, name) {
    const msg = `Are you sure you want to delete <strong>${name}</strong>?<br><small class="text-muted">This action cannot be undone.</small>`;
    if (typeof RMS !== 'undefined' && RMS.confirmModal) {
        RMS.confirmModal({
            title: 'Delete Inventory Item',
            message: msg,
            confirmText: 'Delete',
            confirmClass: 'btn-danger',
            onConfirm: () => {
                document.getElementById('del_inventory_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        });
    } else {
        if (confirm(`Delete ${name}?`)) {
            document.getElementById('del_inventory_id').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
}

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        bootstrap.Alert.getOrCreateInstance(a).close();
    });
}, 5000);
</script>
</body>
</html>