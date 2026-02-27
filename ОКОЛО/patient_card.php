<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireLogin();

$patient_id = $_GET['id'] ?? 0;
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];
$user_id = $_SESSION['user_id'];

// Получаем информацию о пациенте
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        u.full_name, 
        u.email, 
        u.phone, 
        u.district,
        s.id as surgery_id,
        s.surgery_type,
        s.status as surgery_status,
        s.surgery_date,
        s.notes as surgery_notes,
        s.disease_id,
        d.name as diagnosis,
        d.code as diagnosis_code,
        d.description as diagnosis_description,
        doc.full_name as doctor_name,
        surg.full_name as surgeon_name
    FROM patients p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN surgeries s ON p.id = s.patient_id
    LEFT JOIN diseases d ON s.disease_id = d.id
    LEFT JOIN users doc ON p.doctor_id = doc.id
    LEFT JOIN users surg ON p.surgeon_id = surg.id
    WHERE p.id = ?
");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: patients.php');
    exit();
}

// Получаем список всех заболеваний (МКБ-10)
$stmt = $pdo->query("SELECT id, code, name FROM diseases ORDER BY code");
$diseases = $stmt->fetchAll();

// Получаем список хирургов
$stmt = $pdo->query("
    SELECT id, full_name, district 
    FROM users 
    WHERE role = 'surgeon' AND is_active = 1
    ORDER BY full_name
");
$surgeons = $stmt->fetchAll();

// Получаем типы операций
$surgery_types = [
    'phaco' => 'Факоэмульсификация',
    'glaucoma' => 'Антиглаукоматозная операция',
    'vitrectomy' => 'Витрэктомия',
    'laser' => 'Лазерная коррекция',
    'other' => 'Другое'
];

// Обработка сохранения диагноза и назначения операции
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_diagnosis'])) {
    $disease_id = $_POST['disease_id'] ?? null;
    $surgery_type = $_POST['surgery_type'] ?? '';
    $surgeon_id = $_POST['surgeon_id'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if ($disease_id && $surgery_type) {
        // Обновляем операцию
        $stmt = $pdo->prepare("
            UPDATE surgeries SET 
                disease_id = ?,
                surgery_type = ?,
                surgeon_id = ?,
                notes = ?,
                status = 'preparation',
                updated_at = NOW()
            WHERE patient_id = ?
        ");
        $stmt->execute([$disease_id, $surgery_type, $surgeon_id, $notes, $patient_id]);
        
        $success = 'Диагноз и операция сохранены. Пациент направлен к хирургу.';
        
        // Обновляем данные
        $patient['disease_id'] = $disease_id;
        $patient['surgery_type'] = $surgery_type;
        $patient['surgeon_id'] = $surgeon_id;
        $patient['surgery_status'] = 'preparation';
    }
}

// Получаем анализы
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
$stmt->execute([$patient['surgery_id']]);
$tests = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карточка пациента - <?php echo htmlspecialchars($patient['full_name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .patient-card-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .diagnosis-form {
            background: linear-gradient(135deg, #708090 100%, #2a5298);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
        }
        
        .diagnosis-form h3 {
            color: white;
            margin-bottom: 1.5rem;
        }
        
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
            margin-bottom: 0.5rem;
            color: rgba(255,255,255,0.9);
            font-weight: 500;
        }
        
        .form-group select, .form-group input, .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        .btn-save {
            background: #28a745;
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .info-section {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .mkb-code {
            display: inline-block;
            background: #e8f0fe;
            color: #708090 100%;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-family: monospace;
            margin-right: 0.5rem;
        }
        
        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-block;
        }
        
        .status-preparation { background: #fff3cd; color: #856404; }
        .status-review { background: #cce5ff; color: #004085; }
        .status-approved { background: #d4edda; color: #155724; }
        
        .surgeon-info {
            background: #e8f0fe;
            padding: 1rem;
            border-radius: 10px;
            margin-top: 1rem;
        }
        
        @media (max-width: 768px) {
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
                <a href="patients.php">Мои пациенты</a>
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

    <main class="container patient-card-container">
        <h1>Карточка пациента</h1>
        <p style="font-size: 1.2rem; margin-bottom: 2rem;"><?php echo htmlspecialchars($patient['full_name']); ?></p>

        <!-- Форма для офтальмолога (диагноз и направление к хирургу) -->
        <?php if ($role === 'ophthalmologist'): ?>
        <div class="diagnosis-form">
            <h3>🩺 Назначение диагноза и операции</h3>
            
            <?php if (isset($success)): ?>
            <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <?php echo $success; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="save_diagnosis" value="1">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Диагноз (МКБ-10) *</label>
                        <select name="disease_id" required>
                            <option value="">-- Выберите диагноз --</option>
                            <?php foreach ($diseases as $disease): ?>
                            <option value="<?php echo $disease['id']; ?>" 
                                <?php echo ($patient['disease_id'] == $disease['id']) ? 'selected' : ''; ?>>
                                <?php echo $disease['code']; ?> - <?php echo $disease['name']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Тип операции *</label>
                        <select name="surgery_type" required>
                            <option value="">-- Выберите операцию --</option>
                            <?php foreach ($surgery_types as $key => $value): ?>
                            <option value="<?php echo $key; ?>" 
                                <?php echo ($patient['surgery_type'] == $key) ? 'selected' : ''; ?>>
                                <?php echo $value; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Направить к хирургу</label>
                    <select name="surgeon_id">
                        <option value="">-- Выберите хирурга (опционально) --</option>
                        <?php foreach ($surgeons as $surgeon): ?>
                        <option value="<?php echo $surgeon['id']; ?>" 
                            <?php echo ($patient['surgeon_id'] == $surgeon['id']) ? 'selected' : ''; ?>>
                            <?php echo $surgeon['full_name']; ?> (<?php echo $surgeon['district']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Примечания</label>
                    <textarea name="notes" rows="3" placeholder="Дополнительная информация..."><?php echo htmlspecialchars($patient['surgery_notes'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="btn-save">💾 Сохранить и направить к хирургу</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Информация о текущем статусе -->
        <div class="info-section">
            <h3>📋 Текущий статус</h3>
            <div class="info-grid">
                <div>
                    <p><strong>Статус операции:</strong> 
                        <span class="status-badge status-<?php echo $patient['surgery_status']; ?>">
                            <?php 
                            $statuses = [
                                'new' => 'Новый',
                                'preparation' => 'Подготовка',
                                'review' => 'На проверке',
                                'approved' => 'Одобрен',
                                'rejected' => 'Отклонен'
                            ];
                            echo $statuses[$patient['surgery_status']] ?? 'Новый';
                            ?>
                        </span>
                    </p>
                    
                    <?php if ($patient['diagnosis']): ?>
                    <p><strong>Диагноз:</strong> 
                        <span class="mkb-code"><?php echo $patient['diagnosis_code']; ?></span>
                        <?php echo $patient['diagnosis']; ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($patient['surgery_type']): ?>
                    <p><strong>Операция:</strong> <?php echo $surgery_types[$patient['surgery_type']] ?? $patient['surgery_type']; ?></p>
                    <?php endif; ?>
                </div>
                
                <div>
                    <p><strong>Офтальмолог:</strong> <?php echo $patient['doctor_name'] ?? 'Вы'; ?></p>
                    
                    <?php if ($patient['surgeon_name']): ?>
                    <p><strong>Хирург:</strong> <?php echo $patient['surgeon_name']; ?></p>
                    <?php else: ?>
                    <p><strong>Хирург:</strong> <span style="color: #999;">Не назначен</span></p>
                    <?php endif; ?>
                    
                    <?php if ($patient['surgery_date']): ?>
                    <p><strong>Дата операции:</strong> <?php echo date('d.m.Y', strtotime($patient['surgery_date'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Паспортные данные -->
        <div class="info-section">
            <h3>📋 Паспортные данные</h3>
            <div class="info-grid">
                <div>
                    <p><strong>ФИО:</strong> <?php echo $patient['full_name']; ?></p>
                    <p><strong>Дата рождения:</strong> <?php echo $patient['birth_date'] ? date('d.m.Y', strtotime($patient['birth_date'])) : '—'; ?></p>
                </div>
                <div>
                    <p><strong>Серия паспорта:</strong> <?php echo $patient['passport_series'] ?? '—'; ?></p>
                    <p><strong>Номер паспорта:</strong> <?php echo $patient['passport_number'] ?? '—'; ?></p>
                </div>
                <div>
                    <p><strong>СНИЛС:</strong> <?php echo $patient['snils'] ?? '—'; ?></p>
                    <p><strong>Полис:</strong> <?php echo $patient['polis'] ?? '—'; ?></p>
                </div>
            </div>
        </div>

        <!-- Анализы -->
        <div class="info-section">
            <h3>📊 Анализы</h3>
            <table>
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tests as $test): ?>
                    <tr>
                        <td><?php echo $test['test_name']; ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $test['status']; ?>">
                                <?php 
                                $test_statuses = [
                                    'pending' => 'Ожидает',
                                    'uploaded' => 'Загружен',
                                    'approved' => 'Принят',
                                    'rejected' => 'Отклонен'
                                ];
                                echo $test_statuses[$test['status']];
                                ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($role === 'ophthalmologist' && $test['status'] === 'pending'): ?>
                            <a href="upload_test.php?test_id=<?php echo $test['id']; ?>" class="btn-small">Загрузить</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО</p>
    </footer>
</body>
</html>