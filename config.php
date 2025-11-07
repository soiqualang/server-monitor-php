<?php
session_start();

// Cấu hình PIN (thay đổi mã PIN này)
define('ADMIN_PIN', '123456'); // ĐỔI MÃ PIN NÀY!

// Thời gian timeout session (phút)
define('SESSION_TIMEOUT', 30);

// Kiểm tra authentication
function checkAuth() {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }
    
    // Kiểm tra timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > (SESSION_TIMEOUT * 60))) {
        session_unset();
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

// Xử lý login
function processLogin($pin) {
    if ($pin === ADMIN_PIN) {
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        return true;
    }
    return false;
}

// Logout
function processLogout() {
    session_unset();
    session_destroy();
}
?>