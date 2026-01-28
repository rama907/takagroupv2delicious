<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Handle form submission
if ($_POST['action'] ?? '' === 'submit_resignation') {
    $resignation_date = $_POST['resignation_date'] ?? '';
    $reason_ooc = $_POST['reason_ooc'] ?? '';
    $reason_ic = $_POST['reason_ic'] ?? '';
    $passport = $_POST['passport'] ?? '';
    $cid = $_POST['cid'] ?? '';
    
    if ($resignation_date && $passport && $cid) {
        $stmt = $conn->prepare("
            INSERT INTO resignation_requests (employee_id, start_date, end_date, reason_ooc, reason_ic, passport, cid)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        // Use the same date for both start_date and end_date for compatibility with existing database structure
        $stmt->bind_param("issssss", $user['id'], $resignation_date, $resignation_date, $reason_ooc, $reason_ic, $passport, $cid);
        
        if ($stmt->execute()) {
            sendDiscordNotification([
                'employee_name' => $user['name'],
                'resignation_date' => $resignation_date,
                'passport' => $passport,
                'cid' => $cid,
                'reason_ooc' => $reason_ooc,
                'reason_ic' => $reason_ic
            ], 'resignation_request_submitted');
            $success = "Permohonan resign berhasil diajukan!";
        } else {
            $error = "Gagal mengajukan permohonan resign!";
        }
    } else {
        $error = "Tanggal resign, passport, dan CID wajib diisi!";
    }
}

// Get user's resignation requests
$stmt = $conn->prepare("
    SELECT rr.*, e.name as approved_by_name 
    FROM resignation_requests rr
    LEFT JOIN employees e ON rr.approved_by = e.id
    WHERE rr.employee_id = ?
    ORDER BY rr.created_at DESC
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$resignation_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan Resign - Delicious</title>
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
                    <span class="page-icon">📄</span>
                    Permohonan Resign
                </h1>
                <p>Ajukan permohonan resign Anda</p>
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
                        <h3>Form Permohonan Resign</h3>
                    </div>
                    <div class="card-content">
                        <form method="POST" class="resignation-form">
                            <input type="hidden" name="action" value="submit_resignation">
                            
                            <div class="form-group">
                                <label for="resignation_date">Tanggal Resign</label>
                                <input type="date" name="resignation_date" id="resignation_date" class="form-input" required>
                                <small class="form-help">Pilih tanggal efektif resign Anda</small>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="passport">Passport</label>
                                    <input type="text" name="passport" id="passport" class="form-input" 
                                           placeholder="Masukkan nomor passport" required>
                                </div>
                                <div class="form-group">
                                    <label for="cid">CID</label>
                                    <input type="text" name="cid" id="cid" class="form-input" 
                                           placeholder="Masukkan CID" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="reason_ooc">Alasan OOC (Out of Character)</label>
                                <textarea name="reason_ooc" id="reason_ooc" rows="3" class="form-textarea" 
                                          placeholder="Jelaskan alasan OOC untuk resign..."></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="reason_ic">Alasan IC (In Character)</label>
                                <textarea name="reason_ic" id="reason_ic" rows="3" class="form-textarea" 
                                          placeholder="Jelaskan alasan IC untuk resign..."></textarea>
                            </div>
                            
                            <div class="warning-message">
                                <strong>Peringatan:</strong> Permohonan resign tidak dapat dibatalkan setelah disetujui.
                            </div>
                            
                            <button type="submit" class="btn btn-danger">Ajukan Permohonan Resign</button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>Riwayat Permohonan Resign</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($resignation_requests)): ?>
                            <div class="no-data">Belum ada permohonan resign</div>
                        <?php else: ?>
                            <div class="requests-list">
                                <?php foreach ($resignation_requests as $request): ?>
                                <div class="request-item">
                                    <div class="request-header">
                                        <div class="request-dates">
                                            Tanggal Resign: <?= date('d/m/Y', strtotime($request['start_date'])) ?>
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
                                    
                                    <div class="request-details">
                                        <div><strong>Passport:</strong> <?= htmlspecialchars($request['passport']) ?></div>
                                        <div><strong>CID:</strong> <?= htmlspecialchars($request['cid']) ?></div>
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
