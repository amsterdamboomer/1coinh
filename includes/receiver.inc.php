<?php
    session_start();
    if (!isset($_POST['submit'])) {
        header("location: ../index2.php");
        exit();
    }

    require 'dbh.inc.php';
    require 'functions.inc.php';

    $targetGiverId = $_POST['giver_id']; 
    $myId = $_SESSION['userid'];
    $rawAmount = $_POST['amount']; // Keep the raw string with spaces for the redirect
    $amount = (float)str_replace(' ', '', $rawAmount);
    $description = $_POST['description'];

    // Create a query string to keep the form filled on error
    $reFill = "&amount=" . urlencode($rawAmount) . "&desc=" . urlencode($description);

    // --- 1. VALIDATE DESCRIPTION ---
    $cleanDesc = strip_tags($description);
    preg_match_all('/[a-zA-Z]/', $cleanDesc, $matches);
    $letterCount = count($matches[0]);

    if (empty($description) || $cleanDesc !== $description || $letterCount < 4) {
        header("location: ../receiver.php?user=$targetGiverId&error=desc_insufficient" . $reFill);
        exit();
    }

    // --- 2. CHECK PROPOSAL LIMITS ---
    if (countPendingProposals($conn, $targetGiverId) >= 5) {
        header("location: ../receiver.php?user=$targetGiverId&error=target_limit" . $reFill);
        exit();
    }
    if (countPendingProposals($conn, $myId) >= 5) {
        header("location: ../receiver.php?user=$targetGiverId&error=user_limit" . $reFill);
        exit();
    }

    // --- 3. FETCH & VERIFY INTEGRITY ---
    $giverData = getUserData($conn, $targetGiverId);
    $myData = getUserData($conn, $myId);

    if (!$giverData || !$myData) {
        header("location: ../index2.php?error=usernotfound");
        exit();
    }

    if (!verifyIntegrity($giverData) || !verifyIntegrity($myData)) {
        header("location: ../index2.php?error=hashmismatch");
        exit();
    }

    // --- 4. RE-CALCULATE FUNDS ---
    $available = calculateAvailableCoins($conn, $targetGiverId); 
    if ($amount > $available) {
        header("location: ../receiver.php?user=$targetGiverId&error=insufficient_funds" . $reFill);
        exit();
    }

    // --- 5. SAVE TO DATABASE ---
    $timeStamp = date("Y-m-d H:i:s");
    $sql = "INSERT INTO proposals (giver, receiver, amount, description, time_stamp, hashgiver, hashreceiver) VALUES (?, ?, ?, ?, ?, ?, ?);";
    $stmt = mysqli_stmt_init($conn);

    if (mysqli_stmt_prepare($stmt, $sql)) {
        mysqli_stmt_bind_param($stmt, "iidssss", $targetGiverId, $myId, $amount, $description, $timeStamp, $giverData['hash'], $myData['hash']);
        mysqli_stmt_execute($stmt);
        $proposalId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        // --- 6. SEND EMAILS ---
        $emailSuccess = sendProposalEmails($giverData, $myData, $amount, $description);
        unset($_SESSION['receiver_data']);
        if ($emailSuccess) {
            header("location: ../index2.php?success=proposal_sent&name=" . urlencode($giverData['usersName']));
        } else {
            // ROLLBACK
            $sqlDel = "DELETE FROM proposals WHERE pid = ?;";
            $stmtDel = $conn->prepare($sqlDel);
            $stmtDel->bind_param("i", $proposalId);
            $stmtDel->execute();
            // Pass data back even on email failure
            header("location: ../receiver.php?user=$targetGiverId&error=email_issue" . $reFill);
        }
        exit();
    }