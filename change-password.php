<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Semua field harus diisi.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Kata sandi baru dan konfirmasi kata sandi tidak cocok.";
    } elseif (strlen($new_password) < 6) {
        $error = "Kata sandi baru minimal harus 6 karakter.";
    } else {
        // Lakukan verifikasi kata sandi lama
        $stmt = $conn->prepare("SELECT password FROM employees WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result && password_verify($current_password, $result['password'])) {
            // Hash kata sandi baru dan perbarui di database
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE employees SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user['id']);
            
            if ($update_stmt->execute()) {
                $success = "Kata sandi berhasil diubah!";
                sendDiscordNotification([
                    'employee_name' => $user['name'],
                    'action_type' => 'change_password',
                ], 'employee_action');
            } else {
                $error = "Gagal mengubah kata sandi: " . $conn->error;
            }
            $update_stmt->close();
        } else {
            $error = "Kata sandi lama tidak valid.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Kata Sandi - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">🔒</span>
                    Ubah Kata Sandi
                </h1>
                <p>Kelola kata sandi akun Anda.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Formulir Perubahan Kata Sandi</h3>
                </div>
                <div class="card-content">
                    <form method="POST" class="change-password-form" id="changePasswordForm">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password">Kata Sandi Lama</label>
                            <div class="password-wrapper">
                                <input type="password" name="current_password" id="current_password" class="form-input" placeholder="Masukkan kata sandi lama" required>
                                <button type="button" class="password-toggle" data-target="current_password" aria-label="Tampilkan Kata Sandi">
                                    <span class="icon">👁️</span>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">Kata Sandi Baru</label>
                            <div class="password-wrapper">
                                <input type="password" name="new_password" id="new_password" class="form-input" placeholder="Masukkan kata sandi baru" required>
                                <button type="button" class="password-toggle" data-target="new_password" aria-label="Tampilkan Kata Sandi">
                                    <span class="icon">👁️</span>
                                </button>
                            </div>
                            <small class="form-help">Minimal 6 karakter.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Konfirmasi Kata Sandi Baru</label>
                            <div class="password-wrapper">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Konfirmasi kata sandi baru" required>
                                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Tampilkan Kata Sandi">
                                    <span class="icon">👁️</span>
                                </button>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Ubah Kata Sandi</button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>