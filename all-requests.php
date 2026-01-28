<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Get all leave requests
$leave_stmt = $conn->query("
    SELECT lr.*, e.name as employee_name, e.role as employee_role, 
           approver.name as approved_by_name
    FROM leave_requests lr
    JOIN employees e ON lr.employee_id = e.id
    LEFT JOIN employees approver ON lr.approved_by = approver.id
    ORDER BY lr.created_at DESC
");
$leave_requests = $leave_stmt->fetch_all(MYSQLI_ASSOC);

// Get all resignation requests
$resignation_stmt = $conn->query("
    SELECT rr.*, e.name as employee_name, e.role as employee_role,
           approver.name as approved_by_name
    FROM resignation_requests rr
    JOIN employees e ON rr.employee_id = e.id
    LEFT JOIN employees approver ON rr.approved_by = approver.id
    ORDER BY rr.created_at DESC
");
$resignation_requests = $resignation_stmt->fetch_all(MYSQLI_ASSOC);

// Count statistics
$leave_pending = count(array_filter($leave_requests, fn($r) => $r['status'] === 'pending'));
$leave_approved = count(array_filter($leave_requests, fn($r) => $r['status'] === 'approved'));
$leave_rejected = count(array_filter($leave_requests, fn($r) => $r['status'] === 'rejected'));

$resignation_pending = count(array_filter($resignation_requests, fn($r) => $r['status'] === 'pending'));
$resignation_approved = count(array_filter($resignation_requests, fn($r) => $r['status'] === 'approved'));
$resignation_rejected = count(array_filter($resignation_requests, fn($r) => $r['status'] === 'rejected'));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semua Permohonan - Delicious</title>
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
                    <span class="page-icon">📋</span>
                    Semua Permohonan
                </h1>
                <p>Daftar semua permohonan izin dan resign dari seluruh anggota</p>
            </div>

            <!-- Statistics Cards -->
            <div class="requests-stats-grid">
                <div class="stat-card-requests">
                    <div class="stat-icon-requests leave-icon">📝</div>
                    <div class="stat-content-requests">
                        <h3>Permohonan Izin</h3>
                        <div class="stat-numbers">
                            <span class="stat-total"><?= count($leave_requests) ?></span>
                            <div class="stat-breakdown">
                                <span class="stat-pending"><?= $leave_pending ?> Pending</span>
                                <span class="stat-approved"><?= $leave_approved ?> Disetujui</span>
                                <span class="stat-rejected"><?= $leave_rejected ?> Ditolak</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card-requests">
                    <div class="stat-icon-requests resign-icon">📄</div>
                    <div class="stat-content-requests">
                        <h3>Permohonan Resign</h3>
                        <div class="stat-numbers">
                            <span class="stat-total"><?= count($resignation_requests) ?></span>
                            <div class="stat-breakdown">
                                <span class="stat-pending"><?= $resignation_pending ?> Pending</span>
                                <span class="stat-approved"><?= $resignation_approved ?> Disetujui</span>
                                <span class="stat-rejected"><?= $resignation_rejected ?> Ditolak</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs for switching between request types -->
            <div class="all-requests-tabs">
                <button class="tab-button active" onclick="switchTab('leave')">
                    📝 Permohonan Izin (<?= count($leave_requests) ?>)
                </button>
                <button class="tab-button" onclick="switchTab('resignation')">
                    📄 Permohonan Resign (<?= count($resignation_requests) ?>)
                </button>
            </div>

            <!-- Leave Requests Tab -->
            <div id="leave-tab" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3>Daftar Permohonan Izin</h3>
                        <div class="filter-controls">
                            <select id="leave-status-filter" class="form-select-small" onchange="filterRequests('leave')">
                                <option value="all">Semua Status</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Disetujui</option>
                                <option value="rejected">Ditolak</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-content">
                        <?php if (empty($leave_requests)): ?>
                            <div class="no-data">Belum ada permohonan izin</div>
                        <?php else: ?>
                            <div class="all-requests-list" id="leave-requests-list">
                                <?php foreach ($leave_requests as $request): ?>
                                <div class="all-request-item" data-status="<?= $request['status'] ?>">
                                    <div class="request-header-all">
                                        <div class="employee-info-all">
                                            <div class="employee-avatar-all">
                                                <span class="avatar-icon">👤</span>
                                            </div>
                                            <div class="employee-details-all">
                                                <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                                <span class="role-badge role-<?= $request['employee_role'] ?>">
                                                    <?= getRoleDisplayName($request['employee_role']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="request-status-all">
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
                                    </div>
                                    
                                    <div class="request-details-all">
                                        <div class="request-dates-all">
                                            <span class="date-label">📅 Periode Izin:</span>
                                            <span class="date-value">
                                                <?= date('d/m/Y', strtotime($request['start_date'])) ?> - 
                                                <?= date('d/m/Y', strtotime($request['end_date'])) ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($request['reason_ooc']): ?>
                                        <div class="request-reason-all">
                                            <strong>Alasan OOC:</strong> 
                                            <span><?= htmlspecialchars($request['reason_ooc']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($request['reason_ic']): ?>
                                        <div class="request-reason-all">
                                            <strong>Alasan IC:</strong> 
                                            <span><?= htmlspecialchars($request['reason_ic']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="request-meta-all">
                                        <span class="meta-item">
                                            <span class="meta-icon">🕒</span>
                                            Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                        </span>
                                        <?php if ($request['approved_by_name']): ?>
                                            <span class="meta-item">
                                                <span class="meta-icon">👤</span>
                                                <?= $request['status'] == 'approved' ? 'Disetujui' : 'Ditolak' ?> oleh: 
                                                <?= htmlspecialchars($request['approved_by_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Resignation Requests Tab -->
            <div id="resignation-tab" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3>Daftar Permohonan Resign</h3>
                        <div class="filter-controls">
                            <select id="resignation-status-filter" class="form-select-small" onchange="filterRequests('resignation')">
                                <option value="all">Semua Status</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Disetujui</option>
                                <option value="rejected">Ditolak</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-content">
                        <?php if (empty($resignation_requests)): ?>
                            <div class="no-data">Belum ada permohonan resign</div>
                        <?php else: ?>
                            <div class="all-requests-list" id="resignation-requests-list">
                                <?php foreach ($resignation_requests as $request): ?>
                                <div class="all-request-item" data-status="<?= $request['status'] ?>">
                                    <div class="request-header-all">
                                        <div class="employee-info-all">
                                            <div class="employee-avatar-all">
                                                <span class="avatar-icon">👤</span>
                                            </div>
                                            <div class="employee-details-all">
                                                <h4><?= htmlspecialchars($request['employee_name']) ?></h4>
                                                <span class="role-badge role-<?= $request['employee_role'] ?>">
                                                    <?= getRoleDisplayName($request['employee_role']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="request-status-all">
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
                                    </div>
                                    
                                    <div class="request-details-all">
                                        <div class="request-dates-all">
                                            <span class="date-label">📅 Tanggal Resign:</span>
                                            <span class="date-value">
                                                <?= date('d/m/Y', strtotime($request['start_date'])) ?>
                                            </span>
                                        </div>
                                        
                                        <div class="request-credentials-all">
                                            <div class="credential-item">
                                                <strong>Passport:</strong> <?= htmlspecialchars($request['passport']) ?>
                                            </div>
                                            <div class="credential-item">
                                                <strong>CID:</strong> <?= htmlspecialchars($request['cid']) ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($request['reason_ooc']): ?>
                                        <div class="request-reason-all">
                                            <strong>Alasan OOC:</strong> 
                                            <span><?= htmlspecialchars($request['reason_ooc']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($request['reason_ic']): ?>
                                        <div class="request-reason-all">
                                            <strong>Alasan IC:</strong> 
                                            <span><?= htmlspecialchars($request['reason_ic']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="request-meta-all">
                                        <span class="meta-item">
                                            <span class="meta-icon">🕒</span>
                                            Diajukan: <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                        </span>
                                        <?php if ($request['approved_by_name']): ?>
                                            <span class="meta-item">
                                                <span class="meta-icon">👤</span>
                                                <?= $request['status'] == 'approved' ? 'Disetujui' : 'Ditolak' ?> oleh: 
                                                <?= htmlspecialchars($request['approved_by_name']) ?>
                                            </span>
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
    <script>
        // Tab switching functionality
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Filter functionality
        function filterRequests(type) {
            const filterSelect = document.getElementById(type + '-status-filter');
            const selectedStatus = filterSelect.value;
            const requestsList = document.getElementById(type + '-requests-list');
            const requestItems = requestsList.querySelectorAll('.all-request-item');

            requestItems.forEach(item => {
                const itemStatus = item.getAttribute('data-status');
                
                if (selectedStatus === 'all' || itemStatus === selectedStatus) {
                    item.style.display = 'block';
                    item.style.animation = 'fadeIn 0.3s ease-out';
                } else {
                    item.style.display = 'none';
                }
            });

            // Update visible count
            const visibleItems = requestsList.querySelectorAll('.all-request-item[style*="block"], .all-request-item:not([style*="none"])').length;
            console.log(`Showing ${visibleItems} ${type} requests`);
        }

        // Search functionality (optional enhancement)
        function searchRequests(query) {
            const allItems = document.querySelectorAll('.all-request-item');
            
            allItems.forEach(item => {
                const employeeName = item.querySelector('h4').textContent.toLowerCase();
                const reasons = item.querySelectorAll('.request-reason-all span');
                let reasonText = '';
                reasons.forEach(reason => reasonText += reason.textContent.toLowerCase() + ' ');
                
                if (employeeName.includes(query.toLowerCase()) || reasonText.includes(query.toLowerCase())) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Auto-refresh functionality (optional)
        function autoRefresh() {
            // Refresh page every 5 minutes to get latest data
            setTimeout(() => {
                location.reload();
            }, 300000); // 5 minutes
        }

        // Initialize auto-refresh
        autoRefresh();
    </script>
</body>
</html>
