<?php
// DO NOT include header.php here
session_start();
require 'includes/dbh.inc.php';
require 'includes/functions.inc.php';

if (!isset($_SESSION['userid'])) { exit("Access Denied"); }

$myId = (int)$_SESSION['userid'];
$viewId = isset($_GET['user']) ? (int)$_GET['user'] : $myId;

// 1. Filename Setup (Restored: 00000000XX-yyyy-mm-dd-hh-ii-ss.csv)
$paddedUid = str_pad($viewId, 10, "0", STR_PAD_LEFT);
$timestamp = date("Y-m-d-H-i-s");
$fileName = $paddedUid . "-" . $timestamp . ".csv";

// 2. Clear buffer and set headers
if (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');

$output = fopen('php://output', 'w');

/**
 * Custom function to build the row exactly as requested:
 * "Field1", "Field2", "Field3";\r\n
 */
function writeCustomRow($handle, $fields) {
    $quotedFields = array_map(function($f) {
        $val = ($f === null || $f === "") ? "-" : $f;
        // Escape existing double quotes by doubling them
        $val = str_replace('"', '""', $val);
        return '"' . $val . '"';
    }, $fields);
    
    // Join with comma and space, end with semicolon and CRLF
    $line = implode(", ", $quotedFields) . ";\r\n";
    fwrite($handle, $line);
}

// --- PART A: Timestamp of Export ---
writeCustomRow($output, ["A", date("Y-m-d H:i:s")]);

// --- PART B: Old User Data (Historic) ---
$stmtB = $conn->prepare("SELECT * FROM users_old WHERE uid_old = ? ORDER BY usersOldId ASC");
$stmtB->bind_param("i", $viewId);
if ($stmtB->execute()) {
    $resB = $stmtB->get_result();
    while ($row = $resB->fetch_assoc()) {
        writeCustomRow($output, [
            "B", 
            $row['usersName_old'], $row['image_old'], $row['birthday_old'], 
            $row['gender_old'], $row['height_old'], $row['hair_old'], 
            $row['leftEye_old'], $row['rightEye_old'], 
            cleanForCsv($row['specialFeatures_old']), $row['start_old'], $row['Hash_old']
        ]);
    }
}
$stmtB->close();

// --- PART C: Current User Data ---
$stmtC = $conn->prepare("SELECT * FROM users WHERE usersId = ?");
$stmtC->bind_param("i", $viewId);
if ($stmtC->execute()) {
    $resC = $stmtC->get_result()->fetch_assoc();
    if ($resC) {
        writeCustomRow($output, [
            "C", 
            $resC['usersName'], $resC['image'], $resC['birthday'], 
            $resC['gender'], $resC['height'], $resC['hair'], 
            $resC['leftEye'], $resC['rightEye'], 
            cleanForCsv($resC['specialFeatures']), $resC['start'], 
            $resC['lastHash'], $resC['Hash']
        ]);
    }
}
$stmtC->close();

// --- PART D: Transactions ---
$stmtD = $conn->prepare("SELECT * FROM transactions WHERE giver = ? OR receiver = ? ORDER BY time_stamp ASC");
$stmtD->bind_param("ii", $viewId, $viewId);
if ($stmtD->execute()) {
    $resD = $stmtD->get_result();
    while ($row = $resD->fetch_assoc()) {
        writeCustomRow($output, [
            "D", 
            $row['giver'], $row['receiver'], $row['amount'], "", 
            cleanForCsv($row['description']), "", 
            $row['time_stamp'], $row['hashgiver'], $row['hashreceiver']
        ]);
    }
}
$stmtD->close();

fclose($output);
exit();
