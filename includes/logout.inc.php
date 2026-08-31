<?php
// 1. Setup the session cookie params to match header.php BEFORE starting the session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => '/', 'samesite' => 'Lax']);
    session_start();
}

// 2. Unset all session variables
$_SESSION = array();

// 3. Delete the session cookie thoroughly
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Finally, destroy the session server-side
session_destroy();

// 5. Redirect based on the reason
if (isset($_GET['reason']) && $_GET['reason'] == 'timeout') {
    header("Location: ../index2.php?error=timeout");
} else {
    // Standard logout from the button
    header("Location: ../index2.php");
}

exit();
