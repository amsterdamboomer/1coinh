<?php

    require "header.php";

    // 1. FIRST: Get values from GET (used if the handler redirects back with an error)
    $oldAmount = filter_input(INPUT_GET, 'amount', FILTER_SANITIZE_SPECIAL_CHARS) ?: "";
    $oldDesc = filter_input(INPUT_GET, 'desc', FILTER_SANITIZE_SPECIAL_CHARS) ?: "";


    // 2. SECOND: Handle the "Save and Go to Details" POST logic
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['go_to_details'])) {
        $_SESSION['receiver_data'] = [
            'amount' => $_POST['amount'],
            'description' => $_POST['description']
        ];
        $targetId = (int)$_POST['giver_id'];
        $returnPath = urlencode("receiver.php?user=" . $targetId);
        header("Location: humandetails.php?user=$targetId&from=$returnPath");
        exit();
    }

    // 2. Clear data if we are coming fresh from request.php
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referrer, 'request.php') !== false) {
        unset($_SESSION['receiver_data']);
    }

    // 3. THIRD: Determine what to actually show in the input boxes
    // We check the Session first (coming back from details), 
    // then the GET parameters (coming back from error), 
    // then default to an empty string.
    $dispAmount = $_SESSION['receiver_data']['amount'] ?? $oldAmount;
    $dispDesc   = $_SESSION['receiver_data']['description'] ?? $oldDesc;

    $msg = "";
    $msgClass = "error"; 

    if (isset($_GET['error'])) {
        $error = $_GET['error'];
        // Use this if you are on an older PHP version or get a 500 error
        switch ($error) {
            case "desc_insufficient":  $msg = t('RCV_ERR_DESC'); break;
            case "target_limit":       $msg = t('RCV_ERR_TLIMIT'); break;
            case "user_limit":         $msg = t('RCV_ERR_ULIMIT'); break;
            case "insufficient_funds": $msg = t('RCV_ERR_FUNDS'); break;
            case "email_issue":        $msg = t('RCV_ERR_EMAIL'); break;
            case "hashmismatch":       $msg = t('RCV_ERR_HASH'); break;
            default:                   $msg = ""; break;
        }
    }

    if (isset($_GET['success']) && $_GET['success'] == "proposal_sent") {
        $msg = t('RCV_SUCCESS') . " " . htmlspecialchars($_GET['name']);
        $msgClass = "success";
    }

    if (isset($_SESSION['useruid'])) {
        // Note: In receiver.php, $giver is the person being asked for money
        $giverId = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT) ?: 0;
        $myId = $_SESSION['userid'];
        $h_userData = getUserData($conn, $giverId);

        if ($h_userData) {
            $h_name = $h_userData['usersName'];
            
            // Flatten image if it's an array (following our previous fix)
            $h_image = $h_userData['image'];
            if (is_array($h_image)) {
                $h_image = implode('', $h_image);
            }

            $h_availablecoins = calculateAvailableCoins($conn, $giverId);

            // --- NEW: FIREWALL LOGIC (CORRECTED) ---
            $isBlocked = false;
            $useWhitelist = (int)($h_userData['useWhitelist'] ?? 0);

            if ($useWhitelist === 1) {
                // MODE: WHITELIST - You are blocked UNLESS you are on their Whitelist (type 1)
                $sqlW = "SELECT 1 FROM lists WHERE ownerId = ? AND targetId = ? AND listType = 1 LIMIT 1";
                $stmtW = $conn->prepare($sqlW);
                $stmtW->bind_param("ii", $giverId, $myId);
                $stmtW->execute();
                if ($stmtW->get_result()->num_rows === 0) {
                    $isBlocked = true;
                }
                $stmtW->close();
            } else {
                // MODE: BLACKLIST - You are allowed UNLESS you are on their Blacklist (type 0)
                $sqlB = "SELECT 1 FROM lists WHERE ownerId = ? AND targetId = ? AND listType = 0 LIMIT 1";
                $stmtB = $conn->prepare($sqlB);
                $stmtB->bind_param("ii", $giverId, $myId);
                $stmtB->execute();
                if ($stmtB->get_result()->num_rows > 0) {
                    $isBlocked = true;
                }
                $stmtB->close();
            }
            // --- END FIREWALL LOGIC ---

            //================================================
            //                SHOW SCREEN
            //================================================

            echo "<div id='centerContainer'>";
                echo "<div class='field-wrapper'>";
                    echo "<div class='full_line'></div>";
                    
                    // HEADER ROW
                    echo "<div class='button4-row'>";
                        echo "<div class='button4-column1'>";
                            if (!$isBlocked) {
                                echo "<button type='submit' form='form__submit' name='submit' class='login-button'>" . t('RCV_SEND') . "</button>";
                            }
                        echo "</div>";
                        echo "<div class='button4-column2'>";
                            echo '<p class="title">' . mb_strtoupper(t('RCV_TITLE'), 'UTF-8') . '</p>';
                        echo "</div>";
                        echo "<div class='button4-column3'>";
                            echo "<a href='request.php'><button type='button' class='login-button'>" . t('RCV_CANCEL') . "</button></a>";
                        echo "</div>";
                    echo "</div>";
                    echo "<div class='medium_line'></div>";

                    //================================================
                    //          PROFILE INFO (Always Visible)
                    //================================================
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";
                            $padded = str_pad($giverId, 10, "0", STR_PAD_LEFT);
                            $displayNum = substr($padded, 0, 1) . " " . substr($padded, 1, 3) . " " . substr($padded, 4, 3) . " " . substr($padded, 7, 3);
                            echo "<div class='display-num2'>" . $displayNum . "</div>";
                        echo "</div>";
                    echo "</div>";
                    echo "<div class='small_line'></div>";
                    
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";

                            // Change the icon button
                            echo "<button type='submit' name='go_to_details' form='form__submit' formaction='receiver.php' formnovalidate style='background:none; border:none; cursor:pointer; padding:0;'>";

                                echo "<p class='title'><img src='" . $h_image . "' class='humanicon2' /></p>";
                            echo "</button>";

                        echo "</div>";
                    echo "</div>";
                    echo "<div class='small_line'></div>";
                    
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";

                            // Change the name button
                            echo "<button type='submit' name='go_to_details' form='form__submit' formaction='receiver.php' formnovalidate style='background:none; border:none; cursor:pointer; padding:0;'>";
                                echo "<p class='title2'>" . htmlspecialchars($h_name) . "</p>";
                            echo "</button>";

                        echo "</div>";
                    echo "</div>";
                    
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";
                            echo "<p class='feedback'>" . t('RCV_AVAIL') . ": " . number_format($h_availablecoins, 2) . " ᕫ</p>";
                        echo "</div>";
                    echo "</div>";

                    if ($isBlocked) {
                        //================================================
                        //          SPECIFIC BLOCKED REMINDER
                        //================================================
                        echo "<div class='large_line'></div>";
                        echo "<div class='button9-row'><div class='button9-column1'>";
                        echo "<p class='feedback'>";
                            echo t('RCV_BLOCKED_1') . "<br>";
                            echo t('RCV_BLOCKED_2') . "<br>";
                            echo t('RCV_BLOCKED_3');
                        echo "</p>";
                        echo "</div></div>";
                    } else {
                        //================================================
                        //          STANDARD REQUEST FORM
                        //================================================
                        echo "<form id='form__submit' action='includes/receiver.inc.php' method='POST'>";
                            echo "<input type='hidden' name='giver_id' value='" . $giverId . "'>";

                            echo "<div class='full_line'></div>";

                            echo "<div class='button4-row'>";
                                echo "<div class='button4-column1'></div>";
                                echo "<div class='button4-column2'><p class='title'>" . mb_strtoupper(t('RCV_PAY'), 'UTF-8') . "</p></div>";
                                echo "<div class='button4-column3'></div>";
                            echo "</div>";

                            echo "<div class='amount-outer-container'>";
                                echo "<label class='form__label_amount'>" . t('RCV_AMT') . "</label>";
                                echo "<div class='input-container'>";
     
                                    // Inside the form__submit block:
                                    echo "<input type='text' class='form__input_amount' name='amount' id='amountInput' value='$dispAmount' required='' placeholder='0' />";

                                echo "</div>";        
                            echo "</div>";

                            echo "<div class='full_line'></div>";

                            echo "<div class='button4-row'>";
                                echo "<div class='button4-column1'></div>";
                                echo "<div class='button4-column2'><p class='title'>" . mb_strtoupper(t('RCV_FOR'), 'UTF-8') . "</p></div>";
                                echo "<div class='button4-column3'></div>";
                            echo "</div>";
                            echo "<div>";
                                echo "<label class='form__label'>" . t('RCV_DESC') . "</label><br>";

                                // ... and for description:
                                echo "<input type='text' class='form__input' name='description' value='" . htmlspecialchars($dispDesc) . "' required='' />";

                            echo "</div>";
                        echo "</form>"; 
                    }

                    // Notification System
                    if (!empty($msg)) {
                        echo "<div class='full_line'></div>"; 
                        echo "<div class='signupdiv'>"; 
                            if (strlen($msg) > 45 && strpos($msg, '.') !== false) {
                                $parts = explode('.', $msg, 2);
                                echo "<p class='" . $msgClass . "'>" . trim($parts[0]) . ".</p>";
                                echo "<p class='" . $msgClass . "'>" . trim($parts[1]) . "</p>";
                            } else {
                                echo "<p class='" . $msgClass . "'>" . $msg . "</p>";
                            }
                        echo "</div>";
                    }
                    echo "<div class='full_line'></div>";
                echo "</div>"; // field-wrapper
            echo "</div>"; // centerContainer
        } // Closes if ($h_userData)
    } // Closes if (isset($_SESSION['useruid']))
    echo "<script>";
        echo "function goToDetails(userId) {";
            echo "window.location.href = 'humandetails.php?user=' + userId + '&from=' + encodeURIComponent('receiver.php?user=' + userId);";
        echo "}";
        
        // Pass the PHP value to JS
        echo "const availableCoins = parseFloat(" . $h_availablecoins . ") || 0;";



        echo "const amountInput = document.getElementById('amountInput');";
            
        // Only add the listener if the input actually exists (not blocked)
        echo "if (amountInput) {";
            echo "amountInput.addEventListener('input', function (e) {";
                echo "let value = e.target.value.replace(/[^\d.]/g, '');";
                echo "let parts = value.split('.');";
                echo "parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');";
                echo "e.target.value = parts.join('.');";
                
                echo "const currentAmount = parseFloat(e.target.value.replace(/\s/g, '')) || 0;";

                echo "if (currentAmount > availableCoins) {";
                    echo "amountInput.classList.add('amount-error');";
                echo "} else {";
                    echo "amountInput.classList.remove('amount-error');";
                echo "}";
            echo "});";

            echo "amountInput.dispatchEvent(new Event('input'));";
        echo "}";


    echo "</script>";
    ob_end_flush();
?>
</body>
</html>