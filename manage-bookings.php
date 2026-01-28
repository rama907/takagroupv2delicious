<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

// Semua logika perhitungan gaji telah dihapus untuk mengimplementasikan mode maintenance.
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pemesanan - UNDER MAINTENANCE</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Gaya khusus untuk mode Maintenance */
        .maintenance-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 70vh; /* Memastikan konten berada di tengah halaman */
            text-align: center;
        }
        .maintenance-card {
            background: var(--bg-card);
            border: 2px solid var(--warning-color); /* Border merah/kuning untuk peringatan */
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            padding: var(--spacing-2xl);
            max-width: 600px;
            width: 100%;
        }
        .maintenance-icon {
            font-size: 5rem;
            color: var(--warning-color);
            margin-bottom: var(--spacing-lg);
            animation: pulse 2s infinite; /* Animasi sederhana untuk menarik perhatian */
        }
        .maintenance-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--spacing-md);
        }
        .maintenance-message {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xl);
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="maintenance-container">
                <div class="maintenance-card">
                    <div class="maintenance-icon">🚧</div>
                    <h2 class="maintenance-title">Sedang Dalam Perawatan</h2>
                    <p class="maintenance-message">
                        Mohon maaf atas ketidaknyamanan ini.
                    </p>
                    <p class="maintenance-message">
                        Silakan hubungi bagian Pihak Developers By Rinaldi Production.
                    </p>
                    <a href="dashboard.php" class="btn btn-primary">Kembali ke Dashboard</a>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>