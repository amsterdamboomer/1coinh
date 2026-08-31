<?php
    require "header.php";

    // 1. Grab token from PATH_INFO (everything after .php/)
    $token = "";
    if (isset($_SERVER['PATH_INFO'])) {
        $token = trim($_SERVER['PATH_INFO'], '/');
    }

    // --- INITIALIZE VARIABLES ---
    $title = t('AC_SUCCESS');
    $targetPage = "profile.php";
    $statusType = "feedback";
    $messages = [];

    // 2. Initial check: Is the token empty?
    if (empty($token)) {
        $title = t('AC_TITLE_LINK_ERR');
        $targetPage = "index2.php";
        $statusType = "error";
        $messages = [t('AC_ERR_TOKEN_MISSING'), t('AC_TRY_AGAIN')];
    } else {
        $currentTime = date("Y-m-d H:i:s");

        // 3. Database check
        $sql = "SELECT * FROM pending_changes WHERE token = ? AND requestedAt > DATE_SUB(?, INTERVAL 1 HOUR);";
        $stmt = mysqli_stmt_init($conn);
        if (!mysqli_stmt_prepare($stmt, $sql)) {
            $title = t('AC_TITLE_DB_ERR');
            $statusType = "error";
            $messages = [t('AC_ERR_DB_FAILED'), t('AC_TRY_AGAIN')];
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $token, $currentTime);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($result)) {
                $userId = $row['usersId'];
                $newEmail = $row['newEmail'];
                $newUid = $row['newUid'];

                // 4. Final collision check
                $sqlCheck = "SELECT usersId FROM users WHERE (usersEmail = ? OR usersUid = ?) AND usersId <> ?;";
                $stmtCheck = mysqli_stmt_init($conn);
                mysqli_stmt_prepare($stmtCheck, $sqlCheck);
                mysqli_stmt_bind_param($stmtCheck, "ssi", $newEmail, $newUid, $userId);
                mysqli_stmt_execute($stmtCheck);
                $resCheck = mysqli_stmt_get_result($stmtCheck);

                if (mysqli_num_rows($resCheck) > 0) {
                    $title = t('AC_TITLE_UPDATE_ERR');
                    $statusType = "error";
                    $messages = [t('AC_ERR_TAKEN'), t('AC_TRY_AGAIN')];
                } else {
                    // 5. Update the Users Table
                    $updateSql = "UPDATE users SET usersEmail = COALESCE(?, usersEmail), usersUid = COALESCE(?, usersUid) WHERE usersId = ?;";
                    $stmtUpd = mysqli_stmt_init($conn);
                    mysqli_stmt_prepare($stmtUpd, $updateSql);
                    mysqli_stmt_bind_param($stmtUpd, "ssi", $newEmail, $newUid, $userId);
                    if (mysqli_stmt_execute($stmtUpd)) {
                        // Cleanup: Remove the used token
                        $delSql = "DELETE FROM pending_changes WHERE pcid = ?;";
                        $stmtDel = mysqli_stmt_init($conn);
                        mysqli_stmt_prepare($stmtDel, $delSql);
                        mysqli_stmt_bind_param($stmtDel, "i", $row['pcid']);
                        mysqli_stmt_execute($stmtDel);

                        // Refresh Session
                        if (isset($_SESSION["userid"]) && $_SESSION["userid"] == $userId) {
                            if ($newEmail) $_SESSION["useremail"] = $newEmail;
                            if ($newUid) $_SESSION["useruid"] = $newUid;
                        }

                        // REDIRECT instead of echoing success here
                        header("Location: /profile.php?error=none");
                        exit();
                    }
                }
            } else {
                $title = t('AC_TITLE_LINK_ERR');
                $statusType = "error";
                $messages = [t('AC_ERR_EXPIRED'), t('AC_TRY_AGAIN')];
            }
        }
    }

    // --- RENDER LAYOUT ---
    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='full_line'></div>";
            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'><p class='title'>$title</p></div>";
            echo "</div>";
            echo "<div class='full_line'></div>";
            echo "<div class='button3-row'>";
                echo "<div class='button3-column1'></div>";
                echo "<div class='button3-column2'>";
                    echo "<br><a href='$targetPage'><button type='button' class='login-button'>".t('AC_BTN_CONTINUE')."</button></a>";
                echo "</div>";
            echo "</div>";
            echo "<div class='full_line'></div>";
            foreach ($messages as $msg) {
                echo "<p class='$statusType'>$msg</p>";
            }
            echo "<div class='full_line'></div>";
        echo "</div>";
    echo "</div>";
?>
</body>
</html>