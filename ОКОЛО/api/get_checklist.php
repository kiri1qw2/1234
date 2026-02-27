<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$patient_id = $_GET['patient_id'] ?? 0;
$user_id = $_SESSION['user_id'];

if (!$patient_id) {
    echo json_encode(['error' => 'Patient ID required']);
    exit();
}

try {
    // Получаем информацию о пациенте и его операции
    $stmt = $pdo->prepare("
        SELECT p.id, u.full_name, s.id as surgery_id, s.surgery_type
        FROM patients p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN surgeries s ON p.id = s.patient_id
        WHERE p.id = ? AND p.doctor_id = ?
    ");
    $stmt->execute([$patient_id, $user_id]);
    $patient = $stmt->fetch();

    if (!$patient) {
        echo json_encode(['error' => 'Patient not found']);
        exit();
    }

    // Определяем чек-лист в зависимости от типа операции
    $checklist_templates = [
        'phaco' => [
            ['name' => 'Общий анализ крови (глюкоза, гемоглобин)', 'required' => true],
            ['name' => 'ЭКГ (расшифровка)', 'required' => true],
            ['name' => 'Флюорография', 'required' => true],
            ['name' => 'Осмотр терапевта', 'required' => true],
            ['name' => 'Забор материала из глаза (бакпосев)', 'required' => false],
            ['name' => 'Биометрия глаза (IOL Master)', 'required' => true],
            ['name' => 'Расчет ИОЛ', 'required' => true, 'is_calculator' => true]
        ],
        'glaucoma' => [
            ['name' => 'Общий анализ крови', 'required' => true],
            ['name' => 'Тонометрия', 'required' => true],
            ['name' => 'Гониоскопия', 'required' => true],
            ['name' => 'Периметрия', 'required' => true],
            ['name' => 'Осмотр терапевта', 'required' => true],
            ['name' => 'ЭКГ', 'required' => true]
        ]
    ];

    $surgery_type = $patient['surgery_type'] ?? 'phaco';
    $checklist = $checklist_templates[$surgery_type] ?? $checklist_templates['phaco'];

    // Получаем текущие статусы анализов
    if ($patient['surgery_id']) {
        $stmt = $pdo->prepare("
            SELECT test_name, status, file_path, uploaded_at
            FROM tests
            WHERE surgery_id = ?
        ");
        $stmt->execute([$patient['surgery_id']]);
        $tests = $stmt->fetchAll();
    } else {
        $tests = [];
    }

    // Преобразуем в ассоциативный массив по названию
    $test_statuses = [];
    foreach ($tests as $test) {
        $test_statuses[$test['test_name']] = [
            'status' => $test['status'],
            'file_path' => $test['file_path'],
            'uploaded_at' => $test['uploaded_at']
        ];
    }

    // Формируем ответ
    $response = [
        'patient_name' => $patient['full_name'],
        'surgery_type' => $surgery_type,
        'checklist' => []
    ];

    foreach ($checklist as $item) {
        $item_name = $item['name'];
        $status_data = $test_statuses[$item_name] ?? ['status' => 'pending', 'file_path' => null, 'uploaded_at' => null];
        
        $response['checklist'][] = [
            'name' => $item_name,
            'required' => $item['required'],
            'is_calculator' => $item['is_calculator'] ?? false,
            'status' => $status_data['status'],
            'file_path' => $status_data['file_path'],
            'uploaded_at' => $status_data['uploaded_at']
        ];
    }

    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>