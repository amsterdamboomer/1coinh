<?php
session_start();

if (isset($_POST["submit"])) {
    $username = $_POST["uid"];
    $pwd = $_POST["pwd"];

    require_once 'dbh.inc.php';
    require_once 'functions.inc.php';

    // 1. Basic validation: Check if fields are empty (Native PHP check)
    if (empty($username) || empty($pwd)) {
        header("location: ../index2.php?error=emptyinput");
        exit();
    }

    // 2. Execute the login logic
    loginuser($conn, $username, $pwd);

} else {
    header("location: ../index2.php");
    exit();
}