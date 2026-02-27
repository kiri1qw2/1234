<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireRole('surgeon');

$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];

// Получаем статистику хирурга
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT CASE WHEN s.status = 'review' THEN s.id END) as pending_review,
        COUNT(DISTINCT CASE WHEN s.status = 'approved' AND s.surgery_date >= CURDATE() THEN s.id END) as upcoming_surgeries,
        COUNT(DISTINCT CASE WHEN s.status = 'preparation' THEN s.id END) as in_preparation,
        COUNT(DISTINCT s.id) as total_surgeries,
        COUNT(DISTINCT p.id) as total_patients,
        COUNT(DISTINCT CASE WHEN s.status = 'rejected' AND s.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN s.id END) as recent_rejections
    FROM patients p
    LEFT JOIN surgeries s ON p.id = s.patient_id
    WHERE p.surgeon_id = ? OR (p.surgeon_id IS NULL AND s.status IN ('review', 'preparation'))
");
$stmt->execute([$user_id]);
$stats = $stmt->fetch();

// Получаем ВСЕХ пациентов из всех районов, ожидающих подтверждения (статус 'review')
$stmt = $pdo->prepare("
    SELECT 
        p.id as patient_id,
        u.full_name as patient_name,
        u.district,
        u.phone as patient_phone,
        u.email as patient_email,
        u.id as user_id,
        s.id as surgery_id,
        s.surgery_type,
        s.status,
        s.created_at as surgery_created,
        s.updated_at as surgery_updated,
        s.notes as surgery_notes,
        d.name as diagnosis,
        d.code as diagnosis_code,
        d.description as diagnosis_description,
        -- Информация о враче
        doc.id as doctor_id,
        doc.full_name as doctor_name,
        doc.phone as doctor_phone,
        doc.email as doctor_email,
        doc.district as doctor_district,
        -- Статистика по анализам
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_uploaded,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'approved') as tests_approved,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'rejected') as tests_rejected,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'pending') as tests_pending
    FROM patients p
    JOIN users u ON p.user_id = u.id
    JOIN surgeries s ON p.id = s.patient_id
    JOIN diseases d ON s.disease_id = d.id
    LEFT JOIN users doc ON p.doctor_id = doc.id
    WHERE s.status IN ('review', 'preparation')
    ORDER BY 
        CASE 
            WHEN s.status = 'review' THEN 1 
            ELSE 2 
        END,
        s.created_at ASC
");
$stmt->execute(); // Убираем параметры, так как в запросе нет ?
$all_patients = $stmt->fetchAll();


// Разделяем пациентов по статусам
$pending_review = array_filter($all_patients, function($p) { 
    return $p['status'] === 'review'; 
});
$in_preparation = array_filter($all_patients, function($p) { 
    return $p['status'] === 'preparation'; 
});

// Получаем предстоящие операции
$stmt = $pdo->prepare("
    SELECT 
        p.id as patient_id,
        u.full_name as patient_name,
        u.district,
        s.id as surgery_id,
        s.surgery_type,
        s.surgery_date,
        d.name as diagnosis,
        d.code as diagnosis_code,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'approved') as tests_approved,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total,
        doc.full_name as doctor_name
    FROM patients p
    JOIN users u ON p.user_id = u.id
    JOIN surgeries s ON p.id = s.patient_id
    JOIN diseases d ON s.disease_id = d.id
    LEFT JOIN users doc ON p.doctor_id = doc.id
    WHERE p.surgeon_id = ? AND s.status = 'approved' AND s.surgery_date >= CURDATE()
    ORDER BY s.surgery_date ASC
    LIMIT 10
");
$stmt->execute([$user_id]);
$upcoming_surgeries = $stmt->fetchAll();

