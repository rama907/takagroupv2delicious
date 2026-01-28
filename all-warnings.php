<?php
require_once 'config.php';

// Cek hanya apakah pengguna sudah login, tanpa memeriksa peran.
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser(); // Untuk mendapatkan data pengguna yang sedang login

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Mengambil semua surat peringatan dari database
$warning_letters = [];
$stmt = $conn->query("
    SELECT 
        wl.*,
        employee.name as employee_name,
        employee.role as employee_role,
        issued_by.name as issued_by_name
    FROM warning_letters wl
    JOIN employees employee ON wl.employee_id = employee.id
    LEFT JOIN employees issued_by ON wl.issued_by_employee_id = issued_by.id
    ORDER BY wl.issued_at DESC
");

if ($stmt) {
    $warning_letters = $stmt->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Menghitung statistik untuk ringkasan
$sp1_count = 0;
$sp2_count = 0;
$sp3_count = 0;
$phk_count = 0;

foreach ($warning_letters as $warning) {
    switch ($warning['sp_type']) {
        case 'SP1':
            $sp1_count++;
            break;
        case 'SP2':
            $sp2_count++;
            break;
        case 'SP3':
            $sp3_count++;
            break;
        case 'PHK':
            $phk_count++;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semua Surat Peringatan - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .warning-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }
        .warning-stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-md);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        .warning-stat-card h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }
        .warning-stat-card .value {
            font-size: 2rem;
            font-weight: 700;
        }
        .warning-stat-card.sp1 .value { color: #ffc107; }
        .warning-stat-card.sp2 .value { color: #fd7e14; }
        .warning-stat-card.sp3 .value { color: #dc3545; }
        .warning-stat-card.phk .value { color: #b71c1c; }
        
        .warning-list-item {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            background: var(--bg-card);
            box-shadow: var(--shadow-sm);
        }
        .warning-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing-md);
            border-bottom: 1px solid var(--border-light);
            padding-bottom: var(--spacing-md);
        }
        .warning-list-header .employee-info-small {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
        }
        .warning-list-header .employee-name {
            font-size: 1.125rem;
            font-weight: 700;
        }
        .warning-list-header .role-badge {
            font-size: 0.75rem;
        }
        .warning-details {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .warning-details strong {
            color: var(--text-primary);
        }
        .warning-reason {
            margin-top: var(--spacing-md);
            padding: var(--spacing-md);
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            border-left: 3px solid var(--danger-color);
        }
        .warning-reason p {
            margin: 0;
            white-space: pre-wrap;
        }
        .sp-badge-list {
            font-size: 0.9rem;
            font-weight: 700;
            padding: 0.4em 0.8em;
            border-radius: var(--radius-md);
            text-transform: uppercase;
        }
        .sp-badge-list.sp1 { background: #FFD54F; color: #FF6F00; }
        .sp-badge-list.sp2 { background: #FFB74D; color: #F57C00; }
        .sp-badge-list.sp3 { background: #FF8A65; color: #E64A19; }
        .sp-badge-list.phk { background: #E57373; color: #D32F2F; }
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
                    Semua Surat Peringatan
                </h1>
                <p>Daftar semua surat peringatan dan PHK yang pernah dikeluarkan.</p>
            </div>

            <div class="warning-stats-grid">
                <div class="warning-stat-card sp1">
                    <h4>SP1</h4>
                    <p class="value"><?= $sp1_count ?></p>
                </div>
                <div class="warning-stat-card sp2">
                    <h4>SP2</h4>
                    <p class="value"><?= $sp2_count ?></p>
                </div>
                <div class="warning-stat-card sp3">
                    <h4>SP3</h4>
                    <p class="value"><?= $sp3_count ?></p>
                </div>
                <div class="warning-stat-card phk">
                    <h4>PHK</h4>
                    <p class="value"><?= $phk_count ?></p>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Daftar Surat Peringatan</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($warning_letters)): ?>
                        <div class="no-data">Tidak ada surat peringatan yang tercatat.</div>
                    <?php else: ?>
                        <div class="requests-list">
                            <?php foreach ($warning_letters as $warning): ?>
                                <div class="warning-list-item">
                                    <div class="warning-list-header">
                                        <div class="employee-info-small">
                                            <div class="employee-avatar-small">👤</div>
                                            <div>
                                                <div class="employee-name"><?= htmlspecialchars($warning['employee_name']) ?></div>
                                                <span class="role-badge role-<?= htmlspecialchars($warning['employee_role']) ?>">
                                                    <?= htmlspecialchars(getRoleDisplayName($warning['employee_role'])) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="sp-badge-list <?= strtolower($warning['sp_type']) ?>">
                                            <?= htmlspecialchars($warning['sp_type']) ?>
                                        </span>
                                    </div>
                                    <div class="warning-details">
                                        <strong>Dikeluarkan pada:</strong> <?= date('d/m/Y H:i', strtotime($warning['issued_at'])) ?><br>
                                        <strong>Dikeluarkan oleh:</strong> <?= htmlspecialchars($warning['issued_by_name']) ?>
                                    </div>
                                    <div class="warning-reason">
                                        <strong>Alasan:</strong>
                                        <p><?= htmlspecialchars($warning['reason']) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>