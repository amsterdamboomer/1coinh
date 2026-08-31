<?php

$serverName = "localhost";
$dBUsername = "root";
$dBPassword = "";
$dBName = "1CoinH";

$conn = mysqli_connect($serverName, $dBUsername, $dBPassword, $dBName);

if (!$conn) {
    // Log errors internally; don't show specific details to users
    error_log("Connection failed: " . mysqli_connect_error());
    die("A database error occurred. Please try again later.");
}