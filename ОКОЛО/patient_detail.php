<?php
require_once 'includes/auth.php';
requireLogin();

$patient_id = $_GET['id'] ?? 0;
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

// Получаем информацию о пациенте
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name, u.district, u.email, u.username,
        d.name as diagnosis, d.description as diagnosis_desc,
        s.id as surgery_id, s.surgery_type, s.status, s.surgery_date, s.notes,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id) as tests_total,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'uploaded') as tests_uploaded,
        (SELECT COUNT(*) FROM tests WHERE surgery_id = s.id AND status = 'approved') as tests_approved,
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

// Получаем список анализов
$stmt = $pdo->prepare("
    SELECT * FROM tests 
    WHERE surgery_id = ? 
    ORDER BY 
        CASE status 
            WHEN 'pending' THEN 1 
            WHEN 'uploaded' THEN 2 
            WHEN 'approved' THEN 3 
            WHEN 'rejected' THEN 4 
        END,
        test_name
");
$stmt->execute([$patient['surgery_id']]);
$tests = $stmt->fetchAll();

// Обработка изменения статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'] ?? '';
    $surgery_notes = $_POST['notes'] ?? '';
    $surgery_id = $patient['surgery_id'];
    
    if ($new_status && $surgery_id) {
        try {
            $stmt = $pdo->prepare("UPDATE surgeries SET status = ?, notes = ? WHERE id = ?");
            $result = $stmt->execute([$new_status, $surgery_notes, $surgery_id]);
            
            if ($result) {
                header("Location: patient_detail.php?id=$patient_id&updated=1");
                exit();
            } else {
                $error = "Ошибка при обновлении статуса";
            }
        } catch (PDOException $e) {
            $error = "Ошибка базы данных: " . $e->getMessage();
        }
    } else {
        $error = "Не указан статус или ID операции";
    }
}

