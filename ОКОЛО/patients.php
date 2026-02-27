<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireLogin();

$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];

// Функция для определения цвета статуса
function getPatientStatusColor($status, $tests_completed, $tests_total, $has_surgeon_comment = false) {
    // Для хирурга - готов к операции (одобрен)
    if ($status === 'approved') {
        return 'green'; // Зеленый - готов к операции
    }
    // Для хирурга - на проверке
    elseif ($status === 'review') {
        return 'yellow'; // Желтый - в подготовке/на проверке
    }
    // Для хирурга - требуется консультация (есть комментарий или отклонен)
    elseif ($has_surgeon_comment || $status === 'rejected') {
        return 'red'; // Красный - требуется консультация
    }
    return 'gray';
}

// Получаем список пациентов
$patients = [];

if ($role === 'ophthalmologist') {
    // Для районного офтальмолога - все его пациенты
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            u.full_name,
            u.district,
            u.phone,
            u.email,
            p.birth_date,
            s.id as surgery_id,
            s.surgery_type,
            s.status,
            s.surgery_date,
            s.notes as surgeon_comment,
            d.name as diagnosis,
            d.code as diagnosis_code,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_uploaded,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'approved') as tests_approved,
            (SELECT COUNT(*) FROM media WHERE patient_id = p.id) as media_count,
            surg.full_name as surgeon_name
        FROM patients p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN surgeries s ON p.id = s.patient_id
        LEFT JOIN diseases d ON s.disease_id = d.id
        LEFT JOIN users surg ON p.surgeon_id = surg.id
        WHERE p.doctor_id = ?
        ORDER BY 
            CASE 
                WHEN s.status = 'preparation' THEN 1
                WHEN s.status = 'review' THEN 2
                WHEN s.status = 'new' THEN 3
                ELSE 4
            END,
            u.full_name ASC
    ");
    $stmt->execute([$user_id]);
    $patients = $stmt->fetchAll();
    
} elseif ($role === 'surgeon') {
    // Для хирурга - пациенты на проверке и одобренные
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            u.full_name,
            u.district,
            u.phone,
            u.email,
            p.birth_date,
            s.id as surgery_id,
            s.surgery_type,
            s.status,
            s.surgery_date,
            s.notes as surgeon_comment,
            d.name as diagnosis,
            d.code as diagnosis_code,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_uploaded,
            (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'approved') as tests_approved,
            doc.full_name as doctor_name,
            p.doctor_id,
            (SELECT COUNT(*) FROM media WHERE patient_id = p.id) as media_count
        FROM patients p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN surgeries s ON p.id = s.patient_id
        LEFT JOIN diseases d ON s.disease_id = d.id
        LEFT JOIN users doc ON p.doctor_id = doc.id
        WHERE s.status IN ('review', 'preparation') 
           OR (p.surgeon_id = ? AND s.status = 'approved')
        ORDER BY 
            CASE 
                WHEN s.status = 'review' THEN 1
                WHEN s.status = 'preparation' THEN 2
                ELSE 3
            END,
            s.created_at ASC
    ");
    $stmt->execute([$user_id]);
    $patients = $stmt->fetchAll();
}

