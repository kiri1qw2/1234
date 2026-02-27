<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireLogin();

// Включаем отображение ошибок для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Method not allowed');
}

$patient_id = $_POST['patient_id'] ?? 0;
$test_name = $_POST['test_name'] ?? '';
$user_id = $_SESSION['user_id'];

// Получаем surgery_id
$stmt = $pdo->prepare("
    SELECT s.id as surgery_id
    FROM patients p
    LEFT JOIN surgeries s ON p.id = s.patient_id
    WHERE p.id = ? AND p.doctor_id = ?
");
$stmt->execute([$patient_id, $user_id]);
$patient = $stmt->fetch();

if (!$patient) {
    die('Patient not found');
}

// Загружаем файл
if (isset($_FILES['test_file']) && $_FILES['test_file']['error'] === 0) {
    $upload_dir = "../uploads/patient_{$patient_id}/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_name = time() . '_' . $_FILES['test_file']['name'];
    $file_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($_FILES['test_file']['tmp_name'], $file_path)) {
        // Обновляем или создаем запись в tests
        $stmt = $pdo->prepare("
            INSERT INTO tests (surgery_id, test_name, status, file_path, uploaded_at)
            VALUES (?, ?, 'uploaded', ?, NOW())
            ON DUPLICATE KEY UPDATE
            status = 'uploaded', file_path = ?, uploaded_at = NOW()
        ");
        $stmt->execute([$patient['surgery_id'], $test_name, $file_path, $file_path]);
        
        echo "success";
        exit();
    }
}

echo "error";
?>