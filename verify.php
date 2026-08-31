<?php
require_once 'header.php'; // Includes session_start and dbh.inc.php

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // 1. Find the pending request
    $sql = "SELECT * FROM pending_changes WHERE token = ?;";
    $stmt = mysqli_stmt_init($conn);
    if (!mysqli_stmt_prepare($stmt, $sql)) {
        header("Location: index2.php?error=stmtfailed");
        exit();
    }
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($pending = mysqli_fetch_assoc($result)) {
        $userId = $pending['usersId'];
        
        // 2. Get current user data for the integrity chain
        $userSql = "SELECT * FROM users WHERE usersId = ?;";
        $userStmt = mysqli_stmt_init($conn);
        mysqli_stmt_prepare($userStmt, $userSql);
        mysqli_stmt_bind_param($userStmt, "i", $userId);
        mysqli_stmt_execute($userStmt);
        $userData = mysqli_fetch_assoc(mysqli_stmt_get_result($userStmt));

        // 3. Determine new values (use old ones if pending is null)
        $finalEmail = $pending['newEmail'] ?? $userData['usersEmail'];
        $finalUid = $pending['newUid'] ?? $userData['usersUid'];
        $mutationDate = date("Y-m-d H:i:s");

        // 4. Update Audit Logic (Similar to profile.inc.php)
        // Archive the old email/uid before they are overwritten
        $auditEmail = ($finalEmail !== $userData['usersEmail']) ? $userData['usersEmail'] : "";
        $auditUid = ($finalUid !== $userData['usersUid']) ? $userData['usersUid'] : "";

        $sqlOld = "INSERT INTO usersOldId (usersName_old, mutationDate, hash_old) VALUES (?, ?, ?);";
        // Note: You may want to expand this to include auditEmail_old/auditUid_old if your table supports it.
        $stmtOld = mysqli_stmt_init($conn);
        mysqli_stmt_prepare($stmtOld, $sqlOld);
        mysqli_stmt_bind_param($stmtOld, "sss", $userData['usersName'], $mutationDate, $userData['hash']);
        mysqli_stmt_execute($stmtOld);

        // 5. Generate New Integrity Hash
        $lastHash = $userData['hash'];
        // Concatenate in same order as profile.inc.php
        $dataToHash = $userData['usersName'] . $userData . $userData['birthday'] . 
                      $userData['gender'] . $userData['height'] . $userData['hair'] . 
                      $userData['leftEye'] . $userData['rightEye'] . $userData['specialFeatures'] . 
                      $userData['start'] . $mutationDate . $lastHash;
        $newHash = hash('sha256', $dataToHash);

        // 6. Final Update to Users Table
        $updateSql = "UPDATE users SET usersEmail = ?, usersUid = ?, lastHash = ?, hash = ? WHERE usersId = ?;";
        $updStmt = mysqli_stmt_init($conn);
        mysqli_stmt_prepare($updStmt, $updateSql);
        mysqli_stmt_bind_param($updStmt, "ssssi", $finalEmail, $finalUid, $lastHash, $newHash, $userId);
        mysqli_stmt_execute($updStmt);

        // 7. Cleanup: Delete the pending record
        $delSql = "DELETE FROM pending_changes WHERE token = ?;";
        $delStmt = mysqli_stmt_init($conn);
        mysqli_stmt_prepare($delStmt, $delSql);
        mysqli_stmt_bind_param($delStmt, "s", $token);
        mysqli_stmt_execute($delStmt);

        header("Location: index2.php?error=none&verify=success");
        exit();
    } else {
        header("Location: index2.php?error=invalidtoken");
        exit();
    }
} else {
    header("Location: index2.php");
    exit();
}
