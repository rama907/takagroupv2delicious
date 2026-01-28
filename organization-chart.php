<?php
require_once 'config.php';

// Redirect to login page if not logged in
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

global $conn; // Mengakses koneksi database global

$success_message = null;
$error_message = null;

// Fungsi bantu untuk meng-escape teks agar aman untuk label node Mermaid.js
// Ini akan meng-escape backslash dan tanda kutip ganda yang bisa merusak sintaks Mermaid
function escape_for_mermaid_label($text) {
    // Escape backslashes first (penting agar tidak terjadi masalah dengan escaping kutip setelahnya)
    $text = str_replace('\\', '\\\\', $text);
    // Escape double quotes agar tidak merusak string yang dibungkus tanda kutip di Mermaid
    $text = str_replace('"', '\"', $text);
    return $text;
}


// --- Bagian 1: Menangani Pengiriman Form (Simpan Perubahan Bagan) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_chart_assignments') {
    $assignments_data = $_POST['assignments'] ?? [];
    $current_user_id = getCurrentUser()['id'] ?? null;

    $conn->begin_transaction();
    try {
        foreach ($assignments_data as $position_key => $employee_id_str) {
            $employee_id = null; // Default to NULL if no employee selected or empty string
            if ($employee_id_str !== '' && $employee_id_str !== 'null') { // Check if a valid employee_id is selected
                $employee_id = (int)$employee_id_str;
            }

            // Gunakan INSERT ... ON DUPLICATE KEY UPDATE untuk handle insert dan update
            $stmt_check_insert = $conn->prepare("INSERT INTO chart_assignments (position_key, employee_id, assigned_by_employee_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id), assigned_by_employee_id = VALUES(assigned_by_employee_id)");
            if (!$stmt_check_insert) {
                throw new Exception("Gagal menyiapkan query insert/update: " . $conn->error);
            }
            $stmt_check_insert->bind_param("sii", $position_key, $employee_id, $current_user_id);
            $stmt_check_insert->execute();
            $stmt_check_insert->close();
        }

        $conn->commit();
        $success_message = "Penugasan bagan berhasil disimpan!";
        // Opsional: Kirim notifikasi Discord jika ada perubahan besar
        // sendDiscordNotification(['action' => 'chart_update', 'admin' => getCurrentUser()['name']], 'info');

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Gagal menyimpan penugasan bagan: " . $e->getMessage();
    }
    // Redirect untuk menghindari resubmission form
    header("Location: organization-chart.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
    exit;
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


// --- Bagian 2: Mengambil Data untuk Menampilkan Bagan dan Form ---

// Mengambil semua anggota aktif dari database untuk dropdown
$stmt_employees = $conn->prepare("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name ASC");
$stmt_employees->execute();
$all_active_employees = $stmt_employees->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_employees->close();

// Mengambil penugasan yang sudah tersimpan dari tabel chart_assignments
$current_assignments_db = [];
$stmt_chart_assignments = $conn->query("SELECT position_key, employee_id FROM chart_assignments");
if ($stmt_chart_assignments) {
    while ($row = $stmt_chart_assignments->fetch_assoc()) {
        $current_assignments_db[$row['position_key']] = $row['employee_id'];
    }
    $stmt_chart_assignments->free();
} else {
    // Jika tabel belum ada (misal, user belum menjalankan SQL), set $current_assignments_db kosong
    $current_assignments_db = [];
    error_log("Tabel 'chart_assignments' belum ada atau error saat mengambil data: " . $conn->error);
}


// Definisikan semua posisi dalam bagan dan defaultnya
$chart_positions_definitions = [
    'CEO'                       => ['label' => 'CEO', 'class' => 'ceo-style'],
    'Direktur Operasional'      => ['label' => 'Direktur Operasional', 'class' => 'director-style'],
    'Direktur Keuangan'         => ['label' => 'Direktur Keuangan', 'class' => 'director-style'],
    'Direktur HR'               => ['label' => 'Direktur HR', 'class' => 'director-style'],
    'Kepala HRD'                => ['label' => 'Kepala HRD', 'class' => 'dept-head-style'],
    'Kepala Administrasi'       => ['label' => 'Kepala Administrasi', 'class' => 'dept-head-style'],
    'Kepala Finance'            => ['label' => 'Kepala Finance', 'class' => 'dept-head-style'],
    'Kepala Marketing & Sales'  => ['label' => 'Kepala Marketing & Sales', 'class' => 'dept-head-style'],
    'Kepala Manajemen Gudang'   => ['label' => 'Kepala Manajemen Gudang', 'class' => 'dept-head-style'],
    'Kepala Chef'               => ['label' => 'Kepala Chef', 'class' => 'team-head-style'],
    'Kepala Kasir & Pramusaji'  => ['label' => 'Kepala Kasir & Pramusaji', 'class' => 'team-head-style'],
    'Koordinator Lapangan'      => ['label' => 'Koordinator Lapangan', 'class' => 'team-head-style'],
];

// Tetapkan nama anggota ke posisi di bagan, mengambil dari database atau placeholder
$chart_members_for_mermaid = [];
foreach ($chart_positions_definitions as $key => $details) {
    $employee_id_assigned = $current_assignments_db[$key] ?? null;
    $assigned_name = "{$details['label']} (BELUM DITENTUKAN)"; // Default placeholder

    if ($employee_id_assigned !== null) {
        foreach ($all_active_employees as $emp) {
            if ($emp['id'] === (int)$employee_id_assigned) {
                $assigned_name = $emp['name'];
                break;
            }
        }
    }
    // Meng-escape nama menggunakan fungsi baru yang lebih aman untuk Mermaid
    $chart_members_for_mermaid[$key] = escape_for_mermaid_label($assigned_name);
}


// Membangun sintaks Mermaid.js untuk ditampilkan
$mermaid_syntax = "graph TD\n";

// Definisi kelas gaya untuk Mermaid.js (ini adalah bagian dari sintaks Mermaid)
$mermaid_syntax .= "    classDef ceo-style fill:#FFEBEE,stroke:#FFCDD2,stroke-width:2px,color:#B71C1C;\n";
$mermaid_syntax .= "    classDef director-style fill:#E3F2FD,stroke:#BBDEFB,color:#2196F3;\n";
$mermaid_syntax .= "    classDef dept-head-style fill:#E8F5E9,stroke:#C8E6C9,color:#4CAF50;\n";
$mermaid_syntax .= "    classDef team-head-style fill:#FFFDE7,stroke:#FFF9C4,color:#FFC107;\n\n";

// Level 0: CEO
$mermaid_syntax .= "    CEO_Node[\"{$chart_members_for_mermaid['CEO']} <br/> CEO\"]:::ceo-style\n\n";

// Level 1: Direksi (Directors)
$mermaid_syntax .= "    CEO_Node --> D_Operasional[\"{$chart_members_for_mermaid['Direktur Operasional']} <br/> Direktur Operasional\"]:::director-style\n";
$mermaid_syntax .= "    CEO_Node --> D_Keuangan[\"{$chart_members_for_mermaid['Direktur Keuangan']} <br/> Direktur Keuangan\"]:::director-style\n";
$mermaid_syntax .= "    CEO_Node --> D_HR[\"{$chart_members_for_mermaid['Direktur HR']} <br/> Direktur HR\"]:::director-style\n\n";

// Level 2: Kepala/Ketua Tim (Department Heads)
// Di bawah Direktur HR
$mermaid_syntax .= "    D_HR --> K_HRD[\"{$chart_members_for_mermaid['Kepala HRD']} <br/> Kepala HRD\"]:::dept-head-style\n";
$mermaid_syntax .= "    D_HR --> K_Admin[\"{$chart_members_for_mermaid['Kepala Administrasi']} <br/> Kepala Administrasi]\"]:::dept-head-style\n\n";

// Di bawah Direktur Keuangan
$mermaid_syntax .= "    D_Keuangan --> K_Finance[\"{$chart_members_for_mermaid['Kepala Finance']} <br/> Kepala Finance\"]:::dept-head-style\n\n";

// Di bawah Direktur Operasional
$mermaid_syntax .= "    D_Operasional --> K_MarketingSales[\"{$chart_members_for_mermaid['Kepala Marketing & Sales']} <br/> Kepala Marketing & Sales]\"]:::dept-head-style\n";
$mermaid_syntax .= "    D_Operasional --> K_Warehouse[\"{$chart_members_for_mermaid['Kepala Manajemen Gudang']} <br/> Kepala Manajemen Gudang]\"]:::dept-head-style\n\n";

// Level 3: Kepala/Ketua Tim (Function Heads)
// Di bawah Finance
$mermaid_syntax .= "    K_Finance --> KCashier[\"{$chart_members_for_mermaid['Kepala Kasir & Pramusaji']} <br/> Kepala Kasir & Pramusaji]\"]:::team-head-style\n\n";

// Di bawah Marketing & Sales
$mermaid_syntax .= "    K_MarketingSales --> KChef[\"{$chart_members_for_mermaid['Kepala Chef']} <br/> Kepala Chef]\"]:::team-head-style\n\n";

// Di bawah Manajemen Gudang
$mermaid_syntax .= "    K_Warehouse --> KKoordinator[\"{$chart_members_for_mermaid['Koordinator Lapangan']} <br/> Koordinator Lapangan]\"]:::team-head-style\n";

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struktur Perusahaan - Warung Om Tante</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Gaya khusus untuk halaman struktur perusahaan */
        .organization-chart-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
            margin-top: var(--spacing-xl);
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column; /* Pusatkan konten secara vertikal */
            overflow-x: auto; /* Tambahkan scroll jika bagan terlalu lebar */
        }

        .organization-chart-container h2 {
            font-size: 1.8rem;
            color: var(--text-primary);
            margin-bottom: var(--spacing-lg);
            text-align: center;
        }

        .mermaid {
            background-color: var(--bg-card);
            padding: var(--spacing-md);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            fill: var(--text-primary);
            font-family: 'Arial', sans-serif;
            min-width: 1600px; /* Sesuaikan ukuran bagan */
            height: auto;
        }

        /* --- Custom class styles for Mermaid nodes (Light Mode) --- */
        .mermaid .node.ceo-style rect,
        .mermaid .node.ceo-style circle,
        .mermaid .node.ceo-style polygon {
            fill: #FFEBEE !important; /* Light Red */
            stroke: #FFCDD2 !important; /* Lighter Red Border */
            stroke-width: 2px !important;
        }
        .mermaid .node.ceo-style text {
            fill: #B71C1C !important; /* Dark Red Text */
            font-weight: bold !important;
        }

        .mermaid .node.director-style rect,
        .mermaid .node.director-style circle,
        .mermaid .node.director-style polygon {
            fill: #E3F2FD !important; /* Light Blue */
            stroke: #BBDEFB !important; /* Lighter Blue Border */
        }
        .mermaid .node.director-style text {
            fill: #2196F3 !important; /* Blue Text */
        }

        .mermaid .node.dept-head-style rect,
        .mermaid .node.dept-head-style circle,
        .mermaid .node.dept-head-style polygon {
            fill: #E8F5E9 !important; /* Light Green */
            stroke: #C8E6C9 !important; /* Lighter Green Border */
        }
        .mermaid .node.dept-head-style text {
            fill: #4CAF50 !important; /* Green Text */
        }

        .mermaid .node.team-head-style rect,
        .mermaid .node.team-head-style circle,
        .mermaid .node.team-head-style polygon {
            fill: #FFFDE7 !important; /* Light Yellow */
            stroke: #FFF9C4 !important; /* Lighter Yellow Border */
        }
        .mermaid .node.team-head-style text {
            fill: #FFC107 !important; /* Yellow Text */
        }

        /* --- Dark Mode Overrides for Custom Classes --- */
        /* Menggunakan data-theme="dark" jika Anda menggunakan JS untuk toggle tema */
        html[data-theme="dark"] .mermaid .node.ceo-style rect,
        html[data-theme="dark"] .mermaid .node.ceo-style circle,
        html[data-theme="dark"] .mermaid .node.ceo-style polygon {
            fill: #420F0F !important; /* Darker Red */
            stroke: #8D1C1C !important; /* Red Border */
        }
        html[data-theme="dark"] .mermaid .node.ceo-style text {
            fill: #FFCDD2 !important; /* Lighter Red Text */
        }

        html[data-theme="dark"] .mermaid .node.director-style rect,
        html[data-theme="dark"] .mermaid .node.director-style circle,
        html[data-theme="dark"] .mermaid .node.director-style polygon {
            fill: #1A237E !important; /* Darker Blue */
            stroke: #5C6BC0 !important; /* Blue Border */
        }
        html[data-theme="dark"] .mermaid .node.director-style text {
            fill: #BBDEFB !important; /* Lighter Blue Text */
        }

        html[data-theme="dark"] .mermaid .node.dept-head-style rect,
        html[data-theme="dark"] .mermaid .node.dept-head-style circle,
        html[data-theme="dark"] .mermaid .node.dept-head-style polygon {
            fill: #1B5E20 !important; /* Darker Green */
            stroke: #66BB6A !important; /* Green Border */
        }
        html[data-theme="dark"] .mermaid .node.dept-head-style text {
            fill: #C8E6C9 !important; /* Lighter Green Text */
        }

        html[data-theme="dark"] .mermaid .node.team-head-style rect,
        html[data-theme="dark"] .mermaid .node.team-head-style circle,
        html[data-theme="dark"] .mermaid .node.team-head-style polygon {
            fill: #42420F !important; /* Darker Yellow/Brown */
            stroke: #9E9D24 !important; /* Yellow Border */
        }
        html[data-theme="dark"] .mermaid .node.team-head-style text {
            fill: #FFFACD !important; /* Lighter Yellow Text */
        }

        /* Jika Anda menggunakan @media (prefers-color-scheme: dark) untuk tema,
           pastikan blok CSS ini juga ada (atau cukup pakai yang data-theme="dark") */
        @media (prefers-color-scheme: dark) {
            .mermaid .node.ceo-style rect, .mermaid .node.ceo-style circle, .mermaid .node.ceo-style polygon { fill: #420F0F !important; stroke: #8D1C1C !important; }
            .mermaid .node.ceo-style text { fill: #FFCDD2 !important; }
            .mermaid .node.director-style rect, .mermaid .node.director-style circle, .mermaid .node.director-style polygon { fill: #1A237E !important; stroke: #5C6BC0 !important; }
            .mermaid .node.director-style text { fill: #BBDEFB !important; }
            .mermaid .node.dept-head-style rect, .mermaid .node.dept-head-style circle, .mermaid .node.dept-head-style polygon { fill: #1B5E20 !important; stroke: #66BB6A !important; }
            .mermaid .node.dept-head-style text { fill: #C8E6C9 !important; }
            .mermaid .node.team-head-style rect, .mermaid .node.team-head-style circle, .mermaid .node.team-head-style polygon { fill: #42420F !important; stroke: #9E9D24 !important; }
            .mermaid .node.team-head-style text { fill: #FFFACD !important; }
        }


        @media (max-width: 768px) {
            .organization-chart-container {
                padding: var(--spacing-lg);
            }
            .organization-chart-container h2 {
                font-size: 1.5rem;
            }
            .mermaid {
                min-width: 800px;
            }
        }

        .chart-edit-section {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
            margin-top: var(--spacing-2xl);
            width: 100%;
        }

        .chart-edit-section .form-group {
            margin-bottom: var(--spacing-md);
        }

        .chart-edit-section label {
            display: block;
            margin-bottom: var(--spacing-xs);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.875rem;
        }

        .chart-edit-section .form-select {
            width: 100%;
            padding: var(--spacing-sm);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }

        .chart-edit-section .btn-primary {
            margin-top: var(--spacing-lg);
            width: auto;
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
                    <span class="page-icon">🏛️</span>
                    Struktur Perusahaan
                </h1>
                <p>Lihat dan kelola bagan organisasi Warung Om Tante.</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="organization-chart-container">
                <h2>Bagan Struktur Organisasi Warung Om Tante</h2>

                <div class="info-message" style="margin-bottom: var(--spacing-xl); text-align: left;">
                    <strong>💡 Info:</strong> Gunakan formulir di bawah ini untuk menetapkan anggota ke posisi di bagan. Perubahan akan disimpan di database.
                </div>

                <div class="mermaid">
                    <?php echo $mermaid_syntax; ?>
                </div>
                
                <p class="detail-text" style="margin-top: var(--spacing-lg); color: var(--text-muted);">
                    Bagan ini digenerasi langsung dari data anggota di website menggunakan Mermaid.js.
                </p>
            </div>

            <?php if (hasRole(['direktur', 'wakil_direktur', 'manager'])): ?>
            <div class="chart-edit-section">
                <div class="card-header">
                    <h3>Ubah Penugasan Anggota di Bagan</h3>
                </div>
                <div class="card-content">
                    <form method="POST" action="organization-chart.php">
                        <input type="hidden" name="action" value="save_chart_assignments">

                        <?php foreach ($chart_positions_definitions as $key => $details): ?>
                            <div class="form-group">
                                <label for="assign_<?= $key ?>"><?= $details['label'] ?>:</label>
                                <select name="assignments[<?= $key ?>]" id="assign_<?= $key ?>" class="form-select">
                                    <option value="">-- Pilih Anggota --</option>
                                    <?php foreach ($all_active_employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>" 
                                            <?= (isset($current_assignments_db[$key]) && $current_assignments_db[$key] == $emp['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars(getRoleDisplayName($emp['role'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-primary">Simpan Perubahan Bagan</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <script src="script.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            mermaid.initialize({
                startOnLoad: true,
                theme: 'default', // Tema default. CSS kustom akan menangani dark mode.
                securityLevel: 'loose'
            });
        });
    </script>
</body>
</html>