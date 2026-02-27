<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireLogin();

$patient_id = $_GET['id'] ?? 0;
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

// Получаем информацию о пациенте
$stmt = $pdo->prepare("
    SELECT u.full_name
    FROM patients p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: patients.php');
    exit();
}

// Получаем все медиафайлы пациента
$stmt = $pdo->prepare("
    SELECT 
        'test' as source,
        t.file_path,
        t.test_name as file_name,
        t.uploaded_at as created_at,
        NULL as media_type,
        0 as compressed
    FROM tests t
    WHERE t.surgery_id = (SELECT id FROM surgeries WHERE patient_id = ? LIMIT 1)
    AND t.file_path IS NOT NULL
    
    UNION ALL
    
    SELECT 
        'media' as source,
        m.file_path,
        m.file_name,
        m.created_at,
        m.media_type,
        m.compressed
    FROM media m
    WHERE m.patient_id = ?
    
    ORDER BY created_at DESC
");
$stmt->execute([$patient_id, $patient_id]);
$media_files = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Медиатека - <?php echo htmlspecialchars($patient['full_name']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .media-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .media-header {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .media-header h1 {
            color: #708090;
            margin-bottom: 0.5rem;
        }
        
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .media-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .media-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .media-preview {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: #f0f0f0;
        }
        
        .media-info {
            padding: 1rem;
        }
        
        .media-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.3rem;
        }
        
        .media-date {
            font-size: 0.8rem;
            color: #666;
        }
        
        .media-type {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #e8f0fe;
            color: #708090;
            border-radius: 5px;
            font-size: 0.7rem;
            margin-top: 0.3rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 15px;
            color: #666;
            grid-column: 1/-1;
        }
        
        .back-btn {
            display: inline-block;
            margin-bottom: 1rem;
            padding: 0.5rem 1rem;
            background: #708090;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        
        .back-btn:hover {
            background: #4a5568;
        }
        
        @media (max-width: 768px) {
            .media-grid {
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

    <main class="container media-container">
        <a href="patients.php" class="back-btn">← Назад к пациентам</a>
        
        <div class="media-header">
            <h1>Медиатека</h1>
            <p>Пациент: <strong><?php echo htmlspecialchars($patient['full_name']); ?></strong></p>
        </div>

        <div class="media-grid">
            <?php if (empty($media_files)): ?>
                <div class="empty-state">
                    <h3>Нет загруженных файлов</h3>
                    <p>У этого пациента пока нет загруженных снимков или документов</p>
                </div>
            <?php else: ?>
                <?php foreach ($media_files as $media): ?>
                <div class="media-card" onclick="window.open('<?php echo $media['file_path']; ?>', '_blank')">
                    <?php 
                    $ext = pathinfo($media['file_path'], PATHINFO_EXTENSION);
                    $is_image = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'bmp']);
                    ?>
                    
                    <?php if ($is_image): ?>
                        <img src="<?php echo $media['file_path']; ?>" class="media-preview" alt="Снимок">
                    <?php else: ?>
                        <div class="media-preview" style="display: flex; align-items: center; justify-content: center; background: #708090; color: white;">
                            <span style="font-size: 3rem;">📄</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="media-info">
                        <div class="media-name"><?php echo htmlspecialchars($media['file_name']); ?></div>
                        <div class="media-date"><?php echo date('d.m.Y H:i', strtotime($media['created_at'])); ?></div>
                        <span class="media-type">
                            <?php 
                            $types = [
                                'slit_lamp' => 'Щелевая лампа',
                                'fundus' => 'Глазное дно',
                                'keratotopography' => 'Кератотопограмма',
                                'oct' => 'ОКТ',
                                'test' => 'Анализ',
                                'other' => 'Документ'
                            ];
                            echo $types[$media['media_type']] ?? 'Документ';
                            ?>
                        </span>
                        <?php if ($media['compressed']): ?>
                            <span style="background: #28a745; color: white; padding: 0.1rem 0.3rem; border-radius: 3px; font-size: 0.7rem; margin-left: 0.3rem;">Сжато</span>
                        <?php endif; ?>
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