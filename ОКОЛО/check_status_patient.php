<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Только для пациентов
if (!isPatient()) {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

// Получаем информацию о пациенте
$stmt = $pdo->prepare("
    SELECT p.id as patient_id, u.full_name, u.district
    FROM patients p
    JOIN users u ON p.user_id = u.id
    WHERE p.user_id = ?
");
$stmt->execute([$user_id]);
$patient = $stmt->fetch();

if (!$patient) {
    // Если пациент не найден, создаем запись
    $stmt = $pdo->prepare("INSERT INTO patients (user_id, district) VALUES (?, ?)");
    $stmt->execute([$user_id, $_SESSION['district'] ?? '']);
    $patient_id = $pdo->lastInsertId();
} else {
    $patient_id = $patient['patient_id'];
}

// Получаем все операции пациента
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        d.name as diagnosis,
        d.code as diagnosis_code,
        d.description as diagnosis_description,
        -- Информация о враче из таблицы patients, а не surgeries
        doc.full_name as doctor_name,
        doc.phone as doctor_phone,
        surg.full_name as surgeon_name
    FROM surgeries s
    JOIN diseases d ON s.disease_id = d.id
    LEFT JOIN patients p ON s.patient_id = p.id
    LEFT JOIN users doc ON p.doctor_id = doc.id
    LEFT JOIN users surg ON p.surgeon_id = surg.id
    WHERE s.patient_id = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$patient_id]);
$surgeries = $stmt->fetchAll();

