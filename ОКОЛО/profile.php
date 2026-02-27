<?php
require_once 'includes/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];
$success = false;
$error = '';

// Получаем информацию о пользователе
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Получаем дополнительные данные в зависимости от роли
if ($role === 'patient') {
    // Получаем данные пациента
    $stmt = $pdo->prepare("
        SELECT p.*, d.name as diagnosis_name, d.code as diagnosis_code, 
               d.description as diagnosis_description,
               s.surgery_type, s.status as surgery_status, s.surgery_date
        FROM patients p
        LEFT JOIN surgeries s ON p.id = s.patient_id
        LEFT JOIN diseases d ON s.disease_id = d.id
        WHERE p.user_id = ?
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $patient_data = $stmt->fetch();
    
    // Получаем историю операций
    
    $stmt = $pdo->prepare("
    SELECT s.*, d.name as diagnosis_name, d.code as diagnosis_code
    FROM surgeries s
    LEFT JOIN diseases d ON s.disease_id = d.id
    WHERE s.patient_id = (SELECT id FROM patients WHERE user_id = ?)
    ORDER BY 
        CASE 
            WHEN s.surgery_date IS NULL THEN 1 ELSE 0 
        END,
        s.surgery_date DESC
");
    $stmt->execute([$user_id]);
    $surgeries_history = $stmt->fetchAll();
    
} elseif ($role === 'ophthalmologist') {
    // Получаем данные офтальмолога
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.id) as total_patients,
            COUNT(DISTINCT s.id) as total_surgeries,
            COUNT(DISTINCT CASE WHEN s.status = 'approved' THEN s.id END) as approved_surgeries,
            COUNT(DISTINCT CASE WHEN s.status = 'preparation' THEN s.id END) as in_preparation
        FROM patients p
        LEFT JOIN surgeries s ON p.id = s.patient_id
        WHERE p.doctor_id = ?
    ");
    $stmt->execute([$user_id]);
    $doctor_stats = $stmt->fetch();
    
} elseif ($role === 'surgeon') {
    // Получаем данные хирурга
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.id) as total_patients,
            COUNT(DISTINCT s.id) as total_surgeries,
            COUNT(DISTINCT CASE WHEN s.status = 'approved' AND s.surgery_date >= CURDATE() THEN s.id END) as upcoming_surgeries,
            COUNT(DISTINCT CASE WHEN s.status = 'review' THEN s.id END) as pending_review
        FROM patients p
        LEFT JOIN surgeries s ON p.id = s.patient_id
        WHERE p.surgeon_id = ?
    ");
    $stmt->execute([$user_id]);
    $surgeon_stats = $stmt->fetch();
}

