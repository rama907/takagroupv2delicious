<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$page = $_GET['page'] ?? '';
$lastUpdate = intval($_GET['last_update'] ?? 0);
$currentTime = time() * 1000; // Convert to milliseconds

$response = [
    'has_updates' => false,
    'timestamp' => $currentTime
];

try {
    switch ($page) {
        case 'dashboard':
            $response = checkDashboardUpdates($conn, $lastUpdate);
            break;
            
        case 'activities':
            $response = checkActivitiesUpdates($conn, $lastUpdate);
            break;
            
        case 'sales':
            $response = checkSalesUpdates($conn, $lastUpdate);
            break;
            
        case 'employees':
            $response = checkEmployeesUpdates($conn, $lastUpdate);
            break;
            
        case 'employee-activities':
            $response = checkEmployeeActivitiesUpdates($conn, $lastUpdate);
            break;
    }
} catch (Exception $e) {
    error_log("Update check error: " . $e->getMessage());
    $response = ['has_updates' => false, 'error' => 'Check failed'];
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo json_encode($response);

function checkDashboardUpdates($conn, $lastUpdate) {
    $response = ['has_updates' => false, 'timestamp' => time() * 1000];
    
    // Check for new activities since last update
    $stmt = $conn->prepare("
        SELECT COUNT(*) as new_count 
        FROM activities 
        WHERE UNIX_TIMESTAMP(timestamp) * 1000 > ?
    ");
    $stmt->bind_param("i", $lastUpdate);
    $stmt->execute();
    $newActivities = $stmt->get_result()->fetch_assoc()['new_count'];
    
    // Check for new sales since last update
    $stmt = $conn->prepare("
        SELECT COUNT(*) as new_count 
        FROM sales 
        WHERE UNIX_TIMESTAMP(timestamp) * 1000 > ?
    ");
    $stmt->bind_param("i", $lastUpdate);
    $stmt->execute();
    $newSales = $stmt->get_result()->fetch_assoc()['new_count'];
    
    if ($newActivities > 0 || $newSales > 0) {
        $response['has_updates'] = true;
        
        // Get updated dashboard stats
        $response['dashboard_stats'] = getDashboardStats($conn);
        
        if ($newActivities > 0) {
            $response['notifications'][] = [
                'message' => "Ada {$newActivities} aktivitas baru",
                'type' => 'info'
            ];
        }
        
        if ($newSales > 0) {
            $response['notifications'][] = [
                'message' => "Ada {$newSales} transaksi penjualan baru",
                'type' => 'success'
            ];
        }
    }
    
    return $response;
}

function checkActivitiesUpdates($conn, $lastUpdate) {
    $response = ['has_updates' => false, 'timestamp' => time() * 1000];
    
    // Get new activities since last update
    $stmt = $conn->prepare("
        SELECT a.*, e.name 
        FROM activities a 
        JOIN employees e ON a.employee_id = e.id 
        WHERE UNIX_TIMESTAMP(a.timestamp) * 1000 > ? 
        ORDER BY a.timestamp DESC 
        LIMIT 10
    ");
    $stmt->bind_param("i", $lastUpdate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $newActivities = [];
    while ($row = $result->fetch_assoc()) {
        $newActivities[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'activity' => getActivityDisplayName($row['activity']),
            'timestamp' => $row['timestamp'],
            'status' => $row['activity'] === 'clock_in' ? 'active' : 'completed'
        ];
    }
    
    if (!empty($newActivities)) {
        $response['has_updates'] = true;
        $response['activities'] = $newActivities;
        $response['notifications'][] = [
            'message' => count($newActivities) . " aktivitas baru ditambahkan",
            'type' => 'info'
        ];
    }
    
    return $response;
}

function checkSalesUpdates($conn, $lastUpdate) {
    $response = ['has_updates' => false, 'timestamp' => time() * 1000];
    
    // Get new sales since last update
    $stmt = $conn->prepare("
        SELECT s.*, e.name as cashier_name 
        FROM sales s 
        JOIN employees e ON s.cashier_id = e.id 
        WHERE UNIX_TIMESTAMP(s.timestamp) * 1000 > ? 
        ORDER BY s.timestamp DESC 
        LIMIT 10
    ");
    $stmt->bind_param("i", $lastUpdate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $newSales = [];
    while ($row = $result->fetch_assoc()) {
        $newSales[] = [
            'id' => $row['id'],
            'cashier' => $row['cashier_name'],
            'total' => $row['total'],
            'timestamp' => $row['timestamp']
        ];
    }
    
    if (!empty($newSales)) {
        $response['has_updates'] = true;
        $response['sales'] = $newSales;
        $totalAmount = array_sum(array_column($newSales, 'total'));
        $response['notifications'][] = [
            'message' => count($newSales) . " transaksi baru (Total: Rp " . number_format($totalAmount, 0, ',', '.') . ")",
            'type' => 'success'
        ];
    }
    
    return $response;
}

function checkEmployeesUpdates($conn, $lastUpdate) {
    $response = ['has_updates' => false, 'timestamp' => time() * 1000];
    
    // Check for employee status changes
    $stmt = $conn->prepare("
        SELECT COUNT(*) as changes 
        FROM employees 
        WHERE UNIX_TIMESTAMP(updated_at) * 1000 > ? OR UNIX_TIMESTAMP(created_at) * 1000 > ?
    ");
    $stmt->bind_param("ii", $lastUpdate, $lastUpdate);
    $stmt->execute();
    $changes = $stmt->get_result()->fetch_assoc()['changes'];
    
    if ($changes > 0) {
        $response['has_updates'] = true;
        $response['notifications'][] = [
            'message' => "Ada perubahan data karyawan",
            'type' => 'info'
        ];
    }
    
    return $response;
}

function checkEmployeeActivitiesUpdates($conn, $lastUpdate) {
    $userId = $_SESSION['user_id'];
    $response = ['has_updates' => false, 'timestamp' => time() * 1000];
    
    // Check for new activities for current user
    $stmt = $conn->prepare("
        SELECT COUNT(*) as new_count 
        FROM activities 
        WHERE employee_id = ? AND UNIX_TIMESTAMP(timestamp) * 1000 > ?
    ");
    $stmt->bind_param("ii", $userId, $lastUpdate);
    $stmt->execute();
    $newActivities = $stmt->get_result()->fetch_assoc()['new_count'];
    
    if ($newActivities > 0) {
        $response['has_updates'] = true;
        $response['notifications'][] = [
            'message' => "Aktivitas Anda telah diperbarui",
            'type' => 'info'
        ];
    }
    
    return $response;
}

function getDashboardStats($conn) {
    $stats = [];
    
    // Total employees
    $result = $conn->query("SELECT COUNT(*) as count FROM employees WHERE status = 'active'");
    $stats['total_employees'] = $result->fetch_assoc()['count'];
    
    // Active employees (clocked in today)
    $result = $conn->query("
        SELECT COUNT(DISTINCT employee_id) as count 
        FROM activities 
        WHERE activity = 'clock_in' 
        AND DATE(timestamp) = CURDATE()
        AND employee_id NOT IN (
            SELECT DISTINCT employee_id 
            FROM activities 
            WHERE activity = 'clock_out' 
            AND DATE(timestamp) = CURDATE() 
            AND timestamp > (
                SELECT MAX(timestamp) 
                FROM activities a2 
                WHERE a2.employee_id = activities.employee_id 
                AND a2.activity = 'clock_in' 
                AND DATE(a2.timestamp) = CURDATE()
            )
        )
    ");
    $stats['active_employees'] = $result->fetch_assoc()['count'];
    
    // Today's sales
    $result = $conn->query("
        SELECT COALESCE(SUM(total), 0) as total 
        FROM sales 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $stats['today_sales'] = 'Rp ' . number_format($result->fetch_assoc()['total'], 0, ',', '.');
    
    // Today's transactions
    $result = $conn->query("
        SELECT COUNT(*) as count 
        FROM sales 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $stats['today_transactions'] = $result->fetch_assoc()['count'];
    
    return $stats;
}

function getActivityDisplayName($activity) {
    $activities = [
        'clock_in' => 'Masuk Kerja',
        'clock_out' => 'Pulang Kerja',
        'break_start' => 'Mulai Istirahat',
        'break_end' => 'Selesai Istirahat',
        'manual_entry' => 'Input Manual'
    ];
    
    return $activities[$activity] ?? $activity;
}
?>
