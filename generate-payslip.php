<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

$employee_id_to_view = 0;

if (isset($_GET['employee_id'])) {
    $requested_id = (int)$_GET['employee_id'];
    
    if ($is_admin_or_manager) {
        $employee_id_to_view = $requested_id;
    } elseif ($requested_id === $user['id']) {
        $employee_id_to_view = $user['id'];
    } else {
        header('Location: my-payslip.php');
        exit;
    }
} else {
    $employee_id_to_view = $user['id'];
}

if ($employee_id_to_view <= 0) {
    die("ID anggota tidak valid atau tidak diberikan.");
}

// --- New Rounding Function ---
/**
 * Membulatkan total menit duty ke jam terdekat.
 * 2 jam 29 menit -> 2 jam.
 * 2 jam 30 menit -> 3 jam.
 */
function roundToNearestHour($minutes) {
    // PHP's round() function naturally handles X.5 up, which fits the 30-minute rule.
    return round($minutes / 60);
}

// --- LOGIKA GAJI BARU ---
$hourly_rates = [
    'ceo' => 40000,          
    'direktur' => 40000,     
    'wakil_direktur' => 40000, 
    'manager' => 24400,
    'guard' => 19200,
    'barista' => 19200,
    'waiters' => 14000,
    'karyawan' => 14000,
    'magang' => 9600,
    'chef' => 0, 
];

// Konstanta perhitungan (dalam jam)
$MIN_DUTY_FULL_PAY_HOURS = 10;
$MIN_DUTY_40_CUT_HOURS = 8;
// --- AKHIR LOGIKA GAJI BARU ---

// --- LANGKAH 1: Ambil Data Dasar Karyawan dan Status Bayar ---
$stmt = $conn->prepare("
    SELECT id, name, role, is_paid
    FROM employees
    WHERE id = ? AND status = 'active'
");
if (!$stmt) {
    die("Gagal menyiapkan query base: " . $conn->error); 
}
$stmt->bind_param("i", $employee_id_to_view);
$stmt->execute();
$employee_data_raw = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee_data_raw) {
    die("Data slip gaji tidak ditemukan untuk ID anggota ini.");
}

// --- LANGKAH 2: Ambil Total Duty Minutes (Query terpisah agar stabil) ---
$stmt_duty = $conn->prepare("
    SELECT COALESCE(SUM(duration_minutes), 0) as total_duty_minutes
    FROM duty_logs
    WHERE employee_id = ? AND status = 'completed'
");
if (!$stmt_duty) {
    die("Gagal menyiapkan query duty: " . $conn->error); 
}
$stmt_duty->bind_param("i", $employee_id_to_view);
$stmt_duty->execute();
$duty_result = $stmt_duty->get_result()->fetch_assoc();
$stmt_duty->close();


// Inisialisasi variabel dari hasil query
$employee_role = $employee_data_raw['role'];
$total_duty_minutes = (int)($duty_result['total_duty_minutes'] ?? 0);
$is_paid = (bool)($employee_data_raw['is_paid'] ?? false);


// --- PERHITUNGAN GAJI UTAMA ---
// 1. Hitung Jam Kerja yang Dibulatkan
$rounded_duty_hours = roundToNearestHour($total_duty_minutes);

$gaji_pokok_base = 0; // Gaji Pokok (Base Pay) 100% sebelum potongan
$total_gajian = 0; // Gaji Akhir yang Dibayarkan
$keterangan_gaji = 'N/A';
$is_cut = false;
$cut_percentage_display = 0;

if (isset($hourly_rates[$employee_role])) {
    $hourly_rate = $hourly_rates[$employee_role];

    // Gaji Pokok (Base Pay) berdasarkan jam yang dibulatkan
    $gaji_pokok_base = $rounded_duty_hours * $hourly_rate;

    // 1. Peran Khusus (Chef)
    if (in_array($employee_role, ['chef'])) {
        $keterangan_gaji = 'Tidak Digaji/Jabatan Khusus';
        $gaji_pokok = 0;
        $total_gajian = 0;
    }
    // 2. Peran Senior (CEO, Direktur, Wakil Direktur)
    elseif (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])) {
        $total_gajian = $gaji_pokok_base;
        $keterangan_gaji = 'Full Pay (Senior)';
    }
    // 3. Perhitungan Gaji Operasional dengan Potongan
    else {
        if ($rounded_duty_hours >= $MIN_DUTY_FULL_PAY_HOURS) {
            // Full Pay (>= 10 jam)
            $total_gajian = $gaji_pokok_base;
            $keterangan_gaji = 'Lulus Syarat (Full Pay)';
        } elseif ($rounded_duty_hours >= $MIN_DUTY_40_CUT_HOURS) {
            // Potongan 40% (8 jam <= Duty < 10 jam)
            $cut_percentage_display = 40;
            $total_gajian = $gaji_pokok_base * 0.60; // Pay 60%
            $keterangan_gaji = 'Potongan 40% (Duty < 10j)';
            $is_cut = true;
        } else {
            // Potongan 50% (< 8 jam)
            $cut_percentage_display = 50;
            $total_gajian = $gaji_pokok_base * 0.50; // Pay 50%
            $keterangan_gaji = 'Potongan 50% (Duty < 8j)';
            $is_cut = true;
        }
    }
}
// --- AKHIR PERHITUNGAN GAJI UTAMA ---

