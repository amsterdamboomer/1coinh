<?php
    require "header.php";

    $myId = $_SESSION['userid'];
    $tid = filter_input(INPUT_GET, 'tid', FILTER_VALIDATE_INT) ?: 0;
    // This is the path back to the main transactions list
    $from = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_URL) ?: "transactions.php";

    // Fetch transaction data and user images
    $sql = "SELECT t.*, 
            uG.usersName as giverName, uG.image as giverImg, uG.usersId as giverId,
            uR.usersName as receiverName, uR.image as receiverImg, uR.usersId as receiverId
            FROM transactions t
            JOIN users uG ON t.giver = uG.usersId
            JOIN users uR ON t.receiver = uR.usersId
            WHERE t.tid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tid);
    $stmt->execute();
    $tData = $stmt->get_result()->fetch_assoc();

    if (!$tData) { header("Location: index2.php"); exit(); }

    // Prepare a return path for the clickable images:
    // It encodes the current page state so the Back button on Profile/HumanDetails knows to come BACK here.
    $currentDetailsPath = urlencode("transactiondetails.php?tid=$tid&from=" . urlencode($from));

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            
            echo "<div class='button4-row'>";
                echo "<div class='button4-column1'></div>";
                // Translated Title
                echo "<div class='button4-column2'><p class='title'>" . mb_strtoupper(t('TD_TITLE'), 'UTF-8') . "</p></div>";
                echo "<div class='button4-column3'>";
                    // Reused Back button key
                    echo "<a href='$from'><button type='button' class='login-button'>" . t('PR_BACK') . "</button></a>";
                echo "</div>";
            echo "</div>";
            echo "<div class='full_line'></div>";

            echo "<div class='signupdiv' style='text-align:left;'>";
                
                // DATE - Localized Month
                echo "<label class='form__label'>" . t('TD_DATE') . "</label>";
                $ts = strtotime($tData['time_stamp']);
                $dateStr = date("j", $ts) . " " . t("M_" . date("n", $ts)) . " " . date("Y | g:i A", $ts);
                echo "<textarea class='amounttextarea' readonly>" . $dateStr . "</textarea>";
                echo "<div class='small_line'></div>";

                // DESCRIPTION
                echo "<label class='form__label'>" . t('TD_DESC') . "</label>";
                echo "<textarea class='descriptiontextarea' readonly style='resize: none;'>" . htmlspecialchars($tData['description']) . "</textarea>";
                echo "<div class='small_line'></div>";
                
                // AMOUNT
                echo "<label class='form__label'>" . t('TD_AMT') . "</label>";
                echo "<textarea class='amounttextarea' readonly>" . number_format($tData['amount'], 2) . " ᕫ</textarea>";
                echo "<div class='small_line'></div>";

                // --- FROM SECTION ---
                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        echo "<p class='form__label'>" . t('TD_FROM') . "&nbsp&nbsp</p>";
                    echo "</div>";
                echo "</div>";
                echo "<div class='small_line'></div>";
                        
                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        $giverId = (int)$tData['giverId'];
                        $giverTarget = ($giverId == $myId) ? "profile.php?from=$currentDetailsPath" : "humandetails.php?user=$giverId&from=$currentDetailsPath";
                        
                        echo "<p class='title'>";
                            // Preserved ['giverImg'] key exactly
                            echo "<a href='$giverTarget'><img src='" . htmlspecialchars($tData['giverImg']) . "' class='humanicon2' /></a>";
                        echo "</p><br>";
                        echo "<div class='small_line'></div>";
                    echo "</div>";
                echo "</div>";

                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        echo "<p class='title'>" . htmlspecialchars($tData['giverName']) . "</p><br>";
                    echo "</div>";
                echo "</div>";

                // --- TO SECTION ---
                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        echo "<p class='form__label'>" . t('TD_TO') . "&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp</p>";
                    echo "</div>";
                echo "</div>";
                echo "<div class='small_line'></div>";

                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        $receiverId = (int)$tData['receiverId'];
                        $receiverTarget = ($receiverId == $myId) ? "profile.php?from=$currentDetailsPath" : "humandetails.php?user=$receiverId&from=$currentDetailsPath";

                        echo "<p class='title'>";
                            // Preserved ['receiverImg'] key exactly
                            echo "<a href='$receiverTarget'><img src='" . htmlspecialchars($tData['receiverImg']) . "' class='humanicon2' /></a>";
                        echo "</p><br>";
                        echo "<div class='small_line'></div>";
                    echo "</div>";
                echo "</div>";

                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        echo "<p class='title'>" . htmlspecialchars($tData['receiverName']) . "</p><br>";
                    echo "</div>";
                echo "</div>";

            echo "</div>";
            echo "<div class='full_line'></div>";
        echo "</div>";
    echo "</div>";
?>
</body>
</html>



 
