<?php
require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ОКОЛО - Главная</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <div class="logo">
            <img src="assets/img/logo.png" alt="ОКОЛО" width="70" height="55">
            ОКОЛО
            <span>Цифровая платформа</span>
        </div>
        <nav>
            <div class="nav-links">
                <a href="index.php">Главная</a>
                <a href="login.php">Вход</a>
                <a href="register.php">Регистрация</a>
                <a href="check_status.php">Статус подготовки</a>
            </div>
        </nav>
    </header>

    <main class="container">
        <section class="welcome-section" style="text-align: center; padding: 4rem 0;">
            <h1 style="font-size: 3rem; margin-bottom: 1rem;">ОКОЛО</h1>
            <p style="font-size: 1.2rem; color: #666; max-width: 600px; margin: 0 auto;">
                Цифровая платформа для удалённой подготовки пациентов 
                к офтальмологическим операциям
            </p>
        </section>

        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stat-card">
                <h3>Районный офтальмолог</h3>
                <p>Подготовка пациентов, загрузка анализов, контроль готовности</p>
            </div>
            <div class="stat-card">
                <h3>Хирург-куратор</h3>
                <p>Проверка готовности, одобрение операций, обратная связь</p>
            </div>
            <div class="stat-card">
                <h3>Пациент</h3>
                <p>Просмотр статуса подготовки к операции</p>
            </div>
        </div>

        <div style="text-align: center; margin: 3rem 0;">
            <a href="login.php" class="btn" style="width: auto; padding: 1rem 3rem;">Войти в систему</a>
        </div>
    </main>

    <footer>
        <p>&copy; 2026 ОКОЛО. Все права защищены.</p>
    </footer>

    <script src="assets/js/script.js"></script>
</body>
</html>