// Статусы
$status_labels = [
    'new' => 'Новый',
    'preparation' => 'На подготовке',
    'review' => 'На проверке',
    'approved' => 'Одобрен',
    'rejected' => 'Отклонен'
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои пациенты - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .patients-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-header h1 {
            color: #708090 100%;
            font-size: 2rem;
        }
        
        .search-box {
            display: flex;
            gap: 0.5rem;
            background: white;
            padding: 0.3rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .search-box input {
            border: none;
            padding: 0.7rem 1rem;
            width: 300px;
            font-size: 0.95rem;
            border-radius: 8px;
        }
        
        .search-box input:focus {
            outline: none;
            box-shadow: 0 0 0 2px #708090 100%;
        }
        
        .search-box button {
            background: #708090 100%;
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .search-box button:hover {
            background: #2a5298;
        }
        
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            color: #708090 100%;
            font-weight: 600;
            border-bottom: 2px solid #708090 100%;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .patient-name {
            font-weight: 600;
            color: #708090 100%;
            cursor: pointer;
        }
        
        .patient-name:hover {
            text-decoration: underline;
        }
        
        .patient-contact {
            font-size: 0.8rem;
            color: #666;
        }
        
        .mkb-code {
            display: inline-block;
            background: #e8f0fe;
            color: #708090 100%;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.8rem;
            margin-right: 0.3rem;
        }
        
        .tests-progress {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .progress-bar-mini {
            width: 60px;
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill-mini {
            height: 100%;
            background: linear-gradient(90deg, #2a5298, #708090 100%);
            border-radius: 3px;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-align: center;
            display: inline-block;
        }
        
        .status-badge.red { 
            background: #dc3545; 
            color: white; 
            animation: pulse 2s infinite;
        }
        
        .status-badge.yellow { 
            background: #ffc107; 
            color: #333; 
        }
        
        .status-badge.green { 
            background: #28a745; 
            color: white; 
        }
        
        .status-badge.gray { 
            background: #6c757d; 
            color: white; 
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        
        .action-buttons {
            display: flex;
            gap: 0.3rem;
        }
        
        .btn-icon {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-view {
            background: #6c757d;
            color: white;
        }
        
        .btn-view:hover {
            background: #5a6268;
        }
        
        .btn-card {
            background: #708090 100%;
            color: white;
        }
        
        .btn-card:hover {
            background: #2a5298;
        }
        
        .btn-checklist {
            background: #28a745;
            color: white;
        }
        
        .btn-checklist:hover {
            background: #218838;
        }
        
        .btn-media {
            background: #17a2b8;
            color: white;
        }
        
        .btn-media:hover {
            background: #138496;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .stats-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .stat-pill {
            background: #e8f0fe;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .stat-pill span {
            font-weight: bold;
            color: #708090 100%;
            margin-right: 0.3rem;
        }
        
        .surgeon-comment {
            font-size: 0.8rem;
            color: #dc3545;
            margin-top: 0.3rem;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                width: 100%;
            }
            
            .search-box input {
                width: 100%;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            table {
                font-size: 0.9rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-icon {
                text-align: center;
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
                <a href="patients.php" class="active">Мои пациенты</a>
                <?php if ($role === 'surgeon'): ?>
                <a href="review.php">На проверку</a>
                <?php endif; ?>
                <a href="schedule.php">Расписание</a>
                <a href="profile.php">Профиль</a>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="role-badge">
                    <?php 
                    $roles = [
                        'ophthalmologist' => 'Районный офтальмолог',
                        'surgeon' => 'Хирург-куратор'
                    ];
                    echo $roles[$role] ?? $role;
                    ?>
                </span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container patients-container">
        <div class="page-header">
            <h1>Мои пациенты</h1>
            
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Поиск по имени, району или телефону...">
                <button onclick="searchPatients()">🔍 Найти</button>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stats-row">
            <div class="stat-pill">
                <span><?php echo count($patients); ?></span> всего пациентов
            </div>
            <div class="stat-pill">
                <span><?php 
                    $red = array_filter($patients, function($p) { 
                        return getPatientStatusColor($p['status'] ?? '', $p['tests_uploaded'] ?? 0, $p['tests_total'] ?? 1, !empty($p['surgeon_comment'])) === 'red'; 
                    });
                    echo count($red); 
                ?></span> требуют консультации
            </div>
            <div class="stat-pill">
                <span><?php 
                    $yellow = array_filter($patients, function($p) { 
                        return getPatientStatusColor($p['status'] ?? '', $p['tests_uploaded'] ?? 0, $p['tests_total'] ?? 1) === 'yellow'; 
                    });
                    echo count($yellow); 
                ?></span> в подготовке
            </div>
            <div class="stat-pill">
                <span><?php 
                    $green = array_filter($patients, function($p) { 
                        return getPatientStatusColor($p['status'] ?? '', $p['tests_uploaded'] ?? 0, $p['tests_total'] ?? 1) === 'green'; 
                    });
                    echo count($green); 
                ?></span> готовы к операции
            </div>
        </div>

        <!-- Фильтры по статусу -->
        <div class="filter-section">
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="filterPatients('all')">Все пациенты</button>
                <button class="filter-tab" onclick="filterPatients('red')">🔴 Требуют консультации</button>
                <button class="filter-tab" onclick="filterPatients('yellow')">🟡 В подготовке</button>
                <button class="filter-tab" onclick="filterPatients('green')">🟢 Готовы</button>
            </div>
        </div>

        <!-- Таблица пациентов -->
        <div class="patients-table">
            <?php if (empty($patients)): ?>
                <div class="empty-state">
                    <h3>Нет пациентов</h3>
                    <p>У вас пока нет назначенных пациентов</p>
                </div>
            <?php else: ?>
            <table id="patientsTable">
                <thead>
                    <tr>
                        <th>Пациент</th>
                        <th>Район</th>
                        <th>Диагноз (МКБ-10)</th>
                        <th>Операция</th>
                        <th>Анализы</th>
                        <th>Статус</th>
                        <th>Медиа</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patients as $patient): 
                        $progress = ($patient['tests_total'] ?? 0) > 0 ? 
                            round((($patient['tests_uploaded'] ?? 0) / $patient['tests_total']) * 100) : 0;
                        $status_color = getPatientStatusColor(
                            $patient['status'] ?? '', 
                            $patient['tests_uploaded'] ?? 0, 
                            $patient['tests_total'] ?? 1,
                            !empty($patient['surgeon_comment'])
                        );
                    ?>
                    <tr data-status="<?php echo $status_color; ?>">
                        <td>
                            <div class="patient-name" onclick="location.href='patient_card.php?id=<?php echo $patient['id']; ?>'">
                                <?php echo htmlspecialchars($patient['full_name'] ?? 'Неизвестно'); ?>
                            </div>
                            <div class="patient-contact">
                                <?php echo htmlspecialchars($patient['phone'] ?? ''); ?>
                                <?php if (!empty($patient['birth_date'])): ?><br>📅 <?php echo date('d.m.Y', strtotime($patient['birth_date'])); ?><?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($patient['district'] ?? 'Не указан'); ?></td>
                        <td>
                            <?php if (!empty($patient['diagnosis_code'])): ?>
                                <span class="mkb-code"><?php echo htmlspecialchars($patient['diagnosis_code']); ?></span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($patient['diagnosis'] ?? 'Диагноз не указан'); ?>
                            <?php if ($role === 'surgeon' && !empty($patient['doctor_name'])): ?>
                                <br><small>Врач: <?php echo htmlspecialchars($patient['doctor_name']); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($patient['surgeon_comment'])): ?>
                                <div class="surgeon-comment">💬 Есть комментарий</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($patient['surgery_type'] ?? 'Не назначена'); ?></td>
                        <td>
                            <div class="tests-progress">
                                <span><?php echo $patient['tests_uploaded'] ?? 0; ?>/<?php echo $patient['tests_total'] ?? 0; ?></span>
                                <div class="progress-bar-mini">
                                    <div class="progress-fill-mini" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $status_color; ?>">
                                <?php 
                                if ($status_color === 'red') echo '🔴 Требуется консультация';
                                elseif ($status_color === 'yellow') echo '🟡 Идет подготовка';
                                elseif ($status_color === 'green') echo '🟢 Готов к операции';
                                else echo $status_labels[$patient['status'] ?? 'new'] ?? 'Новый';
                                ?>
                            </span>
                            <?php if (!empty($patient['surgery_date'])): ?>
                                <br><small>📅 <?php echo date('d.m.Y', strtotime($patient['surgery_date'])); ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if (($patient['media_count'] ?? 0) > 0): ?>
                                <span class="status-badge" style="background: #17a2b8; color: white;">
                                    📸 <?php echo $patient['media_count']; ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #999;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="patient_card.php?id=<?php echo $patient['id']; ?>" class="btn-icon btn-card" title="Карточка пациента">📋</a>
                                <a href="checklist.php?patient_id=<?php echo $patient['id']; ?>" class="btn-icon btn-checklist" title="Чек-лист">✅</a>
                                <a href="patient_media.php?id=<?php echo $patient['id']; ?>" class="btn-icon btn-media" title="Медиатека">📸</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО - Панель управления врачом</p>
    </footer>

    <script>
        function searchPatients() {
            const searchText = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#patientsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchText) ? '' : 'none';
            });
        }
        
        function filterPatients(color) {
    const rows = document.querySelectorAll('#patientsTable tbody tr');
    const tabs = document.querySelectorAll('.filter-tab');
    
    tabs.forEach(tab => tab.classList.remove('active'));
    event.target.classList.add('active');
    
    let visibleCount = 0;
    let greenCount = 0;
    
    rows.forEach(row => {
        const statusCell = row.querySelector('.status-badge');
        if (!statusCell) return;
        
        const statusText = statusCell.textContent;
        const rowColor = row.dataset.status;
        let showRow = false;
        
        if (color === 'all') {
            showRow = true;
        } 
        else if (color === 'red' && rowColor === 'red') {
            showRow = true;
        } 
        else if (color === 'yellow' && rowColor === 'yellow') {
            showRow = true;
        } 
        else if (color === 'green' && rowColor === 'green') {
            showRow = true;
            greenCount++;
        }
        
        if (showRow) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    console.log(`Показано пациентов: ${visibleCount}, зеленых: ${greenCount}`);
}
        
        document.getElementById('searchInput').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') searchPatients();
        });
    </script>
</body>
</html>