// Обработка подтверждения готовности
// Обработка подтверждения готовности
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_surgery'])) {
    $surgery_id = $_POST['surgery_id'];
    $patient_id = $_POST['patient_id'];
    $surgery_date = $_POST['surgery_date'] ?? date('Y-m-d', strtotime('+14 days'));
    $notes = $_POST['notes'] ?? '';
    
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Сначала получаем текущие notes
        $stmt = $pdo->prepare("SELECT notes FROM surgeries WHERE id = ?");
        $stmt->execute([$surgery_id]);
        $current = $stmt->fetch();
        
        $new_notes = ($current['notes'] ? $current['notes'] . "\n\n" : "") . 
                     "[Одобрено хирургом: " . date('d.m.Y H:i') . "] " . $notes;
        
        // Обновляем статус операции
        $stmt = $pdo->prepare("
            UPDATE surgeries SET 
                status = 'approved', 
                surgeon_id = ?, 
                surgery_date = ?,
                notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $surgery_date, $new_notes, $surgery_id]);
        
        // Обновляем хирурга у пациента
        $stmt = $pdo->prepare("UPDATE patients SET surgeon_id = ? WHERE id = ?");
        $stmt->execute([$user_id, $patient_id]);
        
        // Автоматически подтверждаем все загруженные анализы
        $stmt = $pdo->prepare("
            UPDATE tests SET status = 'approved' 
            WHERE surgery_id = ? AND status = 'uploaded'
        ");
        $stmt->execute([$surgery_id]);
        
        $pdo->commit();
        
        header("Location: review.php?approved=1");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Ошибка при одобрении операции: " . $e->getMessage();
    }
}

// Обработка отклонения с комментарием
// Обработка отклонения с комментарием
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_surgery'])) {
    $surgery_id = $_POST['surgery_id'];
    $comment = $_POST['comment'] ?? '';
    $recommendation = $_POST['recommendation'] ?? '';
    
    $full_comment = "🔴 ЗАМЕЧАНИЯ ХИРУРГА:\n";
    $full_comment .= "Комментарий: " . $comment . "\n";
    $full_comment .= "Рекомендация: " . $recommendation . "\n";
    $full_comment .= "Дата: " . date('d.m.Y H:i');
    
    // Исправляем запрос - сначала получаем текущие notes
    $stmt = $pdo->prepare("SELECT notes FROM surgeries WHERE id = ?");
    $stmt->execute([$surgery_id]);
    $current = $stmt->fetch();
    
    $new_notes = ($current['notes'] ? $current['notes'] . "\n\n" : "") . $full_comment;
    
    $stmt = $pdo->prepare("
        UPDATE surgeries SET 
            status = 'rejected', 
            notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$new_notes, $surgery_id]);
    
    // Если есть конкретные анализы для отклонения
    if (isset($_POST['reject_tests']) && is_array($_POST['reject_tests'])) {
        foreach ($_POST['reject_tests'] as $test_id) {
            $stmt = $pdo->prepare("UPDATE tests SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$test_id]);
        }
    }
    
    header("Location: review.php?rejected=1");
    exit();
}

// Обработка отправки сообщения врачу
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $doctor_id = $_POST['doctor_id'];
    $patient_id = $_POST['patient_id'];
    $message = $_POST['message'] ?? '';
    
    // Здесь можно добавить таблицу для сообщений
    // Пока просто обновляем заметки
    $stmt = $pdo->prepare("
        UPDATE surgeries s
        JOIN patients p ON s.patient_id = p.id
        SET s.notes = CONCAT(IFNULL(s.notes, ''), '\n[Сообщение врачу: ', ?, ']')
        WHERE p.id = ? AND s.status IN ('review', 'preparation')
    ");
    $stmt->execute([$message, $patient_id]);
    
    header("Location: review.php?message_sent=1");
    exit();
}

