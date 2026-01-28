<?php
// File: refrigerator-stock.php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();
$is_manager_or_higher = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

$success = null;
$error = null;

// Tentukan tanggal filter default (Hari Ini)
$selected_date = $_GET['filter_date'] ?? date('Y-m-d');
$filter_date_sql = $selected_date;

// Handle form submission for deposit or withdraw
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    $quantities = $_POST['quantities'] ?? [];
    $has_input = false;
    
    // Filter hanya produk dengan kuantitas > 0
    $items_to_process = array_filter($quantities, fn($qty) => (int)$qty > 0);
    
    if (empty($items_to_process)) {
        $error = "Pilih minimal satu produk dengan jumlah lebih dari 0!";
    } else {
        $conn->begin_transaction();
        $successful_logs = 0;
        $processed_items_list = [];

        try {
            foreach ($items_to_process as $product_name => $quantity) {
                $quantity = (int)$quantity;
                
                // 1. Get current stock (FOR UPDATE prevents race conditions)
                $stmt = $conn->prepare("SELECT quantity FROM refrigerator_stock WHERE product_name = ? FOR UPDATE");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query: " . $conn->error);
                }
                $stmt->bind_param("s", $product_name);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $current_quantity = $result['quantity'] ?? 0;
                $stmt->close();
    
                $new_quantity = $current_quantity;
                $transaction_type = '';

                if ($action === 'deposit') {
                    $new_quantity += $quantity;
                    $transaction_type = 'deposit';
                } elseif ($action === 'withdraw') {
                    if ($current_quantity < $quantity) {
                        throw new Exception("Stok produk " . ucwords(str_replace('_', ' ', $product_name)) . " tidak mencukupi! Stok: {$current_quantity}.");
                    }
                    $new_quantity -= $quantity;
                    $transaction_type = 'withdraw';
                } else {
                    throw new Exception("Aksi tidak valid.");
                }

                // 2. Update stock
                $stmt = $conn->prepare("UPDATE refrigerator_stock SET quantity = ? WHERE product_name = ?");
                if (!$stmt) {
                     throw new Exception("Gagal menyiapkan query update stok: " . $conn->error);
                }
                $stmt->bind_param("is", $new_quantity, $product_name);
                $stmt->execute();
                $stmt->close();

                // 3. Log transaction
                $stmt = $conn->prepare("INSERT INTO refrigerator_transactions (product_name, employee_id, transaction_type, quantity) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query log transaksi: " . $conn->error);
                }
                $stmt->bind_param("sisi", $product_name, $user['id'], $transaction_type, $quantity);
                if (!$stmt->execute()) {
                    throw new Exception("Gagal menyimpan log transaksi: " . $stmt->error);
                }
                $stmt->close();
                
                $successful_logs++;
                $processed_items_list[] = htmlspecialchars(ucwords(str_replace('_', ' ', $product_name))) . " (x{$quantity})";
            }
            
            $conn->commit();
            $success = "Transaksi " . ucfirst($action) . " berhasil untuk {$successful_logs} item: " . implode(', ', $processed_items_list);
            
            // Kirim notifikasi Discord untuk stok kulkas
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'product_list' => $items_to_process, // Kirim array product_name => quantity
            ], "refrigerator_{$action}");
            
            // Redirect untuk menampilkan pesan sukses dan memuat ulang data
            header("Location: refrigerator-stock.php?msg=" . urlencode($success) . "&type=success&filter_date=" . urlencode($selected_date));
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Gagal memproses transaksi: " . $e->getMessage();
            // Redirect untuk menampilkan pesan error
            header("Location: refrigerator-stock.php?msg=" . urlencode($error) . "&type=error&filter_date=" . urlencode($selected_date));
            exit;
        }
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}

// --- LOGIKA PENGAMBILAN DATA UNTUK FILTER ---