// Обработка обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $email = $_POST['email'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        
        // Проверка уникальности email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $error = 'Этот email уже используется';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET email = ?, full_name = ?, phone = ? WHERE id = ?");
            $stmt->execute([$email, $full_name, $phone, $user_id]);
            $_SESSION['full_name'] = $full_name;
            $success = true;
            
            // Обновляем данные пользователя
            $user['email'] = $email;
            $user['full_name'] = $full_name;
            $user['phone'] = $phone;
        }
    }
    
    if (isset($_POST['update_patient_data']) && $role === 'patient') {
        // Обновление данных пациента
        $passport_series = $_POST['passport_series'] ?? '';
        $passport_number = $_POST['passport_number'] ?? '';
        $passport_issued = $_POST['passport_issued'] ?? '';
        $passport_date = $_POST['passport_date'] ?? '';
        $snils = $_POST['snils'] ?? '';
        $polis = $_POST['polis'] ?? '';
        $birth_date = $_POST['birth_date'] ?? '';
        $address = $_POST['address'] ?? '';
        $emergency_contact = $_POST['emergency_contact'] ?? '';
        $blood_type = $_POST['blood_type'] ?? '';
        $allergies = $_POST['allergies'] ?? '';
        
        // Обновляем или создаем запись в patients
        $stmt = $pdo->prepare("
            UPDATE patients SET 
                passport_series = ?, passport_number = ?, passport_issued = ?,
                passport_date = ?, snils = ?, polis = ?, birth_date = ?,
                address = ?, emergency_contact = ?, blood_type = ?, allergies = ?
            WHERE user_id = ?
        ");
        $stmt->execute([
            $passport_series, $passport_number, $passport_issued,
            $passport_date, $snils, $polis, $birth_date,
            $address, $emergency_contact, $blood_type, $allergies,
            $user_id
        ]);
        $success = true;
        
        // Обновляем локальные данные
        $patient_data['passport_series'] = $passport_series;
        $patient_data['passport_number'] = $passport_number;
        $patient_data['passport_issued'] = $passport_issued;
        $patient_data['passport_date'] = $passport_date;
        $patient_data['snils'] = $snils;
        $patient_data['polis'] = $polis;
        $patient_data['birth_date'] = $birth_date;
        $patient_data['address'] = $address;
        $patient_data['emergency_contact'] = $emergency_contact;
        $patient_data['blood_type'] = $blood_type;
        $patient_data['allergies'] = $allergies;
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                if (strlen($new_password) >= 6) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $user_id]);
                    $success = true;
                } else {
                    $error = 'Пароль должен содержать минимум 6 символов';
                }
            } else {
                $error = 'Новый пароль и подтверждение не совпадают';
            }
        } else {
            $error = 'Текущий пароль неверен';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid #f0f4f8;
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #708090 100%, #2a5298);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .profile-title {
            flex: 1;
        }
        
        .profile-name {
            font-size: 2rem;
            color: #708090 100%;
            margin-bottom: 0.5rem;
        }
        
        .profile-badges {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .role-badge {
            padding: 0.3rem 1rem;
            background: #e8f0fe;
            color: #708090 100%;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-badge {
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 0.5rem 1.5rem;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s ease;
            color: #666;
            font-weight: 500;
        }
        
        .tab:hover {
            background: #f0f4f8;
        }
        
        .tab.active {
            background: #708090 100%;
            color: white;
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease-out;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Медицинская карта */
        .medical-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            border-left: 5px solid #708090 100%;
        }
        
        .medical-card h3 {
            color: #708090 100%;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .info-group {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .info-group h4 {
            color: #708090 100%;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 0.5rem;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 1rem;
            padding: 0.5rem;
            border-bottom: 1px dashed #e0e0e0;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            width: 140px;
            color: #666;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .info-value {
            flex: 1;
            color: #333;
            font-weight: 500;
        }
        
        .info-value.highlight {
            color: #708090 100%;
            font-weight: 600;
        }
        
        /* Интеграция с ЕМИАС */
        .emias-integration {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .emias-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .emias-status .dot {
            width: 10px;
            height: 10px;
            background: #4ade80;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        .emias-badge {
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        
        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #708090 100%;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Таблицы */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
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
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        /* МКБ-10 код */
        .mkb-code {
            display: inline-block;
            background: #e8f0fe;
            color: #708090 100%;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-family: monospace;
            font-weight: bold;
            margin-right: 0.5rem;
        }
        
        /* Формы */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            color: #555;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.7rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color #708090 100%;
            box-shadow: 0 0 0 3px rgba(30,60,114,0.1);
        }
        
        .form-group input[readonly] {
            background: #f8f9fa;
            cursor: not-allowed;
        }
        
        .btn {
            background: linear-gradient(135deg, #708090 100%, #2a5298);
            color: white;
            border: none;
            padding: 0.8rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-success {
            background: #28a745;
        }
        
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
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                text-align: center;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: auto;
                margin-bottom: 0.3rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
                <?php if ($role !== 'patient'): ?>
                <a href="patients.php">Мои пациенты</a>
                <a href="schedule.php">Расписание</a>
                <?php endif; ?>
                <?php if ($role === 'surgeon'): ?>
                <a href="review.php">На проверку</a>
                <?php endif; ?>
                <a href="profile.php" class="active">Профиль</a>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container profile-container">
        <?php if ($success): ?>
        <div class="alert alert-success">
            <span>Данные успешно сохранены!</span>
            <span onclick="this.parentElement.remove()" style="cursor: pointer; float: right;">&times;</span>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-error">
            <span><?php echo $error; ?></span>
            <span onclick="this.parentElement.remove()" style="cursor: pointer; float: right;">&times;</span>
        </div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo mb_substr($user['full_name'], 0, 1); ?>
                </div>
                <div class="profile-title">
                    <h1 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h1>
                    <div class="profile-badges">
                        <span class="role-badge">
                            <?php 
                            $roles = [
                                'patient' => 'Пациент',
                                'ophthalmologist' => 'Районный офтальмолог',
                                'surgeon' => 'Хирург-куратор'
                            ];
                            echo $roles[$role] ?? $role;
                            ?>
                        </span>
                        <span class="status-badge active">
                            <i class="fas fa-circle" style="color: #28a745; font-size: 0.5rem;"></i> Активен
                        </span>
                    </div>
                </div>
            </div>

            <!-- Навигация по вкладкам в зависимости от роли -->
            <div class="tabs">
                <div class="tab active" onclick="showTab('main')">Основное</div>
                <?php if ($role === 'patient'): ?>
                <div class="tab" onclick="showTab('medical')">Медицинская карта</div>
                <div class="tab" onclick="showTab('documents')">Документы</div>
                <div class="tab" onclick="showTab('history')">История операций</div>
                <?php elseif ($role === 'ophthalmologist'): ?>
                <div class="tab" onclick="showTab('work')">Рабочая информация</div>
                <div class="tab" onclick="showTab('patients')">Мои пациенты</div>
                <div class="tab" onclick="showTab('stats')">Статистика</div>
                <?php elseif ($role === 'surgeon'): ?>
                <div class="tab" onclick="showTab('work')">Хирургическая практика</div>
                <div class="tab" onclick="showTab('schedule')">График операций</div>
                <div class="tab" onclick="showTab('stats')">Статистика</div>
                <?php endif; ?>
                <div class="tab" onclick="showTab('settings')">Настройки</div>
            </div>

            <!-- ========== ВКЛАДКА: Основное (для всех) ========== -->
            <div id="tab-main" class="tab-content active">
                <h2 style="margin-bottom: 1.5rem; color: #708090 100%;">Личная информация</h2>
                
                <!-- Интеграция с ЕМИАС (заглушка) -->
                <div class="emias-integration">
                    <div class="emias-status">
                        <span class="dot"></span>
                        <span>ЕМИАС: Данные синхронизированы</span>
                    </div>
                    <span class="emias-badge">ID: EM-<?php echo str_pad($user_id, 8, '0', STR_PAD_LEFT); ?></span>
                </div>
                
                <div class="info-grid">
                    <div class="info-group">
                        <h4>Контактные данные</h4>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['email'] ?: 'Не указан'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Телефон:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? '+7 (***) ***-**-**'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Район:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['district'] ?: 'Не указан'); ?></span>
                        </div>
                    </div>
                    
                    <div class="info-group">
                        <h4>Системная информация</h4>
                        <div class="info-row">
                            <span class="info-label">Логин:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['username']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Роль:</span>
                            <span class="info-value"><?php echo $roles[$role]; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Регистрация:</span>
                            <span class="info-value"><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($role === 'patient'): ?>
            <!-- ========== ВКЛАДКА: Медицинская карта (для пациента) ========== -->
            <div id="tab-medical" class="tab-content">
                <div class="medical-card">
                    <h3>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 12h-4l-3 9-4-18-3 9H2"/>
                        </svg>
                        Медицинская карта пациента
                    </h3>
                    
                    <!-- Диагноз с МКБ-10 -->
                    <?php if ($patient_data && $patient_data['diagnosis_name']): ?>
                    <div style="background: #e8f0fe; padding: 1.5rem; border-radius: 10px; margin-bottom: 2rem;">
                        <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                            <span class="mkb-code">МКБ-10: <?php echo htmlspecialchars($patient_data['diagnosis_code'] ?: 'H25.9'); ?></span>
                            <h4 style="color: #708090 100%; margin: 0;"><?php echo htmlspecialchars($patient_data['diagnosis_name']); ?></h4>
                        </div>
                        <p style="margin-top: 1rem; color: #666;">
                            <?php echo htmlspecialchars($patient_data['diagnosis_description'] ?: 'Катаракта - помутнение хрусталика глаза'); ?>
                        </p>
                        <div style="margin-top: 1rem; display: flex; gap: 2rem;">
                            <div><strong>Планируемая операция:</strong> <?php echo htmlspecialchars($patient_data['surgery_type'] ?: 'Не назначена'); ?></div>
                            <div><strong>Статус:</strong> 
                                <span class="status-badge status-<?php echo $patient_data['surgery_status']; ?>">
                                    <?php 
                                    $statuses = [
                                        'new' => 'Новый',
                                        'preparation' => 'Подготовка',
                                        'review' => 'Проверка',
                                        'approved' => 'Одобрен',
                                        'rejected' => 'Отклонен'
                                    ];
                                    echo $statuses[$patient_data['surgery_status']] ?? 'Не назначен';
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="info-grid">
                        <div class="info-group">
                            <h4>Персональные данные</h4>
                            <div class="info-row">
                                <span class="info-label">Дата рождения:</span>
                                <span class="info-value"><?php echo !empty($patient_data['birth_date']) ? date('d.m.Y', strtotime($patient_data['birth_date'])) : 'Не указана'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Пол:</span>
                                <span class="info-value"><?php echo $patient_data['gender'] ?? 'Не указан'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Адрес:</span>
                                <span class="info-value"><?php echo htmlspecialchars($patient_data['address'] ?: 'Не указан'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Контакт для экстренных случаев:</span>
                                <span class="info-value"><?php echo htmlspecialchars($patient_data['emergency_contact'] ?: 'Не указан'); ?></span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <h4>Медицинские данные</h4>
                            <div class="info-row">
                                <span class="info-label">Группа крови:</span>
                                <span class="info-value"><?php echo $patient_data['blood_type'] ?? 'Не указана'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Аллергии:</span>
                                <span class="info-value"><?php echo htmlspecialchars($patient_data['allergies'] ?: 'Нет'); ?></span>
                            </div>
                            <div class="info-row">
                                
                                <span class="info-value"><?php echo $patient_data['chronic_diseases'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Форма редактирования медицинских данных -->
                <form method="POST" class="profile-card" style="margin-top: 1rem;">
                    <h3 style="margin-bottom: 1.5rem; color: #708090 100%;">Редактировать медицинские данные</h3>
                    <input type="hidden" name="update_patient_data" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Дата рождения</label>
                            <input type="date" name="birth_date" value="<?php echo $patient_data['birth_date'] ?? ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>Пол</label>
                            <select name="gender">
                                <option value="">Не указан</option>
                                <option value="Мужской" <?php echo ($patient_data['gender'] ?? '') == 'Мужской' ? 'selected' : ''; ?>>Мужской</option>
                                <option value="Женский" <?php echo ($patient_data['gender'] ?? '') == 'Женский' ? 'selected' : ''; ?>>Женский</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Адрес проживания</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($patient_data['address'] ?? ''); ?>" placeholder="Город, улица, дом, квартира">
                    </div>
                    
                    <div class="form-group">
                        <label>Контакт для экстренных случаев</label>
                        <input type="text" name="emergency_contact" value="<?php echo htmlspecialchars($patient_data['emergency_contact'] ?? ''); ?>" placeholder="ФИО, телефон">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Группа крови</label>
                            <select name="blood_type">
                                <option value="">Не указана</option>
                                <option value="0(I)" <?php echo ($patient_data['blood_type'] ?? '') == '0(I)' ? 'selected' : ''; ?>>0(I)</option>
                                <option value="A(II)" <?php echo ($patient_data['blood_type'] ?? '') == 'A(II)' ? 'selected' : ''; ?>>A(II)</option>
                                <option value="B(III)" <?php echo ($patient_data['blood_type'] ?? '') == 'B(III)' ? 'selected' : ''; ?>>B(III)</option>
                                <option value="AB(IV)" <?php echo ($patient_data['blood_type'] ?? '') == 'AB(IV)' ? 'selected' : ''; ?>>AB(IV)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Аллергии</label>
                            <input type="text" name="allergies" value="<?php echo htmlspecialchars($patient_data['allergies'] ?? ''); ?>" placeholder="Через запятую">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">Сохранить медицинские данные</button>
                </form>
            </div>

            <!-- ========== ВКЛАДКА: Документы (паспорт, СНИЛС, полис) ========== -->
            <div id="tab-documents" class="tab-content">
                <div class="medical-card">
                    <h3>Документы</h3>
                    
                    <!-- Интеграция с ЕМИАС (заглушка) -->
                    <div class="emias-integration" style="background: linear-gradient(135deg,  #708090 100%, #2a5298 0%);">
                        <div class="emias-status">
                            <span class="dot"></span>
                            <span>ЕМИАС: Данные верифицированы</span>
                        </div>
                        <span class="emias-badge">Полис ОМС: подтвержден</span>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="update_patient_data" value="1">
                        
                        <div class="info-group" style="margin-bottom: 2rem;">
                            <h4>Паспортные данные</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Серия паспорта</label>
                                    <input type="text" name="passport_series" value="<?php echo htmlspecialchars($patient_data['passport_series'] ?? ''); ?>" placeholder="0000" maxlength="4">
                                </div>
                                <div class="form-group">
                                    <label>Номер паспорта</label>
                                    <input type="text" name="passport_number" value="<?php echo htmlspecialchars($patient_data['passport_number'] ?? ''); ?>" placeholder="000000" maxlength="6">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Кем выдан</label>
                                <input type="text" name="passport_issued" value="<?php echo htmlspecialchars($patient_data['passport_issued'] ?? ''); ?>" placeholder="Наименование отделения">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Дата выдачи</label>
                                    <input type="date" name="passport_date" value="<?php echo $patient_data['passport_date'] ?? ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Код подразделения</label>
                                    <input type="text" value="000-000" readonly class="readonly" placeholder="Заглушка">
                                </div>
                            </div>
                        </div>
                        
                        <div class="info-group" style="margin-bottom: 2rem;">
                            <h4>СНИЛС и полис</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>СНИЛС</label>
                                    <input type="text" name="snils" value="<?php echo htmlspecialchars($patient_data['snils'] ?? ''); ?>" placeholder="000-000-000 00">
                                    <small style="color: #666;">Интеграция с ЕМИАС: данные проверены</small>
                                </div>
                                <div class="form-group">
                                    <label>Полис ОМС</label>
                                    <input type="text" name="polis" value="<?php echo htmlspecialchars($patient_data['polis'] ?? ''); ?>" placeholder="0000000000000000">
                                    <small style="color: #666;">ЕМИАС: полис действителен</small>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn">Сохранить документы</button>
                    </form>
                </div>
            </div>

            <!-- ========== ВКЛАДКА: История операций ========== -->
            <!-- Вкладка История операций для пациента -->
<div id="tab-history" class="tab-content">
    <h2 style="margin-bottom: 1.5rem; color: #708090;">Мои операции</h2>
    
    <?php if (!empty($surgeries_history)): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Диагноз (МКБ-10)</th>
                    <th>Операция</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($surgeries_history as $surgery): ?>
                <tr>
                    <td><?php echo $surgery['surgery_date'] ? date('d.m.Y', strtotime($surgery['surgery_date'])) : 'Не назначена'; ?></td>
                    <td>
                        <span class="mkb-code"><?php echo $surgery['diagnosis_code'] ?? 'H25.9'; ?></span>
                        <?php echo htmlspecialchars($surgery['diagnosis_name'] ?? 'Катаракта'); ?>
                    </td>
                    <td><?php 
                        $surgery_types = [
                            'phaco' => 'Факоэмульсификация',
                            'glaucoma' => 'Антиглаукоматозная операция',
                            'laser' => 'Лазерная коррекция'
                        ];
                        echo $surgery_types[$surgery['surgery_type']] ?? $surgery['surgery_type'];
                    ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $surgery['status']; ?>">
                            <?php 
                            $statuses = [
                                'new' => 'Новый',
                                'preparation' => 'Подготовка',
                                'review' => 'Проверка',
                                'approved' => 'Одобрен',
                                'rejected' => 'Отклонен'
                            ];
                            echo $statuses[$surgery['status']] ?? $surgery['status'];
                            ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <p>История операций отсутствует</p>
    </div>
    <?php endif; ?>
</div>

            <?php elseif ($role === 'ophthalmologist'): ?>
            <!-- ========== ВКЛАДКА: Рабочая информация (для офтальмолога) ========== -->
            <div id="tab-work" class="tab-content">
                <div class="medical-card">
                    <h3>Профессиональная информация</h3>
                    
                    <div class="info-grid">
                        <div class="info-group">
                            <h4>Квалификация</h4>
                            <div class="info-row">
                                <span class="info-label">Специальность:</span>
                                <span class="info-value">Офтальмолог</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Категория:</span>
                                <span class="info-value">Высшая</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Стаж работы:</span>
                                <span class="info-value">12 лет</span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <h4>Рабочее место</h4>
                            <div class="info-row">
                                <span class="info-label">Учреждение:</span>
                                <span class="info-value">Районная поликлиника</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Район:</span>
                                <span class="info-value"><?php echo htmlspecialchars($user['district'] ?: 'Кировский'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Кабинет:</span>
                                <span class="info-value">321</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Интеграция с ЕМИАС -->
                    <div class="emias-integration" style="margin-top: 1rem;">
                        <span>ЕМИАС: Доступ к электронным картам пациентов</span>
                        <span class="emias-badge">Уровень доступа: полный</span>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $doctor_stats['total_patients'] ?? 0; ?></div>
                        <div class="stat-label">Всего пациентов</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $doctor_stats['in_preparation'] ?? 0; ?></div>
                        <div class="stat-label">На подготовке</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $doctor_stats['approved_surgeries'] ?? 0; ?></div>
                        <div class="stat-label">Одобрено операций</div>
                    </div>
                </div>
            </div>

            <!-- ========== ВКЛАДКА: Мои пациенты (для офтальмолога) ========== -->
            <div id="tab-patients" class="tab-content">
                <h2 style="margin-bottom: 1.5rem; color: #708090 100%;">Список пациентов с МКБ-10</h2>
                
                <?php
                $stmt = $pdo->prepare("
                    SELECT u.full_name, u.district, d.code as diagnosis_code, 
                           d.name as diagnosis_name, s.status, s.surgery_type
                    FROM patients p
                    JOIN users u ON p.user_id = u.id
                    LEFT JOIN surgeries s ON p.id = s.patient_id
                    LEFT JOIN diseases d ON s.disease_id = d.id
                    WHERE p.doctor_id = ?
                    ORDER BY s.created_at DESC
                ");
                $stmt->execute([$user_id]);
                $doctor_patients = $stmt->fetchAll();
                ?>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Пациент</th>
                                <th>Район</th>
                                <th>Диагноз (МКБ-10)</th>
                                <th>Операция</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($doctor_patients as $patient): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($patient['district']); ?></td>
                                <td>
                                    <span class="mkb-code"><?php echo $patient['diagnosis_code'] ?? 'H25.9'; ?></span>
                                    <?php echo htmlspecialchars($patient['diagnosis_name'] ?? 'Катаракта'); ?>
                                </td>
                                <td><?php echo htmlspecialchars($patient['surgery_type'] ?? 'Не назначена'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $patient['status']; ?>">
                                        <?php echo $statuses[$patient['status']] ?? 'Новый'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($role === 'surgeon'): ?>
            <!-- ========== ВКЛАДКА: Хирургическая практика (для хирурга) ========== -->
            <div id="tab-work" class="tab-content">
                <div class="medical-card">
                    <h3>Хирургическая практика</h3>
                    
                    <div class="info-grid">
                        <div class="info-group">
                            <h4>Специализация</h4>
                            <div class="info-row">
                                <span class="info-label">Направление:</span>
                                <span class="info-value">Офтальмохирургия</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Категория:</span>
                                <span class="info-value">Высшая</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Операций проведено:</span>
                                <span class="info-value">342</span>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <h4>Текущая нагрузка</h4>
                            <div class="info-row">
                                <span class="info-label">Пациентов:</span>
                                <span class="info-value"><?php echo $surgeon_stats['total_patients'] ?? 0; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">На проверке:</span>
                                <span class="info-value"><?php echo $surgeon_stats['pending_review'] ?? 0; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Предстоящих операций:</span>
                                <span class="info-value"><?php echo $surgeon_stats['upcoming_surgeries'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="emias-integration" style="margin-top: 1rem;">
                        <span>ЕМИАС: Доступ к операционному журналу</span>
                        <span class="emias-badge">Хирургический профиль</span>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $surgeon_stats['total_patients'] ?? 0; ?></div>
                        <div class="stat-label">Всего пациентов</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $surgeon_stats['pending_review'] ?? 0; ?></div>
                        <div class="stat-label">На проверке</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $surgeon_stats['upcoming_surgeries'] ?? 0; ?></div>
                        <div class="stat-label">Предстоит</div>
                    </div>
                </div>
            </div>

            <!-- ========== ВКЛАДКА: График операций (для хирурга) ========== -->
            <div id="tab-schedule" class="tab-content">
                <h2 style="margin-bottom: 1.5rem; color: #708090 100%;">График операций</h2>
                
                <?php
                $stmt = $pdo->prepare("
                    SELECT u.full_name, d.code as diagnosis_code, d.name as diagnosis_name,
                           s.surgery_type, s.surgery_date, s.status
                    FROM surgeries s
                    JOIN patients p ON s.patient_id = p.id
                    JOIN users u ON p.user_id = u.id
                    LEFT JOIN diseases d ON s.disease_id = d.id
                    WHERE p.surgeon_id = ? AND s.surgery_date IS NOT NULL
                    ORDER BY s.surgery_date ASC
                ");
                $stmt->execute([$user_id]);
                $surgeries_schedule = $stmt->fetchAll();
                ?>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Дата операции</th>
                                <th>Пациент</th>
                                <th>Диагноз (МКБ-10)</th>
                                <th>Операция</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($surgeries_schedule as $surgery): ?>
                            <tr>
                                <td><?php echo date('d.m.Y H:i', strtotime($surgery['surgery_date'])); ?></td>
                                <td><?php echo htmlspecialchars($surgery['full_name']); ?></td>
                                <td>
                                    <span class="mkb-code"><?php echo $surgery['diagnosis_code'] ?? 'H25.9'; ?></span>
                                    <?php echo htmlspecialchars($surgery['diagnosis_name'] ?? 'Катаракта'); ?>
                                </td>
                                <td><?php echo htmlspecialchars($surgery['surgery_type']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $surgery['status']; ?>">
                                        <?php echo $statuses[$surgery['status']] ?? $surgery['status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ========== ВКЛАДКА: Статистика (для врачей) ========== -->
            <?php if ($role !== 'patient'): ?>
            <div id="tab-stats" class="tab-content">
                <h2 style="margin-bottom: 1.5rem; color: #708090 100%;">Статистика работы</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $role === 'ophthalmologist' ? ($doctor_stats['total_patients'] ?? 0) : ($surgeon_stats['total_patients'] ?? 0); ?></div>
                        <div class="stat-label">Пациентов</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $role === 'ophthalmologist' ? ($doctor_stats['total_surgeries'] ?? 0) : ($surgeon_stats['total_surgeries'] ?? 0); ?></div>
                        <div class="stat-label">Операций</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $role === 'ophthalmologist' ? ($doctor_stats['approved_surgeries'] ?? 0) : ($surgeon_stats['upcoming_surgeries'] ?? 0); ?></div>
                        <div class="stat-label"><?php echo $role === 'ophthalmologist' ? 'Одобрено' : 'Запланировано'; ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ========== ВКЛАДКА: Настройки (для всех) ========== -->
            <div id="tab-settings" class="tab-content">
                <div class="profile-card" style="margin-bottom: 1rem;">
                    <h3 style="margin-bottom: 1.5rem; color: #708090 100%;">Редактировать профиль</h3>
                    
                    <form method="POST">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-group">
                            <label>Полное имя</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Телефон</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+7 (___) ___-__-__">
                        </div>
                        
                        <button type="submit" class="btn">Сохранить изменения</button>
                    </form>
                </div>
                
                <div class="profile-card">
                    <h3 style="margin-bottom: 1.5rem; color: #708090 100%;">Изменить пароль</h3>
                    
                    <form method="POST">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="form-group">
                            <label>Текущий пароль</label>
                            <input type="password" name="current_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Новый пароль</label>
                            <input type="password" name="new_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Подтверждение пароля</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        
                        <div class="password-requirements">
                            <p><strong>Требования к паролю:</strong></p>
                            <ul>
                                <li>Минимум 6 символов</li>
                                <li>Содержит буквы и цифры</li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn">Изменить пароль</button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО. Интеграция с ЕМИАС </p>
    </footer>

    <script src="assets/js/script.js"></script>
    <script>
        function showTab(tabName) {
            // Скрываем все вкладки
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Убираем активный класс у всех табов
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Показываем выбранную вкладку
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Добавляем активный класс выбранному табу
            event.target.classList.add('active');
        }
        
        // Валидация телефона
        document.querySelector('input[name="phone"]')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value.length <= 1) {
                    value = '+7' + value;
                } else if (value.length <= 4) {
                    value = '+7 (' + value.substring(1, 4);
                } else if (value.length <= 7) {
                    value = '+7 (' + value.substring(1, 4) + ') ' + value.substring(4, 7);
                } else if (value.length <= 9) {
                    value = '+7 (' + value.substring(1, 4) + ') ' + value.substring(4, 7) + '-' + value.substring(7, 9);
                } else {
                    value = '+7 (' + value.substring(1, 4) + ') ' + value.substring(4, 7) + '-' + value.substring(7, 9) + '-' + value.substring(9, 11);
                }
                e.target.value = value;
            }
        });
        
        // Валидация СНИЛС
        document.querySelector('input[name="snils"]')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 3) {
                value = value.substring(0,3) + '-' + value.substring(3);
            }
            if (value.length > 7) {
                value = value.substring(0,7) + '-' + value.substring(7);
            }
            if (value.length > 11) {
                value = value.substring(0,11) + ' ' + value.substring(11,13);
            }
            e.target.value = value;
        });
        
        // Валидация полиса
        document.querySelector('input[name="polis"]')?.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0,16);
        });
        
        // Валидация паспорта
        document.querySelector('input[name="passport_series"]')?.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0,4);
        });
        
        document.querySelector('input[name="passport_number"]')?.addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/\D/g, '').substring(0,6);
        });
    </script>
</body>
</html>