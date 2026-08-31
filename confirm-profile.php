<?php
    require_once "includes/dbh.inc.php";

    // 1. PRE-HEADER LOOKUP: Find the user language based on the token
    if (isset($_GET["token"])) {
        $token = $_GET["token"];
        
        $sqlLang = "SELECT u.language FROM users u 
                    JOIN pending_changes p ON u.usersId = p.usersId 
                    WHERE p.token = ? LIMIT 1;";
        
        $stmtL = mysqli_stmt_init($conn);
        if (mysqli_stmt_prepare($stmtL, $sqlLang)) {
            mysqli_stmt_bind_param($stmtL, "s", $token);
            mysqli_stmt_execute($stmtL);
            $resL = mysqli_stmt_get_result($stmtL);
            if ($rowL = mysqli_fetch_assoc($resL)) {
                if (session_status() === PHP_SESSION_NONE) { session_start(); }
                // Set the language for the whole site session
                $_SESSION['user_lang'] = $rowL['language'];
                // Force write the session before the header/redirects happen
                session_write_close(); 
            }
            mysqli_stmt_close($stmtL);
        }
    }

    require_once "header.php"; // To keep the site look and translations

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";

    if (isset($_GET["token"])) {
        $token = $_GET["token"];
        $now = date("Y-m-d H:i:s");

        // 1. Check if token exists and is not expired (1 hour limit)
        $sql = "SELECT * FROM pending_changes WHERE token = ? AND requestedAt >= DATE_SUB(?, INTERVAL 1 HOUR);";
        $stmt = mysqli_stmt_init($conn);
        
        if (!mysqli_stmt_prepare($stmt, $sql)) {
            echo "<p class='error'>" . t('PR_ERR_DB') . "</p>";
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $token, $now);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($result)) {
                $userId = $row['usersId'];
                $newEmail = $row['newEmail'];
                $newUid = $row['newUid'];

                // 2. Prepare the Update for the main users table
                // We only update the fields that were actually changed (not null)
                $updateFields = [];
                $params = [];
                $types = "";

                if (!empty($newEmail)) {
                    $updateFields[] = "usersEmail = ?";
                    $params[] = $newEmail;
                    $types .= "s";
                }
                if (!empty($newUid)) {
                    $updateFields[] = "usersUid = ?";
                    $params[] = $newUid;
                    $types .= "s";
                }

                if (!empty($updateFields)) {
                    $sqlUpd = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE usersId = ?;";
                    $params[] = $userId;
                    $types .= "i";

                    $stmtUpd = mysqli_stmt_init($conn);
                    if (mysqli_stmt_prepare($stmtUpd, $sqlUpd)) {
                        mysqli_stmt_bind_param($stmtUpd, $types, ...$params);
                        mysqli_stmt_execute($stmtUpd);
                        
                        // Clean up the pending_changes table
                        $sqlDel = "DELETE FROM pending_changes WHERE usersId = ?;";
                        $stmtDel = mysqli_stmt_init($conn);
                        mysqli_stmt_prepare($stmtDel, $sqlDel);
                        mysqli_stmt_bind_param($stmtDel, "i", $userId);
                        mysqli_stmt_execute($stmtDel);

                        // --- THE FIX: Ensure session is cleared so user MUST log in again ---
                        unset($_SESSION['userid']);
                        unset($_SESSION['form_data']);

                        echo "<p class='title'>" . t('PR_SUCCESS_CONFIRM') . "</p>";
                        echo "<p class='feedback'>" . t('PR_LOGIN_READY') . "</p>";
                        // Point to a clean index return
                        echo "<br><a href='index2.php?error=profileready'><button class='login-button'>" . t('NP_BTN_BACK') . "</button></a>";
                    }

                }
            } else {
                // Token invalid or expired
                echo "<p class='error'>" . t('PR_ERR_EXPIRED_TOKEN') . "</p>";
                echo "<p class='error'>" . t('PR_ERR_NEW_REQ') . "</p>";
                echo "<br><a href='profile.php'><button class='login-button'>" . t('PR_BACK') . "</button></a>";
            }
        }
    } else {
        header("Location: index2.php");
        exit();
    }

        echo "</div>";
    echo "</div>";
?>
