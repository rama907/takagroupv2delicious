<?php
require_once 'config.php';

session_start();

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_new_employee_request') {
    $employee_name = $_POST['employee_name'] ?? '';
    $requested_password = $_POST['requested_password'] ?? '';
    $requested_role = $_POST['requested_role'] ?? 'karyawan'; // Default ke karyawan jika tidak login

    if (empty($employee_name) || empty($requested_role)) {
        $error = "Nama dan jabatan anggota harus diisi.";
    } else {
        // Gunakan password default jika tidak diisi
        $password_to_hash = !empty($requested_password) ? $requested_password : 'password';
        $hashed_password = password_hash($password_to_hash, PASSWORD_DEFAULT);

        $requested_by_id = isset($user['id']) ? $user['id'] : NULL;

        $stmt = $conn->prepare("
            INSERT INTO add_employee_requests (employee_name, requested_password, requested_role, requested_by)
            VALUES (?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            $error = "Gagal menyiapkan query: " . $conn->error;
        } else {
            $stmt->bind_param("sssi", $employee_name, $hashed_password, $requested_role, $requested_by_id);

            if ($stmt->execute()) {
                $success = "Permintaan anggota baru untuk **" . htmlspecialchars($employee_name) . "** berhasil diajukan! Menunggu persetujuan admin.";
                sendDiscordNotification([
                    'employee_name' => isset($user['name']) ? $user['name'] : 'Pengguna Anonim',
                    'new_employee_name' => $employee_name,
                    'requested_role' => $requested_role,
                ], 'new_employee_request_submitted');
            } else {
                $error = "Gagal mengajukan permintaan anggota baru: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajukan Anggota Baru - Royale Grill & Bar</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .centered-page-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .page-content-wrapper {
            max-width: 600px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
    </style>
</head>
<body class="login-body">
    <?php include 'includes/header.php'; ?>

    <main class="main-content">
        <div class="centered-page-container">
            <div class="page-content-wrapper">
                <div class="page-header">
                    <h1>
                        <span class="page-icon">📝</span>
                        Ajukan Anggota Baru
                    </h1>
                    <p>Ajukan permintaan penambahan anggota baru untuk disetujui oleh admin.</p>
                </div>
                
                <?php if (isset($success)): ?>
                    <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
    
                <div class="card full-width" style="max-width: 600px;">
                    <div class="card-header">
                        <h3>Formulir Permintaan Anggota Baru</h3>
                    </div>
                    <div class="card-content">
                        <form method="POST">
                            <input type="hidden" name="action" value="submit_new_employee_request">
                            
                            <div class="form-group">
                                <label for="employee_name">Nama Anggota</label>
                                <input type="text" name="employee_name" id="employee_name" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="requested_role">Jabatan yang Diminta</label>
                                <select name="requested_role" id="requested_role" class="form-select">
                                    <option value="karyawan">Karyawan</option>
                                    <option value="magang">Magang</option>
                                </select>
                            </div>
    
                            <div class="form-group">
                                <label for="requested_password">Kata Sandi (Opsional)</label>
                                <input type="password" name="requested_password" id="requested_password" class="form-input" placeholder="Kosongkan untuk password default: password">
                                <small class="form-help">Jika dikosongkan, kata sandi default akan digunakan.</small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Ajukan Permintaan</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
        
    <script src="script.js"></script>
    </body>
</html>