// Получаем шаблоны частых комментариев
$common_comments = [
    'Высокий сахар' => 'Отправьте пациента к эндокринологу для коррекции диабета',
    'Плохая ЭКГ' => 'Требуется консультация кардиолога',
    'Неполные анализы' => 'Необходимо досдать общий анализ крови и биохимию',
    'Высокое давление' => 'Скорректируйте терапию, направьте к терапевту',
    'Проблемы с сердцем' => 'Требуется дополнительное обследование у кардиолога',
    'Аллергия' => 'Уточните аллергологический анамнез',
    'Инфекция' => 'Необходимо исключить острые инфекционные заболевания',
    'Плохая биометрия' => 'Повторите биометрию глаза (IOL Master)'
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Кабинет хирурга - Модерация пациентов</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .surgeon-cabinet {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .stat-card {
            background: white;
            padding: 1.2rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
            border-bottom: 3px solid transparent;
        }
        
        .stat-card.pending { border-bottom-color: #ffc107; }
        .stat-card.preparation { border-bottom-color: #17a2b8; }
        .stat-card.approved { border-bottom-color: #28a745; }
        .stat-card.rejected { border-bottom-color: #dc3545; }
        .stat-card.total { border-bottom-color: #708090 100%; }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #708090 100%;
        }
        
        /* Таблица пациентов */
        .patients-table-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin: 2rem 0;
            overflow-x: auto;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 0.5rem 1.5rem;
            border: none;
            background: #f0f4f8;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .filter-tab:hover {
            background: #e0e7f0;
        }
        
        .filter-tab.active {
            background: #708090 100%;
            color: white;
        }
        
        .patients-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .patients-table th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            color: #708090 100%;
            font-weight: 600;
            border-bottom: 2px solid #708090 100%;
        }
        
        .patients-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        .patients-table tr:hover {
            background: #f8f9fa;
        }
        
        .patient-info {
            display: flex;
            flex-direction: column;
        }
        
        .patient-name {
            font-weight: 600;
            color: #708090 100%;
        }
        
        .patient-meta {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.2rem;
        }
        
        .doctor-badge {
            background: #e8f0fe;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            display: inline-block;
        }
        
        .tests-progress-mini {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .progress-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: conic-gradient(#28a745 0deg, #e0e0e0 0deg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-align: center;
            display: inline-block;
        }
        
        .status-review { background: #fff3cd; color: #856404; }
        .status-preparation { background: #cce5ff; color: #004085; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        
        .btn-view { background: #6c757d; color: white; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .btn-message { background: #17a2b8; color: white; }
        
        /* Модальные окна */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease-out;
        }
        
        .modal-lg {
            max-width: 800px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .modal-header h2 {
            color: #708090 100%;
            margin: 0;
        }
        
        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .close-modal:hover {
            color: #dc3545;
        }
        
        /* Детальная информация */
        .patient-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .info-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 10px;
        }
        
        .info-section h4 {
            color: #708090 100%;
            margin-bottom: 1rem;
            border-bottom: 1px solid #ddd;
            padding-bottom: 0.5rem;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .info-label {
            width: 120px;
            color: #666;
        }
        
        .info-value {
            flex: 1;
            color: #333;
            font-weight: 500;
        }
        
        .tests-list {
            list-style: none;
            padding: 0;
        }
        
        .test-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .test-item:last-child {
            border-bottom: none;
        }
        
        .test-status {
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        .test-status.uploaded { background: #cce5ff; color: #004085; }
        .test-status.approved { background: #d4edda; color: #155724; }
        .test-status.rejected { background: #f8d7da; color: #721c24; }
        .test-status.pending { background: #fff3cd; color: #856404; }
        
        /* Комментарии */
        .comment-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1rem 0;
        }
        
        .comment-preset {
            background: #e8f0fe;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .comment-preset:hover {
            background: #708090 100%;
            color: white;
        }
        
        .recommendation-text {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            margin: 1rem 0;
            resize: vertical;
        }
        
        /* Алерты */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease-out;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .patient-detail-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-icon {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo">
            <img src="assets/img/logo.png" alt="ОКОЛО" width="70" height="55">
            ОКОЛО
        </div>
        <nav>
            <div class="nav-links">
                <a href="dashboard.php">Дашборд</a>
                <a href="patients.php">Мои пациенты</a>
                <a href="review.php" class="active">Кабинет хирурга</a>
                <a href="schedule.php">Расписание</a>
                <a href="profile.php">Профиль</a>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="role-badge">Хирург-куратор</span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container surgeon-cabinet">
        <?php if (isset($_GET['approved'])): ?>
        <div class="alert alert-success">
            ✅ Операция подтверждена! Пациент добавлен в расписание.
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['rejected'])): ?>
        <div class="alert alert-info">
            📝 Комментарий отправлен районному врачу. Пациент отправлен на доработку.
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['message_sent'])): ?>
        <div class="alert alert-info">
            ✉️ Сообщение отправлено врачу.
        </div>
        <?php endif; ?>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card pending">
                <div class="stat-number"><?php echo count($pending_review); ?></div>
                <div class="stat-label">Ожидают проверки</div>
            </div>
            <div class="stat-card preparation">
                <div class="stat-number"><?php echo count($in_preparation); ?></div>
                <div class="stat-label">На подготовке</div>
            </div>
            <div class="stat-card approved">
                <div class="stat-number"><?php echo $stats['upcoming_surgeries'] ?? 0; ?></div>
                <div class="stat-label">Предстоит операций</div>
            </div>
            <div class="stat-card rejected">
                <div class="stat-number"><?php echo $stats['recent_rejections'] ?? 0; ?></div>
                <div class="stat-label">Отклонено (7 дней)</div>
            </div>
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total_patients'] ?? 0; ?></div>
                <div class="stat-label">Всего пациентов</div>
            </div>
        </div>

        <!-- Лента пациентов -->
        <div class="patients-table-container">
            <div class="table-header">
                <h2>📋 Лента пациентов (из всех районов)</h2>
                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterPatients('all')">Все</button>
                    <button class="filter-tab" onclick="filterPatients('review')">Ожидают проверки</button>
                    <button class="filter-tab" onclick="filterPatients('preparation')">На подготовке</button>
                </div>
            </div>

            <table class="patients-table" id="patientsTable">
                <thead>
                    <tr>
                        <th>Пациент</th>
                        <th>Район</th>
                        <th>Диагноз (МКБ-10)</th>
                        <th>Врач</th>
                        <th>Анализы</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_patients as $patient): 
                        $progress = $patient['tests_total'] > 0 ? 
                            round(($patient['tests_uploaded'] / $patient['tests_total']) * 100) : 0;
                        $all_tests_uploaded = ($patient['tests_uploaded'] == $patient['tests_total']);
                        $tests_json = json_decode($patient['tests_json'] ?? '[]', true);
                    ?>
                    <tr data-status="<?php echo $patient['status']; ?>">
                        <td>
                            <div class="patient-info">
                                <span class="patient-name"><?php echo htmlspecialchars($patient['patient_name']); ?></span>
                                <span class="patient-meta"><?php echo htmlspecialchars($patient['patient_phone'] ?? ''); ?></span>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($patient['district']); ?></td>
                        <td>
                            <span class="mkb-code"><?php echo htmlspecialchars($patient['diagnosis_code'] ?: 'H25.9'); ?></span>
                            <div><?php echo htmlspecialchars($patient['diagnosis']); ?></div>
                        </td>
                        <td>
                            <span class="doctor-badge">
                                <?php echo htmlspecialchars($patient['doctor_name'] ?: 'Не назначен'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="tests-progress-mini">
                                <div class="progress-circle" style="background: conic-gradient(#28a745 <?php echo $progress * 3.6; ?>deg, #e0e0e0 0deg);">
                                    <?php echo $progress; ?>%
                                </div>
                                <span><?php echo $patient['tests_uploaded']; ?>/<?php echo $patient['tests_total']; ?></span>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $patient['status']; ?>">
                                <?php 
                                $statuses = [
                                    'review' => 'Ожидает проверки',
                                    'preparation' => 'На подготовке',
                                    'approved' => 'Одобрено',
                                    'rejected' => 'Отклонено'
                                ];
                                echo $statuses[$patient['status']] ?? $patient['status'];
                                ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-icon btn-view" onclick="viewPatientDetails(<?php echo htmlspecialchars(json_encode($patient)); ?>)">👁️</button>
                                
                                <?php if ($patient['status'] === 'review' && $all_tests_uploaded): ?>
                                <button class="btn-icon btn-approve" onclick="openApproveModal(<?php echo $patient['surgery_id']; ?>, <?php echo $patient['patient_id']; ?>, '<?php echo htmlspecialchars($patient['patient_name']); ?>')">✅</button>
                                <?php endif; ?>
                                
                                <button class="btn-icon btn-reject" onclick="openRejectModal(<?php echo $patient['surgery_id']; ?>, <?php echo $patient['patient_id']; ?>, '<?php echo htmlspecialchars($patient['patient_name']); ?>', <?php echo htmlspecialchars(json_encode($tests_json)); ?>)">✏️</button>
                                
                                <button class="btn-icon btn-message" onclick="openMessageModal(<?php echo $patient['doctor_id']; ?>, <?php echo $patient['patient_id']; ?>, '<?php echo htmlspecialchars($patient['doctor_name']); ?>', '<?php echo htmlspecialchars($patient['patient_name']); ?>')">💬</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($all_patients)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 3rem; color: #666;">
                            <h3>Нет пациентов на модерации</h3>
                            <p>Все пациенты проверены или находятся на подготовке</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Предстоящие операции (кратко) -->
        <?php if (!empty($upcoming_surgeries)): ?>
        <div style="background: white; border-radius: 15px; padding: 1.5rem; margin: 2rem 0; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <h3 style="color: #708090 100%; margin-bottom: 1rem;">📅 Ближайшие операции</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                <?php foreach ($upcoming_surgeries as $surgery): ?>
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 10px; border-left: 4px solid #28a745;">
                    <div style="font-weight: bold;"><?php echo htmlspecialchars($surgery['patient_name']); ?></div>
                    <div style="font-size: 0.9rem; color: #666;"><?php echo htmlspecialchars($surgery['diagnosis']); ?></div>
                    <div style="margin-top: 0.5rem; color: #28a745;">📅 <?php echo date('d.m.Y H:i', strtotime($surgery['surgery_date'])); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Модальное окно детальной информации -->
    <div id="viewModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2>Детальная информация</h2>
                <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div id="viewModalContent"></div>
        </div>
    </div>

    <!-- Модальное окно подтверждения -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Подтвердить готовность</h2>
                <span class="close-modal" onclick="closeModal('approveModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="surgery_id" id="approve_surgery_id">
                <input type="hidden" name="patient_id" id="approve_patient_id">
                <input type="hidden" name="approve_surgery" value="1">
                
                <div style="margin-bottom: 1.5rem;">
                    <p><strong>Пациент:</strong> <span id="approve_patient_name"></span></p>
                    <p>Все анализы загружены. Вы можете назначить дату операции:</p>
                </div>
                
                <div class="form-group">
                    <label for="surgery_date">Дата операции:</label>
                    <input type="date" name="surgery_date" id="surgery_date" 
                           value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" 
                           min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="approve_notes">Дополнительные заметки:</label>
                    <textarea name="notes" id="approve_notes" rows="3" style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 5px;"></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn-approve" style="flex: 2;">✅ Подтвердить</button>
                    <button type="button" class="btn-reject" onclick="closeModal('approveModal')" style="flex: 1;">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно отклонения с комментарием -->
    <div id="rejectModal" class="modal">
        <div class="modal-content modal-lg">
            <div class="modal-header">
                <h2>Отправить на доработку</h2>
                <span class="close-modal" onclick="closeModal('rejectModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="surgery_id" id="reject_surgery_id">
                <input type="hidden" name="patient_id" id="reject_patient_id">
                <input type="hidden" name="reject_surgery" value="1">
                
                <div style="margin-bottom: 1.5rem;">
                    <p><strong>Пациент:</strong> <span id="reject_patient_name"></span></p>
                </div>
                
                <!-- Шаблоны частых комментариев -->
                <div class="comment-presets">
                    <?php foreach ($common_comments as $title => $text): ?>
                    <span class="comment-preset" onclick="setComment('<?php echo htmlspecialchars($text); ?>')">
                        <?php echo $title; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-group">
                    <label for="comment">Комментарий для врача:</label>
                    <textarea name="comment" id="comment" rows="3" required 
                              style="width: 100%; padding: 0.8rem; border: 2px solid #e0e0e0; border-radius: 8px;"
                              placeholder="Опишите, что нужно исправить..."></textarea>
                </div>
                
                <div class="form-group">
                    <label for="recommendation">Рекомендация:</label>
                    <textarea name="recommendation" id="recommendation" rows="2" 
                              style="width: 100%; padding: 0.8rem; border: 2px solid #e0e0e0; border-radius: 8px;"
                              placeholder="Что конкретно нужно сделать?"></textarea>
                </div>
                
                <!-- Список анализов для выборочного отклонения -->
                <div id="reject_tests_list" style="margin: 1rem 0; max-height: 200px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 8px; padding: 0.5rem;">
                    <p style="font-weight: bold;">Выберите анализы для отклонения:</p>
                    <!-- Заполняется через JavaScript -->
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn-reject" style="flex: 2;">📝 Отправить на доработку</button>
                    <button type="button" class="btn-approve" onclick="closeModal('rejectModal')" style="flex: 1;">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно отправки сообщения врачу -->
    <div id="messageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Сообщение врачу</h2>
                <span class="close-modal" onclick="closeModal('messageModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="doctor_id" id="message_doctor_id">
                <input type="hidden" name="patient_id" id="message_patient_id">
                <input type="hidden" name="send_message" value="1">
                
                <div style="margin-bottom: 1.5rem;">
                    <p><strong>Кому:</strong> <span id="message_doctor_name"></span></p>
                    <p><strong>По пациенту:</strong> <span id="message_patient_name"></span></p>
                </div>
                
                <div class="form-group">
                    <label for="message">Сообщение:</label>
                    <textarea name="message" id="message" rows="5" required 
                              style="width: 100%; padding: 0.8rem; border: 2px solid #e0e0e0; border-radius: 8px;"
                              placeholder="Напишите сообщение..."></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn-message" style="flex: 2;">💬 Отправить</button>
                    <button type="button" class="btn-reject" onclick="closeModal('messageModal')" style="flex: 1;">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <footer>
        <p>&copy; 2026 ОКОЛО - Кабинет хирурга</p>
    </footer>

    <script>
        let currentTests = [];
        
        function filterPatients(status) {
            const rows = document.querySelectorAll('#patientsTable tbody tr');
            const tabs = document.querySelectorAll('.filter-tab');
            
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function viewPatientDetails(patient) {
            const content = document.getElementById('viewModalContent');
            const tests = patient.tests_json ? JSON.parse(patient.tests_json) : [];
            
            let testsHtml = '';
            tests.forEach(test => {
                testsHtml += `
                    <div class="test-item">
                        <span>${test.name}</span>
                        <span class="test-status ${test.status}">
                            ${getTestStatusText(test.status)}
                            ${test.uploaded_at ? '<br><small>' + new Date(test.uploaded_at).toLocaleDateString() + '</small>' : ''}
                        </span>
                    </div>
                `;
            });
            
            content.innerHTML = `
                <div class="patient-detail-grid">
                    <div class="info-section">
                        <h4>👤 Пациент</h4>
                        <div class="info-row">
                            <span class="info-label">ФИО:</span>
                            <span class="info-value">${patient.patient_name}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Район:</span>
                            <span class="info-value">${patient.district}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Телефон:</span>
                            <span class="info-value">${patient.patient_phone || 'Не указан'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span class="info-value">${patient.patient_email || 'Не указан'}</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>🩺 Диагноз</h4>
                        <div class="info-row">
                            <span class="info-label">МКБ-10:</span>
                            <span class="info-value">${patient.diagnosis_code || 'H25.9'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Диагноз:</span>
                            <span class="info-value">${patient.diagnosis}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Операция:</span>
                            <span class="info-value">${patient.surgery_type}</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>👨‍⚕️ Врач</h4>
                        <div class="info-row">
                            <span class="info-label">ФИО:</span>
                            <span class="info-value">${patient.doctor_name || 'Не назначен'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Район:</span>
                            <span class="info-value">${patient.doctor_district || 'Не указан'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Телефон:</span>
                            <span class="info-value">${patient.doctor_phone || 'Не указан'}</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h4>📊 Прогресс</h4>
                        <div class="info-row">
                            <span class="info-label">Анализы:</span>
                            <span class="info-value">${patient.tests_uploaded}/${patient.tests_total}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Принято:</span>
                            <span class="info-value">${patient.tests_approved}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Отклонено:</span>
                            <span class="info-value">${patient.tests_rejected}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Статус:</span>
                            <span class="info-value">${getStatusText(patient.status)}</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h4>📋 Список анализов</h4>
                    <div class="tests-list">
                        ${testsHtml || '<p>Нет загруженных анализов</p>'}
                    </div>
                </div>
                
                ${patient.surgery_notes ? `
                <div class="info-section">
                    <h4>📝 Примечания</h4>
                    <p style="white-space: pre-wrap;">${patient.surgery_notes}</p>
                </div>
                ` : ''}
            `;
            
            openModal('viewModal');
        }
        
        function openApproveModal(surgeryId, patientId, patientName) {
            document.getElementById('approve_surgery_id').value = surgeryId;
            document.getElementById('approve_patient_id').value = patientId;
            document.getElementById('approve_patient_name').innerText = patientName;
            openModal('approveModal');
        }
        
        function openRejectModal(surgeryId, patientId, patientName, tests) {
            document.getElementById('reject_surgery_id').value = surgeryId;
            document.getElementById('reject_patient_id').value = patientId;
            document.getElementById('reject_patient_name').innerText = patientName;
            
            currentTests = tests;
            
            let testsHtml = '<p style="font-weight: bold;">Выберите анализы для отклонения:</p>';
            tests.forEach(test => {
                if (test.status === 'uploaded') {
                    testsHtml += `
                        <label style="display: block; padding: 0.5rem;">
                            <input type="checkbox" name="reject_tests[]" value="${test.id}">
                            ${test.name} (загружен)
                        </label>
                    `;
                }
            });
            
            document.getElementById('reject_tests_list').innerHTML = testsHtml;
            openModal('rejectModal');
        }
        
        function openMessageModal(doctorId, patientId, doctorName, patientName) {
            document.getElementById('message_doctor_id').value = doctorId;
            document.getElementById('message_patient_id').value = patientId;
            document.getElementById('message_doctor_name').innerText = doctorName || 'Врач';
            document.getElementById('message_patient_name').innerText = patientName;
            openModal('messageModal');
        }
        
        function setComment(text) {
            document.getElementById('comment').value = text;
        }
        
        function getTestStatusText(status) {
            const statuses = {
                'pending': 'Ожидает',
                'uploaded': 'Загружен',
                'approved': 'Принят',
                'rejected': 'Отклонен'
            };
            return statuses[status] || status;
        }
        
        function getStatusText(status) {
            const statuses = {
                'review': 'Ожидает проверки',
                'preparation': 'На подготовке',
                'approved': 'Одобрено',
                'rejected': 'Отклонено'
            };
            return statuses[status] || status;
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Установка минимальной даты для выбора
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('surgery_date');
            if (dateInput) {
                const today = new Date().toISOString().split('T')[0];
                dateInput.min = today;
            }
        });
    </script>
</body>
</html>