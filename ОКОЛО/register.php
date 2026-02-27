<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

require_once 'includes/config.php';
require_once 'includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

// Получаем всех офтальмологов для выбора при регистрации
$stmt = $pdo->query("
    SELECT id, full_name, district 
    FROM users 
    WHERE role = 'ophthalmologist' AND is_active = 1
    ORDER BY district, full_name
");
$ophthalmologists = $stmt->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'patient';
    $district = $_POST['district'] ?? '';
    $selected_doctor_id = $_POST['selected_doctor_id'] ?? null;
    
    // Дополнительные поля для пациента
    $passport_series = trim($_POST['passport_series'] ?? '');
    $passport_number = trim($_POST['passport_number'] ?? '');
    $passport_issued = trim($_POST['passport_issued'] ?? '');
    $passport_date = $_POST['passport_date'] ?? '';
    $snils = trim($_POST['snils'] ?? '');
    $polis = trim($_POST['polis'] ?? '');
    $birth_date = $_POST['birth_date'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $emergency_contact = trim($_POST['emergency_contact'] ?? '');
    $blood_type = $_POST['blood_type'] ?? '';
    $allergies = trim($_POST['allergies'] ?? '');
    
    // Массив для сбора ошибок
    $errors = [];
    
    // Валидация username
    if (empty($username)) {
        $errors['username'] = 'Имя пользователя обязательно';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $errors['username'] = 'Имя пользователя должно содержать только латинские буквы, цифры и _, от 3 до 50 символов';
    }
    
    // Валидация full_name
    if (empty($full_name)) {
        $errors['full_name'] = 'Полное имя обязательно';
    } elseif (!preg_match('/^[а-яА-ЯёЁa-zA-Z\s-]{3,100}$/u', $full_name)) {
        $errors['full_name'] = 'Полное имя должно содержать только буквы, пробелы и дефисы';
    }
    
    // Валидация email
    if (empty($email)) {
        $errors['email'] = 'Email обязателен';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный email адрес';
    }
    
    // Валидация телефона (если указан)
    if (!empty($phone)) {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (strlen($cleanPhone) !== 11 || $cleanPhone[0] !== '7') {
            $errors['phone'] = 'Телефон должен быть в формате +7 (___) ___-__-__';
        }
    }
    
    // Валидация пароля
    if (empty($password)) {
        $errors['password'] = 'Пароль обязателен';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Пароль должен содержать минимум 6 символов';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors['password'] = 'Пароль должен содержать хотя бы одну букву и одну цифру';
    }
    
    // Подтверждение пароля
    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Пароли не совпадают';
    }
    
    // Валидация района
    if (empty($district)) {
        $errors['district'] = 'Выберите район';
    }
    
    // Для пациентов - дополнительная валидация
    if ($role === 'patient') {
        if (empty($selected_doctor_id)) {
            $errors['selected_doctor_id'] = 'Выберите офтальмолога';
        }
        
        // Валидация паспортных данных (если указаны)
        if (!empty($passport_series) && !preg_match('/^\d{4}$/', $passport_series)) {
            $errors['passport_series'] = 'Серия паспорта должна содержать 4 цифры';
        }
        
        if (!empty($passport_number) && !preg_match('/^\d{6}$/', $passport_number)) {
            $errors['passport_number'] = 'Номер паспорта должен содержать 6 цифр';
        }
        
        if (!empty($passport_date)) {
            $dateObj = DateTime::createFromFormat('Y-m-d', $passport_date);
            if (!$dateObj || $dateObj > new DateTime()) {
                $errors['passport_date'] = 'Дата выдачи паспорта не может быть в будущем';
            }
        }
        
        // Валидация СНИЛС (если указаны)
        if (!empty($snils)) {
            $cleanSnils = preg_replace('/\D/', '', $snils);
            if (strlen($cleanSnils) !== 11) {
                $errors['snils'] = 'СНИЛС должен содержать 11 цифр';
            }
        }
        
        // Валидация полиса (если указан)
        if (!empty($polis)) {
            $cleanPolis = preg_replace('/\D/', '', $polis);
            if (strlen($cleanPolis) !== 16) {
                $errors['polis'] = 'Полис должен содержать 16 цифр';
            }
        }
        
        // Валидация даты рождения
        if (!empty($birth_date)) {
            $birthDateObj = DateTime::createFromFormat('Y-m-d', $birth_date);
            if (!$birthDateObj) {
                $errors['birth_date'] = 'Укажите корректную дату рождения';
            } elseif ($birthDateObj > new DateTime()) {
                $errors['birth_date'] = 'Дата рождения не может быть в будущем';
            }
        }
        
        // Валидация адреса (если указан)
        if (!empty($address) && strlen($address) < 5) {
            $errors['address'] = 'Адрес должен содержать минимум 5 символов';
        }
    }
    
    // Если есть ошибки, показываем их
    if (!empty($errors)) {
        $error = 'Пожалуйста, исправьте ошибки в форме';
    } else {
        // Проверка уникальности username и email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $error = 'Имя пользователя или email уже используются';
        } else {
            // Хеширование пароля
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Вставка пользователя
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, full_name, email, phone, role, district, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            
            if ($stmt->execute([$username, $hashed_password, $full_name, $email, $phone, $role, $district])) {
                $user_id = $pdo->lastInsertId();
                
                // Если это пациент, создаем запись в таблице patients
                if ($role === 'patient') {
                    $assigned_doctor_id = $selected_doctor_id;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO patients (
                            user_id, district, doctor_id, 
                            passport_series, passport_number, passport_issued, passport_date,
                            snils, polis, birth_date, gender, address, emergency_contact,
                            blood_type, allergies
                        ) VALUES (
                            ?, ?, ?, 
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?,
                            ?, ?
                        )
                    ");
                    $stmt->execute([
                        $user_id, $district, $assigned_doctor_id,
                        $passport_series, $passport_number, $passport_issued, $passport_date,
                        $snils, $polis, $birth_date, $gender, $address, $emergency_contact,
                        $blood_type, $allergies
                    ]);
                    
                    $patient_id = $pdo->lastInsertId();
                    
                    // Создаем дефолтную операцию
                    $stmt = $pdo->prepare("
                        INSERT INTO surgeries (patient_id, status, created_at) 
                        VALUES (?, 'new', NOW())
                    ");
                    $stmt->execute([$patient_id]);
                }
                
                $success = 'Регистрация успешна! Теперь вы можете войти в систему.';
                
                // Очистка формы
                $_POST = [];
            } else {
                $error = 'Ошибка при регистрации. Пожалуйста, попробуйте позже.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - Окулус-Фельдшер</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #FAF3ED 0%, #FAF3ED 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: #708090;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            padding: 1rem 2rem;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            backdrop-filter: blur(10px);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.8rem;
            font-weight: bold;
            color: #FAF3ED;
            margin-bottom: 0.5rem;
        }

        .logo span {
            font-size: 1rem;
            font-weight: normal;
            color: #FAF3ED;
            margin-left: 0.5rem;
        }

        .logo img {
            border-radius: 10px;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        .nav-links a {
            text-decoration: none;
            color: #FAF3ED;
            font-weight: 500;
            transition: all 0.3s;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            white-space: nowrap;
        }

        .nav-links a:hover {
            color: #FAF3ED;
            background: rgba(112, 128, 144, 0.1);
            transform: translateY(-2px);
        }

        .nav-links a.active {
            color: #FAF3ED;
            background: rgba(112, 128, 144, 0.1);
        }

        main {
            flex: 1;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .register-container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(112, 128, 144, 0.3);
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .register-container h2 {
            color: #708090;
            margin-bottom: 2rem;
            text-align: center;
            font-size: 2rem;
            font-weight: 600;
        }

        .form-section {
            background: #f8fafd;
            border-radius: 15px;
            padding: 1.8rem;
            margin-bottom: 2rem;
            border-left: 4px solid #708090;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }   

        .form-section h3 {
            color: #708090;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2d3748;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .form-group input, 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:hover,
        .form-group select:hover,
        .form-group textarea:hover {
            border-color: #cbd5e0;
        }

        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            outline: none;
            border-color: #708090;
            box-shadow: 0 0 0 3px rgba(112, 128, 144, 0.1);
        }

        .form-group input.error,
        .form-group select.error,
        .form-group textarea.error {
            border-color: #fc8181;
            background-color: #fff5f5;
        }

        .form-group input.valid,
        .form-group select.valid,
        .form-group textarea.valid {
            border-color: #68d391;
            background-color: #f0fff4;
        }

        .error-message {
            color: #e53e3e;
            font-size: 0.85rem;
            margin-top: 0.3rem;
            display: block;
        }

        .hint {
            color: #718096;
            font-size: 0.85rem;
            margin-top: 0.3rem;
            display: block;
        }

        .required::after {
            content: " *";
            color: #e53e3e;
        }

        .doctor-selection {
            background: #e6fffa;
            padding: 1.5rem;
            border-radius: 10px;
            margin: 1rem 0;
            border: 1px solid #b2f5ea;
        }

        .password-strength {
            margin-top: 0.8rem;
        }

        .strength-bar {
            height: 5px;
            background: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 0.3rem;
        }

        .strength-bar-fill {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .strength-text {
            font-size: 0.85rem;
            color: #718096;
        }

        .strength-weak .strength-bar-fill {
            background: #fc8181;
            width: 33.33%;
        }

        .strength-medium .strength-bar-fill {
            background: #fbbf24;
            width: 66.66%;
        }

        .strength-strong .strength-bar-fill {
            background: #68d391;
            width: 100%;
        }

        .btn-register {
            background: linear-gradient(135deg, #708090 0%, #708090 100%);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 1rem;
        }

        .btn-register:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(112, 128, 144, 0.4);
        }

        .btn-register:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease-out;
            font-weight: 500;
        }

        .alert-error {
            background: #fee;
            color: #c53030;
            border-left: 4px solid #c53030;
        }

        .alert-success {
            background: #e6fffa;
            color: #234e52;
            border-left: 4px solid #234e52;
        }

        .login-link {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 2px solid #e2e8f0;
        }

        .login-link a {
            color: #708090;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .login-link a:hover {
            color: #708090;
            text-decoration: underline;
        }

        footer {
            background: rgba(255, 255, 255, 0.95);
            text-align: center;
            padding: 1rem;
            margin-top: auto;
            color: #4a5568;
            font-size: 0.9rem;
        }

        .emias-badge {
            display: inline-block;
            background: #48bb78;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            header {
                padding: 1rem;
            }
            
            .logo {
                font-size: 1.5rem;
            }
            
            .nav-links {
                gap: 0.5rem;
            }
            
            .nav-links a {
                padding: 0.4rem 0.8rem;
                font-size: 0.9rem;
            }
            
            .register-container {
                margin: 1rem;
                padding: 1.5rem;
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
            <span>Цифровая платформа</span>
        </div>
        <div class="nav-links">
            <a href="index.php">Главная</a>
            <a href="login.php">Вход</a>
            <a href="register.php" class="active">Регистрация</a>
            <a href="check_status.php">Статус подготовки</a>
        </div>
    </header>

    <main class="container">
        <div class="register-container">
            <h2>📝 Регистрация в системе</h2>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>❌ Ошибка!</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>✅ Успех!</strong> <?php echo htmlspecialchars($success); ?>
                <br>
                <a href="login.php" style="color: #234e52; font-weight: 600; margin-top: 0.5rem; display: inline-block;">➡️ Перейти к входу</a>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="registerForm" novalidate>
                <!-- Основная информация -->
                <div class="form-section">
                    <h3>📋 Основная информация</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username" class="required">Имя пользователя</label>
                            <input type="text" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                                   required minlength="3" maxlength="50"
                                   pattern="[a-zA-Z0-9_]+" 
                                   title="Только латинские буквы, цифры и знак подчеркивания"
                                   data-validate="username">
                            <span class="hint">🔤 Только латинские буквы, цифры и _ (3-50 символов)</span>
                            <span class="error-message" id="username-error"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="full_name" class="required">Полное имя</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                   required minlength="3" maxlength="100"
                                   data-validate="fullname">
                            <span class="hint">👤 Введите ваше полное имя</span>
                            <span class="error-message" id="full_name-error"></span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="required">Email</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                   required data-validate="email">
                            <span class="hint">📧 example@domain.com</span>
                            <span class="error-message" id="email-error"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Телефон</label>
                            <input type="text" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                                   placeholder="+7 (___) ___-__-__"
                                   data-validate="phone">
                            <span class="hint">📱 Формат: +7 (999) 999-99-99</span>
                            <span class="error-message" id="phone-error"></span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="role" class="required">Роль в системе</label>
                            <select name="role" id="role" required onchange="togglePatientFields()">
                                <option value="patient" <?php echo ($_POST['role'] ?? 'patient') === 'patient' ? 'selected' : ''; ?>>👤 Пациент</option>
                                <option value="ophthalmologist" <?php echo ($_POST['role'] ?? '') === 'ophthalmologist' ? 'selected' : ''; ?>>👨‍⚕️ Районный офтальмолог</option>
                                <option value="surgeon" <?php echo ($_POST['role'] ?? '') === 'surgeon' ? 'selected' : ''; ?>>👨‍🏥 Хирург-куратор</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="district" class="required">Район</label>
                            <select name="district" id="district" required>
                                <option value="">Выберите район</option>
                                <option value="Кировский" <?php echo ($_POST['district'] ?? '') === 'Кировский' ? 'selected' : ''; ?>>Кировский район</option>
                                <option value="Первомайский" <?php echo ($_POST['district'] ?? '') === 'Первомайский' ? 'selected' : ''; ?>>Первомайский район</option>
                                <option value="Октябрьский" <?php echo ($_POST['district'] ?? '') === 'Октябрьский' ? 'selected' : ''; ?>>Октябрьский район</option>
                                <option value="Свердловский" <?php echo ($_POST['district'] ?? '') === 'Свердловский' ? 'selected' : ''; ?>>Свердловский район</option>
                                <option value="Ленинский" <?php echo ($_POST['district'] ?? '') === 'Ленинский' ? 'selected' : ''; ?>>Ленинский район</option>
                                <option value="Областной центр" <?php echo ($_POST['district'] ?? '') === 'Областной центр' ? 'selected' : ''; ?>>Областной центр</option>
                            </select>
                            <span class="hint" id="district-hint">📍 Укажите ваш район</span>
                            <span class="error-message" id="district-error"></span>
                        </div>
                    </div>
                </div>

                <!-- Выбор офтальмолога (только для пациентов) -->
                <div id="doctorSelection" style="display: none;">
                    <div class="doctor-selection">
                        <div class="form-group">
                            <label for="selected_doctor_id" class="required">👨‍⚕️ Выберите офтальмолога</label>
                            <select name="selected_doctor_id" id="selected_doctor_id" class="form-control">
                                <option value="">-- Выберите офтальмолога --</option>
                                <?php foreach ($ophthalmologists as $doctor): ?>
                                <option value="<?php echo $doctor['id']; ?>" 
                                    data-district="<?php echo htmlspecialchars($doctor['district']); ?>"
                                    <?php echo ($_POST['selected_doctor_id'] ?? '') == $doctor['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($doctor['full_name']); ?> (<?php echo htmlspecialchars($doctor['district']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint">🔍 Выберите врача, к которому хотите прикрепиться</span>
                            <span class="error-message" id="selected_doctor_id-error"></span>
                        </div>
                    </div>
                </div>

                <!-- Документы (только для пациентов) -->
                <div id="patient-fields">
                    <div class="form-section">
                        <h3>🪪 Паспортные данные</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="passport_series">Серия паспорта</label>
                                <input type="text" id="passport_series" name="passport_series"
                                       value="<?php echo htmlspecialchars($_POST['passport_series'] ?? ''); ?>" 
                                       maxlength="4" placeholder="0000"
                                       data-validate="passport_series">
                                <span class="hint">🔢 4 цифры</span>
                                <span class="error-message" id="passport_series-error"></span>
                            </div>
                            
                            <div class="form-group">
                                <label for="passport_number">Номер паспорта</label>
                                <input type="text" id="passport_number" name="passport_number"
                                       value="<?php echo htmlspecialchars($_POST['passport_number'] ?? ''); ?>" 
                                       maxlength="6" placeholder="000000"
                                       data-validate="passport_number">
                                <span class="hint">🔢 6 цифр</span>
                                <span class="error-message" id="passport_number-error"></span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="passport_issued">Кем выдан</label>
                            <input type="text" id="passport_issued" name="passport_issued" 
                                   value="<?php echo htmlspecialchars($_POST['passport_issued'] ?? ''); ?>" 
                                   placeholder="Наименование отделения">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="passport_date">Дата выдачи</label>
                                <input type="date" id="passport_date" name="passport_date" 
                                       value="<?php echo htmlspecialchars($_POST['passport_date'] ?? ''); ?>"
                                       data-validate="passport_date">
                                <span class="error-message" id="passport_date-error"></span>
                            </div>
                            
                            <div class="form-group">
                                <label>Код подразделения</label>
                                <input type="text" value="000-000" readonly class="readonly" placeholder="Заглушка ЕМИАС">
                                <span class="emias-badge">ЕМИАС</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>📄 СНИЛС и полис</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="snils">СНИЛС</label>
                                <input type="text" id="snils" name="snils"
                                       value="<?php echo htmlspecialchars($_POST['snils'] ?? ''); ?>" 
                                       placeholder="000-000-000 00"
                                       data-validate="snils">
                                <span class="hint">🔢 Формат: 000-000-000 00</span>
                                <span class="error-message" id="snils-error"></span>
                                <span class="emias-badge">ЕМИАС</span>
                            </div>
                            
                            <div class="form-group">
                                <label for="polis">Полис ОМС</label>
                                <input type="text" id="polis" name="polis"
                                       value="<?php echo htmlspecialchars($_POST['polis'] ?? ''); ?>" 
                                       placeholder="0000000000000000" maxlength="16"
                                       data-validate="polis">
                                <span class="hint">🔢 16 цифр</span>
                                <span class="error-message" id="polis-error"></span>
                                <span class="emias-badge">ЕМИАС</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>👤 Личные данные</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="birth_date">Дата рождения</label>
                                <input type="date" id="birth_date" name="birth_date" 
                                       value="<?php echo htmlspecialchars($_POST['birth_date'] ?? ''); ?>"
                                       data-validate="birth_date">
                                <span class="error-message" id="birth_date-error"></span>
                            </div>
                            
                            <div class="form-group">
                                <label for="gender">Пол</label>
                                <select id="gender" name="gender">
                                    <option value="">Не указан</option>
                                    <option value="Мужской" <?php echo ($_POST['gender'] ?? '') === 'Мужской' ? 'selected' : ''; ?>>👨 Мужской</option>
                                    <option value="Женский" <?php echo ($_POST['gender'] ?? '') === 'Женский' ? 'selected' : ''; ?>>👩 Женский</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Адрес проживания</label>
                            <input type="text" id="address" name="address" 
                                   value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" 
                                   placeholder="Город, улица, дом, квартира"
                                   data-validate="address">
                            <span class="error-message" id="address-error"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="emergency_contact">Контакт для экстренных случаев</label>
                            <input type="text" id="emergency_contact" name="emergency_contact" 
                                   value="<?php echo htmlspecialchars($_POST['emergency_contact'] ?? ''); ?>" 
                                   placeholder="ФИО, телефон">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>⚕️ Медицинские данные</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="blood_type">Группа крови</label>
                                <select id="blood_type" name="blood_type">
                                    <option value="">Не указана</option>
                                    <option value="0(I)" <?php echo ($_POST['blood_type'] ?? '') === '0(I)' ? 'selected' : ''; ?>>0(I)</option>
                                    <option value="A(II)" <?php echo ($_POST['blood_type'] ?? '') === 'A(II)' ? 'selected' : ''; ?>>A(II)</option>
                                    <option value="B(III)" <?php echo ($_POST['blood_type'] ?? '') === 'B(III)' ? 'selected' : ''; ?>>B(III)</option>
                                    <option value="AB(IV)" <?php echo ($_POST['blood_type'] ?? '') === 'AB(IV)' ? 'selected' : ''; ?>>AB(IV)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="allergies">Аллергии</label>
                                <input type="text" id="allergies" name="allergies" 
                                       value="<?php echo htmlspecialchars($_POST['allergies'] ?? ''); ?>" 
                                       placeholder="Через запятую">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Пароль -->
                <div class="form-section">
                    <h3>🔐 Безопасность</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password" class="required">Пароль</label>
                            <input type="password" id="password" name="password" required minlength="6"
                                   data-validate="password">
                            <div class="password-strength" id="password-strength">
                                <div class="strength-bar">
                                    <div class="strength-bar-fill"></div>
                                </div>
                                <span class="strength-text">Надежность пароля</span>
                            </div>
                            <span class="error-message" id="password-error"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password" class="required">Подтверждение пароля</label>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   data-validate="confirm_password">
                            <span class="error-message" id="confirm_password-error"></span>
                        </div>
                    </div>
                    
                    <div class="password-requirements">
                        <strong>📋 Требования к паролю:</strong>
                        <ul style="margin-top: 0.5rem; margin-left: 1.5rem; color: #4a5568;">
                            <li>Минимум 6 символов</li>
                            <li>Содержит буквы и цифры</li>
                            <li>Не должен содержать личные данные</li>
                        </ul>
                    </div>
                </div>

                <div class="role-info" style="background: #e6fffa; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; border-left: 4px solid #708090;">
                    <p style="margin: 0.5rem 0;"><strong>👤 Пациент:</strong> просмотр статуса подготовки, проверка анализов, медицинская карта</p>
                    <p style="margin: 0.5rem 0;"><strong>👨‍⚕️ Районный офтальмолог:</strong> подготовка пациентов, загрузка анализов, контроль готовности</p>
                    <p style="margin: 0.5rem 0;"><strong>👨‍🏥 Хирург-куратор:</strong> проверка готовности, одобрение операций, обратная связь</p>
                    <p style="margin: 0.5rem 0; color: #718096; font-size: 0.9rem;">🔔 Все данные синхронизируются с ЕМИАС (тестовый режим)</p>
                </div>

                <button type="submit" class="btn-register" id="submitBtn">
                    ✅ Зарегистрироваться
                </button>

                <div class="login-link">
                    Уже есть аккаунт? <a href="login.php">Войти в систему</a>
                </div>
            </form>
        </div>
    </main>

    <footer>
        <p>&copy; 2024 Окулус-Фельдшер. Интеграция с ЕМИАС (тестовый режим)</p>
    </footer>

    <script>
        // Функция переключения полей для пациента
        function togglePatientFields() {
            const role = document.getElementById('role').value;
            const patientFields = document.getElementById('patient-fields');
            const doctorSelection = document.getElementById('doctorSelection');
            const districtSelect = document.getElementById('district');
            const districtHint = document.getElementById('district-hint');
            const doctorSelect = document.getElementById('selected_doctor_id');
            
            if (role === 'patient') {
                patientFields.style.display = 'block';
                doctorSelection.style.display = 'block';
                districtHint.innerHTML = '📍 Укажите район проживания';
                districtSelect.disabled = false;
                if (doctorSelect) doctorSelect.required = true;
            } else if (role === 'surgeon') {
                patientFields.style.display = 'none';
                doctorSelection.style.display = 'none';
                districtSelect.value = 'Областной центр';
                districtSelect.disabled = true;
                districtHint.innerHTML = '🏥 Хирурги работают в областном центре';
                if (doctorSelect) doctorSelect.required = false;
            } else {
                patientFields.style.display = 'none';
                doctorSelection.style.display = 'none';
                districtSelect.disabled = false;
                districtHint.innerHTML = '📍 Укажите район работы';
                if (doctorSelect) doctorSelect.required = false;
            }
        }
        
        // Валидация на лету
        document.addEventListener('DOMContentLoaded', function() {
            togglePatientFields();
            
            // Добавляем обработчики для всех полей с валидацией
            const validateFields = document.querySelectorAll('[data-validate]');
            validateFields.forEach(field => {
                field.addEventListener('input', function() {
                    validateField(this);
                });
                field.addEventListener('blur', function() {
                    validateField(this);
                });
            });
            
            // Специальные обработчики для форматирования
            setupInputFormatting();
        });
        
        // Функция валидации поля
        function validateField(field) {
            const value = field.value.trim();
            const fieldId = field.id;
            const errorElement = document.getElementById(fieldId + '-error');
            
            let isValid = true;
            let errorMessage = '';
            
            switch(field.dataset.validate) {
                case 'username':
                    if (value.length < 3) {
                        isValid = false;
                        errorMessage = 'Имя пользователя должно содержать минимум 3 символа';
                    } else if (!/^[a-zA-Z0-9_]+$/.test(value)) {
                        isValid = false;
                        errorMessage = 'Только латинские буквы, цифры и _';
                    }
                    break;
                    
                case 'fullname':
                    if (value.length < 3) {
                        isValid = false;
                        errorMessage = 'Введите полное имя';
                    } else if (!/^[а-яА-ЯёЁa-zA-Z\s-]+$/u.test(value)) {
                        isValid = false;
                        errorMessage = 'Только буквы, пробелы и дефисы';
                    }
                    break;
                    
                case 'email':
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        isValid = false;
                        errorMessage = 'Введите корректный email';
                    }
                    break;
                    
                case 'phone':
                    if (value) {
                        const digits = value.replace(/\D/g, '');
                        if (digits.length !== 11 || digits[0] !== '7') {
                            isValid = false;
                            errorMessage = 'Неверный формат телефона';
                        }
                    }
                    break;
                    
                case 'password':
                    if (value.length < 6) {
                        isValid = false;
                        errorMessage = 'Минимум 6 символов';
                    } else if (!/[A-Za-z]/.test(value) || !/\d/.test(value)) {
                        isValid = false;
                        errorMessage = 'Должна быть хотя бы одна буква и цифра';
                    }
                    // Обновляем индикатор надежности пароля
                    updatePasswordStrength(value);
                    break;
                    
                case 'confirm_password':
                    const password = document.getElementById('password').value;
                    if (value !== password) {
                        isValid = false;
                        errorMessage = 'Пароли не совпадают';
                    }
                    break;
                    
                case 'passport_series':
                    if (value && !/^\d{4}$/.test(value)) {
                        isValid = false;
                        errorMessage = 'Должно быть 4 цифры';
                    }
                    break;
                    
                case 'passport_number':
                    if (value && !/^\d{6}$/.test(value)) {
                        isValid = false;
                        errorMessage = 'Должно быть 6 цифр';
                    }
                    break;
                    
                case 'snils':
                    if (value) {
                        const digits = value.replace(/\D/g, '');
                        if (digits.length !== 11) {
                            isValid = false;
                            errorMessage = 'Должно быть 11 цифр';
                        }
                    }
                    break;
                    
                case 'polis':
                    if (value) {
                        const digits = value.replace(/\D/g, '');
                        if (digits.length !== 16) {
                            isValid = false;
                            errorMessage = 'Должно быть 16 цифр';
                        }
                    }
                    break;
                    
                case 'birth_date':
                    if (value) {
                        const birthDate = new Date(value);
                        const today = new Date();
                        if (birthDate > today) {
                            isValid = false;
                            errorMessage = 'Дата не может быть в будущем';
                        }
                    }
                    break;
                    
                case 'address':
                    if (value && value.length < 5) {
                        isValid = false;
                        errorMessage = 'Минимум 5 символов';
                    }
                    break;
            }
            
            // Обновляем отображение
            if (errorMessage) {
                field.classList.add('error');
                field.classList.remove('valid');
                if (errorElement) {
                    errorElement.textContent = errorMessage;
                }
            } else {
                field.classList.remove('error');
                field.classList.add('valid');
                if (errorElement) {
                    errorElement.textContent = '';
                }
            }
            
            // Обновляем состояние кнопки отправки
            updateSubmitButton();
            
            return isValid;
        }
        
        // Функция обновления индикатора надежности пароля
        function updatePasswordStrength(password) {
            const strengthBar = document.querySelector('.strength-bar-fill');
            const strengthText = document.querySelector('.strength-text');
            const strengthDiv = document.getElementById('password-strength');
            
            if (!strengthBar || !strengthDiv) return;
            
            // Удаляем предыдущие классы
            strengthDiv.classList.remove('strength-weak', 'strength-medium', 'strength-strong');
            
            if (!password) {
                strengthBar.style.width = '0';
                strengthText.textContent = 'Введите пароль';
                return;
            }
            
            let strength = 0;
            
            // Проверка длины
            if (password.length >= 6) strength += 1;
            if (password.length >= 8) strength += 1;
            
            // Проверка наличия цифр
            if (/\d/.test(password)) strength += 1;
            
            // Проверка наличия букв в разных регистрах
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength += 1;
            
            // Проверка наличия специальных символов
            if (/[!@#$%^&*]/.test(password)) strength += 1;
            
            if (strength <= 2) {
                strengthDiv.classList.add('strength-weak');
                strengthText.textContent = 'Ненадежный пароль';
            } else if (strength <= 4) {
                strengthDiv.classList.add('strength-medium');
                strengthText.textContent = 'Средний пароль';
            } else {
                strengthDiv.classList.add('strength-strong');
                strengthText.textContent = 'Надежный пароль';
            }
        }
        
        // Функция обновления кнопки отправки
        function updateSubmitButton() {
            const submitBtn = document.getElementById('submitBtn');
            const errorFields = document.querySelectorAll('.error');
            const requiredFields = document.querySelectorAll('[required]');
            
            let allValid = true;
            
            // Проверяем все обязательные поля
            requiredFields.forEach(field => {
                if (!field.value && field.type !== 'select-one') {
                    allValid = false;
                }
            });
            
            // Проверяем наличие ошибок
            if (errorFields.length > 0) {
                allValid = false;
            }
            
            // Специальная проверка для пациентов
            const role = document.getElementById('role').value;
            if (role === 'patient') {
                const doctorSelect = document.getElementById('selected_doctor_id');
                if (!doctorSelect || !doctorSelect.value) {
                    allValid = false;
                }
            }
            
            submitBtn.disabled = !allValid;
        }
        
        // Настройка форматирования полей
        function setupInputFormatting() {
            // Телефон
            document.getElementById('phone')?.addEventListener('input', function(e) {
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
                validateField(e.target);
            });
            
            // СНИЛС
            document.getElementById('snils')?.addEventListener('input', function(e) {
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
                e.target.value = value.substring(0,15); // XXX-XXX-XXX XX
                validateField(e.target);
            });
            
            // Полис
            document.getElementById('polis')?.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '').substring(0,16);
                validateField(e.target);
            });
            
            // Паспортные данные
            document.getElementById('passport_series')?.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '').substring(0,4);
                validateField(e.target);
            });
            
            document.getElementById('passport_number')?.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '').substring(0,6);
                validateField(e.target);
            });
        }
        
        // Финальная валидация перед отправкой
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const role = document.getElementById('role').value;
            
            // Проверяем все поля с валидацией
            const validateFields = document.querySelectorAll('[data-validate]');
            let isFormValid = true;
            
            validateFields.forEach(field => {
                if (!validateField(field)) {
                    isFormValid = false;
                }
            });
            
            // Проверка для пациента
            if (role === 'patient') {
                const doctorSelect = document.getElementById('selected_doctor_id');
                if (!doctorSelect || !doctorSelect.value) {
                    isFormValid = false;
                    alert('Пожалуйста, выберите офтальмолога');
                }
            }
            
            if (!isFormValid) {
                e.preventDefault();
                alert('Пожалуйста, исправьте ошибки в форме');
            }
        });
    </script>
</body>
</html>