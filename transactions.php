<?php
    require "header.php";

    // 1. Safety Gate: Redirect if session is lost
    if (!isset($_SESSION['userid'])) {
        header("Location: index2.php?error=session_expired");
        exit();
    }

    $myId = $_SESSION['userid'];
    $viewId = isset($_GET['user']) ? (int)$_GET['user'] : $myId;

    $viewUser = getUserData($conn, $viewId);

    // 2. Error Handling: If user doesn't exist, don't crash
    if (!$viewUser) {
        die("User not found.");
    }

    // require "header.php";

    // // transactions.php top section
    // $myId = $_SESSION['userid'];
    // // The ID of the person whose ledger we are viewing
    // $viewId = isset($_GET['user']) ? (int)$_GET['user'] : $myId;

    // // Fetch data for the person being viewed
    // $viewUser = getUserData($conn, $viewId);

    $selectedYear = $_POST['year'] ?? $_GET['year'] ?? date("Y");
    $selectedMonth = $_POST['month'] ?? $_GET['month'] ?? date("n");
    $selectedTid = $_POST['tid'] ?? $_GET['tid'] ?? 0;

    // Fetch transactions list for the selected month
    $sqlList = "SELECT * FROM transactions 
                WHERE (giver = ? OR receiver = ?) 
                AND YEAR(time_stamp) = ? AND MONTH(time_stamp) = ? 
                ORDER BY time_stamp ASC";
    $stmtL = $conn->prepare($sqlList);
    $stmtL->bind_param("iiii", $viewId, $viewId, $selectedYear, $selectedMonth);
    $stmtL->execute();
    $transactions = $stmtL->get_result()->fetch_all(MYSQLI_ASSOC);

    // --- VALIDATE SELECTION ---
    $tidExists = false;
    if ($selectedTid > 0 && !empty($transactions)) {
        foreach ($transactions as $tr) {
            if ($tr['tid'] == $selectedTid) {
                $tidExists = true;
                break;
            }
        }
    }

    if (!$tidExists && !empty($transactions)) {
        $selectedTid = $transactions[0]['tid'];
    } elseif (empty($transactions)) {
        $selectedTid = 0;
    }

    // --- SMART TIME NAVIGATION LOGIC ---

    // Define current view window
    $currentViewStart = "$selectedYear-$selectedMonth-01 00:00:00";
    $currentViewEnd   = date("Y-m-t 23:59:59", strtotime($currentViewStart));

    // 1. Find the nearest PREVIOUS month with transactions
    $sqlPrev = "SELECT YEAR(time_stamp) as y, MONTH(time_stamp) as m 
                FROM transactions 
                WHERE (giver = ? OR receiver = ?) 
                AND time_stamp < ? 
                ORDER BY time_stamp DESC LIMIT 1";
    $stP = $conn->prepare($sqlPrev);
    $stP->bind_param("iis", $viewId, $viewId, $currentViewStart);
    $stP->execute();
    $prevMonthData = $stP->get_result()->fetch_assoc();

    // 2. Find the nearest NEXT month with transactions
    $sqlNext = "SELECT YEAR(time_stamp) as y, MONTH(time_stamp) as m 
                FROM transactions 
                WHERE (giver = ? OR receiver = ?) 
                AND time_stamp > ? 
                ORDER BY time_stamp ASC LIMIT 1";
    $stN = $conn->prepare($sqlNext);
    $stN->bind_param("iis", $viewId, $viewId, $currentViewEnd);
    $stN->execute();
    $nextMonthData = $stN->get_result()->fetch_assoc();


    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='medium_line'></div>";
            
            // --- TITLE ROW ---
            echo "<div class='button4-row'>";
                echo "<div class='button4-column1'></div>";
                echo "<div class='button4-column2' style='display:flex; flex-direction:column; align-items:center;'>";
                    // Translated Title (Forced to caps)
                    echo "<p class='title'>" . mb_strtoupper(t('TR_TITLE'), 'UTF-8') . "</p>";
                    echo "<p class='display-num' style='margin-top:-5px; color:var(--text);'>" . htmlspecialchars($viewUser['usersName']) . "</p>";
                echo "</div>";
                // Reused PR_BACK
                echo "<div class='button4-column3'><a href='index2.php'><button class='login-button'>".t('PR_BACK')."</button></a></div>";
            echo "</div>";
            echo "<div class='small_line'></div>";


            // --- FILTERS ROW ---
            // --- FILTERS WRAPPER (Syncs with Listbox) ---
            echo "<div class='nav-filter-wrapper'>";
                echo "<form method='POST' id='filterForm' class='button_t-row'>";

                    // 1. YEAR
                    echo "<div class='t-col-year'>";
                        echo "<select name='year' id='navYear' onchange='this.form.submit()' class='year-month-pill'>";
                            for($y=2024; $y<=date("Y"); $y++) {
                                $sel = ($y == $selectedYear) ? "selected" : "";
                                echo "<option value='$y' $sel>$y</option>";
                            }
                        echo "</select>";
                    echo "</div>";

                    // 2. PREV BUTTON (<)
                    $pClass = $prevMonthData ? "time-nav-button" : "time-nav-button nav-disabled";
                    $pClick = $prevMonthData ? "onclick=\"document.getElementById('navYear').value='".$prevMonthData['y']."'; document.getElementById('navMonth').value='".$prevMonthData['m']."'; document.getElementById('filterForm').submit();\"" : "";
                    echo "<div class='t-col-prev'>";
                        echo "<button type='button' class='$pClass' $pClick>&nbsp;&nbsp;<&nbsp;&nbsp;</button>";
                    echo "</div>";

                    // 3. MONTH
                    echo "<div class='t-col-month'>";
                        echo "<select name='month' id='navMonth' onchange='this.form.submit()' class='year-month-pill'>";
                            for($m=1; $m<=12; $m++) {
                                $sel = ($m == $selectedMonth) ? "selected" : "";
                                echo "<option value='$m' $sel>" . t("ML_$m") . "</option>";
                            }
                        echo "</select>";
                    echo "</div>";

                    // 4. NEXT BUTTON (>)
                    $nClass = $nextMonthData ? "time-nav-button" : "time-nav-button nav-disabled";
                    $nClick = $nextMonthData ? "onclick=\"document.getElementById('navYear').value='".$nextMonthData['y']."'; document.getElementById('navMonth').value='".$nextMonthData['m']."'; document.getElementById('filterForm').submit();\"" : "";
                    echo "<div class='t-col-next'>";
                        echo "<button type='button' class='$nClass' $nClick>&nbsp;&nbsp;>&nbsp;&nbsp;</button>";
                    echo "</div>";

                    // 5. IMAGE OR SPACER
                    if ($viewId !== $myId) {
                        echo "<div class='t-col-image'>";
                            $currentState = "transactions.php?user=$viewId&year=$selectedYear&month=$selectedMonth&tid=$selectedTid";
                            $returnPath = urlencode($currentState);
                            $targetLink = "humandetails.php?user=" . (int)$viewId . "&from=$returnPath";
                            echo "<a href='$targetLink' style='display: block;'>";
                                echo "<img src='" . htmlspecialchars($viewUser['image'] ?? '') . "' class='transactionicon'>";
                            echo "</a>";
                        echo "</div>";
                    } else {
                        echo "<div class='t-col-spacer'></div>";
                    }

                    echo "<input type='hidden' name='tid' id='hiddenTid' value='$selectedTid'>";
                echo "</form>";
            echo "</div>";

        

            // --- LISTBOX ---
            if (empty($transactions)) {
                // Translated "No transactions found"
                echo "<p class='feedback'>".t('TR_NONE')."</p>";
            } else {
                echo "<div class='listbox-container'>";
                foreach ($transactions as $t) {
                    $rowState = getPreviousTransactionState($conn, $viewId, $t['time_stamp']);
                    $isLedgerOwnerGiver = ($t['giver'] == $viewId);
                    $balanceAfter = $rowState['availableBalance'] + ($isLedgerOwnerGiver ? -$t['amount'] : $t['amount']);
                    
                    $rowClass = ($t['tid'] == $selectedTid) ? "list-row-selected" : "list-row";
                    $amtColor = $isLedgerOwnerGiver ? "var(--error)" : "#00BFFF";
                    $sign = $isLedgerOwnerGiver ? "-" : "+";

                    echo "<div class='$rowClass' id='trow_" . $t['tid'] . "' onclick='selectTransaction(" . $t['tid'] . ", this)'>";
                        
                        // Localized short month formatting
                        $ts = strtotime($t['time_stamp']);
                        $dateStr = date("d", $ts) . " " . t("M_" . date("n", $ts)) . " " . date("H:i", $ts);
                        echo "<span class='list-date'>" . $dateStr . "</span>";
                        
                        echo "<span class='list-amt' style='color:$amtColor'>$sign" . number_format($t['amount'], 2) . " ᕫ</span>";
                        echo "<span class='list-bal'>" . number_format($balanceAfter, 2) . " ᕫ</span>";
                    echo "</div>";
                }
                echo "</div>";
            }


                   // --- CALCULATION DISPLAY (Only if transactions exist) ---
            if (!empty($transactions) && $selectedTid > 0) {
                
                $currentT = null;
                foreach($transactions as $tr) { 
                    if((int)$tr['tid'] === (int)$selectedTid) { 
                        $currentT = $tr; 
                        break; 
                    } 
                }
                
                if ($currentT) {
                    // FIXED: The partner is whoever is NOT the person being viewed ($viewId)
                    $partnerId = ($currentT['giver'] == $viewId) ? $currentT['receiver'] : $currentT['giver'];
                    $partner = getUserData($conn, $partnerId);

                    $state = getPreviousTransactionState($conn, $viewId, $currentT['time_stamp']);
                    
                    // FIXED: Logic follows the Ledger Owner ($viewId)
                    $isLedgerOwnerGiver = ($currentT['giver'] == $viewId);
                    $newBalance = $state['availableBalance'] + ($isLedgerOwnerGiver ? -$currentT['amount'] : $currentT['amount']);

                    echo "<div class='calc-table'>";
                        echo "<div class='full_line'></div>";
                        echo "<div class='calc-row'><span class='calc-title'>".t('TR_HOURS')."</span><span class='calc-val'>" . (int)$state['hours'] . "</span></div>";
                        echo "<div class='calc-row'><span class='calc-title'>".t('TR_REDUCTION_MATH').": 0.99995^" . (int)$state['hours'] . "</span><span class='calc-val'>" . number_format($state['reductionFactor'], 8) . "</span></div>";
                        
                        echo "<div class='full-screen-line'></div>";
                        
                        // --- BOLD: PREVIOUS BALANCE ---
                        echo "<div class='calc-row'>
                                <span class='calc-title'><strong>".t('TR_PREV_BAL')."</strong></span>
                                <span class='calc-val'><strong>" . number_format($state['previousBalance'], 2) . " ᕫ</strong></span>
                              </div>";

                        echo "<div class='calc-row'>";
                            echo "<span class='calc-title' style='color:var(--feedback)'>".t('TR_REDUCTION')."</span>";
                            echo "<span class='calc-val' style='color:var(--error)'>-" . number_format(abs($state['reductionAmount']), 2) . " ᕫ</span>";
                        echo "</div>";

                        echo "<div class='calc-row'><span class='calc-title' style='color:var(--feedback)'>".t('TR_INCOME')."</span><span class='calc-val' style='color:#00BFFF'>+" . number_format($state['income'], 2) . " ᕫ</span></div>";
                        
                        echo "<div class='full-screen-line'></div>";
                        
                        // --- BOLD: AVAILABLE BALANCE ---
                        echo "<div class='calc-row'>
                                <span class='calc-title'><strong>".t('TR_AVAIL_BAL')."</strong></span>
                                <span class='calc-val'><strong>" . number_format($state['availableBalance'], 2) . " ᕫ</strong></span>
                              </div>";
                        
                        $fullStatePath = "transactions.php?user=$viewId&year=$selectedYear&month=$selectedMonth&tid=$selectedTid";
                        $encodedPath = urlencode($fullStatePath);

                        // Define the color based on the Ledger Owner's perspective
                        $amtColor = $isLedgerOwnerGiver ? "var(--error)" : "#00BFFF";
                        $amtSign = $isLedgerOwnerGiver ? "-" : "+";

                        echo "<a href='transactiondetails.php?tid=" . (int)$currentT['tid'] . "&from=$encodedPath' class='transaction-detail-link'>";
                            echo "<div class='calc-row transaction-hover-row' style='align-items:center; position:relative; display:flex;'>";

                                echo "<span class='calc-title' style='display:flex; align-items:center; flex:1; min-width:0;'>";
                                    if ($partner && !empty($partner)) {
                                        echo "<img src='" . htmlspecialchars($partner['image']) . "' class='transactionicon2' />";
                                    } else {
                                        // Added class for scaling placeholder
                                        echo "<div class='transactionicon2-placeholder'></div>";
                                    }
                                    
                                    echo "<span class='expanded-desc'>" . htmlspecialchars(mb_strimwidth($currentT['description'], 0, 25, "...")) . "</span>";
                                echo "</span>";
                                
                                echo "<span class='calc-val' style='color:$amtColor; flex-shrink:0; text-align:right;'>";
                                    echo "$amtSign" . number_format($currentT['amount'], 2) . " ᕫ";
                                echo "</span>";
                            echo "</div>";
                        echo "</a>";

                        echo "<div class='full-screen-line'></div>";
                        
                        // --- BOLD: NEW BALANCE ---
                        echo "<div class='calc-row'>
                                <span class='calc-title'><strong>".t('TR_NEW_BAL')."</strong></span>
                                <span class='calc-val'><strong>" . number_format($newBalance, 2) . " ᕫ</strong></span>
                              </div>";
                        
                        echo "<div class='full-screen-line'></div>";
                    echo "</div>";

                }
            }

            echo "<div class='hd-footer-row' style='justify-content: center;'>"; // Centering the group
                echo "<div class='hd-download-group'>";
                    echo "<a href='download-pdf.php?user=$viewId&year=$selectedYear&month=$selectedMonth' class='btn-gap-wide'>";
                        echo "<button type='button' class='login-button'>PDF</button>";
                    echo "</a>";

                        // CSV Button
                        echo "<a href='download-csv.php?user=$viewId' target='_blank' class='csv-edge-margin'>";
                            echo "<button type='button' class='login-button'>CSV</button>";
                        echo "</a>";

                    echo "</a>";
                echo "</div>";
            echo "</div>";

        echo "</div>";
    echo "</div>";

    echo "<script>

    // Change the function signature to handle both 1 and 2 arguments
    function selectTransaction(tid, element = null) {
        if (element) {
            // 1. Instant UI Feedback
            document.querySelectorAll('.list-row-selected').forEach(el => {
                el.classList.remove('list-row-selected');
                el.classList.add('list-row');
            });
            element.classList.add('list-row-selected');
        }

        // 2. Save scroll position
        const container = document.querySelector('.listbox-container');
        if (container) {
            sessionStorage.setItem('scrollPos', container.scrollTop);
        }

        // 3. Submit form
        const hiddenInput = document.getElementById('hiddenTid');
        if (hiddenInput) {
            hiddenInput.value = tid;
            document.getElementById('filterForm').submit();
        }
    }

    // 4. Restore scroll position on page load
    window.onload = function() {
        const scrollPos = sessionStorage.getItem('scrollPos');
        if (scrollPos) {
            document.querySelector('.listbox-container').scrollTop = scrollPos;
            sessionStorage.removeItem('scrollPos'); // Clear it so it doesn't affect manual reloads
        }
    }


    </script>";
?>