<?php
require_once 'config.php';

session_start();

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_password_reset_request') {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $reset_type = $_POST['reset_type'] ?? 'default';

    if ($employee_id <= 0) {
        $error = "Anggota belum dipilih!";
    } elseif ($reset_type === 'new' && empty($new_password)) {
        $error = "Kata sandi baru tidak boleh kosong!";
    } else {
        // Find employee name for notification
        $requested_employee_name = '';
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id) {
                $requested_employee_name = $emp['name'];
                break;
            }
        }

        $password_to_hash = ($reset_type === 'new') ? $new_password : 'password';
        $hashed_password = password_hash($password_to_hash, PASSWORD_DEFAULT);
        
        $requested_by_id = isset($user['id']) ? $user['id'] : NULL;


        $stmt = $conn->prepare("
            INSERT INTO password_reset_requests (employee_id, requested_password, reset_type, requested_by)
            VALUES (?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            $error = "Gagal menyiapkan query: " . $conn->error;
        } else {
            $stmt->bind_param("issi", $employee_id, $hashed_password, $reset_type, $requested_by_id);

            if ($stmt->execute()) {
                $success = "Permintaan reset kata sandi untuk **" . htmlspecialchars($requested_employee_name) . "** berhasil diajukan! Menunggu persetujuan admin.";
                sendDiscordNotification([
                    'employee_name' => isset($user['name']) ? $user['name'] : 'Pengguna Anonim',
                    'target_employee_name' => $requested_employee_name,
                    'reset_type' => $reset_type,
                ], 'password_reset_request_submitted');
            } else {
                $error = "Gagal mengajukan permintaan reset kata sandi: " . $stmt->error;
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
    <title>Reset Kata Sandi - Royale Grill & Bar</title>
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
                        <span class="page-icon">🔄</span>
                        Reset Kata Sandi Anggota
                    </h1>
                    <p>Ajukan permohonan untuk mereset kata sandi anggota lain.</p>
                </div>

                <?php if (isset($success)): ?>
                    <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Formulir Permintaan Reset Kata Sandi</h3>
                    </div>
                    <div class="card-content">
                        <form method="POST">
                            <input type="hidden" name="action" value="submit_password_reset_request">
                            
                            <div class="form-group">
                                <label for="employee_id">Pilih Anggota</label>
                                <select name="employee_id" id="employee_id" class="form-select" required>
                                    <option value="">-- Pilih Anggota --</option>
                                    <?php foreach ($all_employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Tipe Reset</label>
                                <div class="form-row" style="margin-bottom: 0;">
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <input type="radio" name="reset_type" id="reset_default" value="default" checked onchange="toggleNewPasswordInput()">
                                        <label for="reset_default" style="display: inline;">Reset ke Password Default ('password')</label>
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <input type="radio" name="reset_type" id="reset_new" value="new" onchange="toggleNewPasswordInput()">
                                        <label for="reset_new" style="display: inline;">Ajukan Password Baru</label>
                                    </div>
                                </div>
                            </div>
    
                            <div class="form-group" id="new-password-group" style="display: none;">
                                <label for="new_password">Kata Sandi Baru yang Diajukan</label>
                                <input type="password" name="new_password" id="new_password" class="form-input" placeholder="Masukkan kata sandi baru">
                            </div>
    
                            <button type="submit" class="btn btn-primary">Ajukan Permintaan Reset</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script src="script.js"></script>
    <script>
        function toggleNewPasswordInput() {
            const newPasswordGroup = document.getElementById('new-password-group');
            const newPasswordInput = document.getElementById('new_password');
            const resetNewRadio = document.getElementById('reset_new');
    
            if (resetNewRadio.checked) {
                newPasswordGroup.style.display = 'block';
                newPasswordInput.setAttribute('required', 'required');
            } else {
                newPasswordGroup.style.display = 'none';
                newPasswordInput.removeAttribute('required');
            }
        }
    </script>
</body>
</html>