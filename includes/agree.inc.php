<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require 'dbh.inc.php';
require 'functions.inc.php';

// Initialize Translation Helper
global $translations;
$lang = $_SESSION['userlang'] ?? 'en'; // Assuming language is stored in session

$tr = function($key, $lang) use ($translations) {
    return $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
};

$pid = filter_input(INPUT_GET, 'proposal', FILTER_VALIDATE_INT);
$uid = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT);
$action = $_GET['action'] ?? "";

// Safety check: Unauthorized access
if (!isset($_SESSION['userid']) || $_SESSION['userid'] != $uid) {
    header("location: ../index2.php?error=unauthorized");
    exit();
}

// 1. Fetch Proposal Data
$pData = getProposalData($conn, $pid);
if (!$pData) {
    header("location: ../index2.php?error=proposalnotfound");
    exit();
}

if ($action == "pay") {
    // 2. Server-Side Fund Check (Decay-aware)
    $available = calculateAvailableCoins($conn, $pData['giver']);
    if ($available < $pData['amount']) {
        // Rollback: Deletion flow if insufficient funds
        deleteProposal($conn, $pid);
        
        // Translation for insufficient funds
        $reason = $tr('AG_ERR_FUNDS', $lang);
        sendDeletionEmail($pData, $reason);
        
        header("location: ../index2.php?error=insufficient_saldo");
        exit();
    }

    // 3. Abundomy Blockchain: Hash Calculation
    $t_now = date("Y-m-d H:i:s");
    $zeroHash = str_repeat("0", 64);

    // A. Chain for Giver (A)
    $lastT_A = getLastUserTransaction($conn, $pData['giver']);
    $hg_old_a = $lastT_A ? $lastT_A['hashgiver'] : $zeroHash;
    $hr_old_a = $lastT_A ? $lastT_A['hashreceiver'] : $zeroHash;
    
    $dataA = (string)$pData['giver'] . (string)$pData['receiver'] . (float)$pData['amount'] . 
             (string)$pData['description'] . (string)$t_now . (string)$hg_old_a . (string)$hr_old_a;
    $hashgiver_new = hash('sha256', $dataA);

    // B. Chain for Receiver (B)
    $lastT_B = getLastUserTransaction($conn, $pData['receiver']);
    $hg_old_b = $lastT_B ? $lastT_B['hashgiver'] : $zeroHash;
    $hr_old_b = $lastT_B ? $lastT_B['hashreceiver'] : $zeroHash;
    
    $dataB = (string)$pData['giver'] . (string)$pData['receiver'] . (float)$pData['amount'] . 
             (string)$pData['description'] . (string)$t_now . (string)$hg_old_b . (string)$hr_old_b;
    $hashreceiver_new = hash('sha256', $dataB);

    // 4. Save Transaction
    $sqlT = "INSERT INTO transactions (giver, receiver, amount, description, time_stamp, hashgiver, hashreceiver) VALUES (?, ?, ?, ?, ?, ?, ?);";
    $stmtT = mysqli_stmt_init($conn);
    if (mysqli_stmt_prepare($stmtT, $sqlT)) {
        mysqli_stmt_bind_param($stmtT, "iidssss", $pData['giver'], $pData['receiver'], $pData['amount'], $pData['description'], $t_now, $hashgiver_new, $hashreceiver_new);
        mysqli_stmt_execute($stmtT);
        $newTid = mysqli_insert_id($conn);
        mysqli_stmt_close($stmtT);
    }

    // 5. Generate and Save CSV Logs to csvFiles Table
    $idA_Content = getUserCSVString($conn, $pData['giver']);
    $idB_Content = getUserCSVString($conn, $pData['receiver']);
    $transA_Content = getTransactionCSVString($conn, $pData['giver']);
    $transB_Content = getTransactionCSVString($conn, $pData['receiver']);

    $sqlCSV = "INSERT INTO csvFiles (tid, giver, receiver, idA, idB, transactionsA, transactionsB) VALUES (?, ?, ?, ?, ?, ?, ?);";
    $stmtCSV = mysqli_stmt_init($conn);
    if (mysqli_stmt_prepare($stmtCSV, $sqlCSV)) {
        mysqli_stmt_bind_param($stmtCSV, "iiissss", $newTid, $pData['giver'], $pData['receiver'], $idA_Content, $idB_Content, $transA_Content, $transB_Content);
        mysqli_stmt_execute($stmtCSV);
        mysqli_stmt_close($stmtCSV);
    }

    // 6. Cleanup and Notification
    deleteProposal($conn, $pid);
    sendPaymentEmail($pData);
    header("location: ../index2.php?success=paid");
    exit();

} else {
    // Action is DELETE
    // Detect if the person deleting is the Giver or Receiver
    if ($_SESSION['userid'] == $pData['giver']) {
        $reason = sprintf($tr('AG_REASON_GIVER', $lang), $_SESSION['useruid']);
    } else {
        $reason = sprintf($tr('AG_REASON_RECEIVER', $lang), $_SESSION['useruid']);
    }

    deleteProposal($conn, $pid);
    sendDeletionEmail($pData, $reason);
    header("location: ../index2.php?success=deleted");
    exit();
}
