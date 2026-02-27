<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$patient_id = $_POST['patient_id'] ?? 0;
$test_name = $_POST['test_name'] ?? '';
$user_id = $_SESSION['user_id'];

if (!$patient_id || !$test_name) {
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

try {
    // Проверяем, что пациент принадлежит этому офтальмологу
    $stmt = $pdo->prepare("
        SELECT p.id, s.id as surgery_id
        FROM patients p
        LEFT JOIN surgeries s ON p.id = s.patient_id
        WHERE p.id = ? AND p.doctor_id = ?
    ");
    $stmt->execute([$patient_id, $user_id]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit();
    }

    if (!isset($_FILES['test_file']) || $_FILES['test_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No file uploaded']);
        exit();
    }

    // Создаем папку для загрузок, если её нет
    $upload_dir = "../uploads/patient_{$patient_id}/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Генерируем имя файла
    $file_extension = pathinfo($_FILES['test_file']['name'], PATHINFO_EXTENSION);
    $safe_test_name = preg_replace('/[^a-zA-Zа-яА-Я0-9]/u', '_', $test_name);
    $file_name = time() . '_' . $safe_test_name . '.' . $file_extension;
    $file_path = $upload_dir . $file_name;
    
    // Перемещаем файл
    if (move_uploaded_file($_FILES['test_file']['tmp_name'], $file_path)) {
        
        // Проверяем, существует ли уже такой анализ
        $stmt = $pdo->prepare("SELECT id FROM tests WHERE surgery_id = ? AND test_name = ?");
        $stmt->execute([$patient['surgery_id'], $test_name]);
        $existing_test = $stmt->fetch();
        
        if ($existing_test) {
            // Обновляем существующий
            $stmt = $pdo->prepare("
                UPDATE tests 
                SET status = 'uploaded', file_path = ?, uploaded_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$file_path, $existing_test['id']]);
        } else {
            // Создаем новый
            $stmt = $pdo->prepare("
                INSERT INTO tests (surgery_id, test_name, status, file_path, uploaded_at)
                VALUES (?, ?, 'uploaded', ?, NOW())
            ");
            $stmt->execute([$patient['surgery_id'], $test_name, $file_path]);
        }
        
        // Сохраняем в media
        $stmt = $pdo->prepare("
            INSERT INTO media (patient_id, surgery_id, file_path, file_name, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $patient_id,
            $patient['surgery_id'],
            $file_path,
            $_FILES['test_file']['name'],
            $_FILES['test_file']['size'],
            $user_id
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'file_path' => $file_path
        ]);
        
    } else {
        echo json_encode(['error' => 'Failed to move uploaded file']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>