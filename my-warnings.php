<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$warning_history = [];
$stmt = $conn->prepare("
    SELECT wl.*, issued_by.name as issued_by_name
    FROM warning_letters wl
    LEFT JOIN employees issued_by ON wl.issued_by_employee_id = issued_by.id
    WHERE wl.employee_id = ?
    ORDER BY wl.issued_at DESC
");
if ($stmt) {
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $warning_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Peringatan Saya - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
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
            white-space: pre-wrap;
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
                    Surat Peringatan Saya
                </h1>
                <p>Daftar semua surat peringatan yang pernah Anda terima.</p>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Riwayat Surat Peringatan</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($warning_history)): ?>
                        <div class="no-data">Tidak ada riwayat surat peringatan. Pertahankan kinerja baik Anda!</div>
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