// Total Gaji (Bersih)
// $total_gajian sudah dihitung di atas.
$gaji_pokok = $gaji_pokok_base; // Untuk display Base Pay di slip gaji


// Helper function untuk format rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}

// Helper function untuk format durasi
function formatDuration($minutes) {
    if ($minutes < 0) return "0 jam 0 menit";
    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    return "{$hours} jam {$remainingMinutes} menit";
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Slip Gaji - <?= htmlspecialchars($employee_data_raw['name']) ?></title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
            line-height: 1.6;
        }
        .payslip-container {
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 30px;
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding-left: 120px;
            padding-right: 120px;
            box-sizing: border-box;
        }
        .header h1 {
            margin: 0;
            font-size: 2em;
            font-weight: 900;
            color: #121212;
            flex-shrink: 0;
        }
        .header p {
            margin: 5px 0 0;
            font-size: 0.9em;
            color: #666;
            flex-shrink: 0;
        }
        .header-content-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
        }
        .logo-header {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 120px;
            height: auto;
            object-fit: contain;
            z-index: 10;
        }
        .logo-left {
            left: 0px;
        }
        .logo-right {
            right: 0px;
        }
        .section-title {
            font-size: 1.2em;
            font-weight: bold;
            margin-top: 25px;
            margin-bottom: 15px;
            color: #3b82f6;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
            margin-bottom: 20px;
        }
        .info-item span:first-child {
            font-weight: bold;
            color: #555;
            min-width: 120px;
            display: inline-block;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        table th, table td {
            border: 1px solid #eee;
            padding: 10px;
            text-align: left;
            font-size: 14px;
        }
        table th {
            background-color: #f0f0f0;
            color: #555;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background-color: #e6f0fa;
            color: #3b82f6;
        }
        .total-row td {
            font-size: 1.1em;
        }
        .grand-total-row td {
            font-size: 16px;
            font-weight: 900;
            background-color: #d1ffd1;
            border-top: 3px solid #333;
        }
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            font-size: 0.9em;
        }
        .signature-box {
            text-align: center;
            width: 30%;
        }
        .signature-box p {
            margin-top: 60px;
            border-top: 1px solid #333;
            padding-top: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            font-size: 0.8em;
            color: #888;
        }
        .note-custom {
            margin-top: 15px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 13px;
            color: #555;
        }
        .note-red {
             color: #dc3545;
             border-color: #dc3545;
             background-color: #fcebeb;
        }
        .note-orange {
             color: #fd7e14;
             border-color: #fd7e14;
             background-color: #fff4e6;
        }
        /* Print styles */
        @media print {
            body {
                background-color: #fff;
                margin: 0;
                padding: 0;
            }
            .payslip-container {
                box-shadow: none;
                border: none;
                margin: 0;
                width: 100%;
                padding: 15px;
            }
            .btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="payslip-container">
        <div class="header">
            <img src="LOGO_WOT.png" alt="Logo Kiri" class="logo-header logo-left">
            <div class="header-content-center">
                <h1 class="payslip-header-title">SLIP GAJI KARYAWAN <br> ELYSIUM NIGHT CLUB</h1>
                <p>Data Akumulatif Duty</p>
            </div>
            <img src="LOGO_WOT.png" alt="Logo Kanan" class="logo-header logo-right">
        </div>

        <div class="section-title">Informasi Karyawan</div>
        <div class="info-grid">
            <div class="info-item"><span>Nama:</span> <?= htmlspecialchars($employee_data_raw['name']) ?></div>
            <div class="info-item"><span>Jabatan:</span> <?= getRoleDisplayName($employee_data_raw['role']) ?></div>
            <div class="info-item"><span>ID Karyawan:</span> <?= $employee_data_raw['id'] ?></div>
            <div class="info-item"><span>Tanggal Cetak:</span> <?= date('d/m/Y H:i') ?></div>
            <div class="info-item"><span>Jam Duty (Asli):</span> <?= formatDuration($total_duty_minutes) ?></div>
            <div class="info-item"><span>Jam Duty (Bulat):</span> 
                <span style="font-weight: bold; color: #3b82f6;">
                    <?= $rounded_duty_hours ?> jam
                </span>
            </div>
            <div class="info-item"><span>Status Pembayaran:</span>
                <span style="color: <?= $is_paid ? 'green' : 'red' ?>; font-weight: bold;">
                    <?= $is_paid ? 'SUDAH DIBAYARKAN' : 'BELUM DIBAYARKAN' ?>
                </span>
            </div>
        </div>

        <div class="section-title">Ringkasan Gaji Bersih</div>
        <table>
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th style="width: 30%; text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Gaji Pokok (Base Pay)</td>
                    <td style="text-align: right;"><?= formatRupiah($gaji_pokok) ?></td>
                </tr>
                <?php if ($is_cut): ?>
                <tr class="total-row" style="background-color: #fcebeb; color: #dc3545;">
                    <td>Potongan Gaji (<?= $cut_percentage_display ?>%)</td>
                    <td style="text-align: right;">- <?= formatRupiah($gaji_pokok - $total_gajian) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total-row">
                    <td>TOTAL GAJI BERSIH</td>
                    <td style="text-align: right;"><?= formatRupiah($total_gajian) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (in_array($employee_role, ['chef'])): ?>
            <div class="note-custom note-red">
                **Keterangan:** Jabatan Anda adalah **<?= getRoleDisplayName($employee_role) ?>**. Sesuai aturan, Anda **TIDAK** mendapatkan gaji operasional per jam.
            </div>
        <?php elseif (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])): ?>
             <div class="note-custom" style="border-left: 3px solid var(--success-color);">
                **Keterangan:** Jabatan Anda adalah **<?= getRoleDisplayName($employee_role) ?>**. Gaji dihitung **Full Pay (Senior)** berdasarkan jam duty yang dibulatkan (<?= $rounded_duty_hours ?> jam).
            </div>
        <?php elseif ($is_cut): ?>
            <div class="note-custom note-red">
                **Keterangan:** Gaji Anda dikenakan **potongan <?= $cut_percentage_display ?>%** karena total jam duty yang dibulatkan (<?= $rounded_duty_hours ?> jam) **dibawah** <?= $MIN_DUTY_FULL_PAY_HOURS ?> jam.
            </div>
        <?php else: ?>
            <div class="note-custom" style="border-left: 3px solid var(--success-color);">
                **Keterangan:** Gaji Anda dihitung **Full Pay** berdasarkan jam duty yang dibulatkan (<?= $rounded_duty_hours ?> jam) karena sudah mencapai minimal <?= $MIN_DUTY_FULL_PAY_HOURS ?> jam.
            </div>
        <?php endif; ?>

        <div class="signature-section">
            <div class="signature-box">
                Diterima Oleh,<br>
                Karyawan Ybs.
                <p>(<?= htmlspecialchars($employee_data_raw['name']) ?>)</p>
            </div>
            <div class="signature-box">
                Dibuat Oleh,<br>
                Admin Elysium Night Club
                <p>(Admin)</p>
            </div>
        </div>

        <div class="footer">
            <p>Slip gaji ini dibuat secara otomatis dan berlaku tanpa tanda tangan basah.</p>
        </div>

        <button onclick="window.print()" class="btn btn-primary" style="display: block; margin: 20px auto;">Cetak Slip Gaji</button>
    </div>
</body>
</html>