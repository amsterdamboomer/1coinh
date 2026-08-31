<?php
// check-balance.php
session_start(['read_and_close' => true]); 

require "includes/dbh.inc.php";
require "includes/functions.inc.php";

if (!isset($_SESSION["userid"])) {
    echo json_encode(["status" => "error"]);
    exit();
}

$uid = $_SESSION["userid"];

$currentBalance = calculateUserBalance($conn, $uid); 

$sqlCount = "SELECT COUNT(pid) as total FROM proposals WHERE giver = ? OR receiver = ?";
$stmtC = $conn->prepare($sqlCount);
$stmtC->bind_param("ii", $uid, $uid);
$stmtC->execute();
$countData = $stmtC->get_result()->fetch_assoc();
$pCount = $countData['total'] ?? 0;

$sql = "SELECT t.*, u.image as partnerImage 
        FROM transactions t 
        JOIN users u ON (t.giver = u.usersId OR t.receiver = u.usersId)
        WHERE (t.giver = ? OR t.receiver = ?) AND u.usersId != ?
        ORDER BY t.time_stamp DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $uid, $uid, $uid);
$stmt->execute();
$lastT = $stmt->get_result()->fetch_assoc();

// Use the translation keys for the type
$typeKey = (isset($lastT['giver']) && $lastT['giver'] == $uid) ? 'CB_SENT' : 'CB_RECEIVED';

echo json_encode([
    "status" => "success",
    "userId" => $uid,
    "balance" => $currentBalance,
    "proposalCount" => $pCount,
    "lastTid" => $lastT['tid'] ?? 0,
    "amount" => $lastT['amount'] ?? 0,
    "type" => t($typeKey), // This will return the translated word
    "image" => $lastT['partnerImage'] ?? ''
]);
