<?php
session_start();
if (isset($_SESSION["userid"]) && isset($_GET['type']) && isset($_GET['mode'])) {
    require_once 'dbh.inc.php';
    
    $userId = $_SESSION["userid"];
    $type = $_GET['type']; // 'newsletter' or 'paymentEmails'
    $mode = (int)$_GET['mode'];

    // Only allow specific column names for security
    if ($type === 'newsletter' || $type === 'paymentEmails') {
        $sql = "UPDATE users SET $type = ? WHERE usersId = ?;";
        $stmt = mysqli_stmt_init($conn);
        if (mysqli_stmt_prepare($stmt, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $mode, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    header("location: ../profile.php");
    exit();
} else {
    header("location: ../index2.php");
    exit();
}