<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireLogin();

$patient_id = $_GET['patient_id'] ?? 0;
$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'];

// Получаем информацию о пациенте
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name, s.surgery_type 
    FROM patients p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN surgeries s ON p.id = s.patient_id
    WHERE p.id = ?
");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: patients.php');
    exit();
}

// Получаем прогресс по анализам
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'uploaded' THEN 1 ELSE 0 END) as uploaded,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
    FROM tests 
    WHERE surgery_id = (SELECT id FROM surgeries WHERE patient_id = ? LIMIT 1)
");
$stmt->execute([$patient_id]);
$test_stats = $stmt->fetch();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Чек-лист - <?php echo $patient['full_name']; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .checklist-page {
            max-width: 800px;
            margin: 2rem auto;
        }
        
        .progress-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: conic-gradient(#28a745 0deg, #ffc107 0deg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        
        .progress-circle-inner {
            width: 100px;
            height: 100px;
            background: white;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .progress-number {
            font-size: 2rem;
            font-weight: bold;
            color: #708090 100%;
        }
        
        .offline-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            background: #28a745;
            color: white;
            font-size: 0.9rem;
            z-index: 1000;
        }
        
        .offline-indicator.offline {
            background: #dc3545;
        }
    </style>
</head>
<body>
    <header>
        <!-- ваш header -->
    </header>
    
    <main class="container checklist-page">
        <h1>Чек-лист подготовки</h1>
        <p>Пациент: <?php echo $patient['full_name']; ?></p>
        <p>Операция: <?php echo $patient['surgery_type'] ?? 'Факоэмульсификация'; ?></p>
        
        <!-- Прогресс -->
        <div class="progress-circle">
            <div class="progress-circle-inner">
                <span class="progress-number"><?php echo $test_stats['uploaded'] ?? 0; ?>/<?php echo $test_stats['total'] ?? 8; ?></span>
                <span>готово</span>
            </div>
        </div>
        
        <!-- Здесь будет интерактивный чек-лист -->
        
        <div class="offline-indicator" id="offlineIndicator">
            🟢 Онлайн режим
        </div>
    </main>
    
    <script>
        // Индикатор оффлайн режима
        function updateOnlineStatus() {
            const indicator = document.getElementById('offlineIndicator');
            if (navigator.onLine) {
                indicator.innerHTML = '🟢 Онлайн режим';
                indicator.className = 'offline-indicator';
            } else {
                indicator.innerHTML = '🔴 Оффлайн режим (данные сохраняются)';
                indicator.className = 'offline-indicator offline';
            }
        }
        
        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();
    </script>
</body>
</html>