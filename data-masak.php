<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Tentukan apakah pengguna memiliki peran admin yang diizinkan untuk menginput data orang lain
$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

// Inisialisasi ID karyawan yang akan diinput datanya. Defaultnya adalah user yang login.
$employee_id_to_submit = $user['id'];
$selected_employee_name = $user['name'];
$selected_employee_role = $user['role'];

// Jika pengguna memiliki peran admin, ambil daftar semua karyawan untuk dropdown
$all_employees = [];
if ($is_admin_or_manager) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
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

$pending_requests_count = getPendingRequestCount();

$success_message = null;
$error_message = null;

// === RESEP (DIHITUNG PER 1 PAKET) ===
// Dikonversi dari request (untuk 20 paket) menjadi per 1 unit.
// Digunakan untuk pengurangan stok gudang otomatis.
$RECIPES_PER_UNIT = [
    'prep_western' => [
        // Request: Tepung 80, Ayam 40, Susu 80, Teh 100 (utk 20 paket)
        'Tepung' => 4,          // 80/20
        'Ayam Kemasan' => 2,    // 40/20
        'Susu' => 4,            // 80/20
        'Serbuk Teh' => 5       // 100/20
    ],
    'prep_nusantara' => [
        // Request: Ayam 60, Beras 100, Tepung 80, Jeruk 80 (utk 20 paket)
        'Ayam Kemasan' => 3,    // 60/20
        'Beras' => 5,           // 100/20
        'Tepung' => 4,          // 80/20
        'Jeruk Kemasan' => 4    // 80/20
    ],
    'prep_kids_meal' => [
        // Request: Ayam 60, Beras 100, Jeruk 60, Susu 40 (utk 20 paket)
        'Ayam Kemasan' => 3,    // 60/20
        'Beras' => 5,           // 100/20
        'Jeruk Kemasan' => 3,   // 60/20
        'Susu' => 2             // 40/20
    ],
    'prep_royale' => [
        // Request: Daging 80, Tepung 120, Susu 120, Teh 120 (utk 20 paket)
        'Daging' => 4,          // 80/20
        'Tepung' => 6,          // 120/20
        'Susu' => 6,            // 120/20
        'Serbuk Teh' => 6       // 120/20
    ],
    'prep_happy_bites' => [
        // Request: Tepung 40, Ayam 60, Jeruk 40, Susu 20 (utk 20 paket)
        'Tepung' => 2,          // 40/20
        'Ayam Kemasan' => 3,    // 60/20
        'Jeruk Kemasan' => 2,   // 40/20
        'Susu' => 1             // 20/20
    ]
];
$MIN_INPUT_UNIT = 20; // Kelipatan minimal untuk input

