<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
requireLogin();

$patient_id = $_GET['patient_id'] ?? 0;

// Получаем медиафайлы пациента
$stmt = $pdo->prepare("
    SELECT m.*, u.full_name as patient_name
    FROM media m
    JOIN patients p ON m.patient_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE m.patient_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$patient_id]);
$media = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Медиатека пациента</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .media-page {
            max-width: 1200px;
            margin: 2rem auto;
        }
        
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
    </style>
</head>
<body>
    <main class="container media-page">
        <h1>Медиатека</h1>
        <div class="media-grid">
            <?php foreach ($media as $item): ?>
            <div class="media-item">
                <img src="<?php echo $item['file_path']; ?>" alt="Медиа">
                <p><?php echo $item['file_name']; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>