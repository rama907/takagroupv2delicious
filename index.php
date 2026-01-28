<?php
require_once 'config.php';

// Handle login
if ($_POST['action'] ?? '' === 'login') {
    $name = $_POST['name'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $conn->prepare("SELECT * FROM employees WHERE name = ? AND status = 'active'");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        header('Location: dashboard');
        exit;
    } else {
        $error = "Nama atau password salah!";
    }
}

// Get all employees for dropdown
$employees = $conn->query("SELECT name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get on-duty and total employee count for the new panel
$total_employees = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'")->fetch_assoc()['count'];
$on_duty_employees_count = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_on_duty = 1")->fetch_assoc()['count'];
$off_duty_employees_count = $total_employees - $on_duty_employees_count;

// Determine if the club is open or closed and get the relevant timestamp
$status_is_open = $on_duty_employees_count > 0;
$status_timestamp = null;
$status_label = '';

if ($status_is_open) {
    $stmt = $conn->query("SELECT MIN(current_duty_start) AS first_on_duty_time FROM employees WHERE is_on_duty = 1");
    $status_timestamp = $stmt->fetch_assoc()['first_on_duty_time'];
    $status_label = 'Buka Sejak';
} else {
    $stmt = $conn->query("SELECT MAX(duty_end) AS last_off_duty_time FROM duty_logs WHERE status = 'completed'");
    $status_timestamp = $stmt->fetch_assoc()['last_off_duty_time'];
    $status_label = 'Tutup Sejak';
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delicious Management System</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* New club status panel styles */
        .club-status-preview {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-2xl);
            width: 100%;
            max-width: 480px;
            animation: fadeIn 0.6s ease-out;
            color: var(--text-primary);
        }
        
        .club-status-header {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-xl);
        }
        
        .club-status-header .status-icon {
            font-size: 2.5rem;
        }
        
        .club-status-header .status-icon.status-open {
            color: var(--success-color);
        }
        
        .club-status-header .status-icon.status-closed {
            color: var(--danger-color);
        }
        
        .club-status-header .status-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .status-details {
            display: flex;
            justify-content: space-around;
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        
        .status-detail-item {
            text-align: center;
            flex: 1;
            padding: var(--spacing-lg);
            background: var(--bg-secondary);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border-light);
        }
        
        .status-detail-item .detail-label {
            display: block;
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--spacing-xs);
        }
        
        .status-detail-item .detail-value {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        
        .status-detail-item .detail-value.on-duty {
            color: var(--success-color);
        }
        
        .status-detail-item .detail-value.off-duty {
            color: var(--danger-color);
        }
        
        .status-timer {
            text-align: center;
            font-family: "SF Mono", "Monaco", "Inconsolata", "Roboto Mono", monospace;
            font-size: 1rem;
            font-weight: 700;
            padding: var(--spacing-lg);
            background: var(--bg-tertiary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        .status-timer .since-label {
            display: block;
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: var(--spacing-xs);
        }
        .status-timer .since-time {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        /* Request Panel styles */
        .request-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-2xl);
            width: 100%;
            max-width: 480px;
            animation: fadeIn 0.6s ease-out;
            color: var(--text-primary);
            margin-top: var(--spacing-xl);
            text-align: center;
        }

        .request-panel h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--spacing-xl);
        }

        .request-buttons {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-lg);
        }

        .request-buttons .btn {
            width: 100%;
        }

        /* Layout changes for vertical stacking on desktop */
        .login-main {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-2xl);
            position: relative;
            z-index: 10;
            gap: var(--spacing-2xl);
            flex-direction: column; /* Default vertical on mobile */
        }
        
        .right-side-panel {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-lg);
            width: 100%;
            max-width: 480px;
        }

        .icon-img { 
            width: 150px; 
            height: 150px;
            object-fit: contain;
            margin-bottom: var(--spacing-md);
            /* Pastikan gambar diposisikan di tengah */
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        /* Media query for desktop layout */
        @media (min-width: 1024px) {
            .login-main {
                flex-direction: row; /* Horizontal on desktop */
                align-items: flex-start;
            }
        }

        /* Remove dark mode toggle on index.php specifically */
        .login-header-content .theme-toggle-login {
            display: none;
        }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-background">
            <div class="bg-shape shape-1"></div>
            <div class="bg-shape shape-2"></div>
            <div class="bg-shape shape-3"></div>
        </div>

        <header class="login-header">
            <div class="login-header-content">
                <div class="logo-large">
                    <img src="LOGO_WOT.png" alt="Delicious Logo" class="icon-img">
                    <div class="logo-text-large">
                        <h1>Delicious By Taka Group</h1>
                        <p>Management System</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="login-main">
            <div class="login-card">
                <div class="login-card-header">
                    <div class="login-icon">
                        <span>👤</span>
                    </div>
                    <h2>Portal Login Karyawan</h2>
                    <p>Masuk ke sistem manajemen Delicious</p>
                </div>
                
                <form method="POST" class="login-form" id="loginForm">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group-login">
                        <label for="name" class="form-label-login">
                            <span class="label-icon">👤</span>
                            Nama Anggota
                        </label>
                        <div class="select-wrapper">
                            <select name="name" id="name" required class="form-select-login">
                                <option value="">Pilih Nama Anda</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= htmlspecialchars($emp['name']) ?>" 
                                            <?= (isset($_POST['name']) && $_POST['name'] === $emp['name']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> 
                                        <span class="role-text">(<?= getRoleDisplayName($emp['role']) ?>)</span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="select-arrow">▼</span>
                        </div>
                    </div>
                    
                    <div class="form-group-login">
                        <label for="password" class="form-label-login">
                            <span class="label-icon">🔒</span>
                            Password
                        </label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" required class="form-input-login" placeholder="Masukkan password Anda">
                            <button type="button" class="password-toggle" data-target="password" aria-label="Tampilkan Kata Sandi">
                                <span class="icon">👁️</span>
                            </button>
                        </div>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="error-message-login">
                            <span class="error-icon">⚠️</span>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn-login" id="loginButton">
                        <span class="btn-icon">🚀</span>
                        <span class="btn-text">Masuk ke Sistem</span>
                        <div class="btn-loading" style="display: none;">
                            <div class="spinner-login"></div>
                            Memproses...
                        </div>
                    </button>
                </form>
                
                <div class="login-info">
                    <div class="info-header">
                        <span class="info-icon">💡</span>
                        <strong>Informasi Login</strong>
                    </div>
                    <div class="info-content">
                        <div class="info-item">
                            <span class="info-bullet">•</span>
                            Password default untuk semua akun: <code>password</code>
                        </div>
                        <div class="info-item">
                            <span class="info-bullet">•</span>
                            Hubungi admin untuk reset password
                        </div>
                        <div class="info-item">
                            <span class="info-bullet">•</span>
                            Pastikan nama yang dipilih sesuai dengan akun Anda
                        </div>
                    </div>
                </div>
            </div>

            <div class="right-side-panel">
                <div class="club-status-preview">
                    <div class="club-status-header">
                        <span class="status-icon <?= $status_is_open ? 'status-open' : 'status-closed' ?>">
                            <?= $status_is_open ? '🎉' : '🌙' ?>
                        </span>
                        <h3 class="status-text"><?= $status_is_open ? 'Resto Sedang Buka' : 'Resto Sedang Tutup' ?></h3>
                    </div>
                    <div class="status-details">
                        <div class="status-detail-item">
                            <span class="detail-label">On Duty</span>
                            <span class="detail-value on-duty"><?= $on_duty_employees_count ?></span>
                        </div>
                        <div class="status-detail-item">
                            <span class="detail-label">Off Duty</span>
                            <span class="detail-value off-duty"><?= $off_duty_employees_count ?></span>
                        </div>
                    </div>
                    <?php if ($status_timestamp): ?>
                        <div class="status-timer">
                            <span class="since-label"><?= $status_label ?></span>
                            <span class="since-time"><?= date('d/m/Y H:i:s', strtotime($status_timestamp)) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="request-panel">
                    <h3>Permintaan Cepat</h3>
                    <div class="request-buttons">
                        <a href="add-employee-request.php" class="btn btn-primary">
                            <span class="btn-icon">➕</span> Ajukan Anggota Baru
                        </a>
                        <a href="reset-password-request.php" class="btn btn-warning">
                            <span class="btn-icon">🔄</span> Reset Password Anggota
                        </a>
                    </div>
                </div>
            </div>
        </main>

        <footer class="login-footer">
            <p>&copy; 2026 Delicious Management System. All rights reserved.</p>
        </footer>
    </div>

    <script src="script.js"></script>
</body>
</html>