// 1. Get current stock levels
// UPDATE: Menggunakan CASE untuk mengurutkan sesuai menu (Western -> Nusantara -> Kids -> Happy Bites -> Royale)
$stock_levels = $conn->query("
    SELECT * FROM refrigerator_stock 
    ORDER BY 
    CASE 
        WHEN product_name LIKE '%Western%' THEN 1
        WHEN product_name LIKE '%Nusantara%' THEN 2
        WHEN product_name LIKE '%Kids%' THEN 3
        WHEN product_name LIKE '%Happy%' THEN 4
        WHEN product_name LIKE '%Royale%' THEN 5
        ELSE 6 
    END, product_name ASC
")->fetch_all(MYSQLI_ASSOC);

// 2. Get Transaction Summary for the selected date
$transaction_summary = [
    'deposit' => 0,
    'withdraw' => 0,
    'details' => []
];

$stmt_summary = $conn->prepare("
    SELECT product_name, transaction_type, SUM(quantity) as total_quantity
    FROM refrigerator_transactions
    WHERE DATE(transaction_at) = ?
    GROUP BY product_name, transaction_type
");
if ($stmt_summary) {
    $stmt_summary->bind_param("s", $filter_date_sql);
    $stmt_summary->execute();
    $result_summary = $stmt_summary->get_result();

    while ($row = $result_summary->fetch_assoc()) {
        $type = $row['transaction_type'];
        $product_key = $row['product_name'];
        $product = ucwords(str_replace('_', ' ', $product_key));
        $qty = (int)$row['total_quantity'];

        $transaction_summary[$type] += $qty;
        
        if (!isset($transaction_summary['details'][$product])) {
            $transaction_summary['details'][$product] = ['deposit' => 0, 'withdraw' => 0];
        }
        $transaction_summary['details'][$product][$type] = $qty;
    }
    $stmt_summary->close();
}


// 3. Get Transaction History for the selected date (only for managers or higher)
$transactions = [];
if ($is_manager_or_higher) {
    $stmt = $conn->prepare("
        SELECT rt.*, e.name as employee_name
        FROM refrigerator_transactions rt
        JOIN employees e ON rt.employee_id = e.id
        WHERE DATE(rt.transaction_at) = ?
        ORDER BY rt.transaction_at DESC
        LIMIT 50
    ");
    if ($stmt) {
        $stmt->bind_param("s", $filter_date_sql);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Stok Resto - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .stock-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }
        .stock-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        .stock-card h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }
        .stock-card .quantity {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .stock-multi-input-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--spacing-lg);
        }
        .product-input-group {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: var(--spacing-md);
        }
        .product-input-group label {
            font-size: 0.9rem;
            font-weight: 600;
        }
        .product-input-group input {
            width: 100%;
            margin-top: 0.5rem;
        }
        .transaction-log-section {
            margin-top: var(--spacing-2xl);
        }
        .log-item {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap; 
        }
        .log-item:last-child {
            border-bottom: none;
        }
        .log-info {
            display: flex;
            flex-direction: column;
        }
        .log-info span {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .log-info strong {
            color: var(--text-primary);
        }
        .log-action {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            margin-top: var(--spacing-sm);
        }
        .log-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .log-type-deposit {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .log-type-withdraw {
            background-color: var(--danger-light);
            color: var(--danger-color);
        }
        .log-quantity {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.25rem;
        }
        .daily-summary-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        .daily-summary-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            box-shadow: var(--shadow-sm);
        }
        .daily-summary-card h4 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: var(--spacing-md);
        }
        .daily-summary-card .total-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: var(--spacing-sm);
        }
        .daily-summary-card .detail-list {
            list-style: none;
            padding: 0;
            font-size: 0.9rem;
            columns: 2; 
            column-gap: 1rem;
        }
        .daily-summary-card .detail-list li {
            padding: 0.25rem 0;
        }
        .filter-control-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: flex-end;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">🍽️</span>
                    Manajemen Stok Resto
                </h1>
                <p>Kelola deposit dan withdraw stok paket makanan (Termasuk Happy Bites & Royale).</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card full-width" style="margin-bottom: var(--spacing-xl);">
                <div class="card-header">
                    <h3>Ringkasan Stok Paket Makanan Saat Ini</h3>
                </div>
                <div class="card-content">
                    <div class="stock-grid">
                        <?php foreach ($stock_levels as $stock): ?>
                            <div class="stock-card">
                                <h4><?= htmlspecialchars(ucwords(str_replace('_', ' ', $stock['product_name']))) ?></h4>
                                <p class="quantity"><?= $stock['quantity'] ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Deposit/Withdraw Stok Paket (Unit)</h3>
                </div>
                <div class="card-content">
                    <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                        <strong>Penting:</strong> Jumlah yang dimasukkan adalah **jumlah per unit paket** yang masuk (Deposit) atau keluar (Withdraw) dari stok kulkas.
                    </div>
                    <form method="POST">
                        <div class="stock-multi-input-grid">
                            <?php foreach ($stock_levels as $stock): ?>
                                <div class="product-input-group">
                                    <label for="quantity_<?= $stock['product_name'] ?>">
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $stock['product_name']))) ?>
                                    </label>
                                    <input type="number" 
                                           name="quantities[<?= htmlspecialchars($stock['product_name']) ?>]" 
                                           id="quantity_<?= $stock['product_name'] ?>" 
                                           class="form-input" 
                                           min="0" 
                                           value="0">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-actions" style="border-top: 1px solid var(--border-color); padding-top: var(--spacing-lg); margin-top: var(--spacing-lg);">
                            <button type="submit" name="action" value="deposit" class="btn btn-success">
                                <span class="btn-icon">➕</span> Deposit Item Dipilih
                            </button>
                            <button type="submit" name="action" value="withdraw" class="btn btn-danger">
                                <span class="btn-icon">➖</span> Withdraw Item Dipilih
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_manager_or_higher): ?>
                <div class="card full-width transaction-log-section">
                    <div class="card-header">
                        <h3>Filter Riwayat Transaksi</h3>
                    </div>
                    <div class="card-content">
                        <div class="filter-control-container">
                            <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="filter_date">Pilih Tanggal</label>
                                    <input type="date" name="filter_date" id="filter_date" class="form-input" 
                                           value="<?= htmlspecialchars($selected_date) ?>" required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            </form>
                        </div>

                        <h4 style="margin-top: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                            Ringkasan Tanggal <?= date('d/m/Y', strtotime($selected_date)) ?>
                        </h4>
                        <div class="daily-summary-cards">
                            <div class="daily-summary-card" style="border-left: 4px solid var(--success-color);">
                                <h4 style="color: var(--success-color);">TOTAL DEPOSIT</h4>
                                <p class="total-value" style="color: var(--success-color);"><?= $transaction_summary['deposit'] ?></p>
                                <ul class="detail-list">
                                    <?php 
                                    foreach ($transaction_summary['details'] as $product => $details):
                                        if (isset($details['deposit']) && $details['deposit'] > 0): ?>
                                            <li><?= htmlspecialchars($product) ?>: <strong><?= $details['deposit'] ?></strong></li>
                                        <?php endif;
                                    endforeach; ?>
                                </ul>
                            </div>
                            <div class="daily-summary-card" style="border-left: 4px solid var(--danger-color);">
                                <h4 style="color: var(--danger-color);">TOTAL WITHDRAW</h4>
                                <p class="total-value" style="color: var(--danger-color);"><?= $transaction_summary['withdraw'] ?></p>
                                <ul class="detail-list">
                                    <?php 
                                    foreach ($transaction_summary['details'] as $product => $details):
                                        if (isset($details['withdraw']) && $details['withdraw'] > 0): ?>
                                            <li><?= htmlspecialchars($product) ?>: <strong><?= $details['withdraw'] ?></strong></li>
                                        <?php endif;
                                    endforeach; ?>
                                </ul>
                            </div>
                        </div>

                        <div style="margin-top: 2rem;">
                            <h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                                Detail Log Transaksi (<?= count($transactions) ?> entri)
                            </h4>
                        </div>
                        <div class="requests-list">
                            <?php if (empty($transactions)): ?>
                                <div class="no-data">Tidak ada riwayat transaksi untuk tanggal <?= date('d/m/Y', strtotime($selected_date)) ?>.</div>
                            <?php else: ?>
                                <?php foreach ($transactions as $log): ?>
                                    <div class="log-item">
                                        <div class="log-info">
                                            <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['product_name']))) ?></strong>
                                            <span>
                                                oleh <span style="font-weight: 600;"><?= htmlspecialchars($log['employee_name']) ?></span> pada <?= date('d/m/Y H:i:s', strtotime($log['transaction_at'])) ?>
                                            </span>
                                        </div>
                                        <div class="log-action">
                                            <span class="log-type-badge log-type-<?= $log['transaction_type'] ?>">
                                                <?= ucfirst($log['transaction_type']) ?>
                                            </span>
                                            <span class="log-quantity"><?= $log['quantity'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>