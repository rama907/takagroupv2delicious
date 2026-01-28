<?php
require_once 'config.php';

// Pastikan hanya manajer dan level di atasnya yang bisa mengakses
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$employees = $conn->query("SELECT id, name FROM employees ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

$selected_employee_id = null;
$selected_date = null;

$refrigerator_transactions = [];
$warehouse_transactions = [];

// NEW: Separate Sale and Prep/Masak Details
$sales_details = [];
$prep_details = [];

$summary_totals = [
    'refrigerator' => ['deposit' => 0, 'withdraw' => 0, 'details' => []],
    'warehouse' => ['deposit' => 0, 'withdraw' => 0, 'details' => []],
    'sales' => ['total' => 0, 'details' => []],
    'prep' => ['total' => 0, 'details' => []], // NEW: Preparation/Masak Summary
];

// Mapping Produk Penjualan dan Masak ke Kolom DB (Updated for Royale)
$SALES_PACKAGE_MAP = [
    'Paket Western' => 'paket_sake', 
    'Paket Nusantara' => 'paket_anggur_merah', 
    'Paket Kids Meal' => 'paket_tuak',
    'Paket Royale' => 'paket_vip_person', // Mapped to VIP/Royale
];
$PREP_PACKAGE_MAP = [
    'Western (Masak)' => 'paket_spicy_1', 
    'Nusantara (Masak)' => 'paket_spicy_2', 
    'Kids Meal (Masak)' => 'paket_spicy_3',
    'Royale (Masak)' => 'paket_vip_person', // Mapped to VIP/Royale
];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $selected_employee_id = (int)($_POST['employee_id'] ?? 0);
    $selected_date = $_POST['report_date'] ?? date('Y-m-d');

    if ($selected_employee_id > 0) {
        
        // --- 1. Fetch Refrigerator Transactions (Deposit/Withdrawal Paket Jadi) ---
        $stmt_fridge = $conn->prepare("
            SELECT rt.product_name, rt.quantity, rt.transaction_type, rt.transaction_at
            FROM refrigerator_transactions rt
            WHERE rt.employee_id = ? AND DATE(rt.transaction_at) = ?
            ORDER BY rt.transaction_at ASC
        ");
        $stmt_fridge->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_fridge->execute();
        $refrigerator_transactions = $stmt_fridge->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_fridge->close();
        
        // Calculate Refrigerator Summary
        foreach ($refrigerator_transactions as $log) {
            $type = $log['transaction_type'];
            $qty = $log['quantity'];
            $product = str_replace('_', ' ', $log['product_name']); 
            
            $summary_totals['refrigerator'][$type] += $qty;
            if (!isset($summary_totals['refrigerator']['details'][$product])) {
                 $summary_totals['refrigerator']['details'][$product] = ['deposit' => 0, 'withdraw' => 0];
            }
            $summary_totals['refrigerator']['details'][$product][$type] += $qty;
        }

        // --- 2. Fetch Warehouse Transactions (Deposit/Withdrawal Bahan Baku) ---
        $stmt_warehouse = $conn->prepare("
            SELECT wt.product_name, wt.quantity, wt.transaction_type, wt.transaction_at
            FROM warehouse_transactions wt
            WHERE wt.employee_id = ? AND DATE(wt.transaction_at) = ?
            ORDER BY wt.transaction_at ASC
        ");
        $stmt_warehouse->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_warehouse->execute();
        $warehouse_transactions = $stmt_warehouse->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_warehouse->close();

        // Calculate Warehouse Summary
        foreach ($warehouse_transactions as $log) {
            $type = $log['transaction_type'];
            $qty = $log['quantity'];
            $product = str_replace('_', ' ', $log['product_name']);
            
            $summary_totals['warehouse'][$type] += $qty;
            if (!isset($summary_totals['warehouse']['details'][$product])) {
                 $summary_totals['warehouse']['details'][$product] = ['deposit' => 0, 'withdraw' => 0];
            }
            $summary_totals['warehouse']['details'][$product][$type] += $qty;
        }

        // --- 3. Fetch Sales Data (Penjualan + Masak) dan Pisahkan ---
        $stmt_all_data = $conn->prepare("
            SELECT 
                id, input_time,
                paket_sake, paket_anggur_merah, paket_tuak, /* Sales Columns */
                paket_spicy_1, paket_spicy_2, paket_spicy_3, /* Prep Columns */
                paket_vip_person /* Royale (Shared Column) */
            FROM sales_data
            WHERE employee_id = ? AND date = ?
            ORDER BY input_time ASC
        ");
        $stmt_all_data->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_all_data->execute();
        $result_all_data = $stmt_all_data->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_all_data->close();
        
        $total_sales_qty = 0;
        $total_prep_qty = 0;
        $sales_details_summary = [];
        $prep_details_summary = [];

        foreach ($result_all_data as $entry) {
            // Hitung total quantity per grup untuk menentukan tipe baris
            $qty_sales_group = ($entry['paket_sake'] + $entry['paket_anggur_merah'] + $entry['paket_tuak']);
            $qty_prep_group = ($entry['paket_spicy_1'] + $entry['paket_spicy_2'] + $entry['paket_spicy_3']);
            $qty_royale = $entry['paket_vip_person'];

            $is_sales_log = false;
            $is_prep_log = false;

            if ($qty_sales_group > 0) {
                // Jika ada data di kolom sales standard, ini pasti log penjualan
                $is_sales_log = true;
            } elseif ($qty_prep_group > 0) {
                // Jika ada data di kolom prep standard, ini pasti log masak
                $is_prep_log = true;
            } elseif ($qty_royale > 0) {
                // KASUS KHUSUS ROYALE: Jika kolom lain 0, tentukan berdasarkan jumlah
                // Masak biasanya batch besar (>= 20), Jual biasanya satuan
                if ($qty_royale >= 20) {
                    $is_prep_log = true;
                } else {
                    $is_sales_log = true;
                }
            }

            if ($is_sales_log) { // SALES LOGIC
                $sales_details[] = $entry;
                
                foreach ($SALES_PACKAGE_MAP as $label => $key) {
                    $qty = $entry[$key] ?? 0;
                    if ($qty > 0) {
                        if (!isset($sales_details_summary[$label])) {
                            $sales_details_summary[$label] = 0;
                        }
                        $sales_details_summary[$label] += $qty;
                        $total_sales_qty += $qty;
                    }
                }
            } elseif ($is_prep_log) { // PREP LOGIC
                $prep_details[] = $entry;

                foreach ($PREP_PACKAGE_MAP as $label => $key) {
                    $qty = $entry[$key] ?? 0;
                    if ($qty > 0) {
                        if (!isset($prep_details_summary[$label])) {
                            $prep_details_summary[$label] = 0;
                        }
                        $prep_details_summary[$label] += $qty;
                        $total_prep_qty += $qty;
                    }
                }
            }
        }
        
        $summary_totals['sales']['total'] = $total_sales_qty;
        $summary_totals['sales']['details'] = $sales_details_summary;

        $summary_totals['prep']['total'] = $total_prep_qty; // New total for Masak
        $summary_totals['prep']['details'] = $prep_details_summary; // New summary for Masak
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Aktivitas Karyawan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .report-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            margin-bottom: 2rem;
            padding: 1rem;
            background: var(--bg-card);
            border-radius: var(--radius-xl);
        }
        .report-results-section {
            margin-top: 2rem;
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }
        .summary-card-small {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-sm);
        }
        .summary-card-small h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }
        .summary-card-small .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .summary-card-detail {
            margin-top: 1rem;
            font-size: 0.85rem;
            line-height: 1.5;
            color: var(--text-primary);
        }
        .summary-card-detail span {
            display: block;
            color: var(--text-muted);
        }
        .summary-card-detail.sales-detail span {
            color: var(--text-primary);
            font-weight: 600;
        }
        .transaction-log-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .transaction-log-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            margin-bottom: 0.75rem;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .transaction-log-item span {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .transaction-log-item strong {
            color: var(--text-primary);
        }
        .sale-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            margin-bottom: 0.75rem;
            padding: 1rem;
        }
        .sale-item ul {
            list-style-type: none;
            padding-left: 0;
            margin-top: 0.5rem;
        }
        .sale-item li {
            font-size: 0.9rem;
            color: var(--text-primary);
            margin-bottom: 0.2rem;
            background: var(--bg-tertiary);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
        }
        .badge-deposit {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .badge-withdraw {
            background-color: var(--danger-light);
            color: var(--danger-color);
        }
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        /* Gaya baru untuk memisahkan sales dan prep detail */
        .sales-log-header {
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 5px;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Laporan Aktivitas Karyawan</h1>
                <p>Lihat detail aktivitas stok, penjualan, dan masak per karyawan.</p>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Cari Aktivitas</h3>
                </div>
                <div class="card-content">
                    <form method="POST" class="report-form">
                        <div class="form-group">
                            <label for="employee_id">Pilih Karyawan</label>
                            <select name="employee_id" id="employee_id" class="form-select" required>
                                <option value="">-- Pilih Karyawan --</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= htmlspecialchars($employee['id']) ?>" <?= ($selected_employee_id == $employee['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($employee['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="report_date">Tanggal</label>
                            <input type="date" name="report_date" id="report_date" class="form-input" value="<?= htmlspecialchars($selected_date) ?>" required>
                        </div>
                        <div class="form-actions" style="padding-top: 0; border-top: none;">
                            <button type="submit" name="search" class="btn btn-primary">
                                <span class="btn-icon">🔍</span> Cari
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($_POST['search'])): ?>
            <div class="report-results-section">
                <div class="summary-grid">
                    <div class="summary-card-small" style="border-left: 4px solid var(--primary-color);">
                        <h4>Total Penjualan Paket</h4>
                        <p class="value" style="color: var(--primary-color);"><?= $summary_totals['sales']['total'] ?></p>
                        <div class="summary-card-detail sales-detail">
                            <?php 
                            foreach ($summary_totals['sales']['details'] as $product => $qty) {
                                echo "<span>{$product}: {$qty}</span>";
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--info-color);">
                        <h4>Total Masak Paket (Unit)</h4>
                        <p class="value" style="color: var(--info-color);"><?= $summary_totals['prep']['total'] ?></p>
                        <div class="summary-card-detail sales-detail">
                            <?php 
                            foreach ($summary_totals['prep']['details'] as $product => $qty) {
                                echo "<span>{$product}: {$qty}</span>";
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--success-color);">
                        <h4>Total Deposit Resto (Unit)</h4>
                        <p class="value" style="color: var(--success-color);"><?= $summary_totals['refrigerator']['deposit'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $fridge_details = $summary_totals['refrigerator']['details'];
                            $product_names = array_keys($fridge_details);
                            sort($product_names);
                            foreach ($product_names as $p) {
                                $qty = $fridge_details[$p]['deposit'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>" . htmlspecialchars($p) . ": {$qty}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--danger-color);">
                        <h4>Total Withdraw Resto (Unit)</h4>
                        <p class="value" style="color: var(--danger-color);"><?= $summary_totals['refrigerator']['withdraw'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $fridge_details = $summary_totals['refrigerator']['details'];
                            $product_names = array_keys($fridge_details);
                            sort($product_names);
                            foreach ($product_names as $p) {
                                $qty = $fridge_details[$p]['withdraw'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>" . htmlspecialchars($p) . ": {$qty}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--success-color);">
                        <h4>Total Deposit Gudang</h4>
                        <p class="value" style="color: var(--success-color);"><?= $summary_totals['warehouse']['deposit'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $warehouse_details = $summary_totals['warehouse']['details'];
                            $product_names = array_keys($warehouse_details);
                            sort($product_names);
                            foreach ($product_names as $p) {
                                $qty = $warehouse_details[$p]['deposit'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>" . htmlspecialchars($p) . ": {$qty}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--danger-color);">
                        <h4>Total Withdraw Gudang</h4>
                        <p class="value" style="color: var(--danger-color);"><?= $summary_totals['warehouse']['withdraw'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $warehouse_details = $summary_totals['warehouse']['details'];
                            $product_names = array_keys($warehouse_details);
                            sort($product_names);
                            foreach ($product_names as $p) {
                                $qty = $warehouse_details[$p]['withdraw'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>" . htmlspecialchars($p) . ": {$qty}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Detail Transaksi Stok Resto (Kulkas)</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($refrigerator_transactions)): ?>
                            <div class="no-data">Tidak ada transaksi Resto (Kulkas) untuk tanggal ini.</div>
                        <?php else: ?>
                            <ul class="transaction-log-list">
                                <?php foreach ($refrigerator_transactions as $log): ?>
                                    <li class="transaction-log-item">
                                        <div>
                                            <strong><?= htmlspecialchars(str_replace('_', ' ', $log['product_name'])) ?></strong>
                                            <span>
                                                pada <?= date('H:i', strtotime($log['transaction_at'])) ?>
                                            </span>
                                        </div>
                                        <div class="log-action">
                                            <span class="badge badge-<?= $log['transaction_type'] ?>"><?= ucfirst($log['transaction_type']) ?></span>
                                            <span><?= $log['quantity'] ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Detail Transaksi Stok Gudang (Bahan Baku)</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($warehouse_transactions)): ?>
                            <div class="no-data">Tidak ada transaksi Gudang (Bahan Baku) untuk tanggal ini.</div>
                        <?php else: ?>
                            <ul class="transaction-log-list">
                                <?php foreach ($warehouse_transactions as $log): ?>
                                    <li class="transaction-log-item">
                                        <div>
                                            <strong><?= htmlspecialchars(str_replace('_', ' ', $log['product_name'])) ?></strong>
                                            <span>pada <?= date('H:i', strtotime($log['transaction_at'])) ?></span>
                                        </div>
                                        <div class="log-action">
                                            <span class="badge badge-<?= $log['transaction_type'] ?>"><?= ucfirst($log['transaction_type']) ?></span>
                                            <span><?= $log['quantity'] ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card full-width">
                    <div class="card-header">
                        <h3 class="sales-log-header">Log Penjualan Paket</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($sales_details)): ?>
                            <div class="no-data">Tidak ada log penjualan untuk tanggal ini.</div>
                        <?php else: ?>
                            <div class="sales-list">
                                <?php foreach ($sales_details as $sale_entry): ?>
                                    <div class="sale-item">
                                        <p><strong>Waktu Input:</strong> <?= date('H:i:s', strtotime($sale_entry['input_time'])) ?></p>
                                        <p><strong>Detail Penjualan:</strong></p>
                                        <ul>
                                            <?php if (($sale_entry['paket_sake'] ?? 0) > 0): ?><li>Paket Western: <?= $sale_entry['paket_sake'] ?> Paket</li><?php endif; ?>
                                            <?php if (($sale_entry['paket_anggur_merah'] ?? 0) > 0): ?><li>Paket Nusantara: <?= $sale_entry['paket_anggur_merah'] ?> Paket</li><?php endif; ?>
                                            <?php if (($sale_entry['paket_tuak'] ?? 0) > 0): ?><li>Paket Kids Meal: <?= $sale_entry['paket_tuak'] ?> Paket</li><?php endif; ?>
                                            <?php if (($sale_entry['paket_vip_person'] ?? 0) > 0): ?><li>Paket Royale: <?= $sale_entry['paket_vip_person'] ?> Paket</li><?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <h3 class="sales-log-header">Log Masak Paket (Input Prep)</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($prep_details)): ?>
                            <div class="no-data">Tidak ada log masak untuk tanggal ini.</div>
                        <?php else: ?>
                            <div class="sales-list">
                                <?php foreach ($prep_details as $prep_entry): ?>
                                    <div class="sale-item">
                                        <p><strong>Waktu Input:</strong> <?= date('H:i:s', strtotime($prep_entry['input_time'])) ?></p>
                                        <p><strong>Detail Masak (Total Paket):</strong></p>
                                        <ul>
                                            <?php if (($prep_entry['paket_spicy_1'] ?? 0) > 0): ?><li>Western (Masak): <?= $prep_entry['paket_spicy_1'] ?> Paket</li><?php endif; ?>
                                            <?php if (($prep_entry['paket_spicy_2'] ?? 0) > 0): ?><li>Nusantara (Masak): <?= $prep_entry['paket_spicy_2'] ?> Paket</li><?php endif; ?>
                                            <?php if (($prep_entry['paket_spicy_3'] ?? 0) > 0): ?><li>Kids Meal (Masak): <?= $prep_entry['paket_spicy_3'] ?> Paket</li><?php endif; ?>
                                            <?php if (($prep_entry['paket_vip_person'] ?? 0) > 0): ?><li>Royale (Masak): <?= $prep_entry['paket_vip_person'] ?> Paket</li><?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>