// --- Handle Delete Masak Entry ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'delete_masak_entry')) {
    $cooking_entry_id = (int)($_POST['cooking_entry_id'] ?? 0);

    if ($cooking_entry_id <= 0) {
        $error_message = "ID entri masak tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail entri sebelum dihapus dari tabel cooking_data
            $stmt_get_entry = $conn->prepare("
                SELECT * FROM cooking_data
                WHERE id = ?
            ");
            if (!$stmt_get_entry) { throw new Exception("Gagal menyiapkan query ambil detail entri: " . $conn->error); }
            $stmt_get_entry->bind_param("i", $cooking_entry_id);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) { throw new Exception("Entri masak tidak ditemukan."); }
            
            // Hapus entri dari cooking_data
            $stmt_delete = $conn->prepare("DELETE FROM cooking_data WHERE id = ?");
            if (!$stmt_delete) { throw new Exception("Gagal menyiapkan query hapus entri masak: " . $conn->error); }
            $stmt_delete->bind_param("i", $cooking_entry_id);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Entri masak tanggal " . date('d/m/Y H:i', strtotime($entry_details['input_time'])) . " berhasil dihapus.";
                
                // Kirim notifikasi penghapusan
                sendDiscordNotification([
                    'employee_name' => getEmployeeNameById($entry_details['employee_id']),
                    'date' => $entry_details['date'],
                    'paket_western' => $entry_details['paket_western'] ?? 0,
                    'paket_nusantara' => $entry_details['paket_nusantara'] ?? 0,
                    'paket_kids' => $entry_details['paket_kids'] ?? 0,
                    'happy_bites' => $entry_details['happy_bites'] ?? 0,
                    'paket_royale' => $entry_details['paket_royale'] ?? 0,
                ], 'cooking_deleted');

            } else {
                throw new Exception("Gagal menghapus entri masak. Mungkin sudah dihapus.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: data-masak.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error') . "&employee_id=" . $employee_id_to_submit);
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

// --- Handle form submission (Update Masak) ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'update_masak')) {
    $employee_id_from_form = (int)($_POST['employee_id'] ?? $user['id']);
    $date_input = $_POST['date'] ?? '';

    // Data Masak yang diinput (Total Paket yang dibuat)
    $prep_western = (int)($_POST['prep_western'] ?? 0);
    $prep_nusantara = (int)($_POST['prep_nusantara'] ?? 0);
    $prep_kids_meal = (int)($_POST['prep_kids_meal'] ?? 0);
    $prep_happy_bites = (int)($_POST['prep_happy_bites'] ?? 0);
    $prep_royale = (int)($_POST['prep_royale'] ?? 0); 
    
    $error_message = null; 

    // --- LOGIKA VALIDASI ---
    $date_obj = null;
    $formatted_date = null;
    if (empty($date_input)) { $error_message = "Tanggal harus diisi!"; }
    
    if (!isset($error_message)) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        if (!$date_obj) { $date_obj = DateTime::createFromFormat('d/m/Y', $date_input); }

        if (!$date_obj) { $error_message = "Format tanggal tidak valid!"; }
        
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
    $total_new_prep = $prep_western + $prep_nusantara + $prep_kids_meal + $prep_happy_bites + $prep_royale;
    if ($total_new_prep === 0 && !isset($error_message)) {
        $error_message = "Harap masukkan minimal {$MIN_INPUT_UNIT} paket yang dimasak!";
    }
    
    // Validasi kelipatan
    if (($prep_western % $MIN_INPUT_UNIT !== 0) || 
        ($prep_nusantara % $MIN_INPUT_UNIT !== 0) || 
        ($prep_kids_meal % $MIN_INPUT_UNIT !== 0) || 
        ($prep_happy_bites % $MIN_INPUT_UNIT !== 0) || 
        ($prep_royale % $MIN_INPUT_UNIT !== 0)) {
        if (!isset($error_message)) {
            $error_message = "Jumlah paket harus kelipatan {$MIN_INPUT_UNIT} (20, 40, 60, dst.).";
        }
    }

    if (isset($error_message)) {
        header("Location: data-masak.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    }

    $week_number = (int)$date_obj->format('W');
    $year = (int)$date_obj->format('Y');
    $input_time = date('Y-m-d H:i:s'); 
    
    // --- START ATOMIC TRANSACTION ---
    $conn->begin_transaction();
    $withdrawn_products = [];
    $deposited_products = []; 

    try {
        // --- 1. LOGIKA WITHDRAW STOK GUDANG (Bahan Baku) ---
        $prep_quantities = [
            'prep_western' => $prep_western, 
            'prep_nusantara' => $prep_nusantara, 
            'prep_kids_meal' => $prep_kids_meal,
            'prep_happy_bites' => $prep_happy_bites,
            'prep_royale' => $prep_royale
        ];
        
        foreach ($prep_quantities as $prep_key => $input_unit_qty) {
            if ($input_unit_qty > 0) {
                // Iterasi melalui setiap item dalam resep
                foreach ($RECIPES_PER_UNIT[$prep_key] as $stock_name => $qty_per_unit) {
                    $qty_to_withdraw = $input_unit_qty * $qty_per_unit;

                    // a. Update stock (decrement) & Check sufficiency in one query
                    $stmt_update_stock = $conn->prepare("
                        UPDATE warehouse_stock 
                        SET quantity = quantity - ? 
                        WHERE product_name = ? AND quantity >= ?
                    ");
                    if (!$stmt_update_stock) { 
                        throw new Exception("Gagal menyiapkan query update stok ({$stock_name}): " . $conn->error); 
                    }
                    
                    $stmt_update_stock->bind_param("isi", $qty_to_withdraw, $stock_name, $qty_to_withdraw);
                    $stmt_update_stock->execute();
                    
                    if ($stmt_update_stock->affected_rows === 0) {
                        // Cek stok saat ini untuk pesan error yang akurat
                        $stmt_check_current = $conn->prepare("SELECT quantity FROM warehouse_stock WHERE product_name = ?");
                        if (!$stmt_check_current) { throw new Exception("Gagal menyiapkan query cek stok saat ini: " . $conn->error); }
                        $stmt_check_current->bind_param("s", $stock_name);
                        $stmt_check_current->execute();
                        $current_qty = $stmt_check_current->get_result()->fetch_assoc()['quantity'] ?? 0;
                        $stmt_check_current->close();
                        
                        throw new Exception("Stok gudang **{$stock_name}** tidak mencukupi untuk paket '{$prep_key}'! (Stok saat ini: {$current_qty}, Butuh: {$qty_to_withdraw}). Transaksi dibatalkan.");
                    }
                    $stmt_update_stock->close();

                    // b. Log the transaction in warehouse_transactions
                    $transaction_type = 'withdraw';
                    $stmt_log_trans = $conn->prepare("
                        INSERT INTO warehouse_transactions (product_name, employee_id, transaction_type, quantity) 
                        VALUES (?, ?, ?, ?)
                    ");
                    if (!$stmt_log_trans) { 
                        throw new Exception("Gagal menyiapkan query log transaksi ({$stock_name}): " . $conn->error); 
                    }
                    
                    $stmt_log_trans->bind_param("sisi", $stock_name, $employee_id_from_form, $transaction_type, $qty_to_withdraw);
                    if (!$stmt_log_trans->execute()) {
                        throw new Exception("Gagal menyimpan log transaksi stok ({$stock_name}): " . $stmt_log_trans->error);
                    }
                    $stmt_log_trans->close();
                    
                    $withdrawn_products[$stock_name] = ($withdrawn_products[$stock_name] ?? 0) + $qty_to_withdraw;
                }
            }
        }
        
        // --- 2. LOGIKA AUTO DEPOSIT STOK KULKAS (Produk Jadi) ---
        $deposit_products_map = [
            'Paket Western' => $prep_western,    
            'Paket Nusantara' => $prep_nusantara,
            'Paket Kids Meal' => $prep_kids_meal,
            'Happy Bites' => $prep_happy_bites,
            'Paket Royale' => $prep_royale
        ];

        foreach ($deposit_products_map as $stock_name => $qty_to_deposit) {
            if ($qty_to_deposit > 0) {
                // a. Update stock (increment) in refrigerator_stock
                $stmt_update_stock = $conn->prepare("
                    UPDATE refrigerator_stock 
                    SET quantity = quantity + ? 
                    WHERE product_name = ?
                ");
                if (!$stmt_update_stock) { throw new Exception("Gagal menyiapkan query update stok kulkas ({$stock_name}): " . $conn->error); }
                
                $stmt_update_stock->bind_param("is", $qty_to_deposit, $stock_name);
                $stmt_update_stock->execute();
                $stmt_update_stock->close();

                // b. Log the transaction in refrigerator_transactions
                $transaction_type = 'deposit';
                $stmt_log_trans = $conn->prepare("
                    INSERT INTO refrigerator_transactions (product_name, employee_id, transaction_type, quantity) 
                    VALUES (?, ?, ?, ?)
                ");
                if (!$stmt_log_trans) { 
                    throw new Exception("Gagal menyiapkan query log transaksi kulkas ({$stock_name}): " . $conn->error); 
                }
                
                $stmt_log_trans->bind_param("sisi", $stock_name, $employee_id_from_form, $transaction_type, $qty_to_deposit);
                if (!$stmt_log_trans->execute()) {
                    throw new Exception("Gagal menyimpan log transaksi stok kulkas ({$stock_name}): " . $stmt_log_trans->error);
                }
                $stmt_log_trans->close();
                
                $deposited_products[$stock_name] = $qty_to_deposit;
            }
        }
        
        // --- 3. INSERT INTO cooking_data (Log Masak) ---
        $stmt = $conn->prepare("
            INSERT INTO cooking_data (
                employee_id, date, input_time, week_number, year, 
                paket_western, paket_nusantara, paket_kids, 
                happy_bites, paket_royale
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) { throw new Exception("Gagal menyiapkan query insert log masak: " . $conn->error); }
        
        $stmt->bind_param("issiiiiiii", 
            $employee_id_from_form, 
            $formatted_date, 
            $input_time,
            $week_number,       
            $year,              
            $prep_western,    
            $prep_nusantara,  
            $prep_kids_meal,
            $prep_happy_bites,
            $prep_royale  
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan log masak: " . $stmt->error);
        }
        $stmt->close();
        
        $conn->commit(); 
        
        $success_message = "Log masak berhasil disimpan untuk tanggal " . date('d/m/Y', strtotime($formatted_date)) . "! Stok gudang dikurangi dan Stok Resto diisi.";
        
        // --- 4. KIRIM NOTIFIKASI DISCORD ---

        sendDiscordNotification([
            'employee_name' => getEmployeeNameById($employee_id_from_form),
            'date' => $formatted_date,
            'input_time' => $input_time,
            'paket_western' => $prep_western,
            'paket_nusantara' => $prep_nusantara,
            'paket_kids' => $prep_kids_meal,
            'happy_bites' => $prep_happy_bites,
            'paket_royale' => $prep_royale
        ], "cooking_input");

        if (!empty($deposited_products)) {
            sendDiscordNotification([
                'employee_name' => getEmployeeNameById($employee_id_from_form),
                'product_list' => $deposited_products, 
            ], "refrigerator_deposit");
        }

        if (!empty($withdrawn_products)) {
            sendDiscordNotification([
                'employee_name' => getEmployeeNameById($employee_id_from_form),
                'product_list' => $withdrawn_products, 
            ], "warehouse_withdraw");
        }
        
        header("Location: data-masak.php?msg=" . urlencode($success_message) . "&type=success" . "&employee_id=" . $employee_id_to_submit);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error database: " . $e->getMessage();
        header("Location: data-masak.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    } 
}

// Query untuk Ringkasan Input Masak (menyeluruh)
$overall_prep_summary = [
    'prep_western' => 0,
    'prep_nusantara' => 0,
    'prep_kids_meal' => 0,
    'prep_happy_bites' => 0,
    'prep_royale' => 0,
];

$stmt = $conn->prepare("
    SELECT 
        SUM(paket_western) as prep_western, 
        SUM(paket_nusantara) as prep_nusantara, 
        SUM(paket_kids) as prep_kids_meal,
        SUM(happy_bites) as prep_happy_bites,
        SUM(paket_royale) as prep_royale
    FROM cooking_data 
    WHERE employee_id = ?
");
$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$overall_prep_summary_result = $stmt->get_result()->fetch_assoc();
if ($overall_prep_summary_result) {
    $overall_prep_summary = $overall_prep_summary_result;
}
$stmt->close();

$total_overall_prep = ($overall_prep_summary['prep_western'] ?? 0) + 
                      ($overall_prep_summary['prep_nusantara'] ?? 0) + 
                      ($overall_prep_summary['prep_kids_meal'] ?? 0) + 
                      ($overall_prep_summary['prep_happy_bites'] ?? 0) + 
                      ($overall_prep_summary['prep_royale'] ?? 0);


$today = date('Y-m-d');
// Query untuk Riwayat Masak Terbaru (Hanya Masak)
$stmt = $conn->prepare("
    SELECT id, input_time, paket_western, paket_nusantara, paket_kids, happy_bites, paket_royale
    FROM cooking_data 
    WHERE employee_id = ? AND date = ? 
    ORDER BY input_time DESC
");
$stmt->bind_param("is", $employee_id_to_submit, $today);
$stmt->execute();
$recent_prep = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Hitung total harian (untuk ditampilkan di card)
$daily_total = [
    'prep_western' => 0,
    'prep_nusantara' => 0,
    'prep_kids_meal' => 0,
    'prep_happy_bites' => 0,
    'prep_royale' => 0,
    'total_entries' => count($recent_prep)
];
foreach ($recent_prep as $entry) {
    $daily_total['prep_western'] += $entry['paket_western']; 
    $daily_total['prep_nusantara'] += $entry['paket_nusantara']; 
    $daily_total['prep_kids_meal'] += $entry['paket_kids']; 
    $daily_total['prep_happy_bites'] += $entry['happy_bites']; 
    $daily_total['prep_royale'] += $entry['paket_royale']; 
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Masak Resto - Delicious</title>
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
        .stock-info {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 5px;
            white-space: pre-wrap;
        }
        /* Style untuk Card Estimasi */
        .estimation-card {
            background: #fff3cd; /* Kuning muda lembut */
            border: 1px solid #ffeeba;
            border-radius: var(--radius-lg);
            padding: var(--spacing-md);
            margin-top: var(--spacing-md); 
            margin-bottom: var(--spacing-lg);
            color: #856404;
        }
        .estimation-title {
            font-weight: 700;
            margin-bottom: var(--spacing-sm);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .estimation-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            font-size: 0.9rem;
        }
        .est-item {
            background: rgba(255,255,255,0.6);
            padding: 5px 10px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
        }
        .est-val { font-weight: 600; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">🔪</span>
                    Data Masak Resto (Unit Paket)
                </h1>
                <p>Input total paket yang dimasak untuk pengurangan stok gudang otomatis.</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="card full-width" style="margin-bottom: var(--spacing-xl);">
                <div class="card-header">
                    <h3>Ringkasan Akumulasi Masak (Total Paket)</h3>
                    <span class="entry-count">
                        <?= $total_overall_prep ?? 0 ?> Total Paket Masak
                    </span>
                </div>
                <div class="card-content">
                    <div class="stats-grid-small">
                        <div class="stat-item">
                            <span class="stat-label">Paket Western</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_prep_summary['prep_western'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Nusantara</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_prep_summary['prep_nusantara'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Kids Meal</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_prep_summary['prep_kids_meal'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Happy Bites</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_prep_summary['prep_happy_bites'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Paket Royale</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_prep_summary['prep_royale'] ?? 0 ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Input Jumlah Paket Masak</h3>
                    <div class="current-time">
                        <span class="time-icon">⏰</span>
                        <span id="current-time"><?= date('H:i:s') ?></span>
                    </div>
                </div>
                <div class="card-content">
                    
                    <div class="info-message" style="margin-bottom: var(--spacing-md);">
                        <strong>Penting:</strong> Masukkan **total paket yang dimasak** (misal: 20, 40, 60, dst.). Angka harus kelipatan **<?= $MIN_INPUT_UNIT ?>**.
                        <br>
                        <strong>Withdraw Stok Otomatis:</strong> Stok Gudang dikurangi (bahan baku) dan Stok Resto diisi (produk jadi).
                    </div>
                    
                    <form method="POST" class="sales-form" id="sales-form">
                        <input type="hidden" name="action" value="update_masak">
                        <input type="hidden" name="employee_id" value="<?= $employee_id_to_submit ?>">
                        <input type="hidden" id="employee_role" value="<?= htmlspecialchars($selected_employee_role) ?>">
                        
                        <?php if ($is_admin_or_manager): ?>
                        <div class="form-group">
                            <label for="employee_id_input">Untuk Anggota</label>
                            <select name="employee_id_select" id="employee_id_input" class="form-select" onchange="window.location.href='data-masak.php?employee_id=' + this.value">
                                <option value="<?= $user['id'] ?>" <?= ($employee_id_to_submit == $user['id']) ? 'selected' : '' ?>>-- Untuk Diri Sendiri --</option>
                                <?php foreach ($all_employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($employee_id_to_submit == $emp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group"> <label for="date">Tanggal Masak</label>
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
                                Pilih tanggal masak. Data akan disimpan pada jam: <strong id="preview-time"><?= date('H:i:s') ?></strong>
                            </small>
                        </div>
                        
                        <div class="estimation-card" id="estimation-card" style="display: none;">
                            <div class="estimation-title">
                                <span>📋</span> Estimasi Bahan Baku yang Akan Diambil
                            </div>
                            <div class="estimation-list" id="estimation-list">
                                </div>
                        </div>

                        <div class="sales-input-grid">
                            
                            <div class="section-separator">Total Paket yang Dimasak</div>
                            
                            <div class="product-card">
                                <label for="prep_western">PAKET WESTERN</label>
                                <p>(Withdrawal Stok Gudang)</p>
                                <div class="quantity-group">
                                    <label for="prep_western">Paket</label>
                                    <input type="number" name="prep_western" id="prep_western" value="0" min="0" step="<?= $MIN_INPUT_UNIT ?>" class="input-calc" onchange="validateStep(this)">
                                </div>
                                <div class="stock-info">
                                    Resep (per 1 paket): Tepung (4), Ayam (2), Susu (4), Teh (5).
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="prep_nusantara">PAKET NUSANTARA</label>
                                <p>(Withdrawal Stok Gudang)</p>
                                <div class="quantity-group">
                                    <label for="prep_nusantara">Paket</label>
                                    <input type="number" name="prep_nusantara" id="prep_nusantara" value="0" min="0" step="<?= $MIN_INPUT_UNIT ?>" class="input-calc" onchange="validateStep(this)">
                                </div>
                                <div class="stock-info">
                                    Resep (per 1 paket): Ayam (3), Beras (5), Tepung (4), Jeruk (4).
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="prep_kids_meal">PAKET KIDS MEAL</label>
                                <p>(Withdrawal Stok Gudang)</p>
                                <div class="quantity-group">
                                    <label for="prep_kids_meal">Paket</label>
                                    <input type="number" name="prep_kids_meal" id="prep_kids_meal" value="0" min="0" step="<?= $MIN_INPUT_UNIT ?>" class="input-calc" onchange="validateStep(this)">
                                </div>
                                <div class="stock-info">
                                    Resep (per 1 paket): Ayam (3), Beras (5), Jeruk (3), Susu (2).
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="prep_happy_bites">HAPPY BITES</label>
                                <p>(Withdrawal Stok Gudang)</p>
                                <div class="quantity-group">
                                    <label for="prep_happy_bites">Paket</label>
                                    <input type="number" name="prep_happy_bites" id="prep_happy_bites" value="0" min="0" step="<?= $MIN_INPUT_UNIT ?>" class="input-calc" onchange="validateStep(this)">
                                </div>
                                <div class="stock-info">
                                    Resep (per 1 paket): Tepung (2), Ayam (3), Jeruk (2), Susu (1).
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="prep_royale">PAKET ROYALE</label>
                                <p>(Withdrawal Stok Gudang)</p>
                                <div class="quantity-group">
                                    <label for="prep_royale">Paket</label>
                                    <input type="number" name="prep_royale" id="prep_royale" value="0" min="0" step="<?= $MIN_INPUT_UNIT ?>" class="input-calc" onchange="validateStep(this)">
                                </div>
                                <div class="stock-info">
                                    Resep (per 1 paket): Daging (4), Tepung (4), Susu (3), Teh (5).
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
                    <h3>Riwayat Input Masak Terbaru (Hari Ini)</h3>
                    <span class="entry-count"><?= $daily_total['total_entries'] ?> Entri Hari Ini</span>
                </div>
                <div class="card-content">
                    <?php if (empty($recent_prep)): ?>
                        <div class="no-data">Belum ada data masak hari ini. Silakan input data pertama Anda!</div>
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
                                    <?php foreach ($recent_prep as $entry): ?>
                                    <?php 
                                        $is_today = date('Y-m-d', strtotime($entry['input_time'])) === date('Y-m-d');
                                    ?>
                                    <tr class="<?= $is_today ? 'today-row' : '' ?>">
                                        <td data-label="Tanggal & Waktu">
                                            <div class="datetime-cell">
                                                <?= date('d/m/Y H:i:s', strtotime($entry['input_time'])) ?>
                                                <?php if ($is_today): ?>
                                                    <span class="today-badge">Hari Ini</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Western"><?= $entry['paket_western'] ?? 0 ?></td>
                                        <td data-label="Nusantara"><?= $entry['paket_nusantara'] ?? 0 ?></td>
                                        <td data-label="Kids Meal"><?= $entry['paket_kids'] ?? 0 ?></td>
                                        <td data-label="Happy Bites"><?= $entry['happy_bites'] ?? 0 ?></td>
                                        <td data-label="Royale"><?= $entry['paket_royale'] ?? 0 ?></td>
                                        <td data-label="Aksi">
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus log masak ini? Catatan: Penghapusan TIDAK mengembalikan stok gudang!')">
                                                <input type="hidden" name="action" value="delete_masak_entry">
                                                <input type="hidden" name="cooking_entry_id" value="<?= $entry['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Hapus Log</button>
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
        // Pass PHP Recipes to JS
        const recipes = <?= json_encode($RECIPES_PER_UNIT) ?>;

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
                submitBtn.innerHTML = `<span class="btn-icon">💾</span> Simpan Data Masak (${shortTime})`;
            }
        }
        
        function validateStep(input) {
            const value = parseInt(input.value) || 0;
            const step = parseInt(input.step) || 1;
            
            if (value % step !== 0) {
                // Bulatkan ke kelipatan terdekat jika tidak pas
                const nearest = Math.round(value / step) * step;
                input.value = nearest;
                alert(`Nilai harus kelipatan ${step} paket! Nilai diatur ke ${nearest}.`);
            }
            if (value < 0) {
                input.value = 0;
            }
            calculateEstimation(); // Recalculate on validation
        }

        // --- NEW: Calculate Ingredient Estimation ---
        function calculateEstimation() {
            const inputs = document.querySelectorAll('.input-calc');
            const totals = {};
            let hasInput = false;

            inputs.forEach(input => {
                const qty = parseInt(input.value) || 0;
                if (qty > 0) {
                    hasInput = true;
                    const id = input.id; // e.g., 'prep_western'
                    if (recipes[id]) {
                        for (const [ingredient, amountPerUnit] of Object.entries(recipes[id])) {
                            if (!totals[ingredient]) totals[ingredient] = 0;
                            totals[ingredient] += qty * amountPerUnit;
                        }
                    }
                }
            });

            const card = document.getElementById('estimation-card');
            const list = document.getElementById('estimation-list');
            list.innerHTML = '';

            if (hasInput) {
                card.style.display = 'block';
                for (const [name, amount] of Object.entries(totals)) {
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'est-item';
                    itemDiv.innerHTML = `<span>${name}</span> <span class="est-val">${amount}</span>`;
                    list.appendChild(itemDiv);
                }
            } else {
                card.style.display = 'none';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('sales-form');
            
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            // Add listener for estimation
            document.querySelectorAll('.input-calc').forEach(input => {
                input.addEventListener('input', calculateEstimation);
            });

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
                    validateStep(input); 
                });
                
                if (totalItems === 0) {
                    e.preventDefault();
                    alert('❌ Harap masukkan minimal satu paket masak.');
                    return false;
                }

                if (!confirm(`Yakin ingin menyimpan log masak pada jam ${timeString}?\nStok gudang akan dikurangi sesuai estimasi.`)) {
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