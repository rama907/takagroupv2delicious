<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Tentukan apakah pengguna memiliki peran admin yang diizinkan untuk menginput data orang lain
$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);
$is_director_level = hasRole(['ceo', 'direktur', 'wakil_direktur']); 

// Inisialisasi ID karyawan yang akan diinput datanya. Defaultnya adalah user yang login.
$employee_id_to_submit = $user['id'];
$selected_employee_name = $user['name'];
$selected_employee_role = $user['role'];

// Jika pengguna memiliki peran admin, ambil daftar semua karyawan untuk dropdown
$all_employees = [];
if ($is_admin_or_manager) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    // Jika ada ID anggota yang dipilih dari form, gunakan ID tersebut
    if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
        $employee_id_to_submit = (int)$_GET['employee_id'];
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id_to_submit) {
                $selected_employee_name = htmlspecialchars($emp['name']);
                $selected_employee_role = $emp['role'];
                break;
            }
        }
    }
}

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

$success_message = null;
$error_message = null;

// --- Handle Delete Sales Entry ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'delete_sales_entry')) {
    $sales_entry_id = (int)($_POST['sales_entry_id'] ?? 0);

    if ($sales_entry_id <= 0) {
        $error_message = "ID entri penjualan tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail entri sebelum dihapus untuk notifikasi
            $stmt_get_entry = $conn->prepare("
                SELECT *, date, input_time, employee_id
                FROM sales_data
                WHERE id = ?
            ");
            if (!$stmt_get_entry) {
                throw new Exception("Gagal menyiapkan query ambil detail entri penjualan: " . $conn->error);
            }
            $stmt_get_entry->bind_param("i", $sales_entry_id);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) {
                throw new Exception("Entri penjualan tidak ditemukan.");
            }
            
            // Hapus entri penjualan
            $stmt_delete = $conn->prepare("DELETE FROM sales_data WHERE id = ?");
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus entri penjualan: " . $conn->error);
            }
            $stmt_delete->bind_param("i", $sales_entry_id);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Entri penjualan tanggal " . date('d/m/Y H:i', strtotime($entry_details['input_time'])) . " berhasil dihapus.";
                
                // Kirim notifikasi Discord
                sendDiscordNotification([
                    'employee_name' => getEmployeeNameById($entry_details['employee_id']),
                    'sales_date_time' => date('d/m/Y H:i', strtotime($entry_details['input_time'])),
                    'paket_western' => $entry_details['paket_western'] ?? 0,
                    'paket_nusantara' => $entry_details['paket_nusantara'] ?? 0,
                    'paket_kids_meal' => $entry_details['paket_kids'] ?? 0, // Disesuaikan nama kolom DB 'paket_kids'
                    'happy_bites' => $entry_details['happy_bites'] ?? 0,
                    'paket_royale' => $entry_details['paket_royale'] ?? 0,
                ], 'sale_deleted');

            } else {
                throw new Exception("Gagal menghapus entri penjualan. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: sales.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error') . "&employee_id=" . $employee_id_to_submit);
        exit;
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

// --- Handle form submission ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'update_sales')) {
    $employee_id_from_form = (int)($_POST['employee_id'] ?? $user['id']);
    $date_input = $_POST['date'] ?? '';

    // === SALES ITEMS ===
    $paket_western = (int)($_POST['paket_western'] ?? 0);
    $paket_nusantara = (int)($_POST['paket_nusantara'] ?? 0);
    $paket_kids_meal = (int)($_POST['paket_kids_meal'] ?? 0);
    $happy_bites = (int)($_POST['happy_bites'] ?? 0); // New Menu
    $paket_royale = (int)($_POST['paket_royale'] ?? 0);
    // ====================
    
    $error_message = null; 

    // --- LOGIKA VALIDASI TANGGAL ---
    $date_obj = null;
    $formatted_date = null;
    if (empty($date_input)) { 
        $error_message = "Tanggal harus diisi!";
    }
    
    if (!isset($error_message)) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        $errors = DateTime::getLastErrors();
        
        if (!$date_obj || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_input);
            $errors = DateTime::getLastErrors();
        }

        if (!$date_obj || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            $error_message = "Format tanggal tidak valid! Harap gunakan format YYYY-MM-DD (misal: 2025-07-24) atau DD/MM/YYYY (misal: 24/07/2025) yang lengkap dan akurat.";
        }
        
        if (!isset($error_message)) {
            $formatted_date = $date_obj->format('Y-m-d');

            $today_limit = new DateTime();
            $today_limit->setTime(23, 59, 59);
            
            if ($date_obj > $today_limit) { $error_message = "Tanggal tidak boleh di masa depan!"; }
            
            $thirty_days_ago = new DateTime();
            $thirty_days_ago->sub(new DateInterval('P30D'));
            $thirty_days_ago->setTime(0, 0, 0);
            
            if ($date_obj < $thirty_days_ago) { $error_message = "Tanggal tidak boleh lebih dari 30 hari yang lalu!"; }
        }
    }

    // PENTING: Cek apakah ada input paket baru
    $total_new_packages = $paket_western + $paket_nusantara + $paket_kids_meal + $happy_bites + $paket_royale;
    if ($total_new_packages === 0 && !isset($error_message)) {
        $error_message = "Harap masukkan minimal satu paket makanan yang terjual!";
    }

    if (isset($error_message)) {
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    }

    $week_number = (int)$date_obj->format('W');
    $year = (int)$date_obj->format('Y');
    $input_time = date('Y-m-d H:i:s'); 
    
    // --- START ATOMIC TRANSACTION ---
    $conn->begin_transaction();
    try {
        // --- 1. INSERT INTO sales_data ---
        // Menggunakan kolom spesifik sesuai struktur database terbaru
        
        $stmt = $conn->prepare("
            INSERT INTO sales_data (
                employee_id, date, input_time, week_number, year, 
                paket_western, paket_nusantara, paket_kids, 
                happy_bites, paket_royale
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
             throw new Exception("Gagal menyiapkan query insert sales: " . $conn->error);
        }
        
        // Binding parameters: isssiiiiii (10 params)
        $stmt->bind_param("isssiiiiii", 
            $employee_id_from_form, 
            $formatted_date, 
            $input_time,
            $week_number,
            $year,
            $paket_western,         
            $paket_nusantara,       
            $paket_kids_meal,       // Maps to paket_kids column
            $happy_bites,
            $paket_royale           
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan data penjualan: " . $stmt->error);
        }
        $stmt->close();
        
        // --- 2. AUTOMATIC STOCK WITHDRAWAL (KULKAS/RESTO STOCK) ---
        // Pemetaan: [internal_key] => ['Stock Name (DB)', 'qty_per_pack', sold_qty]
        $withdrawal_map = [
            'western' => ['Paket Western', 1, $paket_western],
            'nusantara' => ['Paket Nusantara', 1, $paket_nusantara],
            'kids_meal' => ['Paket Kids Meal', 1, $paket_kids_meal],
            'happy_bites' => ['Happy Bites', 1, $happy_bites], // Stock Name assumed
            'royale' => ['Paket Royale', 1, $paket_royale],
        ];

        $total_items_withdrawn = 0;
        $withdrawn_products = [];
        
        foreach ($withdrawal_map as $product_key => $details) {
            list($stock_name, $qty_per_pack, $sold_qty) = $details;
            $qty_to_withdraw = $sold_qty * $qty_per_pack;
            
            if ($qty_to_withdraw > 0) {
                // a. Update stock (decrement) & Check sufficiency in one query
                $stmt_update_stock = $conn->prepare("
                    UPDATE refrigerator_stock 
                    SET quantity = quantity - ? 
                    WHERE product_name = ? AND quantity >= ?
                ");
                if (!$stmt_update_stock) { 
                    throw new Exception("Gagal menyiapkan query update stok: " . $conn->error); 
                }
                
                $stmt_update_stock->bind_param("isi", $qty_to_withdraw, $stock_name, $qty_to_withdraw);
                $stmt_update_stock->execute();
                
                // If the stock update didn't affect rows, check why (insufficient stock)
                if ($stmt_update_stock->affected_rows === 0) {
                    // Mengambil stok saat ini secara eksplisit untuk pesan error yang akurat
                    $stmt_check_current = $conn->prepare("SELECT quantity FROM refrigerator_stock WHERE product_name = ?");
                    if (!$stmt_check_current) {
                        throw new Exception("Gagal menyiapkan query cek stok saat ini: " . $conn->error);
                    }
                    $stmt_check_current->bind_param("s", $stock_name);
                    $stmt_check_current->execute();
                    $current_qty = $stmt_check_current->get_result()->fetch_assoc()['quantity'] ?? 0;
                    $stmt_check_current->close();
                    
                    if ($current_qty < $qty_to_withdraw) {
                         // Rollback semua transaksi karena stok tidak cukup
                         throw new Exception("Stok paket makanan **{$stock_name}** tidak mencukupi! (Stok saat ini: {$current_qty}, Butuh: {$qty_to_withdraw}). Transaksi dibatalkan.");
                    }
                    // Jika affected_rows 0 tapi quantity cukup, berarti product_name tidak ditemukan di refrigerator_stock
                    throw new Exception("Produk **{$stock_name}** tidak ditemukan di database stok resto. Transaksi dibatalkan.");
                }
                $stmt_update_stock->close();

                // b. Log the transaction in refrigerator_transactions (mencatat employee_id yang input sales)
                $transaction_type = 'withdraw';
                $stmt_log_trans = $conn->prepare("
                    INSERT INTO refrigerator_transactions (product_name, employee_id, transaction_type, quantity) 
                    VALUES (?, ?, ?, ?)
                ");
                if (!$stmt_log_trans) { 
                    throw new Exception("Gagal menyiapkan query log transaksi: " . $conn->error); 
                }
                
                $stmt_log_trans->bind_param("sisi", $stock_name, $employee_id_from_form, $transaction_type, $qty_to_withdraw);
                if (!$stmt_log_trans->execute()) {
                    throw new Exception("Gagal menyimpan log transaksi stok: " . $stmt_log_trans->error);
                }
                $stmt_log_trans->close();
                
                $total_items_withdrawn += 1;
                $withdrawn_products[$stock_name] = $qty_to_withdraw;
            }
        }
        
        $conn->commit(); // Commit both sales and stock updates
        
        $success_message = "Data penjualan berhasil disimpan untuk tanggal " . date('d/m/Y', strtotime($formatted_date)) . " pada jam " . date('H:i', strtotime($input_time)) . "!";
        
        // Kirim notifikasi Discord untuk sales
        sendDiscordNotification([
            'employee_name' => getEmployeeNameById($employee_id_from_form),
            'date' => $formatted_date,
            'input_time' => $input_time,
            'paket_western' => $paket_western,
            'paket_nusantara' => $paket_nusantara,
            'paket_kids_meal' => $paket_kids_meal,
            'happy_bites' => $happy_bites,
            'paket_royale' => $paket_royale, 
        ], 'sale_input');
        
        // Kirim notifikasi Discord untuk penarikan stok
        if ($total_items_withdrawn > 0) {
             sendDiscordNotification([
                'employee_name' => getEmployeeNameById($employee_id_from_form),
                'product_list' => $withdrawn_products, 
            ], "refrigerator_withdraw");
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($success_message) . "&type=success" . "&employee_id=" . $employee_id_to_submit);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error database: " . $e->getMessage();
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    } 
}

// Query untuk Ringkasan Input Penjualan (menyeluruh)
// Karena database sales_data sekarang TERPISAH dari data-masak, kita tidak perlu filter 'log masak' lagi.
$overall_sales_summary = [
    'paket_western' => 0,       
    'paket_nusantara' => 0,     
    'paket_kids_meal' => 0,     
    'happy_bites' => 0,
    'paket_royale' => 0,        
];

$stmt = $conn->prepare("
    SELECT 
        SUM(paket_western) as paket_western, 
        SUM(paket_nusantara) as paket_nusantara, 
        SUM(paket_kids) as paket_kids_meal,
        SUM(happy_bites) as happy_bites,
        SUM(paket_royale) as paket_royale
    FROM sales_data 
    WHERE employee_id = ?
");

$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$overall_sales_summary_result = $stmt->get_result()->fetch_assoc();
if ($overall_sales_summary_result) {
    $overall_sales_summary = $overall_sales_summary_result;
}
$stmt->close();

$total_overall_sales = ($overall_sales_summary['paket_western'] ?? 0) + 
                       ($overall_sales_summary['paket_nusantara'] ?? 0) + 
                       ($overall_sales_summary['paket_kids_meal'] ?? 0) + 
                       ($overall_sales_summary['happy_bites'] ?? 0) +
                       ($overall_sales_summary['paket_royale'] ?? 0);


$today = date('Y-m-d');
// Query untuk Riwayat Penjualan Terbaru
$stmt = $conn->prepare("
    SELECT 
        id, input_time, 
        paket_western, paket_nusantara, paket_kids, happy_bites, paket_royale
    FROM sales_data 
    WHERE employee_id = ? AND date = ? 
    ORDER BY input_time DESC
");
$stmt->bind_param("is", $employee_id_to_submit, $today);
$stmt->execute();
$recent_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Hitung total harian (untuk ditampilkan di card)
$daily_total = [
    'paket_western' => 0,
    'paket_nusantara' => 0,
    'paket_kids_meal' => 0,
    'happy_bites' => 0,
    'paket_royale' => 0,
    'total_entries' => count($recent_sales)
];
foreach ($recent_sales as $entry) {
    $daily_total['paket_western'] += $entry['paket_western']; 
    $daily_total['paket_nusantara'] += $entry['paket_nusantara']; 
    $daily_total['paket_kids_meal'] += $entry['paket_kids']; 
    $daily_total['happy_bites'] += $entry['happy_bites'];
    $daily_total['paket_royale'] += $entry['paket_royale']; 
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Penjualan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .sales-input-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-md);
            margin-top: var(--spacing-lg);
        }
        .product-card {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--spacing-md);
            display: flex;
            flex-direction: column;
            gap: var(--spacing-xs);
            position: relative;
            background: var(--bg-secondary);
        }
        .product-card.active {
            background: var(--primary-light);
            border-color: var(--primary-color);
        }
        .product-card label {
            font-size: 1rem;
            font-weight: 600;
        }
        .product-card p {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .quantity-group {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-sm);
        }
        .quantity-group input {
            width: 70px;
            text-align: center;
        }
        .today-badge {
            background-color: var(--info-color);
            color: white;
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            margin-left: var(--spacing-sm);
            font-weight: 600;
        }
        .section-separator {
            grid-column: 1 / -1;
            margin-top: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            padding-bottom: var(--spacing-md);
            border-bottom: 2px solid var(--primary-color);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            text-transform: uppercase;
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
                    Data Penjualan Resto
                </h1>
                <p>Input dan kelola data penjualan paket makanan.</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="card full-width" style="margin-bottom: var(--spacing-xl);">
                <div class="card-header">
                    <h3>Ringkasan Input Penjualan</h3>
                    <span class="entry-count">
                        <?= $total_overall_sales ?? 0 ?> Total Transaksi
                    </span>
                </div>
                <div class="card-content">
                    <div class="stats-grid-small">
                        <div class="stat-item">
                            <span class="stat-label">Paket Western</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_western'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Nusantara</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_nusantara'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Kids Meal</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_kids_meal'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Happy Bites</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['happy_bites'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Royale</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_royale'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Input Data Penjualan</h3>
                    <div class="current-time">
                        <span class="time-icon">⏰</span>
                        <span id="current-time"><?= date('H:i:s') ?></span>
                    </div>
                </div>
                <div class="card-content">
                    
                    <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                        <strong>Penting:</strong> Jumlah yang dimasukkan adalah **jumlah paket makanan yang terjual** per item.
                        <br>
                        <strong>Penarikan Stok Otomatis:</strong> Stok Resto akan dikurangi 1:1 sesuai jumlah paket yang diinput. Pastikan stok **cukup** sebelum input penjualan!
                    </div>
                    
                    <form method="POST" class="sales-form" id="sales-form">
                        <input type="hidden" name="action" value="update_sales">
                        <input type="hidden" id="employee_role" value="<?= htmlspecialchars($selected_employee_role) ?>">
                        
                        <?php if ($is_admin_or_manager): ?>
                        <div class="form-group">
                            <label for="employee_id_input">Untuk Anggota</label>
                            <select name="employee_id" id="employee_id_input" class="form-select" onchange="window.location.href='sales.php?employee_id=' + this.value">
                                <option value="<?= $user['id'] ?>" <?= ($employee_id_to_submit == $user['id']) ? 'selected' : '' ?>>-- Untuk Diri Sendiri --</option>
                                <?php foreach ($all_employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($employee_id_to_submit == $emp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="employee_id" value="<?= $user['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="form-group"> <label for="date">Tanggal Penjualan</label>
                            <input type="date" 
                                    name="date" 
                                    id="date" 
                                    value="<?= htmlspecialchars(date('Y-m-d')) ?>" 
                                    class="form-input" 
                                    required
                                    max="<?= date('Y-m-d') ?>"
                                    min="<?= date('Y-m-d', strtotime('-30 days')) ?>"
                                    onchange="formatDateInput(this)">
                            <small class="form-help">
                                Pilih tanggal penjualan (maksimal 30 hari ke belakang).
                                <br>Data akan disimpan pada jam: <strong id="preview-time"><?= date('H:i:s') ?></strong>
                            </small>
                        </div>
                        
                        <div class="sales-input-grid">
                            
                            <div class="section-separator">Paket Makanan Terjual</div>
                            
                            <div class="product-card">
                                <label for="paket_western">PAKET WESTERN</label>
                                <p>(Potong Stok Resto: 1 Paket Western)</p>
                                <div class="quantity-group">
                                    <label for="paket_western">Paket</label>
                                    <input type="number" name="paket_western" id="paket_western" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="paket_nusantara">PAKET NUSANTARA</label>
                                <p>(Potong Stok Resto: 1 Paket Nusantara)</p>
                                <div class="quantity-group">
                                    <label for="paket_nusantara">Paket</label>
                                    <input type="number" name="paket_nusantara" id="paket_nusantara" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="paket_kids_meal">PAKET KIDS MEAL</label>
                                <p>(Potong Stok Resto: 1 Paket Kids Meal)</p>
                                <div class="quantity-group">
                                    <label for="paket_kids_meal">Paket</label>
                                    <input type="number" name="paket_kids_meal" id="paket_kids_meal" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="happy_bites">HAPPY BITES</label>
                                <p>(Potong Stok Resto: 1 Paket Happy Bites)</p>
                                <div class="quantity-group">
                                    <label for="happy_bites">Paket</label>
                                    <input type="number" name="happy_bites" id="happy_bites" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="paket_royale">PAKET ROYALE</label>
                                <p>(Potong Stok Resto: 1 Paket Royale)</p>
                                <div class="quantity-group">
                                    <label for="paket_royale">Paket</label>
                                    <input type="number" name="paket_royale" id="paket_royale" value="0" min="0">
                                </div>
                            </div>
                            
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submit-btn">
                                <span class="btn-icon">💾</span>
                                Simpan Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Riwayat Penjualan Terbaru (Hari Ini)</h3>
                    <span class="entry-count"><?= $daily_total['total_entries'] ?> Entri Hari Ini</span>
                </div>
                <div class="card-content">
                    <?php if (empty($recent_sales)): ?>
                        <div class="no-data">Belum ada data penjualan hari ini. Silakan input data pertama Anda!</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved"> 
                                <thead>
                                    <tr>
                                        <th>Tanggal & Waktu</th>
                                        <th>Western</th>
                                        <th>Nusantara</th>
                                        <th>Kids Meal</th>
                                        <th>Happy Bites</th>
                                        <th>Royale</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_sales as $sale): ?>
                                    <?php 
                                        $is_today = date('Y-m-d', strtotime($sale['input_time'])) === date('Y-m-d');
                                    ?>
                                    <tr class="<?= $is_today ? 'today-row' : '' ?>">
                                        <td data-label="Tanggal & Waktu">
                                            <div class="datetime-cell">
                                                <?= date('d/m/Y H:i:s', strtotime($sale['input_time'])) ?>
                                                <?php if ($is_today): ?>
                                                    <span class="today-badge">Hari Ini</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Western"><?= $sale['paket_western'] ?? 0 ?></td>
                                        <td data-label="Nusantara"><?= $sale['paket_nusantara'] ?? 0 ?></td>
                                        <td data-label="Kids Meal"><?= $sale['paket_kids'] ?? 0 ?></td>
                                        <td data-label="Happy Bites"><?= $sale['happy_bites'] ?? 0 ?></td>
                                        <td data-label="Royale"><?= $sale['paket_royale'] ?? 0 ?></td>
                                        <td data-label="Aksi">
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus entri penjualan ini? Aksi ini TIDAK DAPAT DIBATALKAN.')">
                                                <input type="hidden" name="action" value="delete_sales_entry">
                                                <input type="hidden" name="sales_entry_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script> 
    <script>
        // Update current time display
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const currentTimeElement = document.getElementById('current-time');
            const previewTimeElement = document.getElementById('preview-time');
            const submitBtn = document.getElementById('submit-btn');
            
            if (currentTimeElement) {
                currentTimeElement.textContent = timeString;
            }
            
            if (previewTimeElement) {
                previewTimeElement.textContent = timeString;
            }
            
            if (submitBtn) {
                const shortTime = now.toLocaleTimeString('id-ID', { 
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit'
                });
                submitBtn.innerHTML = `<span class="btn-icon">💾</span> Simpan Data (${shortTime})`;
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('sales-form');
            
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            form.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('submit-btn');
                
                const now = new Date();
                const timeString = now.toLocaleTimeString('id-ID', { 
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                
                // Minimal check to prevent empty form submission
                let totalItems = 0;
                document.querySelectorAll('.quantity-group input[type="number"]').forEach(input => {
                    totalItems += parseInt(input.value) || 0;
                });

                if (totalItems === 0) {
                    e.preventDefault();
                    alert('❌ Harap masukkan minimal satu paket makanan yang terjual!');
                    return false;
                }

                if (!confirm(`Yakin ingin menyimpan data penjualan pada jam ${timeString}?\nStok resto akan dikurangi secara otomatis (1 unit per paket).`)) {
                    e.preventDefault();
                    return false;
                }
            });
        });

        setInterval(() => {
            updateCurrentTime();
        }, 1000);
    </script>
</body>
</html>