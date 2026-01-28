<?php
require_once 'config.php';

// Cek hak akses. Hanya direktur, wakil direktur, dan manajer yang bisa mengakses.
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

$success = null;
$error = null;
$selected_employee_id = null;
$selected_employee_name = 'Pilih Anggota';
$warning_history = [];

// --- Logika Penghapusan Otomatis (Surat Peringatan Lebih dari 1 bulan) ---
$one_month_ago = date('Y-m-d H:i:s', strtotime('-1 month'));
$stmt_auto_delete = $conn->prepare("DELETE FROM warning_letters WHERE issued_at < ?");
if ($stmt_auto_delete) {
    $stmt_auto_delete->bind_param("s", $one_month_ago);
    $stmt_auto_delete->execute();
    if ($stmt_auto_delete->affected_rows > 0) {
        // Notifikasi Discord bisa ditambahkan di sini jika diperlukan
    }
    $stmt_auto_delete->close();
}

// --- Logika Penghapusan Manual per Entri ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_sp') {
    $sp_id_to_delete = (int)($_POST['sp_id'] ?? 0);
    $employee_id_of_sp = (int)($_POST['employee_id'] ?? 0);

    if ($sp_id_to_delete <= 0) {
        $error = "ID surat peringatan tidak valid!";
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM warning_letters WHERE id = ? AND employee_id = ?");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("ii", $sp_id_to_delete, $employee_id_of_sp);

            if ($stmt->execute()) {
                $success = "Surat peringatan berhasil dihapus.";
                 // Kirim notifikasi Discord
                $employee_name = getEmployeeNameById($employee_id_of_sp);
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'admin_name' => $user['name'],
                    'sp_id' => $sp_id_to_delete
                ], 'warning_letter_deleted');

            } else {
                throw new Exception("Gagal menghapus surat peringatan: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}


// Handle form submission for issuing a new warning letter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_sp') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $sp_type = $_POST['sp_type'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if ($employee_id <= 0 || empty($sp_type) || empty($reason)) {
        $error = "Semua field (Anggota, Tipe SP, dan Alasan) harus diisi!";
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO warning_letters (employee_id, issued_by_employee_id, sp_type, reason)
                VALUES (?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("iiss", $employee_id, $user['id'], $sp_type, $reason);

            if ($stmt->execute()) {
                $success = "Surat peringatan **" . htmlspecialchars($sp_type) . "** berhasil dikeluarkan.";
                
                $employee_name = getEmployeeNameById($employee_id);
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'sp_type' => $sp_type,
                    'reason' => $reason,
                    'admin_name' => $user['name']
                ], 'warning_letter_issued');

            } else {
                throw new Exception("Gagal mengeluarkan surat peringatan: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Get all active employees for the dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Handle employee selection to show history
if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
    $selected_employee_id = (int)$_GET['employee_id'];
    
    foreach ($all_employees as $emp) {
        if ($emp['id'] === $selected_employee_id) {
            $selected_employee_name = htmlspecialchars($emp['name']);
            break;
        }
    }

    $stmt_history = $conn->prepare("
        SELECT wl.*, issued_by.name as issued_by_name
        FROM warning_letters wl
        LEFT JOIN employees issued_by ON wl.issued_by_employee_id = issued_by.id
        WHERE wl.employee_id = ?
        ORDER BY wl.issued_at DESC
    ");
    if ($stmt_history) {
        $stmt_history->bind_param("i", $selected_employee_id);
        $stmt_history->execute();
        $warning_history = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_history->close();
    } else {
        $error = "Gagal mengambil riwayat surat peringatan: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Surat Peringatan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .sp-form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--spacing-lg);
        }
        @media (min-width: 768px) {
            .sp-form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .warning-letter-item {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            background: var(--bg-secondary);
            box-shadow: var(--shadow-sm);
        }
        .sp-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing-md);
            border-bottom: 1px solid var(--border-light);
            padding-bottom: var(--spacing-md);
        }
        .sp-header h4 {
            font-size: 1.25rem;
            font-weight: 700;
        }
        .sp-badge {
            font-size: 0.9rem;
            font-weight: 700;
            padding: 0.4em 0.8em;
            border-radius: var(--radius-md);
            text-transform: uppercase;
        }
        .sp-badge.sp1 { background: #FFD54F; color: #FF6F00; }
        .sp-badge.sp2 { background: #FFB74D; color: #F57C00; }
        .sp-badge.sp3 { background: #FF8A65; color: #E64A19; }
        .sp-badge.phk { background: #E57373; color: #D32F2F; }

        .sp-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: var(--spacing-md);
        }
        .sp-reason {
            padding: var(--spacing-md);
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            border-left: 3px solid var(--danger-color);
        }
        .sp-reason p {
            margin: 0;
            white-space: pre-wrap; /* Mempertahankan format baris baru */
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
                    <span class="page-icon">📄</span>
                    Manajemen Surat Peringatan
                </h1>
                <p>Kelola surat peringatan dan PHK untuk anggota yang bermasalah.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Pilih Anggota</h3>
                </div>
                <div class="card-content">
                    <form method="GET" action="warning-management.php" class="form-group">
                        <label for="employee_select">Pilih Anggota:</label>
                        <select name="employee_id" id="employee_select" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Pilih Anggota --</option>
                            <?php foreach ($all_employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>" <?= ($selected_employee_id === $emp['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <?php if ($selected_employee_id): ?>
                <div class="card" style="margin-top: var(--spacing-xl);">
                    <div class="card-header">
                        <h3>Berikan Surat Peringatan untuk: <?= $selected_employee_name ?></h3>
                    </div>
                    <div class="card-content">
                        <form method="POST" action="warning-management.php?employee_id=<?= $selected_employee_id ?>">
                            <input type="hidden" name="action" value="issue_sp">
                            <input type="hidden" name="employee_id" value="<?= $selected_employee_id ?>">
                            
                            <div class="sp-form-grid">
                                <div class="form-group">
                                    <label for="sp_type">Tipe Surat Peringatan</label>
                                    <select name="sp_type" id="sp_type" class="form-select" required>
                                        <option value="">Pilih Tipe SP</option>
                                        <option value="SP1">SP1 (Surat Peringatan Pertama)</option>
                                        <option value="SP2">SP2 (Surat Peringatan Kedua)</option>
                                        <option value="SP3">SP3 (Surat Peringatan Ketiga)</option>
                                        <option value="PHK">PHK (Pemutusan Hubungan Kerja)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="reason">Alasan Pemberian SP</label>
                                    <textarea name="reason" id="reason" rows="4" class="form-textarea" placeholder="Jelaskan alasan SP diberikan..." required></textarea>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-danger">Keluarkan Surat Peringatan</button>
                        </form>
                    </div>
                </div>

                <div class="card" style="margin-top: var(--spacing-xl);">
                    <div class="card-header">
                        <h3>Riwayat Surat Peringatan: <?= $selected_employee_name ?></h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($warning_history)): ?>
                            <div class="no-data">Belum ada riwayat surat peringatan untuk anggota ini.</div>
                        <?php else: ?>
                            <div class="requests-list">
                                <?php foreach ($warning_history as $sp): ?>
                                    <div class="warning-letter-item">
                                        <div class="sp-header">
                                            <h4 class="sp-title">Surat Peringatan</h4>
                                            <span class="sp-badge <?= strtolower($sp['sp_type']) ?>">
                                                <?= htmlspecialchars($sp['sp_type']) ?>
                                            </span>
                                        </div>
                                        <div class="sp-meta">
                                            Dikeluarkan pada: <?= date('d/m/Y H:i', strtotime($sp['issued_at'])) ?><br>
                                            Oleh: <?= htmlspecialchars($sp['issued_by_name']) ?>
                                        </div>
                                        <div class="sp-reason">
                                            <strong>Alasan:</strong>
                                            <p><?= htmlspecialchars($sp['reason']) ?></p>
                                        </div>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus surat peringatan ini? Tindakan ini tidak dapat dibatalkan.')">
                                            <input type="hidden" name="action" value="delete_sp">
                                            <input type="hidden" name="sp_id" value="<?= $sp['id'] ?>">
                                            <input type="hidden" name="employee_id" value="<?= $selected_employee_id ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" style="margin-top: var(--spacing-md);">Hapus SP</button>
                                        </form>
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