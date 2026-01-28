<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- New Rounding Function ---
/**
 * Membulatkan total menit duty ke jam terdekat.
 */
function roundToNearestHour($minutes) {
    return round($minutes / 60);
}

// --- KONFIGURASI GAJI BARU ---
$RATE_PER_JAM = 200;       // Gaji per jam (setelah pembulatan)
$RATE_BONUS_PAKET = 200;   // Bonus per paket penjualan

// Definisi syarat minimal
$MIN_DUTY_HOURS_REQUIRED = 15; // Minimal 15 jam seminggu untuk dapat gaji duty
$TARGET_SALES_MAGANG = 35;     // Magang harus jual > 35 paket
$TARGET_SALES_STAFF = 60;      // Staff lain harus jual > 60 paket

$success_message = null;
$error_message = null;

// --- Handle Payment Status Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    $conn->begin_transaction();
    try {
        if ($action === 'reset_all_paid_status') {
            $stmt = $conn->prepare("UPDATE employees SET is_paid = FALSE WHERE status = 'active'");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query reset semua status pembayaran: " . $conn->error);
            }

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $conn->commit();
                $success_message = "Semua status gaji anggota berhasil diubah menjadi **Belum Dibayar**.";
                sendDiscordNotification([
                    'admin_name' => $user['name']
                ], 'salary_unpaid_all');
            } else {
                throw new Exception("Gagal mereset semua status pembayaran. Mungkin tidak ada yang perlu direset.");
            }
            $stmt->close();
            
        } elseif ($action === 'delete_all_activity_data') {
            if (!hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
                 throw new Exception("Anda tidak memiliki izin untuk menghapus semua data aktivitas.");
            }

            $stmt_delete_sales = $conn->prepare("DELETE FROM sales_data");
            if (!$stmt_delete_sales) {
                throw new Exception("Gagal menyiapkan query hapus data penjualan massal: " . $conn->error);
            }
            $stmt_delete_sales->execute();
            $deleted_sales_count = $stmt_delete_sales->affected_rows;
            $stmt_delete_sales->close();

            $stmt_delete_duty = $conn->prepare("DELETE FROM duty_logs WHERE status = 'completed'");
            if (!$stmt_delete_duty) {
                throw new Exception("Gagal menyiapkan query hapus log jam kerja massal: " . $conn->error);
            }
            $stmt_delete_duty->execute();
            $deleted_duty_count = $stmt_delete_duty->affected_rows;
            $stmt_delete_duty->close();

            $conn->commit();
            $success_message = "Semua data aktivitas (**{$deleted_sales_count} penjualan** dan **{$deleted_duty_count} log duty selesai**) berhasil dihapus untuk **SEMUA** anggota.";

            sendDiscordNotification([
                'admin_name' => $user['name'],
                'deleted_sales' => $deleted_sales_count,
                'deleted_duty_logs' => $deleted_duty_count,
                'action_type' => 'mass_activity_delete'
            ], 'admin_system_action');
        
        } else {
            if ($employee_id <= 0) {
                throw new Exception("ID anggota tidak valid.");
            }
            $employee_name = getEmployeeNameById($employee_id);

            if ($action === 'mark_paid') {
                $new_status = TRUE;
                $status_text = 'Sudah Dibayar';

                $stmt = $conn->prepare("UPDATE employees SET is_paid = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_status, $employee_id);

                if (!$stmt->execute() || $stmt->affected_rows === 0) {
                    throw new Exception("Gagal mengubah status pembayaran.");
                }
                $stmt->close();

                $conn->commit();
                $success_message = "Status gaji **" . htmlspecialchars($employee_name) . "** berhasil diubah menjadi **{$status_text}**.";
                
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'status' => $status_text,
                    'admin_name' => $user['name']
                ], 'salary_paid_single');
                
            } elseif ($action === 'mark_unpaid') {
                $new_status = FALSE;
                $status_text = 'Belum Dibayar';

                $stmt = $conn->prepare("UPDATE employees SET is_paid = ? WHERE id = ?");
                $stmt->bind_param("ii", $new_status, $employee_id);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $conn->commit();
                    $success_message = "Status gaji **" . htmlspecialchars($employee_name) . "** berhasil diubah menjadi **{$status_text}**.";
                    sendDiscordNotification([
                        'employee_name' => $employee_name,
                        'status' => $status_text,
                        'admin_name' => $user['name']
                    ], 'salary_unpaid_single');
                } else {
                    throw new Exception("Gagal mengubah status pembayaran.");
                }
                $stmt->close();
            } elseif ($action === 'delete_sales_data') {
                $stmt_delete_sales = $conn->prepare("DELETE FROM sales_data WHERE employee_id = ?");
                $stmt_delete_sales->bind_param("i", $employee_id);
                $stmt_delete_sales->execute();
                $stmt_delete_sales->close();

                $stmt_delete_duty = $conn->prepare("DELETE FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
                $stmt_delete_duty->bind_param("i", $employee_id);
                $stmt_delete_duty->execute();
                $stmt_delete_duty->close();

                $conn->commit();
                $success_message = "Semua data penjualan dan jam kerja untuk **" . htmlspecialchars($employee_name) . "** telah dihapus.";
            }
        }

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
    header("Location: salary-recap.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
    exit;
}

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

