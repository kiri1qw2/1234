<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireLogin();

$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];


// ============================================
// ДЛЯ ПАЦИЕНТА - УПРОЩЕННАЯ ВЕРСИЯ
// ============================================
if ($role === 'patient'): 
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
        $stmt = $pdo->prepare("INSERT INTO patients (user_id, district) VALUES (?, ?)");
        $stmt->execute([$user_id, $_SESSION['district'] ?? '']);
        $patient_id = $pdo->lastInsertId();
    } else {
        $patient_id = $patient['patient_id'];
    }
    
    // Получаем информацию об операции пациента
    $stmt = $pdo->prepare("
        SELECT s.*, d.name as diagnosis, d.code as diagnosis_code,
               u_surg.full_name as surgeon_name
        FROM surgeries s
        JOIN diseases d ON s.disease_id = d.id
        LEFT JOIN users u_surg ON s.surgeon_id = u_surg.id
        WHERE s.patient_id = ?
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $surgery = $stmt->fetch();
    
    // Получаем ближайшую операцию (если есть)
    $stmt = $pdo->prepare("
        SELECT s.*, d.name as diagnosis
        FROM surgeries s
        JOIN diseases d ON s.disease_id = d.id
        WHERE s.patient_id = ? AND s.surgery_date >= CURDATE() AND s.status = 'approved'
        ORDER BY s.surgery_date ASC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $next_surgery = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .patient-dashboard {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #708090, #4a5568);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .welcome-card h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .surgery-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            border-left: 5px solid #708090;
        }
        
        .surgery-card h2 {
            color: #708090;
            margin-bottom: 1.5rem;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 1rem;
            padding: 0.5rem;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .info-label {
            width: 150px;
            color: #666;
            font-weight: 500;
        }
        
        .info-value {
            flex: 1;
            color: #333;
            font-weight: 600;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .status-approved { background: #d4edda; color: #155724; }
        .status-preparation { background: #fff3cd; color: #856404; }
        .status-review { background: #cce5ff; color: #004085; }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 15px;
            color: #666;
        }
        
        .actions-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .action-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #708090;
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
                <a href="dashboard.php" class="active">Главная</a>
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

    <main class="container patient-dashboard">
        <div class="welcome-card">
            <h1>Здравствуйте, <?php echo htmlspecialchars($full_name); ?>!</h1>
            <p>Мы помогаем вам подготовиться к операции</p>
        </div>

        <?php if ($next_surgery): ?>
            <!-- Если есть назначенная операция -->
            <div class="surgery-card">
                <h2>📅 Ваша ближайшая операция</h2>
                <div class="info-row">
                    <span class="info-label">Дата:</span>
                    <span class="info-value"><?php echo date('d.m.Y H:i', strtotime($next_surgery['surgery_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Диагноз:</span>
                    <span class="info-value"><?php echo htmlspecialchars($next_surgery['diagnosis']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Операция:</span>
                    <span class="info-value"><?php 
                        $surgery_types = [
                            'phaco' => 'Факоэмульсификация',
                            'glaucoma' => 'Антиглаукоматозная операция',
                            'laser' => 'Лазерная коррекция',
                            'vitrectomy' => 'Витрэктомия'
                        ];
                        echo $surgery_types[$next_surgery['surgery_type']] ?? $next_surgery['surgery_type'];
                    ?></span>
                </div>
            </div>
        <?php elseif ($surgery && $surgery['status'] === 'approved'): ?>
            <!-- Если операция одобрена но дата не назначена -->
            <div class="surgery-card">
                <h2>✅ Операция одобрена</h2>
                <p>Хирург скоро назначит дату операции. Ожидайте.</p>
            </div>
        <?php elseif ($surgery): ?>
            <!-- Если операция в процессе подготовки -->
            <div class="surgery-card">
                <h2>📋 Подготовка к операции</h2>
                <div class="info-row">
                    <span class="info-label">Статус:</span>
                    <span class="info-value">
                        <span class="status-badge status-<?php echo $surgery['status']; ?>">
                            <?php 
                            $statuses = [
                                'new' => 'Новый',
                                'preparation' => 'Подготовка',
                                'review' => 'На проверке'
                            ];
                            echo $statuses[$surgery['status']] ?? $surgery['status'];
                            ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Диагноз:</span>
                    <span class="info-value"><?php echo htmlspecialchars($surgery['diagnosis']); ?></span>
                </div>
            </div>
        <?php else: ?>
            <!-- Если нет операций -->
            <div class="empty-state">
                <h3>У вас пока нет запланированных операций</h3>
                <p>Обратитесь к районному офтальмологу для консультации</p>
            </div>
        <?php endif; ?>

        <div class="actions-row">
            <div class="action-card" onclick="location.href='schedule.php'">
                <div style="font-size: 2rem;">📅</div>
                <div>Расписание</div>
            </div>
            <div class="action-card" onclick="location.href='profile.php'">
                <div style="font-size: 2rem;">👤</div>
                <div>Профиль</div>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО - Личный кабинет пациента</p>
    </footer>
</body>
</html>

<?php 
// ============================================
// ДЛЯ РАЙОННОГО ОФТАЛЬМОЛОГА
// ============================================
elseif ($role === 'ophthalmologist'): 
    // Получаем статистику офтальмолога
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.id) as total_patients,
            COUNT(DISTINCT CASE WHEN s.status = 'preparation' THEN s.id END) as in_preparation,
            COUNT(DISTINCT CASE WHEN s.status = 'approved' THEN s.id END) as approved,
            COUNT(DISTINCT CASE WHEN s.status = 'rejected' THEN s.id END) as rejected
        FROM patients p
        LEFT JOIN surgeries s ON p.id = s.patient_id
        WHERE p.doctor_id = ?
    ");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();
    
    // Если статистика пустая, ставим нули
    if (!$stats) {
        $stats = [
            'total_patients' => 0,
            'in_preparation' => 0,
            'approved' => 0,
            'rejected' => 0
        ];
    }
    
    // Получаем пациентов, требующих внимания
    $stmt = $pdo->prepare("
        SELECT 
            p.id, 
            u.full_name, 
            u.district, 
            s.surgery_type, 
            s.status, 
            d.name as diagnosis,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_completed,
            7 as tests_total
        FROM patients p
        JOIN users u ON p.user_id = u.id
        JOIN surgeries s ON p.id = s.patient_id
        JOIN diseases d ON s.disease_id = d.id
        WHERE p.doctor_id = ? AND s.status IN ('preparation', 'review')
        ORDER BY 
            CASE 
                WHEN (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') = 7 THEN 1
                ELSE 2
            END,
            s.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $patients = $stmt->fetchAll();
    
    // Получаем последние загруженные изображения
    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name as patient_name
        FROM media m
        JOIN patients p ON m.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE p.doctor_id = ?
        ORDER BY m.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_media = $stmt->fetchAll();
    
    // Получаем список пациентов для чек-листа
    $stmt = $pdo->prepare("
        SELECT p.id, u.full_name 
        FROM patients p
        JOIN users u ON p.user_id = u.id
        WHERE p.doctor_id = ? AND p.id IN (SELECT patient_id FROM surgeries WHERE status IN ('preparation', 'review'))
        ORDER BY u.full_name
    ");
    $stmt->execute([$user_id]);
    $patient_list = $stmt->fetchAll();
    
    // Массив русских названий операций
    $surgery_names = [
        'phaco' => 'Факоэмульсификация',
        'vitrectomy' => 'Витрэктомия',
        'glaucoma' => 'Антиглаукоматозная операция',
        'laser' => 'Лазерная коррекция',
        'cataract' => 'Катарактальная хирургия',
        'trabeculectomy' => 'Трабекулэктомия',
        'iridectomy' => 'Иридэктомия',
        'keratoplasty' => 'Кератопластика'
    ];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд офтальмолога - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Стили для чек-листа */
        .checklist-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin: 2rem 0;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .checklist-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .checklist-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .checklist-tab {
            padding: 0.5rem 1.5rem;
            border: none;
            background: #f0f4f8;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .checklist-tab:hover {
            background: #e0e7f0;
        }
        
        .checklist-tab.active {
            background: #708090;
            color: white;
        }
        
        .checklist-items {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .checklist-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            transition: all 0.3s ease;
        }
        
        .checklist-item:hover {
            background: #e8f0fe;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .item-title {
            font-weight: 600;
            color: #708090;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .item-status {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            background: #fff3cd;
            color: #856404;
        }
        
        .item-status.completed {
            background: #d4edda;
            color: #155724;
        }
        
        .item-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-upload {
            background: #28a745;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn-calc {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn-view {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.4rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        /* Калькулятор ИОЛ */
        .iol-calculator {
            background: linear-gradient(135deg, #708090, #4a5568);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            margin: 1rem 0;
        }
        
        .calc-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .calc-input {
            padding: 0.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
        }
        
        .calc-result {
            background: rgba(255,255,255,0.1);
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
        }
        
        .result-value {
            font-size: 2rem;
            font-weight: bold;
        }
        
        /* Галерея медиа */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        
        .media-item {
            background: #f8f9fa;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .media-item:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .media-preview {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }
        
        .media-info {
            padding: 0.5rem;
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Модальное окно */
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
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .compression-badge {
            background: #28a745;
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
            padding: 0.3rem 1rem;
            border-radius: 15px;
            font-size: 0.9rem;
        }

        .status-uploaded {
            background: #cce5ff;
            color: #004085;
            padding: 0.3rem 1rem;
            border-radius: 15px;
            font-size: 0.9rem;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
            padding: 0.3rem 1rem;
            border-radius: 15px;
            font-size: 0.9rem;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 0.3rem 1rem;
            border-radius: 15px;
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .checklist-tabs {
                flex-direction: column;
            }
            
            .item-actions {
                flex-direction: column;
            }
            
            .btn-upload, .btn-calc, .btn-view {
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
                <a href="dashboard.php" class="active">Дашборд</a>
                <a href="patients.php">Мои пациенты</a>
                <a href="schedule.php">Расписание</a>
                <a href="profile.php">Профиль</a>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="role-badge">Районный офтальмолог</span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container">
        <section class="welcome-section">
            <h1>Добро пожаловать, <?php echo htmlspecialchars($full_name); ?>!</h1>
            <p>Обзор ваших пациентов и текущих задач</p>
        </section>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Всего пациентов</h3>
                <div class="stat-number"><?php echo $stats['total_patients']; ?></div>
            </div>
            <div class="stat-card preparation">
                <h3>На подготовке</h3>
                <div class="stat-number"><?php echo $stats['in_preparation']; ?></div>
            </div>
            <div class="stat-card approved">
                <h3>Одобрены</h3>
                <div class="stat-number"><?php echo $stats['approved']; ?></div>
            </div>
            <div class="stat-card revision">
                <h3>Доработка</h3>
                <div class="stat-number"><?php echo $stats['rejected']; ?></div>
            </div>
        </div>

        <!-- ЦИФРОВОЙ ЧЕК-ЛИСТ ПОДГОТОВКИ -->
        <div class="checklist-section">
            <div class="checklist-header">
                <h2 style="color: #708090;">📋 Цифровой чек-лист подготовки</h2>
            </div>

            <!-- Выбор пациента -->
            <div style="margin-bottom: 1rem;">
                <select id="patientSelect" class="calc-input" style="width: 100%; max-width: 300px;" onchange="loadPatientChecklist()">
                    <option value="">Выберите пациента</option>
                    <?php foreach ($patient_list as $pat): ?>
                    <option value="<?php echo $pat['id']; ?>"><?php echo htmlspecialchars($pat['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Чек-лист для выбранного пациента -->
            <div id="checklistContainer">
                <div style="text-align: center; padding: 2rem; color: #666;">
                    Выберите пациента для просмотра чек-листа
                </div>
            </div>
        </div>

        <!-- КАЛЬКУЛЯТОР ИОЛ -->
        <div class="checklist-section">
            <h2 style="color: #708090; margin-bottom: 1rem;">🧮 Калькулятор ИОЛ</h2>
            <div class="iol-calculator">
                <form id="iolForm" onsubmit="calculateIOL(event)">
                    <div class="calc-form">
                        <input type="number" step="0.01" class="calc-input" id="k1" placeholder="K1 (D)" required>
                        <input type="number" step="0.01" class="calc-input" id="k2" placeholder="K2 (D)" required>
                        <input type="number" step="0.01" class="calc-input" id="acd" placeholder="ACD (mm)" required>
                        <input type="number" step="0.01" class="calc-input" id="axial" placeholder="Осевая длина (mm)" required>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; margin: 1rem 0; flex-wrap: wrap;">
                        <label><input type="radio" name="formula" value="srtk" checked> SRK/T</label>
                        <label><input type="radio" name="formula" value="haigis"> Haigis</label>
                        <label><input type="radio" name="formula" value="holladay"> Holladay</label>
                    </div>
                    
                    <button type="submit" class="btn-upload" style="padding: 0.8rem 2rem;">Рассчитать ИОЛ</button>
                    
                    <div id="iolResult" class="calc-result" style="display: none;">
                        <div>Результат расчета:</div>
                        <div class="result-value" id="iolPower">0.0 D</div>
                        <div style="font-size: 0.9rem; margin-top: 0.5rem;" id="formulaUsed"></div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Пациенты, требующие внимания -->
        <h2 class="section-title">Требуют внимания</h2>
        
        <div class="patients-grid">
            <?php if (empty($patients)): ?>
            <div class="empty-schedule" style="grid-column: 1/-1; text-align: center; padding: 3rem;">
                <p>Нет пациентов, требующих внимания</p>
            </div>
            <?php else: ?>
                <?php foreach ($patients as $patient): 
                    $progress = ($patient['tests_completed'] / 7) * 100;
                ?>
                <div class="patient-card">
                    <div class="patient-header">
                        <span class="patient-name"><?php echo htmlspecialchars($patient['full_name']); ?></span>
                        <span class="patient-district"><?php echo htmlspecialchars($patient['district']); ?></span>
                    </div>
                    <div class="patient-diagnosis"><?php echo htmlspecialchars($patient['diagnosis']); ?></div>
                    <div class="analysis-progress">
                        <div class="progress-label">
                            <span>Анализы: <?php echo $patient['tests_completed']; ?>/7</span>
                            <span><?php echo round($progress); ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                    </div>
                    <span class="surgery-type">
                        <?php echo $surgery_names[$patient['surgery_type']] ?? $patient['surgery_type']; ?>
                    </span>
                    <div style="margin-top: 1rem;">
                        <a href="patient_detail.php?id=<?php echo $patient['id']; ?>" class="btn-small">Подробнее</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Модальные окна -->
    <div id="checklistModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="checklistPatientName">Чек-лист подготовки</h2>
                <span class="close-modal" onclick="closeModal('checklistModal')">&times;</span>
            </div>
            <div id="checklistModalContent"></div>
        </div>
    </div>

    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Загрузка медицинских снимков</h2>
                <span class="close-modal" onclick="closeModal('uploadModal')">&times;</span>
            </div>
            <form id="uploadForm" enctype="multipart/form-data" onsubmit="uploadMedia(event)">
                <div class="form-group">
                    <label>Пациент:</label>
                    <select id="uploadPatientId" class="calc-input" required>
                        <option value="">Выберите пациента</option>
                        <?php foreach ($patient_list as $pat): ?>
                        <option value="<?php echo $pat['id']; ?>"><?php echo htmlspecialchars($pat['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Тип снимка:</label>
                    <select id="mediaType" class="calc-input" required>
                        <option value="slit_lamp">Щелевая лампа</option>
                        <option value="fundus">Глазное дно</option>
                        <option value="keratotopography">Кератотопограмма</option>
                        <option value="oct">ОКТ</option>
                        <option value="other">Другое</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Файлы (можно несколько):</label>
                    <input type="file" id="mediaFiles" multiple accept="image/*" required>
                    <small>Автоматическое сжатие для быстрой загрузки</small>
                </div>
                
                <div id="compressionStatus" style="display: none; background: #e8f0fe; padding: 1rem; border-radius: 5px; margin: 1rem 0;">
                    ⚡ Сжатие изображений...
                </div>
                
                <button type="submit" class="btn-upload">Загрузить</button>
            </form>
        </div>
    </div>

    <div id="viewMediaModal" class="modal">
        <div class="modal-content" style="max-width: 90%;">
            <div class="modal-header">
                <h2 id="viewMediaTitle">Просмотр снимка</h2>
                <span class="close-modal" onclick="closeModal('viewMediaModal')">&times;</span>
            </div>
            <div id="viewMediaContent" style="text-align: center;"></div>
        </div>
    </div>

    <footer>
        <p>&copy; 2026 ОКОЛО</p>
    </footer>

    <script>
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function calculateIOL(event) {
            event.preventDefault();
            
            const k1 = parseFloat(document.getElementById('k1').value);
            const k2 = parseFloat(document.getElementById('k2').value);
            const acd = parseFloat(document.getElementById('acd').value);
            const axial = parseFloat(document.getElementById('axial').value);
            const formula = document.querySelector('input[name="formula"]:checked').value;
            
            // Средняя кератометрия
            const km = (k1 + k2) / 2;
            
            // Упрощенный расчет
            let iolPower = (axial * 1.5 - km * 0.5 - acd * 0.3).toFixed(1);
            
            document.getElementById('iolPower').textContent = iolPower + ' D';
            document.getElementById('formulaUsed').textContent = `Формула: ${formula.toUpperCase()}`;
            document.getElementById('iolResult').style.display = 'block';
        }
        
        function uploadMedia(event) {
            event.preventDefault();
            alert('Функция загрузки медиа в разработке');
        }
        
        // Загрузка чек-листа
        function loadPatientChecklist() {
            const patientId = document.getElementById('patientSelect').value;
            if (!patientId) {
                document.getElementById('checklistContainer').innerHTML = 
                    '<div style="text-align: center; padding: 2rem; color: #666;">Выберите пациента</div>';
                return;
            }
            
            fetch('api/get_checklist.php?patient_id=' + patientId)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('checklistContainer').innerHTML = 
                            '<div style="color: red; padding: 2rem;">Ошибка: ' + data.error + '</div>';
                    } else {
                        displayChecklist(data, patientId);
                    }
                })
                .catch(error => {
                    document.getElementById('checklistContainer').innerHTML = 
                        '<div style="color: red; padding: 2rem;">Ошибка загрузки</div>';
                });
        }

        function displayChecklist(data, patientId) {
            let html = `<h3 style="margin-bottom: 1rem; color: #708090;">${data.patient_name}</h3>`;
            
            data.checklist.forEach(item => {
                let statusClass = '';
                let statusText = '';
                let buttonHtml = '';
                
                if (item.status === 'pending') {
                    statusClass = 'status-pending';
                    statusText = '⏳ Ожидает';
                    buttonHtml = `
                        <form method="POST" enctype="multipart/form-data" action="api/upload_file.php">
                            <input type="hidden" name="patient_id" value="${patientId}">
                            <input type="hidden" name="test_name" value="${item.name}">
                            <input type="file" name="test_file" id="file-${item.name.replace(/\s/g, '')}" style="display: none;" onchange="this.form.submit()">
                            <button type="button" class="btn-small" style="background: #28a745; color: white;" onclick="document.getElementById('file-${item.name.replace(/\s/g, '')}').click()">
                                📤 Загрузить
                            </button>
                        </form>
                    `;
                } else if (item.status === 'uploaded') {
                    statusClass = 'status-uploaded';
                    statusText = '📤 Загружен';
                    buttonHtml = `
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn-small" style="background: #17a2b8; color: white;" onclick="window.open('http://localhost/okulus-feldsher/${item.file_path}', '_blank')">
                                👁️ Просмотреть
                            </button>
                            <form method="POST" enctype="multipart/form-data" action="api/upload_file.php" style="display: inline;">
                                <input type="hidden" name="patient_id" value="${patientId}">
                                <input type="hidden" name="test_name" value="${item.name}">
                                <input type="file" name="test_file" id="reload-${item.name.replace(/\s/g, '')}" style="display: none;" onchange="this.form.submit()">
                                <button type="button" class="btn-small" style="background: #ffc107;" onclick="document.getElementById('reload-${item.name.replace(/\s/g, '')}').click()">
                                    📤 Перезагрузить
                                </button>
                            </form>
                        </div>
                    `;
                } else if (item.status === 'approved') {
                    statusClass = 'status-approved';
                    statusText = '✅ Принят';
                    if (item.file_path) {
                        buttonHtml = `
                            <button class="btn-small" style="background: #17a2b8; color: white;" onclick="window.open('http://localhost/okulus-feldsher/${item.file_path}', '_blank')">
                                👁️ Просмотреть
                            </button>
                        `;
                    }
                } else if (item.status === 'rejected') {
                    statusClass = 'status-rejected';
                    statusText = '❌ Отклонен';
                    if (item.file_path) {
                        buttonHtml = `
                            <button class="btn-small" style="background: #17a2b8; color: white;" onclick="window.open('http://localhost/okulus-feldsher/${item.file_path}', '_blank')">
                                👁️ Просмотреть
                            </button>
                        `;
                    }
                }
                
                html += `
                    <div style="background: #f8f9fa; padding: 1.5rem; margin: 1rem 0; border-radius: 8px; border-left: 4px solid #708090;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <div>
                                <strong>${item.name}</strong>
                                ${item.required ? '<span style="color: red; margin-left: 0.5rem;">*</span>' : ''}
                            </div>
                            <div class="${statusClass}">
                                ${statusText}
                            </div>
                        </div>
                        <div>
                            ${buttonHtml}
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('checklistContainer').innerHTML = html;
        }

        // Автоматически загружаем чек-лист при выборе пациента
        document.addEventListener('DOMContentLoaded', function() {
            const select = document.getElementById('patientSelect');
            if (select) {
                select.addEventListener('change', loadPatientChecklist);
            }
        });

        // Показываем сообщение об успешной загрузке
        if (window.location.search.includes('upload_success=1')) {
            alert('Файл успешно загружен!');
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    </script>
</body>
</html>

<?php 
// ============================================
// ДЛЯ ХИРУРГА-КУРАТОРА
// ============================================
elseif ($role === 'surgeon'): 
    // Получаем статистику хирурга
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.id) as total_patients,
            COUNT(DISTINCT s.id) as total_surgeries,
            COUNT(DISTINCT CASE WHEN s.status = 'review' THEN s.id END) as pending_review,
            COUNT(DISTINCT CASE WHEN s.status = 'approved' AND s.surgery_date >= CURDATE() THEN s.id END) as upcoming_surgeries
        FROM patients p
        LEFT JOIN surgeries s ON p.id = s.patient_id
        WHERE p.surgeon_id = ? OR (p.surgeon_id IS NULL AND s.status = 'review')
    ");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();
    
    // Получаем операции, требующие проверки
    $stmt = $pdo->prepare("
        SELECT p.id, u.full_name, u.district, s.surgery_type, s.status, d.name as diagnosis,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_completed,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total
        FROM patients p
        JOIN users u ON p.user_id = u.id
        JOIN surgeries s ON p.id = s.patient_id
        JOIN diseases d ON s.disease_id = d.id
        WHERE s.status = 'review' AND (p.surgeon_id = ? OR p.surgeon_id IS NULL)
        ORDER BY s.created_at ASC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $pending_surgeries = $stmt->fetchAll();
    
    // Массив русских названий операций
    $surgery_names = [
        'phaco' => 'Факоэмульсификация',
        'vitrectomy' => 'Витрэктомия',
        'glaucoma' => 'Антиглаукоматозная операция',
        'laser' => 'Лазерная коррекция',
        'cataract' => 'Катарактальная хирургия'
    ];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд хирурга - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <div class="logo">
            <img src="assets/img/logo.png" alt="ОКОЛО" width="70" height="55">
            ОКОЛО
        </div>
        <nav>
            <div class="nav-links">
                <a href="dashboard.php" class="active">Дашборд</a>
                <a href="review.php">На проверку</a>
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

    <main class="container">
        <section class="welcome-section">
            <h1>Добро пожаловать, <?php echo htmlspecialchars($full_name); ?>!</h1>
            <p>Панель управления хирурга-куратора</p>
        </section>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Всего пациентов</h3>
                <div class="stat-number"><?php echo $stats['total_patients'] ?? 0; ?></div>
            </div>
            <div class="stat-card review">
                <h3>На проверке</h3>
                <div class="stat-number"><?php echo $stats['pending_review'] ?? 0; ?></div>
            </div>
            <div class="stat-card approved">
                <h3>Предстоит операций</h3>
                <div class="stat-number"><?php echo $stats['upcoming_surgeries'] ?? 0; ?></div>
            </div>
        </div>

        <h2 class="section-title">Ожидают проверки</h2>
        
        <div class="patients-grid">
            <?php if (empty($pending_surgeries)): ?>
            <div class="empty-schedule" style="grid-column: 1/-1; text-align: center; padding: 3rem;">
                <p>Нет операций, ожидающих проверки</p>
            </div>
            <?php else: ?>
                <?php foreach ($pending_surgeries as $surgery): 
                    $progress = $surgery['tests_total'] > 0 ? 
                        round(($surgery['tests_completed'] / $surgery['tests_total']) * 100) : 0;
                ?>
                <div class="patient-card">
                    <div class="patient-header">
                        <span class="patient-name"><?php echo htmlspecialchars($surgery['full_name']); ?></span>
                        <span class="patient-district"><?php echo htmlspecialchars($surgery['district']); ?></span>
                    </div>
                    <div class="patient-diagnosis"><?php echo htmlspecialchars($surgery['diagnosis']); ?></div>
                    <div class="analysis-progress">
                        <div class="progress-label">
                            <span>Анализы: <?php echo $surgery['tests_completed']; ?>/<?php echo $surgery['tests_total']; ?></span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                    </div>
                    <span class="surgery-type">
                        <?php echo $surgery_names[$surgery['surgery_type']] ?? $surgery['surgery_type']; ?>
                    </span>
                    <div style="margin-top: 1rem;">
                        <a href="patient_detail.php?id=<?php echo $surgery['id']; ?>" class="btn-small">Проверить</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО</p>
    </footer>
</body>
</html>
<?php 
endif; 
?>