// Получаем анализы для каждой операции
$tests_by_surgery = [];
foreach ($surgeries as $surgery) {
    $stmt = $pdo->prepare("
        SELECT * FROM tests 
        WHERE surgery_id = ? 
        ORDER BY 
            CASE status 
                WHEN 'pending' THEN 1 
                WHEN 'uploaded' THEN 2 
                WHEN 'approved' THEN 3 
                WHEN 'rejected' THEN 4 
            END
    ");
    $stmt->execute([$surgery['id']]);
    $tests_by_surgery[$surgery['id']] = $stmt->fetchAll();
}

// Статусы для отображения
$status_labels = [
    'new' => 'Новый',
    'preparation' => 'Подготовка',
    'review' => 'Проверка',
    'approved' => 'Одобрен',
    'rejected' => 'Отклонен'
];

$test_status_labels = [
    'pending' => 'Ожидает',
    'uploaded' => 'Загружен',
    'approved' => 'Принят',
    'rejected' => 'Отклонен'
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статус подготовки - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .status-container {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .patient-header {
        background: #faf3eo;
        color: white;
        padding: 2rem;
        margin-bottom: 2rem;
        outline: 3px solid #708090;
        }
        
        .patient-header h1 {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        color: #708090;
        }
        
        .patient-meta {
        display: flex;
        gap: 2rem;
        opacity: 0.9;
        color: #708090;
        margin: 5px;
        }
        
        .surgery-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-left: 5px solid #708090 100%;
        }
        
        .surgery-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .surgery-title {
            font-size: 1.5rem;
            color: #708090 100%;
        }
        
        .mkb-code {
            background: #e8f0fe;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-family: monospace;
            font-weight: bold;
        }
        
        .status-badge {
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-weight: 500;
        }
        
        .status-new { background: #e0e0e0; color: #666; }
        .status-preparation { background: #fff3cd; color: #856404; }
        .status-review { background: #cce5ff; color: #004085; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            position: relative;
        }
        
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }
        
        .step {
            position: relative;
            z-index: 2;
            background: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            border: 2px solid #e0e0e0;
            color: #666;
            font-weight: 500;
        }
        
        .step.active {
            border-color: #2a5298;
            background: #2a5298;
            color: white;
        }
        
        .step.completed {
            border-color: #28a745;
            background: #28a745;
            color: white;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin: 1.5rem 0;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        
        .info-value {
            color: #708090 100%;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .tests-section {
            margin: 2rem 0;
        }
        
        .tests-section h3 {
            color: #708090 100%;
            margin-bottom: 1rem;
        }
        
        .tests-list {
            list-style: none;
            padding: 0;
        }
        
        .test-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        
        .test-item:hover {
            background: #f8f9fa;
        }
        
        .test-name {
            font-weight: 500;
            color: #333;
        }
        
        .test-status {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .test-status.pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .test-status.uploaded {
            background: #cce5ff;
            color: #004085;
        }
        
        .test-status.approved {
            background: #d4edda;
            color: #155724;
        }
        
        .test-status.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .doctor-info {
            background: #e8f0fe;
            padding: 1rem;
            border-radius: 10px;
            margin: 1rem 0;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 15px;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .progress-steps {
                flex-direction: column;
                gap: 0.5rem;
                align-items: center;
            }
            
            .progress-steps::before {
                display: none;
            }
            
            .step {
                width: 100%;
                text-align: center;
            }
            
            .patient-meta {
                flex-direction: column;
                gap: 0.5rem;
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
                <a href="check_status_patient.php" class="active">Статус подготовки</a>
                <a href="schedule.php">Расписание</a>
                <a href="profile.php">Профиль</a>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="role-badge">Пациент</span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container status-container">
        <div class="patient-header">
            <h1>Статус подготовки</h1>
            <div class="patient-meta">
                <span><?php echo htmlspecialchars($full_name); ?></span>
                <span>Район: <?php echo htmlspecialchars($patient['district'] ?? 'Не указан'); ?></span>
            </div>
        </div>

        <?php if (empty($surgeries)): ?>
            <div class="empty-state">
                <h3>У вас пока нет запланированных операций</h3>
                <p>Обратитесь к районному офтальмологу для консультации</p>
            </div>
        <?php else: ?>
            <?php foreach ($surgeries as $surgery): ?>
                <div class="surgery-card">
                    <div class="surgery-header">
                        <div>
                            <span class="surgery-title"><?php echo htmlspecialchars($surgery['diagnosis']); ?></span>
                            <span class="mkb-code"><?php echo htmlspecialchars($surgery['diagnosis_code'] ?: 'H25.9'); ?></span>
                        </div>
                        <span class="status-badge status-<?php echo $surgery['status']; ?>">
                            <?php echo $status_labels[$surgery['status']] ?? $surgery['status']; ?>
                        </span>
                    </div>

                    <!-- Прогресс-бары статуса -->
                    <div class="progress-steps">
                        <span class="step <?php echo in_array($surgery['status'], ['preparation', 'review', 'approved']) ? 'completed' : ($surgery['status'] == 'new' ? 'active' : ''); ?>">
                            📝 Новый
                        </span>
                        <span class="step <?php echo in_array($surgery['status'], ['review', 'approved']) ? 'completed' : ($surgery['status'] == 'preparation' ? 'active' : ''); ?>">
                            🔬 Подготовка
                        </span>
                        <span class="step <?php echo $surgery['status'] == 'approved' ? 'completed' : ($surgery['status'] == 'review' ? 'active' : ''); ?>">
                            ✅ Проверка
                        </span>
                        <span class="step <?php echo $surgery['status'] == 'approved' ? 'active' : ''; ?>">
                            🏥 Одобрен
                        </span>
                    </div>

                    <!-- Информация о врачах -->
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Районный офтальмолог</span>
                            <span class="info-value"><?php echo htmlspecialchars($surgery['doctor_name'] ?: 'Не назначен'); ?></span>
                            <?php if ($surgery['doctor_phone']): ?>
                                <small>тел: <?php echo htmlspecialchars($surgery['doctor_phone']); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Хирург-куратор</span>
                            <span class="info-value"><?php echo htmlspecialchars($surgery['surgeon_name'] ?: 'Не назначен'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Планируемая операция</span>
                            <span class="info-value"><?php echo htmlspecialchars($surgery['surgery_type'] ?: 'Не назначена'); ?></span>
                        </div>
                        <?php if ($surgery['surgery_date']): ?>
                        <div class="info-item">
                            <span class="info-label">Дата операции</span>
                            <span class="info-value"><?php echo date('d.m.Y', strtotime($surgery['surgery_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Анализы -->
                    <div class="tests-section">
                        <h3>📋 Анализы и обследования</h3>
                        <div class="tests-list">
                            <?php 
                            $tests = $tests_by_surgery[$surgery['id']] ?? [];
                            $total_tests = count($tests);
                            $completed_tests = count(array_filter($tests, function($t) { 
                                return in_array($t['status'], ['uploaded', 'approved']); 
                            }));
                            ?>
                            
                            <?php if (empty($tests)): ?>
                                <p style="color: #666; text-align: center; padding: 1rem;">
                                    Анализы еще не назначены
                                </p>
                            <?php else: ?>
                                <?php foreach ($tests as $test): ?>
                                <div class="test-item">
                                    <span class="test-name"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                    <span class="test-status <?php echo $test['status']; ?>">
                                        <?php echo $test_status_labels[$test['status']] ?? $test['status']; ?>
                                        <?php if ($test['uploaded_at']): ?>
                                            <br><small><?php echo date('d.m.Y', strtotime($test['uploaded_at'])); ?></small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Прогресс анализов -->
                                <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <span>Прогресс подготовки:</span>
                                        <span><?php echo $completed_tests; ?>/<?php echo $total_tests; ?></span>
                                    </div>
                                    <div class="progress-bar-large" style="height: 10px;">
                                        <div class="progress-fill-large" style="width: <?php echo ($completed_tests / $total_tests) * 100; ?>%;"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Заметки хирурга -->
                    <?php if ($surgery['notes']): ?>
                    <div style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                        <strong>📝 Заметки:</strong>
                        <p style="margin-top: 0.5rem; white-space: pre-wrap;"><?php echo nl2br(htmlspecialchars($surgery['notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО - Личный кабинет пациента</p>
    </footer>
</body>
</html>