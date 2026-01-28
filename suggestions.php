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

// Handle form submission for a new suggestion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_suggestion') {
    $message = trim($_POST['message'] ?? '');

    if (empty($message)) {
        $error = "Pesan saran dan kritik tidak boleh kosong!";
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO suggestions (message) VALUES (?)");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("s", $message);

            if ($stmt->execute()) {
                $success = "Terima kasih atas saran dan kritik Anda. Masukan Anda telah berhasil dikirimkan secara anonim.";
            } else {
                throw new Exception("Gagal mengirimkan saran: " . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Fetch all suggestions if the user has permission
$all_suggestions = [];
if (hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    $stmt_suggestions = $conn->query("
        SELECT id, message, submitted_at
        FROM suggestions
        ORDER BY submitted_at DESC
    ");
    if ($stmt_suggestions) {
        $all_suggestions = $stmt_suggestions->fetch_all(MYSQLI_ASSOC);
        $stmt_suggestions->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saran & Kritik - Delicious</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .suggestion-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
        }
        .suggestion-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            margin-bottom: var(--spacing-md);
        }
        .suggestion-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: var(--spacing-sm);
            border-bottom: 1px solid var(--border-light);
            padding-bottom: var(--spacing-sm);
        }
        .suggestion-message {
            white-space: pre-wrap;
            font-size: 1rem;
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
                    <span class="page-icon">💡</span>
                    Saran & Kritik
                </h1>
                <p>Ruang untuk memberikan masukan anonim demi kemajuan perusahaan.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Kirim Saran/Kritik Anonim</h3>
                </div>
                <div class="card-content">
                    <form method="POST" action="suggestions.php">
                        <input type="hidden" name="action" value="submit_suggestion">
                        
                        <div class="form-group">
                            <label for="message">Pesan Anda</label>
                            <textarea name="message" id="message" rows="6" class="form-textarea" placeholder="Tulis saran atau kritik Anda di sini... (Bersifat anonim, jadi jangan ragu!)" required></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Kirimkan Masukan</button>
                    </form>
                </div>
            </div>

            <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])): ?>
            <div class="suggestion-card" style="margin-top: var(--spacing-2xl);">
                <div class="card-header">
                    <h3>Semua Saran & Kritik</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($all_suggestions)): ?>
                        <div class="no-data">Belum ada saran atau kritik yang dikirimkan.</div>
                    <?php else: ?>
                        <div class="suggestions-list">
                            <?php foreach ($all_suggestions as $suggestion): ?>
                                <div class="suggestion-item">
                                    <div class="suggestion-meta">
                                        Pesan Anonim | Dikirim pada: <?= date('d/m/Y H:i', strtotime($suggestion['submitted_at'])) ?>
                                    </div>
                                    <div class="suggestion-message">
                                        <?= htmlspecialchars($suggestion['message']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>