<?php
session_start();
require "dbh.inc.php";

// Ensure the user is logged in
if (!isset($_SESSION["userid"])) {
    header("Location: ../index2.php");
    exit();
}

$myId = $_SESSION["userid"];

// Helper to handle the 'from' return path consistently
$from = $_GET['from'] ?? "";
$targetId = isset($_GET['target']) ? (int)$_GET['target'] : 0;
$encodedFrom = !empty($from) ? "&from=" . urlencode($from) : "";

// ===============================================
// 1. TOGGLE WHITELIST MODE (From Profile Page)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] == 'toggle') {
    $newMode = (int)$_GET['mode'];

    $sql = "UPDATE users SET useWhitelist = ? WHERE usersId = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $newMode, $myId);
    $stmt->execute();

    if ($newMode == 1 && isset($_GET['import']) && $_GET['import'] == 1) {
        $sqlImport = "INSERT IGNORE INTO lists (ownerId, targetId, listType)
                      SELECT DISTINCT ?, 
                      CASE WHEN giver = ? THEN receiver ELSE giver END, 
                      1 
                      FROM transactions 
                      WHERE giver = ? OR receiver = ?";
        
        $stmtI = $conn->prepare($sqlImport);
        $stmtI->bind_param("iiii", $myId, $myId, $myId, $myId);
        $stmtI->execute();
    }

    header("Location: ../profile.php?success=privacyupdated");
    exit();
}

// ===============================================
// 2. BLOCK AND CLEAR (From Agree Page $X=5)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] == 'block' && isset($_GET['proposal']) && $targetId > 0) {
    // 1. Add to lists table as Blacklisted (0)
    $sqlBlock = "INSERT IGNORE INTO lists (ownerId, targetId, listType) VALUES (?, ?, 0)";
    $stmtB = $conn->prepare($sqlBlock);
    $stmtB->bind_param("ii", $myId, $targetId);
    $stmtB->execute();

    // 2. NEW: Delete ALL proposals between these two users (both directions)
    // This satisfies the requirement to clear all requests upon blocking
    $sqlClear = "DELETE FROM proposals WHERE (giver = ? AND receiver = ?) OR (giver = ? AND receiver = ?)";
    $stmtC = $conn->prepare($sqlClear);
    $stmtC->bind_param("iiii", $myId, $targetId, $targetId, $myId);
    $stmtC->execute();

    header("Location: ../index2.php?success=blocked_and_cleared");
    exit();
}

// ===============================================
// 3. DIRECT BLOCK (Blacklist Mode - humandetails.php)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] == 'block_direct' && $targetId > 0) {
    $sqlBlock = "INSERT IGNORE INTO lists (ownerId, targetId, listType) VALUES (?, ?, 0)";
    $stmtB = $conn->prepare($sqlBlock);
    $stmtB->bind_param("ii", $myId, $targetId);
    $stmtB->execute();

    header("Location: ../humandetails.php?user=$targetId&success=blocked" . $encodedFrom);
    exit();
}

// ===============================================
// 4. UNBLOCK (Blacklist Mode - humandetails.php)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] == 'unblock' && $targetId > 0) {
    $sqlUnblock = "DELETE FROM lists WHERE ownerId = ? AND targetId = ? AND listType = 0";
    $stmtU = $conn->prepare($sqlUnblock);
    $stmtU->bind_param("ii", $myId, $targetId);
    $stmtU->execute();

    header("Location: ../humandetails.php?user=$targetId&success=unblocked" . $encodedFrom);
    exit();
}

// ===============================================
// 5. ALLOW (Whitelist Mode - humandetails.php)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] == 'allow_white' && $targetId > 0) {
    $sql = "INSERT IGNORE INTO lists (ownerId, targetId, listType) VALUES (?, ?, 1)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $myId, $targetId);
    $stmt->execute();

    header("Location: ../humandetails.php?user=$targetId&success=unblocked" . $encodedFrom);
    exit();
}

// ===============================================
// 6. REMOVE/DISALLOW (Whitelist Mode - humandetails.php)
// ===============================================
if (isset($_GET['action']) && $_GET['action'] == 'remove_white' && $targetId > 0) {
    $sql = "DELETE FROM lists WHERE ownerId = ? AND targetId = ? AND listType = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $myId, $targetId);
    $stmt->execute();

    header("Location: ../humandetails.php?user=$targetId&success=blocked" . $encodedFrom);
    exit();
}

// ===============================================
// 7. BULK MANAGEMENT (CORRECTED WITH CLEANUP)
// ===============================================
if (isset($_POST['action'])) {
    $listType = (int)$_POST['list_type'];

    if ($_POST['action'] == 'delete_selected' && isset($_POST['selected_targets'])) {
        $targets = $_POST['selected_targets'];
        $count = count($targets);
        $placeholders = implode(',', array_fill(0, $count, '?'));

        // A. Remove from the current list (Black or White)
        $sql = "DELETE FROM lists WHERE ownerId = ? AND listType = ? AND targetId IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $types = 'ii' . str_repeat('i', $count);
        $params = array_merge([$myId, $listType], $targets);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        // B. NEW: If we are in Whitelist mode (listType 1), deleting someone 
        // effectively blocks them. We must clear their requests.
        if ($listType === 1) {
            $sqlClear = "DELETE FROM proposals 
                         WHERE (giver = ? AND receiver IN ($placeholders)) 
                         OR (receiver = ? AND giver IN ($placeholders))";
            $stmtC = $conn->prepare($sqlClear);
            $typesC = 'i' . str_repeat('i', $count) . 'i' . str_repeat('i', $count);
            $paramsC = array_merge([$myId], $targets, [$myId], $targets);
            $stmtC->bind_param($typesC, ...$paramsC);
            $stmtC->execute();
        }
        
    } elseif ($_POST['action'] == 'clear_all') {
        // ... (existing clear_all logic)
    }

    header("Location: ../black-white-list.php?success=updated");
    exit();
}

header("Location: ../index2.php");
exit();