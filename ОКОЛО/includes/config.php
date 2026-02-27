<?php
session_start();

$host = 'localhost';
$dbname = 'okulus_feldsher';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Для отладки - раскомментируйте если нужно проверить подключение
    // echo "Подключение к БД успешно!";
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Функция для получения названия роли
function getRoleName($role) {
    $roles = [
        'patient' => 'Пациент',
        'ophthalmologist' => 'Районный офтальмолог',
        'surgeon' => 'Хирург-куратор'
    ];
    return $roles[$role] ?? $role;
}

// Функция для получения статуса операции
function getSurgeryStatus($status) {
    $statuses = [
        'new' => 'Новый',
        'preparation' => 'На подготовке',
        'review' => 'На проверке',
        'approved' => 'Одобрен',
        'rejected' => 'Отклонен'
    ];
    return $statuses[$status] ?? $status;
}

// Функция для получения статуса анализа
function getTestStatus($status) {
    $statuses = [
        'pending' => 'Ожидает',
        'uploaded' => 'Загружен',
        'approved' => 'Принят',
        'rejected' => 'Отклонен'
    ];
    return $statuses[$status] ?? $status;
}

// Функция для форматирования даты
function formatDate($date, $format = 'd.m.Y') {
    if (!$date) return 'Не назначена';
    return date($format, strtotime($date));
}
?>