$employees_data = [];
$total_payroll_expenditure = 0;

// --- QUERY UTAMA ---
// [MODIFIKASI]: Menambahkan paket_vip_person (Royale) dan memfilter data masak
$stmt = $conn->query("
    SELECT e.id, e.name, e.role, e.is_on_duty, e.is_paid,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.paket_sake, 0) as paket_sake,
           COALESCE(sales_summary.paket_anggur_merah, 0) as paket_anggur_merah,
           COALESCE(sales_summary.paket_tuak, 0) as paket_tuak,
           COALESCE(sales_summary.paket_soju, 0) as paket_soju,
           COALESCE(sales_summary.paket_vip_person, 0) as paket_royale
    FROM employees e
    LEFT JOIN (
        SELECT employee_id, SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs WHERE status = 'completed' GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    LEFT JOIN (
        SELECT employee_id,
            SUM(paket_sake) as paket_sake,
            SUM(paket_anggur_merah) as paket_anggur_merah,
            SUM(paket_tuak) as paket_tuak,
            SUM(paket_soju) as paket_soju,
            SUM(paket_vip_person) as paket_vip_person
        FROM sales_data
        /* [PENTING] FILTER: Hanya ambil data yang BUKAN masak.
           Di sistem ini, jika spicy_1+2+3 > 0, itu adalah data dari data-masak.php.
           Kita exclude itu agar hanya menghitung Murni Sales. */
        WHERE (paket_spicy_1 + paket_spicy_2 + paket_spicy_3) = 0
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active'
    ORDER BY FIELD(e.role, 'ceo', 'direktur', 'wakil_direktur', 'manager', 'chef', 'waiters', 'karyawan', 'magang'), e.name
");
$employees_raw_data = $stmt->fetch_all(MYSQLI_ASSOC);

foreach ($employees_raw_data as $employee) {
    $employee_id = $employee['id'];
    $employee_role = $employee['role'];
    $total_duty_minutes = $employee['total_duty_minutes'];
    
    // Hitung Jam Kerja yang Dibulatkan
    $rounded_duty_hours = roundToNearestHour($total_duty_minutes);
    
    // [MODIFIKASI] Total Penjualan (Hanya Sales, tanpa Masak)
    // paket_royale diambil dari paket_vip_person
    $total_sales_packages = 
        ($employee['paket_sake'] ?? 0) + 
        ($employee['paket_anggur_merah'] ?? 0) + 
        ($employee['paket_tuak'] ?? 0) + 
        ($employee['paket_soju'] ?? 0) + 
        ($employee['paket_royale'] ?? 0); 
    // Catatan: Tidak menjumlahkan paket_spicy_... karena itu data masak.

    // --- LOGIKA PERHITUNGAN GAJI BARU ---
    $gaji_pokok = 0;        // Gaji Duty
    $bonus_penjualan = 0;   // Bonus Paket
    $total_gajian = 0;
    $keterangan_parts = [];
    $is_cut = false; 

    // 1. Perhitungan Gaji Duty (Semua Anggota)
    // Syarat: Minimal 15 Jam
    if ($rounded_duty_hours >= $MIN_DUTY_HOURS_REQUIRED) {
        $gaji_pokok = $rounded_duty_hours * $RATE_PER_JAM;
        $keterangan_parts[] = "Lulus Jam";
    } else {
        $gaji_pokok = 0;
        $keterangan_parts[] = "Gagal Jam (<{$MIN_DUTY_HOURS_REQUIRED}j)";
        $is_cut = true;
    }

    // 2. Perhitungan Bonus Penjualan
    // Tentukan threshold berdasarkan role
    if ($employee_role === 'magang') {
        $target_sales = $TARGET_SALES_MAGANG;
        $role_target_desc = "Magang > $TARGET_SALES_MAGANG";
    } else {
        // Staff keatas (semua selain magang)
        $target_sales = $TARGET_SALES_STAFF;
        $role_target_desc = "Staff > $TARGET_SALES_STAFF";
    }

    // Cek apakah LEBIH DARI target
    if ($total_sales_packages > $target_sales) {
        $bonus_penjualan = $total_sales_packages * $RATE_BONUS_PAKET;
        $keterangan_parts[] = "Bonus Aktif";
    } else {
        $bonus_penjualan = 0;
        $keterangan_parts[] = "No Bonus ($role_target_desc)";
    }

    // Total Gaji Akhir
    $total_gajian = $gaji_pokok + $bonus_penjualan;
    
    $keterangan_gaji = implode(", ", $keterangan_parts);

    $total_payroll_expenditure += $total_gajian;

    $employees_data[] = [
        'id' => $employee['id'],
        'name' => $employee['name'],
        'role' => $employee['role'],
        'is_paid' => (bool)$employee['is_paid'],
        'total_duty_minutes' => $total_duty_minutes,
        'total_duty_hours' => $total_duty_minutes / 60,
        'rounded_duty_hours' => $rounded_duty_hours,
        'total_sales_packages' => $total_sales_packages,
        'gaji_pokok' => $gaji_pokok, 
        'bonus_penjualan' => $bonus_penjualan,
        'total_gajian' => $total_gajian,
        'is_cut' => $is_cut,
        'keterangan_gaji' => $keterangan_gaji
    ];
}

// === EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rekap_gajian_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = [
        'Nama',
        'Jabatan',
        'Total Jam Duty (Jam Asli)',
        'Total Jam Duty (Bulat)',
        'Total Penjualan (Murni Sales)',
        'Gaji Duty (Rp)',
        'Bonus Penjualan (Rp)',
        'Total Terima (Rp)',
        'Keterangan',
        'Status'
    ];
    fputcsv($output, $headers);

    foreach ($employees_data as $row) {
        $data_row = [
            htmlspecialchars_decode($row['name']),
            getRoleDisplayName($row['role']),
            number_format($row['total_duty_minutes'] / 60, 2),
            $row['rounded_duty_hours'],
            $row['total_sales_packages'],
            $row['gaji_pokok'],
            $row['bonus_penjualan'],
            $row['total_gajian'],
            $row['keterangan_gaji'],
            $row['is_paid'] ? 'Sudah Dibayar' : 'Belum Dibayar'
        ];
        fputcsv($output, $data_row);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji Baru - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .payslip-status {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .payslip-status.paid {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .payslip-status.unpaid {
            background-color: var(--warning-light);
            color: var(--warning-color);
        }
        .action-column {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: flex-end;
        }
        .action-column .btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        .salary-breakdown {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        @media (max-width: 1024px) {
            .activities-table-improved th,
            .activities-table-improved td {
                padding: 0.6rem;
            }
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
                    <span class="page-icon">💸</span>
                    Rekap Gaji (Sistem Baru)
                </h1>
                <p>Syarat: Min 15 Jam Duty & Target Penjualan (Murni Sales, Tanpa Masak)</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="salary-recap.php?export=spreadsheet" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span> Unduh CSV
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin MERESET status pembayaran semua anggota?')">
                        <input type="hidden" name="action" value="reset_all_paid_status">
                        <button type="submit" class="btn btn-warning">
                            <span class="btn-icon">🔄</span> Reset Status Bayar
                        </button>
                    </form>
                    <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur'])): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ Hapus SEMUA data aktivitas? Tidak bisa dibatalkan.')">
                        <input type="hidden" name="action" value="delete_all_activity_data">
                        <button type="submit" class="btn btn-danger">
                            <span class="btn-icon">🗑️</span> Hapus Data Aktivitas
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">💲</div>
                    <div class="summary-content">
                        <h4>Total Pengeluaran</h4>
                        <p class="summary-value"><?= 'Rp ' . number_format($total_payroll_expenditure, 0, ',', '.') ?></p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">👥</div>
                    <div class="summary-content">
                        <h4>Total Anggota</h4>
                        <p class="summary-value"><?= count($employees_data) ?> Orang</p>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Detail Perhitungan</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama / Jabatan</th>
                                    <th>Jam Duty</th>
                                    <th>Penjualan (Sales)</th>
                                    <th>Gaji Duty</th>
                                    <th>Bonus Sales</th>
                                    <th>Total Terima</th>
                                    <th>Keterangan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees_data)): ?>
                                    <tr><td colspan="8" class="no-data">Belum ada data.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($employees_data as $employee): ?>
                                    <tr data-employee-id="<?= $employee['id'] ?>">
                                        <td data-label="Nama">
                                            <div class="employee-name-cell">
                                                <span class="employee-avatar-small"><?= strtoupper(substr($employee['name'], 0, 1)) ?></span>
                                                <div>
                                                    <strong><?= htmlspecialchars($employee['name']) ?></strong><br>
                                                    <small class="role-badge role-<?= $employee['role'] ?>"><?= getRoleDisplayName($employee['role']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Jam Duty">
                                            <strong><?= $employee['rounded_duty_hours'] ?> Jam</strong>
                                            <div class="salary-breakdown">(<?= number_format($employee['total_duty_minutes'] / 60, 1) ?> asli)</div>
                                        </td>
                                        <td data-label="Penjualan">
                                            <strong><?= $employee['total_sales_packages'] ?> Paket</strong>
                                        </td>
                                        <td data-label="Gaji Duty">
                                            Rp <?= number_format($employee['gaji_pokok'], 0, ',', '.') ?>
                                        </td>
                                        <td data-label="Bonus Sales">
                                            Rp <?= number_format($employee['bonus_penjualan'], 0, ',', '.') ?>
                                        </td>
                                        <td data-label="Total Terima">
                                            <strong style="font-size: 1.1em; color: var(--success-color);">
                                                Rp <?= number_format($employee['total_gajian'], 0, ',', '.') ?>
                                            </strong>
                                            <div style="margin-top: 5px;">
                                                <span class="payslip-status <?= $employee['is_paid'] ? 'paid' : 'unpaid' ?>">
                                                    <?= $employee['is_paid'] ? 'Lunas' : 'Belum' ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td data-label="Keterangan">
                                            <span style="font-size: 0.85rem; font-weight: 500; color: <?= $employee['is_cut'] ? 'var(--danger-color)' : 'var(--text-color)' ?>">
                                                <?= $employee['keterangan_gaji'] ?>
                                            </span>
                                        </td>
                                        <td data-label="Aksi" class="action-column">
                                            <?php if (!$employee['is_paid']): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Tandai SUDAH DIBAYAR?')">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button class="btn btn-success btn-sm">✔ Bayar</button>
                                            </form>
                                            <?php else: ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Batalkan status bayar?')">
                                                <input type="hidden" name="action" value="mark_unpaid">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button class="btn btn-warning btn-sm">✖ Batal</button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline; margin-top:5px;" onsubmit="return confirm('Hapus data aktivitas orang ini?')">
                                                <input type="hidden" name="action" value="delete_sales_data">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button class="btn btn-danger btn-sm">🗑️ Reset</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="script.js"></script>
</body>
</html>