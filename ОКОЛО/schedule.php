<?php
require_once 'includes/auth.php';
requireLogin();

$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];

// Получаем текущий месяц и год
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Корректировка месяца
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Формируем даты начала и конца выбранного месяца
$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date = date('Y-m-t', strtotime($start_date));

// ПОЛУЧАЕМ ОПЕРАЦИИ В ЗАВИСИМОСТИ ОТ РОЛИ
$surgeries = [];

if ($role === 'patient') {
    // ДЛЯ ПАЦИЕНТА - ТОЛЬКО ЕГО ОПЕРАЦИИ
    $stmt = $pdo->prepare("
        SELECT 
            s.*, 
            d.name as diagnosis,
            d.code as diagnosis_code,
            u_surg.full_name as surgeon_name,
            u_doc.full_name as doctor_name
        FROM surgeries s
        JOIN patients p ON s.patient_id = p.id
        JOIN diseases d ON s.disease_id = d.id
        LEFT JOIN users u_surg ON s.surgeon_id = u_surg.id
        LEFT JOIN users u_doc ON p.doctor_id = u_doc.id
        WHERE p.user_id = ?
        ORDER BY 
            CASE 
                WHEN s.surgery_date IS NULL THEN 1 
                ELSE 0 
            END,
            s.surgery_date ASC
    ");
    $stmt->execute([$user_id]);
    $surgeries = $stmt->fetchAll();
    
    // СЧИТАЕМ СТАТИСТИКУ ТОЛЬКО ДЛЯ ПАЦИЕНТА
    $total = count($surgeries);
    $scheduled = 0;
    $pending = 0;
    $completed = 0;
    
    foreach ($surgeries as $s) {
        if ($s['surgery_date']) {
            $surgery_month = date('m', strtotime($s['surgery_date']));
            $surgery_year = date('Y', strtotime($s['surgery_date']));
            
            if ($surgery_month == $month && $surgery_year == $year) {
                $scheduled++;
                if ($s['surgery_date'] < date('Y-m-d')) {
                    $completed++;
                }
            }
        } else {
            $pending++;
        }
    }
    
} elseif ($role === 'ophthalmologist') {
    // ДЛЯ ОФТАЛЬМОЛОГА
    $stmt = $pdo->prepare("
        SELECT 
            s.*, 
            u.full_name as patient_name, 
            u.district, 
            d.name as diagnosis
        FROM surgeries s
        JOIN patients p ON s.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN diseases d ON s.disease_id = d.id
        WHERE p.doctor_id = ? 
        ORDER BY 
            CASE WHEN s.surgery_date IS NULL THEN 1 ELSE 0 END,
            s.surgery_date ASC
    ");
    $stmt->execute([$user_id]);
    $surgeries = $stmt->fetchAll();
    
    // СЧИТАЕМ СТАТИСТИКУ ДЛЯ ОФТАЛЬМОЛОГА
    $total = count($surgeries);
    $scheduled = 0;
    $pending = 0;
    $completed = 0;
    
    foreach ($surgeries as $s) {
        if ($s['surgery_date']) {
            $surgery_month = date('m', strtotime($s['surgery_date']));
            $surgery_year = date('Y', strtotime($s['surgery_date']));
            
            if ($surgery_month == $month && $surgery_year == $year) {
                $scheduled++;
                if ($s['surgery_date'] < date('Y-m-d')) {
                    $completed++;
                }
            }
        } else {
            $pending++;
        }
    }
    
} elseif ($role === 'surgeon') {
    // ДЛЯ ХИРУРГА
    $stmt = $pdo->prepare("
        SELECT 
            s.*, 
            u.full_name as patient_name, 
            u.district, 
            d.name as diagnosis
        FROM surgeries s
        JOIN patients p ON s.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN diseases d ON s.disease_id = d.id
        WHERE p.surgeon_id = ?
        ORDER BY 
            CASE WHEN s.surgery_date IS NULL THEN 1 ELSE 0 END,
            s.surgery_date ASC
    ");
    $stmt->execute([$user_id]);
    $surgeries = $stmt->fetchAll();
    
    // СЧИТАЕМ СТАТИСТИКУ ДЛЯ ХИРУРГА
    $total = count($surgeries);
    $scheduled = 0;
    $pending = 0;
    $completed = 0;
    
    foreach ($surgeries as $s) {
        if ($s['surgery_date']) {
            $surgery_month = date('m', strtotime($s['surgery_date']));
            $surgery_year = date('Y', strtotime($s['surgery_date']));
            
            if ($surgery_month == $month && $surgery_year == $year) {
                $scheduled++;
                if ($s['surgery_date'] < date('Y-m-d')) {
                    $completed++;
                }
            }
        } else {
            $pending++;
        }
    }
}

// Названия месяцев
$months = [
    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
    5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
    9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Расписание - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .schedule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .month-navigation {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: white;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .month-nav-btn {
            background: #f0f4f8;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.2rem;
            color: #708090;
        }
        
        .month-nav-btn:hover {
            background: #708090;
            color: white;
        }
        
        .current-month {
            font-size: 1.5rem;
            font-weight: bold;
            color: #708090;
            min-width: 200px;
            text-align: center;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #708090;
        }
        
        .surgeries-list {
            margin-top: 2rem;
        }
        
        .surgery-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border-left: 4px solid #708090;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .surgery-date {
            display: inline-block;
            background: #708090;
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-right: 1rem;
        }
        
        .patient-name {
            font-size: 1.2rem;
            font-weight: bold;
            color: #708090;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 15px;
            color: #666;
        }
        
        .nav-links a {
            display: inline-block;
        }
        
        .role-badge {
            background: #e8f0fe;
            color: #708090;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        .section-title {
            color: #708090;
            margin: 2rem 0 1rem;
            font-size: 1.5rem;
            border-left: 5px solid #708090;
            padding-left: 1rem;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
                <?php if ($role === 'patient'): ?>
                    <a href="dashboard.php">Дашборд</a>
                    <a href="schedule.php" class="active">Расписание</a>
                    <a href="profile.php">Профиль</a>
                <?php elseif ($role === 'ophthalmologist'): ?>
                    <a href="dashboard.php">Дашборд</a>
                    <a href="patients.php">Мои пациенты</a>
                    <a href="schedule.php" class="active">Расписание</a>
                    <a href="profile.php">Профиль</a>
                <?php elseif ($role === 'surgeon'): ?>
                    <a href="dashboard.php">Дашборд</a>
                    <a href="patients.php">Мои пациенты</a>
                    <a href="review.php">На проверку</a>
                    <a href="schedule.php" class="active">Расписание</a>
                    <a href="profile.php">Профиль</a>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="role-badge">
                    <?php 
                    $role_names = [
                        'patient' => 'Пациент',
                        'ophthalmologist' => 'Офтальмолог',
                        'surgeon' => 'Хирург'
                    ];
                    echo $role_names[$role] ?? $role;
                    ?>
                </span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container">
        <div class="schedule-header">
            <h1 class="section-title">Расписание операций</h1>
            
            <div class="month-navigation">
                <button class="month-nav-btn" onclick="changeMonth(-1)">←</button>
                <span class="current-month"><?php echo $months[$month] . ' ' . $year; ?></span>
                <button class="month-nav-btn" onclick="changeMonth(1)">→</button>
            </div>
        </div>

        <!-- СТАТИСТИКА ДЛЯ ПАЦИЕНТА (ТОЛЬКО ЕГО ОПЕРАЦИИ) -->
        <?php if ($role === 'patient'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total; ?></div>
                <div>Мои операции</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $scheduled; ?></div>
                <div>Запланировано</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $pending; ?></div>
                <div>Ожидают даты</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $completed; ?></div>
                <div>Выполнено</div>
            </div>
        </div>
        <?php else: ?>
        <!-- СТАТИСТИКА ДЛЯ ВРАЧЕЙ -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total; ?></div>
                <div>Всего операций</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $scheduled; ?></div>
                <div>Запланировано</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $pending; ?></div>
                <div>Ожидают даты</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $completed; ?></div>
                <div>Выполнено</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- СПИСОК ОПЕРАЦИЙ -->
        <div class="surgeries-list">
            <h2 style="margin-bottom: 1rem; color: #708090;">Операции в <?php echo $months[$month] . ' ' . $year; ?></h2>
            
            <?php 
            $has_month_surgeries = false;
            
            if ($role === 'patient') {
                // ДЛЯ ПАЦИЕНТА - ПОКАЗЫВАЕМ ТОЛЬКО ЕГО ОПЕРАЦИИ
                foreach ($surgeries as $surgery): 
                    if ($surgery['surgery_date']):
                        $surgery_month = date('m', strtotime($surgery['surgery_date']));
                        $surgery_year = date('Y', strtotime($surgery['surgery_date']));
                        
                        if ($surgery_month == $month && $surgery_year == $year):
                            $has_month_surgeries = true;
            ?>
                            <div class="surgery-card">
                                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                                    <span class="surgery-date">
                                        <?php echo date('d.m.Y', strtotime($surgery['surgery_date'])); ?>
                                    </span>
                                    <div style="flex: 1;">
                                        <span class="patient-name">Моя операция</span>
                                        <div style="color: #666;"><?php echo htmlspecialchars($surgery['diagnosis']); ?></div>
                                        <?php if ($surgery['surgeon_name']): ?>
                                            <small>Хирург: <?php echo htmlspecialchars($surgery['surgeon_name']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <span class="status-badge status-<?php echo $surgery['status']; ?>">
                                        <?php 
                                        $statuses = [
                                            'new' => 'Новый',
                                            'preparation' => 'Подготовка',
                                            'review' => 'Проверка',
                                            'approved' => 'Одобрен'
                                        ];
                                        echo $statuses[$surgery['status']] ?? $surgery['status'];
                                        ?>
                                    </span>
                                </div>
                            </div>
                        <?php 
                        endif;
                    endif;
                endforeach;
            } else {
                // ДЛЯ ВРАЧЕЙ - ПОКАЗЫВАЕМ ВСЕ ОПЕРАЦИИ
                foreach ($surgeries as $surgery): 
                    if ($surgery['surgery_date']):
                        $surgery_month = date('m', strtotime($surgery['surgery_date']));
                        $surgery_year = date('Y', strtotime($surgery['surgery_date']));
                        
                        if ($surgery_month == $month && $surgery_year == $year):
                            $has_month_surgeries = true;
            ?>
                            <div class="surgery-card">
                                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                                    <span class="surgery-date">
                                        <?php echo date('d.m.Y', strtotime($surgery['surgery_date'])); ?>
                                    </span>
                                    <div style="flex: 1;">
                                        <span class="patient-name"><?php echo htmlspecialchars($surgery['patient_name']); ?></span>
                                        <span style="color: #666;">(<?php echo htmlspecialchars($surgery['district']); ?>)</span>
                                        <div style="color: #666;"><?php echo htmlspecialchars($surgery['diagnosis']); ?></div>
                                    </div>
                                    <a href="patient_detail.php?id=<?php echo $surgery['patient_id']; ?>" class="btn-small">Подробнее</a>
                                </div>
                            </div>
                        <?php 
                        endif;
                    endif;
                endforeach;
            }
            
            if (!$has_month_surgeries): 
            ?>
                <div class="empty-state">
                    <h3>Нет операций на <?php echo $months[$month] . ' ' . $year; ?></h3>
                    <p>В этом месяце нет запланированных операций</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО</p>
    </footer>

    <script>
        function changeMonth(delta) {
            let month = <?php echo $month; ?>;
            let year = <?php echo $year; ?>;
            
            month += delta;
            
            if (month < 1) {
                month = 12;
                year--;
            } else if (month > 12) {
                month = 1;
                year++;
            }
            
            window.location.href = 'schedule.php?month=' + month + '&year=' + year;
        }
    </script>
</body>
</html>