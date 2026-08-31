<?php
    require "header.php";

    $pid = filter_input(INPUT_GET, 'proposal', FILTER_VALIDATE_INT) ?: 0;
    $uid = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT) ?: 0;
    $X = filter_input(INPUT_GET, 'button', FILTER_VALIDATE_INT) ?: 1;

    if (!isset($_SESSION['userid']) || $_SESSION['userid'] != $uid) {
        header("location: index2.php?error=unauthorized");
        exit();
    }

    $sql = "SELECT p.*, 
            uG.usersName as giverName, uG.image as giverImage, uG.usersId as giverId,
            uR.usersName as receiverName, uR.image as receiverImage, uR.usersId as receiverId
            FROM proposals p
            JOIN users uG ON p.giver = uG.usersId
            JOIN users uR ON p.receiver = uR.usersId
            WHERE p.pid = ?;";

    $stmt = mysqli_stmt_init($conn);
    mysqli_stmt_prepare($stmt, $sql);
    mysqli_stmt_bind_param($stmt, "i", $pid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $pData = mysqli_fetch_assoc($result);

    if (!$pData) {
        header("location: index2.php?error=proposalnotfound");
        exit();
    }

    $isGiver = ($uid == $pData['giverId']);
    $partnerName = $isGiver ? $pData['receiverName'] : $pData['giverName'];
    $partnerImage = $isGiver ? $pData['receiverImage'] : $pData['giverImage'];
    $partnerId = $isGiver ? $pData['receiverId'] : $pData['giverId'];

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='medium_line'></div>";
            
            echo "<div class='button4-row'>";
                echo "<div class='button4-column1'></div>";
                echo "<div class='button4-column2'>";
                    // TRANSLATED TITLE: REQUEST FROM / MY REQUEST TO
                    $titleKey = $isGiver ? 'AGR_TITLE_FROM' : 'AGR_TITLE_TO';
                    echo "<p class='title'>" . mb_strtoupper(t($titleKey), 'UTF-8') . "</p>";
                echo "</div>";
                echo "<div class='button4-column3'>";
                    echo "<a href='index2.php'><button type='button' class='login-button'>".t('PR_BACK')."</button></a>";
                echo "</div>";
            echo "</div>";
            echo "<div class='full_line'></div>";

            echo "<div class='medium_line'></div>";

            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'>";
                    $returnPath = urlencode("agree.php?proposal=$pid&user=$uid&button=$X");
                    echo "<p class='title'>";
                        echo "<a href='humandetails.php?user=" . (int)$partnerId . "&from=$returnPath'>";
                            echo "<img src='$partnerImage' class='humanicon2' />";
                        echo "</a>";
                    echo "</p>";
                echo "</div>";
            echo "</div>";

            echo "<div class='small_line'></div>";
            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'>";
                    echo "<p class='title'>" . htmlspecialchars($partnerName) . "</p>";
                echo "</div>";
            echo "</div>";
            echo "<div class='medium_line'></div>";

            echo "<div class='medium_line'></div>";
            // TRANSLATED LABEL: TO PAY / TO PAY ME
            echo "<p class='feedback3'>" . ($isGiver ? t('AGR_LABEL_PAY') : t('AGR_LABEL_PAY_ME')) . "</p>";
            echo "<div class='medium_line'></div>";
            
            // LOCALIZED DATE
            $dateObj = new DateTime($pData['time_stamp']);
            $formattedDate = $dateObj->format("j") . " " . t("M_" . $dateObj->format("n")) . " " . $dateObj->format("Y | g:i:s A");
            
            echo "<p class='feedback3'>" . number_format($pData['amount'], 2) . " ᕫ</p>";
            echo "<div class='medium_line'></div>";
            echo "<p class='feedback4'>" . $formattedDate . "</p>";
            echo "<div class='medium_line'></div>";
            echo "<textarea class='special-features' id='agree_desc' readonly style='resize:none;'>" . htmlspecialchars($pData['description']) . "</textarea>";
            echo "<div class='full_line'></div>";

            // ... (Top logic stays the same) ...

            echo "<div class='signupdiv'>";
                // 1. SELECTION: DELETE | BLOCK (Triggered by index Cancel button)
                if ($X == 6) {
                    echo "<div class='small_line'></div>";
                    echo "<div class='button4-row' style='background: var(--background);'>";
                        echo "<div class='button4-column1'>";
                            // Points to Delete confirmation (X=4)
                            echo "<a href='agree.php?proposal=$pid&user=$uid&button=4'><button class='login-button'>".t('AGR_BTN_DELETE')."</button></a>";
                        echo "</div>";
                        echo "<div class='button4-column2'></div>";
                        echo "<div class='button4-column3'>";
                            // Points to Block confirmation (X=5)
                            echo "<a href='agree.php?proposal=$pid&user=$uid&button=5'><button type='button' class='error-button'>".t('AGR_BTN_BLOCK')."</button></a>";
                        echo "</div>";
                    echo "</div>";
                }
                
                // 2. CONFIRM DELETE
                elseif ($X == 2 || $X == 4) {
                    echo "<p class='feedback'>".t('AGR_CONFIRM_DEL')."</p>";
                    $confirmUrl = "includes/agree.inc.php?proposal=$pid&user=$uid&action=delete";
                } 
                
                // 3. CONFIRM PAY (Triggered by index Pay button/Box)
                elseif ($X == 3) {
                    echo "<p class='feedback'>".t('AGR_CONFIRM_PAY')."</p>";
                    $confirmUrl = "includes/agree.inc.php?proposal=$pid&user=$uid&action=pay";
                } 
                
                // 4. CONFIRM BLOCK
                elseif ($X == 5) {
                    echo "<p class='feedback'>".t('AGR_CONFIRM_BLK_1')." $partnerName</p>";
                    echo "<p class='feedback'>".t('AGR_CONFIRM_BLK_2')."</p>";
                    
                    // Pending requests logic
                    $sqlCheck = "SELECT COUNT(*) as total FROM proposals WHERE (giver = ? AND receiver = ?) OR (giver = ? AND receiver = ?)";
                    $stmtC = $conn->prepare($sqlCheck);
                    $stmtC->bind_param("iiii", $uid, $partnerId, $partnerId, $uid);
                    $stmtC->execute();
                    $pendingCount = $stmtC->get_result()->fetch_assoc()['total'];

                    if ($pendingCount > 1) {
                        $warnMsg = str_replace('', $pendingCount, t('AGR_WARN_BLK'));
                        echo "<p class='feedback' style='color:var(--error);'>$warnMsg</p>";
                    }
                    $confirmUrl = "includes/list-handler.inc.php?action=block&target=$partnerId&proposal=$pid";
                }

                // --- THE "YES, PROCEED!" BUTTON ---
                // Visible for states 2, 3, 4, 5
                if ($X > 1 && $X != 6) {
                    echo "<div class='small_line'></div>";
                    echo "<div class='centered-button-wrap'>";
                        echo "<a href='$confirmUrl'><button class='login-button'>".t('AGR_PROCEED')."</button></a>";
                    echo "</div>";
                }
            echo "</div>";

        echo "</div>";
    echo "</div>";
?>
</body>
</html>
