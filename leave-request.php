<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Handle form submission
if ($_POST['action'] ?? '' === 'submit_leave') {
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason_ooc = $_POST['reason_ooc'] ?? '';
    $reason_ic = $_POST['reason_ic'] ?? '';
    
    if ($start_date && $end_date) {
        $stmt = $conn->prepare("
            INSERT INTO leave_requests (employee_id, start_date, end_date, reason_ooc, reason_ic)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $user['id'], $start_date, $end_date, $reason_ooc, $reason_ic);
        
        if ($stmt->execute()) {
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'reason_ooc' => $reason_ooc,
                'reason_ic' => $reason_ic
            ], 'leave_request_submitted');
            $success = "Permohonan Izin berhasil diajukan!";
        } else {
            $error = "Gagal mengajukan permohonan Izin!";
        }
    } else {
        $error = "Tanggal mulai dan selesai harus diisi!";
    }
}

// Get user's leave requests
$stmt = $conn->prepare("
    SELECT lr.*, e.name as approved_by_name 
    FROM leave_requests lr
    LEFT JOIN employees e ON lr.approved_by = e.id
    WHERE lr.employee_id = ?
    ORDER BY lr.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$leave_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan Izin - Delicious</title>
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
                    <span class="page-icon">📝</span>
                    Permohonan Izin
                </h1>
                <p>Ajukan permohonan Izin Anda</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message"><?= $success ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?= $error ?></div>
            <?php endif; ?>

            <div class="content-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>Form Permohonan Izin</h3>
                    </div>
                    <div class="card-content">
                        <form method="POST" class="leave-form">
                            <input type="hidden" name="action" value="submit_leave">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="start_date">Tanggal Mulai</label>
                                    <input type="date" name="start_date" id="start_date" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label for="end_date">Tanggal Selesai</label>
                                    <input type="date" name="end_date" id="end_date" class="form-input" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="reason_ooc">Alasan OOC (Out of Character)</label>
                                <textarea name="reason_ooc" id="reason_ooc" rows="3" class="form-textarea" 
                                          placeholder="Jelaskan alasan OOC untuk Izin..."></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="reason_ic">Alasan IC (In Character)</label>
                                <textarea name="reason_ic" id="reason_ic" rows="3" class="form-textarea" 
                                          placeholder="Jelaskan alasan IC untuk Izin..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Ajukan Permohonan</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Riwayat Permohonan Izin</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($leave_requests)): ?>
                            <div class="no-data">Belum ada permohonan Izin</div>
                        <?php else: ?>
                            <div class="requests-list">
                                <?php foreach ($leave_requests as $request): ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <div class="request-dates">
                                            <?= date('d/m/Y', strtotime($request['start_date'])) ?> - 
                                            <?= date('d/m/Y', strtotime($request['end_date'])) ?>
                                        </div>
                                        <span class="status-badge status-<?= $request['status'] ?>">
                                            <?php 
                                            $status_text = [
                                                'pending' => 'Pending',
                                                'approved' => 'Disetujui', 
                                                'rejected' => 'Ditolak'
                                            ];
                                            echo $status_text[$request['status']] ?? ucfirst($request['status']);
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($request['reason_ooc']): ?>
                                    <div class="request-reason">
                                        <strong>Alasan OOC:</strong> <?= htmlspecialchars($request['reason_ooc']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($request['reason_ic']): ?>
                                    <div class="request-reason">
                                        <strong>Alasan IC:</strong> <?= htmlspecialchars($request['reason_ic']) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="request-meta">
                                        <span>Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                        <?php if ($request['approved_by_name'] && $request['status'] == 'approved'): ?>
                                            <span>Disetujui oleh: <?= htmlspecialchars($request['approved_by_name']) ?></span>
                                        <?php elseif ($request['approved_by_name'] && $request['status'] == 'rejected'): ?>
                                            <span>Ditolak oleh: <?= htmlspecialchars($request['approved_by_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>
