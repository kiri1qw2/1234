<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireLogin();

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

if ($_FILES['test_file']['error'] === 0) {
    // Создаем папку
    $upload_dir = "../uploads/patient_{$patient_id}/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_name = time() . '_' . $_FILES['test_file']['name'];
    $file_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($_FILES['test_file']['tmp_name'], $file_path)) {
        // Сохраняем путь БЕЗ ./ и БЕЗ ..
        $web_path = "uploads/patient_{$patient_id}/" . $file_name;
        
        $stmt = $pdo->prepare("SELECT id FROM tests WHERE surgery_id = ? AND test_name = ?");
        $stmt->execute([$patient['surgery_id'], $test_name]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $pdo->prepare("UPDATE tests SET status = 'uploaded', file_path = ?, uploaded_at = NOW() WHERE id = ?");
            $stmt->execute([$web_path, $exists['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO tests (surgery_id, test_name, status, file_path, uploaded_at) VALUES (?, ?, 'uploaded', ?, NOW())");
            $stmt->execute([$patient['surgery_id'], $test_name, $web_path]);
        }
    }
}

header("Location: ../dashboard.php?upload_success=1");
exit();
?>