$success = isset($_GET['success']);
$updated = isset($_GET['updated']);
$scheduled = isset($_GET['scheduled']);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Карта пациента - ОКОЛО</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .patient-profile {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .profile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f4f8;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #708090, #4a5568);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 500;
        }
        
        .status-new { background: #17a2b8; color: white; }
        .status-preparation { background: #ffc107; color: #333; }
        .status-review { background: #fd7e14; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 10px;
        }
        
        .info-label {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        
        .info-value {
            color: #333;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .tests-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin: 2rem 0;
        }
        
        .test-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        
        .test-item:hover {
            background: white;
            transform: translateX(5px);
            border-radius: 8px;
        }
        
        .test-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-icon {
            padding: 0.3rem 0.8rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-icon:hover {
            transform: scale(1.05);
        }
        
        .status-selector {
            padding: 0.3rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-right: 0.5rem;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            animation: slideIn 0.3s ease-out;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
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
            padding: 2rem;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            animation: slideIn 0.3s ease-out;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        
        .close-modal:hover {
            color: #dc3545;
        }
        
        .test-status {
            padding: 0.2rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .test-status.uploaded { background: #cce5ff; color: #004085; }
        .test-status.pending { background: #fff3cd; color: #856404; }
        .test-status.approved { background: #d4edda; color: #155724; }
        .test-status.rejected { background: #f8d7da; color: #721c24; }
        
        .btn {
            background: linear-gradient(135deg, #708090, #4a5568);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-small {
            padding: 0.4rem 1rem;
            font-size: 0.9rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-small:hover {
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .test-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .test-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .action-buttons {
                flex-direction: column;
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
                <?php if ($role === 'ophthalmologist'): ?>
                    <a href="dashboard.php">Дашборд</a>
                    <a href="patients.php">Мои пациенты</a>
                    <a href="schedule.php">Расписание</a>
                    <a href="profile.php">Профиль</a>
                <?php elseif ($role === 'surgeon'): ?>
                    <a href="dashboard.php">Дашборд</a>
                    <a href="patients.php">Мои пациенты</a>
                    <a href="review.php">На проверку</a>
                    <a href="schedule.php">Расписание</a>
                    <a href="profile.php">Профиль</a>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="role-badge">
                    <?php 
                    $roles = [
                        'ophthalmologist' => 'Офтальмолог',
                        'surgeon' => 'Хирург'
                    ];
                    echo $roles[$role] ?? $role;
                    ?>
                </span>
                <a href="logout.php" class="logout-btn">Выйти</a>
            </div>
        </nav>
    </header>

    <main class="container">
        <?php if ($updated): ?>
        <div class="success-message">
            ✅ Статус операции успешно обновлен!
        </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
        <div class="error-message" style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <div class="patient-profile">
            <div class="profile-header">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="profile-avatar">
                        <?php echo mb_substr($patient['full_name'], 0, 1); ?>
                    </div>
                    <div>
                        <h1><?php echo htmlspecialchars($patient['full_name']); ?></h1>
                        <p style="color: #666;">ID: <?php echo str_pad($patient['id'], 6, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
                <div style="text-align: right;">
                    <span class="status-badge status-<?php echo $patient['status'] ?? 'new'; ?>" style="font-size: 1rem; padding: 0.5rem 1.5rem;">
                        <?php 
                        $statuses = [
                            'new' => '🆕 Новый',
                            'preparation' => '📋 На подготовке',
                            'review' => '🔍 На проверке',
                            'approved' => '✅ Одобрен',
                            'rejected' => '❌ Отклонен'
                        ];
                        echo $statuses[$patient['status'] ?? 'new'] ?? 'Новый';
                        ?>
                    </span>
                    
                    <?php if ($role === 'ophthalmologist'): ?>
                    <button class="btn-small" style="margin-top: 0.5rem; margin-left: 1rem; background: #ffc107;" onclick="openModal('statusModal')">
                        ✏️ Изменить статус
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Район</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['district'] ?: 'Не указан'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['email'] ?: 'Не указан'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Районный офтальмолог</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['doctor_name'] ?: 'Не назначен'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Хирург-куратор</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['surgeon_name'] ?: 'Не назначен'); ?></div>
                </div>
            </div>

            <div style="margin: 2rem 0;">
                <h2 class="section-title">Диагноз и операция</h2>
                <div style="background: #e8f0fe; padding: 1.5rem; border-radius: 10px;">
                    <p><strong>Диагноз:</strong> <?php echo htmlspecialchars($patient['diagnosis'] ?: 'Не указан'); ?></p>
                    <p><strong>Описание:</strong> <?php echo htmlspecialchars($patient['diagnosis_desc'] ?: 'Нет описания'); ?></p>
                    <p><strong>Тип операции:</strong> <?php 
                        $surgery_types = [
                            'phaco' => 'Факоэмульсификация',
                            'glaucoma' => 'Антиглаукоматозная операция',
                            'laser' => 'Лазерная коррекция',
                            'vitrectomy' => 'Витрэктомия'
                        ];
                        echo $surgery_types[$patient['surgery_type']] ?? $patient['surgery_type'] ?? 'Не назначена'; 
                    ?></p>
                    <?php if ($patient['surgery_date']): ?>
                    <p><strong>Дата операции:</strong> <?php echo date('d.m.Y H:i', strtotime($patient['surgery_date'])); ?></p>
                    <?php endif; ?>
                    <?php if ($patient['notes']): ?>
                    <p><strong>Примечания:</strong> <?php echo nl2br(htmlspecialchars($patient['notes'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tests-section">
                <h2 style="margin-bottom: 1rem;">Анализы и обследования</h2>
                <div style="display: flex; gap: 2rem; margin-bottom: 2rem;">
                    <div>
                        <strong>Всего анализов:</strong> <?php echo $patient['tests_total'] ?? 0; ?>
                    </div>
                    <div>
                        <strong>Загружено:</strong> <?php echo $patient['tests_uploaded'] ?? 0; ?>
                    </div>
                    <div>
                        <strong>Принято:</strong> <?php echo $patient['tests_approved'] ?? 0; ?>
                    </div>
                </div>

                <div class="tests-list">
                    <?php foreach ($tests as $test): ?>
                    <div class="test-item" id="test-<?php echo $test['id']; ?>">
                        <div>
                            <strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
                            <?php if ($test['uploaded_at']): ?>
                            <br><small>Загружен: <?php echo date('d.m.Y H:i', strtotime($test['uploaded_at'])); ?></small>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <span class="test-status <?php echo $test['status']; ?>">
                                <?php 
                                $test_statuses = [
                                    'pending' => 'Ожидает',
                                    'uploaded' => 'Загружен',
                                    'approved' => 'Принят',
                                    'rejected' => 'Отклонен'
                                ];
                                echo $test_statuses[$test['status']] ?? $test['status'];
                                ?>
                            </span>
                            
                            <?php if ($role === 'surgeon' && $test['status'] === 'uploaded'): ?>
                            <div class="test-actions">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Принять анализ?')">
                                    <input type="hidden" name="test_id" value="<?php echo $test['id']; ?>">
                                    <input type="hidden" name="action" value="approve_test">
                                    <button type="submit" class="btn-icon" style="background: #28a745; color: white;">
                                        ✅ Принять
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Отклонить анализ?')">
                                    <input type="hidden" name="test_id" value="<?php echo $test['id']; ?>">
                                    <input type="hidden" name="action" value="reject_test">
                                    <button type="submit" class="btn-icon" style="background: #dc3545; color: white;">
                                        ❌ Отклонить
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($test['file_path']): ?>
                            <button class="btn-icon" style="background: #17a2b8; color: white;" onclick="window.open('http://localhost/okulus-feldsher/<?php echo $test['file_path']; ?>', '_blank')">
                                👁️ Просмотр
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Модальное окно изменения статуса -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Изменить статус операции</h2>
                <span class="close-modal" onclick="closeModal('statusModal')">&times;</span>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="update_status" value="1">
                
                <div class="form-group">
                    <label for="status">Статус:</label>
                    <select name="status" id="status" class="status-selector" style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 5px;">
                        <option value="new" <?php echo ($patient['status'] ?? '') === 'new' ? 'selected' : ''; ?>>Новый</option>
                        <option value="preparation" <?php echo ($patient['status'] ?? '') === 'preparation' ? 'selected' : ''; ?>>На подготовке</option>
                        <option value="review" <?php echo ($patient['status'] ?? '') === 'review' ? 'selected' : ''; ?>>На проверке</option>
                        <option value="approved" <?php echo ($patient['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Одобрен</option>
                        <option value="rejected" <?php echo ($patient['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>Отклонен</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="notes">Примечания:</label>
                    <textarea name="notes" id="notes" rows="4" style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 5px;"><?php echo htmlspecialchars($patient['notes'] ?? ''); ?></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn" style="flex: 2;">Сохранить изменения</button>
                    <button type="button" class="btn" style="flex: 1; background: #6c757d;" onclick="closeModal('statusModal')">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <footer>
        <p>&copy; 2026 ОКОЛО - Медицинская информационная система</p>
    </footer>

    <script src="assets/js/script.js"></script>
    <script>
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Закрытие при клике вне модального окна
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>