
<?php
// includes/auth.php
require_once __DIR__ . '/config.php';  // Используем __DIR__ для правильного пути

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isPatient() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'patient';
}

function isOphthalmologist() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'ophthalmologist';
}

function isSurgeon() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'surgeon';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php'); // Просто login.php без BASE_URL
        exit();
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header('Location: dashboard.php'); // Просто dashboard.php без BASE_URL
        exit();
    }
}
?>