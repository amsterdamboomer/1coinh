<?php
    require "header.php";

    unset($_SESSION['receiver_data']);
    $displayedResults = 6; 
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $step = $displayedResults;
        $total = isset($_SESSION["total"]) ? (int)$_SESSION["total"] : 0;
        $current = isset($_SESSION["start1"]) ? (int)$_SESSION["start1"] : 1;
        
        if (isset($_POST['btnsearch'])) {
            $input = trim($_POST['searchname'] ?? "");
            
            // LOGIC: If purely numeric, treat as Account Number. Otherwise, treat as Name.
            if (is_numeric($input)) {
                $_SESSION["searchnumber"] = $input;
                $_SESSION["searchname"]   = "";
            } else {
                $_SESSION["searchname"]   = $input;
                $_SESSION["searchnumber"] = "";
            }
            
            $_SESSION["start1"] = 1;
            $_SESSION["findRequest"] = true; 
        } 
        elseif (isset($_POST['nav_action'])) {
            switch ($_POST['nav_action']) {
                case 'Start': $_SESSION["start1"] = 1; break;
                case 'Previous': $_SESSION["start1"] = max(1, $current - $step); break;
                case 'Next': if ($current + $step <= $total) { $_SESSION["start1"] += $step; } break;
                case 'End': if ($total > 0) { $_SESSION["start1"] = max(1, $total - ($displayedResults - 1)); } break;
            }
        }
    }

    $userId = $_SESSION["userid"];
    if ($userId === 0) {
        header("Location: login.php");
        exit();
    }
    
    $start1       = $_SESSION["start1"]       ?? 1;
    $searchname   = (string)($_SESSION["searchname"]   ?? "");
    $searchnumber = (string)($_SESSION["searchnumber"] ?? "");
    $total        = (int)($_SESSION["total"]         ?? 0);
    $findRequest  = $_SESSION["findRequest"]   ?? false;

    // Initialize display slots
    for($i = 1; $i <= $displayedResults; $i++) {
        $idx = sprintf("%02d", $i);
        $_SESSION["r$idx-imag"] = "";
        $_SESSION["r$idx-name"] = "";
        $_SESSION["r$idx-numb"] = "";
    }

    // ===============================================
    //              DATABASE QUERIES
    // ===============================================
    if ($findRequest) {
        
        if (!empty($searchnumber)) {
            $numVal = (int)$searchnumber;

            if ($numVal < 100) {
                // RULE: 000-099 -> Strict exact match only
                $sql = "SELECT COUNT(*) as total FROM users WHERE (usersId = ?) AND (usersId <> ?);";
                $stmt = mysqli_stmt_init($conn);
                mysqli_stmt_prepare($stmt, $sql);
                mysqli_stmt_bind_param($stmt, "ii", $numVal, $userId);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                $total = (int)$row['total'];
                $_SESSION["total"] = $total;

                $sql = "SELECT usersId, usersName, image FROM users WHERE (usersId = ?) AND (usersId <> ?) LIMIT 1;";
                mysqli_stmt_prepare($stmt, $sql);
                mysqli_stmt_bind_param($stmt, "ii", $numVal, $userId);
            } else {
                // RULE: 100+ -> "Starts with" match using LIKE
                $sql = "SELECT COUNT(*) as total FROM users WHERE (usersId LIKE ?) AND (usersId <> ?);";
                $stmt = mysqli_stmt_init($conn);
                mysqli_stmt_prepare($stmt, $sql);
                $likeParam = $searchnumber . "%";
                mysqli_stmt_bind_param($stmt, "si", $likeParam, $userId);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                $total = (int)$row['total'];
                $_SESSION["total"] = $total;

                $bind1 = ($start1 < 1) ? 1 : $start1;
                $bind2 = $bind1 + ($displayedResults - 1);

                $sql = "WITH cte AS (
                            SELECT ROW_NUMBER() OVER(ORDER BY usersId ASC) row_num, usersId, usersName, image 
                            FROM users 
                            WHERE (usersId LIKE ?) AND (usersId <> ?)
                        ) 
                        SELECT usersId, usersName, image 
                        FROM cte 
                        WHERE row_num >= ? AND row_num <= ?;";
                mysqli_stmt_prepare($stmt, $sql);
                mysqli_stmt_bind_param($stmt, "siii", $likeParam, $userId, $bind1, $bind2);
            }
            
            mysqli_stmt_execute($stmt);
            $resultData = mysqli_stmt_get_result($stmt);
            $_SESSION["start1"] = ($total > 0) ? $start1 : 0;

        } else if (strlen($searchname) > 0) {
            // Name Search logic
            $sql = "SELECT COUNT(*) as total FROM users WHERE (usersName LIKE ?) AND (usersId <> ?);";
            $stmt = mysqli_stmt_init($conn);
            mysqli_stmt_prepare($stmt, $sql);
            $bind3 = "%" . $searchname . "%";
            mysqli_stmt_bind_param($stmt, "si", $bind3, $userId);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $total = (int)$row['total'];
            $_SESSION["total"] = $total;

            $bind1 = ($start1 < 1) ? 1 : $start1;
            $bind2 = $bind1 + ($displayedResults - 1); 
            $_SESSION["start1"] = $bind1;

            $sql = "WITH cte AS (
                        SELECT ROW_NUMBER() OVER(ORDER BY usersName ASC) row_num, usersId, usersName, image 
                        FROM users 
                        WHERE (usersName LIKE ?) AND (usersId <> ?)
                    ) 
                    SELECT usersId, usersName, image 
                    FROM cte 
                    WHERE row_num >= ? AND row_num <= ?;";
            mysqli_stmt_prepare($stmt, $sql);
            mysqli_stmt_bind_param($stmt, "ssii", $bind3, $userId, $bind1, $bind2);
            mysqli_stmt_execute($stmt);
            $resultData = mysqli_stmt_get_result($stmt);

        } else {
            // Default View (no search entered)
            $sql = "SELECT COUNT(*) as total FROM users WHERE (usersId <> ?);";
            $stmt = mysqli_stmt_init($conn);
            mysqli_stmt_prepare($stmt, $sql);
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $total = (int)$row['total'];
            $_SESSION["total"] = $total;

            $bind1 = ($start1 < 1) ? 1 : $start1;
            if ($total == 0) $bind1 = 0;
            $bind2 = $bind1 + ($displayedResults - 1);
            $_SESSION["start1"] = $bind1;

            $sql = "WITH cte AS (
                        SELECT ROW_NUMBER() OVER(ORDER BY usersId ASC) row_num, usersId, usersName, image 
                        FROM users 
                        WHERE (usersId <> ?)
                    ) 
                    SELECT usersId, usersName, image 
                    FROM cte 
                    WHERE row_num >= ? AND row_num <= ?;";
            mysqli_stmt_prepare($stmt, $sql);
            mysqli_stmt_bind_param($stmt, "sii", $userId, $bind1, $bind2);
            mysqli_stmt_execute($stmt);
            $resultData = mysqli_stmt_get_result($stmt);
        }

        // --- SESSION FILLING ---
        $counter = 0;
        while ($row = mysqli_fetch_assoc($resultData)) {
            $counter++;
            $idx = sprintf("%02d", $counter);
            $imgData = $row['image'] ?? '';
            if (is_array($imgData)) { $imgData = implode('', $imgData); }

            $_SESSION["r$idx-imag"] = (string)$imgData; 
            $_SESSION["r$idx-name"] = (string)($row["usersName"] ?? "");
            $_SESSION["r$idx-numb"] = (string)($row["usersId"] ?? "");
        }
        mysqli_stmt_close($stmt);
    }

    // ===============================================
    //              DISPLAY THE FORMS
    // ===============================================
    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='medium_line'></div>";
            echo "<div class='button4-row'>";
                echo "<div class='button4-column1'></div>";
                echo "<div class='button4-column2'>";
                    // TITLE: FIND PERSON (Forced to Full Caps)
                    echo '<p class="title">' . mb_strtoupper(t('REQ_TITLE'), 'UTF-8') . '</p>';
                echo "</div>";
                echo "<div class='button4-column3'>";
                    echo "<a href='index2.php'><button type='button' class='login-button'>" . t('REQ_CANCEL') . "</button></a>";
                echo "</div>";
            echo "</div>";
            //============================================
            //        FORM 1: SEARCH COMBINED INPUT)
            //============================================
            echo "<div class='medium_line'></div>";
                echo "<form method='post' action='request.php'>";
                    echo "<label class='form__label'>" . t('REQ_LABEL') . "</label><br>";
                
                    $rawName = (string)($_SESSION["searchname"] ?? "");
                    $rawNum  = (string)($_SESSION["searchnumber"] ?? "");
                    // 2. Use the raw string to preserve leading zeros (like 005)
                    $currentVal = !empty($rawNum) ? $rawNum : $rawName;

                    // PLACEHOLDER Translated
                    echo "<input type='text' class='form__input' id='searchname' name='searchname' autocomplete='off' oninput='validateSearch()' placeholder='" . t('REQ_PLACEHOLDER') . "' value='" . htmlspecialchars($currentVal) . "'>";
                    echo "<div class='medium_line'></div>";
                    
                    echo "<button type='submit' id='btnsearch' class='photo-button' name='btnsearch' style='background-color: var(--disabled); pointer-events: none; opacity: 0.5;'>" . t('REQ_SEARCH') . "</button>";
                echo "</form>";
            echo "<br>";
            echo "<div class='medium_line'></div>";
            //============================================
            // --- FORM 2: NAVIGATION (TOP) ---
            //============================================
            if ((int)$total > $displayedResults) {
                echo "<form method='post' action='request.php'>";
                    echo "<div class='button4-row'>";
                        echo "<div class='button4-column1'>";
                            if ((int)$start1 <= 1) {
                                echo "<button type='button' class='disabled-button' id='start' disabled>" . t('REQ_START') . "</button>";
                            } else {
                                // Note: Keep value='Start' for your PHP logic, translate only the text
                                echo "<button type='submit' name='nav_action' value='Start' class='login-button' id='start'>" . t('REQ_START') . "</button>";
                            }
                        echo "</div>";
                        echo "<div class='button4-column2'></div>";
                        echo "<div class='button4-column3'>";
                            if ((int)$start1 <= 1) {
                                echo "<button type='button' class='disabled-button' id='prev' disabled>" . t('REQ_PREV') . "&nbsp&nbsp</button>";
                            } else {
                                echo "<button type='submit' name='nav_action' value='Previous' class='login-button' id='prev'>" . t('REQ_PREV') . "&nbsp&nbsp</button>";
                            }
                        echo "</div>";
                    echo "</div>";
                echo "</form>";
            }
            // ... (Results loop remains the same) ...
            echo "<div class='medium_line'></div>";

            //============================================
            // --- 3. DISPLAY RESULTS ---
            //============================================
            for ($i = 1; $i <= $displayedResults; $i++) {
                $idx = sprintf("%02d", $i);
                $numbKey = "r$idx-numb";
                $imagKey = "r$idx-imag";
                $nameKey = "r$idx-name";
                
                if (!empty($_SESSION[$numbKey])) {
                    if (($start1 + ($i - 1)) <= $total && $total > 0) {
                        
                        $output_img = $_SESSION[$imagKey] ?? '';

                        echo "<div class='button8-row'>";
                            echo "<div class='button8-column1'>";
                                echo "<button type='button' id='btn$idx' onclick='goToDetails(" . (int)$_SESSION[$numbKey] . ")'>";
                                    echo "<img src='" . $output_img . "' alt='Row$idx-image' class='mainusericon' />";
                                echo "</button>";
                            echo "</div>";

                            echo "<div class='button8-column2'></div>";
                            echo "<div class='button8-column3'>";
                                echo "<button type='button' class='select-button' name='btnsubmit' onclick='goToReceiver(" . (int)$_SESSION[$numbKey] . ")'>";
                                    $padded = str_pad($_SESSION[$numbKey], 10, "0", STR_PAD_LEFT);
                                    $displayNum = substr($padded, 0, 1) . " " . substr($padded, 1, 3) . " " . substr($padded, 4, 3) . " " . substr($padded, 7, 3);
                                    echo "<span>" . $displayNum . "</span>";
                                    echo "<span>" . $_SESSION[$nameKey] . "</span>";
                                echo "</button>";
                            echo "</div>";
                        echo "</div>";
                        echo "<div class='small_line'></div>";
                    }
                }
            }

            echo "<div class='medium_line'></div>";

            //============================================
            //        FORM 4: BOTTOM NAVIGATION
            //============================================
            if ($total > $displayedResults) {
                echo "<form method='post' action='request.php'>";
                    echo "<div class='button6-row'>";
                        echo "<div class='button6-column1'>";
                            $formattedTotal = number_format($total, 0, '.', ' ');
                            if (($start1 + $displayedResults) > $total) {
                                echo "<button type='button' class='disabled-button' id='end' disabled>" . t('REQ_END') . " ($formattedTotal)</button>";
                            } else {
                                echo "<button type='submit' name='nav_action' value='End' class='login-button' id='end'>" . t('REQ_END') . " ($formattedTotal)</button>";
                            }
                        echo "</div>";
                        echo "<div class='button6-column2'></div>"; 
                        echo "<div class='button6-column3'>";
                            if (($start1 + $displayedResults) > $total) {
                                echo "<button type='button' class='disabled-button' id='next' disabled>" . t('REQ_NEXT') . "&nbsp&nbsp</button>";
                            } else {
                                echo "<button type='submit' name='nav_action' value='Next' class='login-button' id='next'>" . t('REQ_NEXT') . "&nbsp&nbsp</button>";
                            }
                        echo "</div>";
                    echo "</div>";
                echo "</form>";
            }

            if ($total == 0) { 
                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        echo "<br><br>";
                        if ($findRequest) { 
                            echo "<p class='error'>" . t('REQ_ERR') . "</p>";
                        }
                    echo "</div>";
                echo "</div>";
            }

            //============================================
            //                   END
            //============================================

        echo "</div>"; 
    echo "</div>"; 

    echo "<script>
        function validateSearch() {
            const input = document.getElementById('searchname');
            const btn = document.getElementById('btnsearch');
            // Clean value: no spaces for the length check
            const cleanValue = input.value.replace(/\s/g, '');

            if (cleanValue.length >= 3) {
                btn.style.backgroundColor = 'var(--button)';
                btn.style.pointerEvents = 'auto';
                btn.style.opacity = '1';
            } else {
                btn.style.backgroundColor = 'var(--disabled)';
                btn.style.pointerEvents = 'none';
                btn.style.opacity = '0.5';
            }
        }
        
        // Initial check on load
        window.addEventListener('load', validateSearch);

        function goToDetails(userId) {
            window.location.href = 'humandetails.php?user=' + userId + '&from=request.php';
        }
        function goToReceiver(userId) {
            window.location.href = 'receiver.php?user=' + userId;
        }
    </script>";

    $_SESSION["findRequest"] = true;
    ob_end_flush();

?>
</body>
</html>