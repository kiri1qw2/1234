<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

$patient_data = null;
$search_performed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['full_name'])) {
    $full_name = $_POST['full_name'];
    $search_performed = true;
    
    $stmt = $pdo->prepare("
        SELECT u.*, p.id as patient_id, s.id as surgery_id, 
            s.status, s.surgery_date, d.name as diagnosis,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_completed,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'approved') as tests_approved
        FROM users u
        JOIN patients p ON u.id = p.user_id
        LEFT JOIN surgeries s ON p.id = s.patient_id
        LEFT JOIN diseases d ON s.disease_id = d.id
        WHERE u.full_name LIKE ? AND u.role = 'patient'
        ORDER BY s.created_at DESC
    ");
    $stmt->execute(["%$full_name%"]);
    $patient_data = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка статуса подготовки - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .status-check {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .search-box {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .search-box input {
            flex: 1;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
        }
        
        .patient-status-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            position: relative;
            flex-wrap: wrap;
            gap: 0.5rem;
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
            transition: all 0.3s ease;
            min-width: 100px;
            text-align: center;
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
        
        .step.current {
            border-color: #ffc107;
            background: #ffc107;
            color: #333;
        }
        
        .tests-list {
            list-style: none;
            margin-top: 1rem;
        }
        
        .test-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .test-status {
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        .test-status.pending { background: #fff3cd; color: #856404; }
        .test-status.uploaded { background: #cce5ff; color: #004085; }
        .test-status.approved { background: #d4edda; color: #155724; }
        .test-status.rejected { background: #f8d7da; color: #721c24; }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .diagnosis-info {
            background: #e8f0fe;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        
        .mkb-code {
            display: inline-block;
            background: #708090 100%;
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-family: monospace;
            margin-right: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .progress-steps {
                flex-direction: column;
            }
            
            .progress-steps::before {
                display: none;
            }
            
            .step {
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
                <a href="index.php">Главная</a>
                <a href="login.php">Вход</a>
                <a href="register.php">Регистрация</a>
                <a href="check_status.php" class="active">Статус подготовки</a>
            </div>
        </nav>
    </header>

    <main class="container">
        <div class="status-check">
            <h2>Проверка статуса подготовки</h2>
            <p>Введите ваше ФИО для просмотра статуса</p>
            
            <form method="POST" action="" class="search-box">
                <input type="text" name="full_name" placeholder="Иванов Пётр Сергеевич" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                <button type="submit" class="btn-small">Найти</button>
            </form>

            <?php if ($search_performed): ?>
                <?php if (!empty($patient_data)): ?>
                    <?php foreach ($patient_data as $patient): ?>
                        <div class="patient-status-card">
                            <h3><?php echo htmlspecialchars($patient['full_name']); ?></h3>
                            
                            <?php if (!empty($patient['diagnosis'])): ?>
                            <div class="diagnosis-info">
                                <span class="mkb-code">МКБ-10: <?php echo htmlspecialchars($patient['diagnosis_code'] ?? 'H25.9'); ?></span>
                                <strong><?php echo htmlspecialchars($patient['diagnosis']); ?></strong>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($patient['surgery_id'])): ?>
                                <!-- Прогресс-бары статуса -->
                                <div class="progress-steps">
                                    <?php
                                    $status_order = ['new', 'preparation', 'review', 'approved'];
                                    $current_status = $patient['status'] ?? 'new';
                                    $current_index = array_search($current_status, $status_order);
                                    
                                    foreach ($status_order as $index => $status):
                                        $step_class = '';
                                        if ($index < $current_index) $step_class = 'completed';
                                        elseif ($index == $current_index) $step_class = 'active';
                                        
                                        $status_names = [
                                            'new' => '🆕 Новый',
                                            'preparation' => '📋 Подготовка',
                                            'review' => '🔍 Проверка',
                                            'approved' => '✅ Одобрен'
                                        ];
                                    ?>
                                        <span class="step <?php echo $step_class; ?>">
                                            <?php echo $status_names[$status]; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php if ($patient['surgery_date']): ?>
                                <p style="margin: 1rem 0; padding: 0.5rem; background: #d4edda; border-radius: 5px;">
                                    <strong>📅 Дата операции:</strong> <?php echo date('d.m.Y H:i', strtotime($patient['surgery_date'])); ?>
                                </p>
                                <?php endif; ?>

                                <!-- Анализы -->
                                <h4 style="margin-top: 2rem;">📊 Анализы <?php echo $patient['tests_completed']; ?>/<?php echo $patient['tests_total']; ?></h4>
                                
                                <?php
                                // Получаем список анализов
                                if (!empty($patient['surgery_id'])) {
                                    $stmt = $pdo->prepare("
                                        SELECT test_name, status 
                                        FROM tests 
                                        WHERE surgery_id = ?
                                        ORDER BY 
                                            CASE status 
                                                WHEN 'pending' THEN 1 
                                                WHEN 'uploaded' THEN 2 
                                                WHEN 'approved' THEN 3 
                                                WHEN 'rejected' THEN 4 
                                            END
                                    ");
                                    $stmt->execute([$patient['surgery_id']]);
                                    $tests = $stmt->fetchAll();
                                } else {
                                    $tests = [];
                                }
                                ?>
                                
                                <?php if (!empty($tests)): ?>
                                    <ul class="tests-list">
                                        <?php foreach ($tests as $test): ?>
                                        <li class="test-item">
                                            <span class="test-name"><?php echo htmlspecialchars($test['test_name']); ?></span>
                                            <span class="test-status <?php echo $test['status']; ?>">
                                                <?php 
                                                $statuses = [
                                                    'pending' => '⏳ Ожидает',
                                                    'uploaded' => '📤 Загружен',
                                                    'approved' => '✅ Принят',
                                                    'rejected' => '❌ Отклонен'
                                                ];
                                                echo $statuses[$test['status']] ?? $test['status'];
                                                ?>
                                            </span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p style="color: #999; text-align: center; padding: 1rem;">
                                        Анализы еще не назначены
                                    </p>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="empty-state">
                                    <p>У пациента пока нет назначенных операций</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>Пациент не найден</h3>
                        <p>Проверьте правильность введенного ФИО</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО - Проверка статуса подготовки</p>
    </footer>

    <script src="assets/js/script.js"></script>
</body>
</html>