<?php
    require "header.php";

    $h_user = filter_input(INPUT_GET, 'user', FILTER_VALIDATE_INT) ?: 0;
    $revisionId = filter_input(INPUT_GET, 'rev', FILTER_VALIDATE_INT) ?: 0; 
    $from_page = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_URL) ?: "index2.php";

    // --- NEW LOGIC: LAND ON NEWEST HISTORY IF COMING FROM PROFILE ---
    if ($revisionId === 0 && strpos($from_page, 'profile.php') !== false) {
        $sqlLatest = "SELECT usersOldId FROM users_old WHERE uid_old = ? ORDER BY start_old DESC LIMIT 1";
        $stmtL = $conn->prepare($sqlLatest);
        $stmtL->bind_param("i", $h_user);
        $stmtL->execute();
        $resLatest = $stmtL->get_result()->fetch_assoc();
        
        if ($resLatest) {
            $revisionId = (int)$resLatest['usersOldId'];
        }
    }

    // 1. Fetch the BASE record
    if ($revisionId === 0) {
        $displayData = getUserData($conn, $h_user);
        $currentTime = $displayData['start'] ?? null; 
        $rawChanges = []; // Not used in current mode
    } else {
        $sql = "SELECT * FROM users_old WHERE usersOldId = ? AND uid_old = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $revisionId, $h_user);
        $stmt->execute();
        $displayData = $stmt->get_result()->fetch_assoc();
        
        // --- THE CRITICAL FIX: Save the RAW state before mapping/recovery ---
        $rawChanges = $displayData; 

        $currentTime = $displayData['start_old'] ?? null;

        if ($displayData) {
            // Map users_old columns to standard keys for the UI
            $displayData['usersName'] = $displayData['usersName_old'];
            $displayData['image'] = $displayData['image_old'];
            $displayData['birthday'] = $displayData['birthday_old'];
            $displayData['gender'] = $displayData['gender_old'];
            $displayData['height'] = $displayData['height_old'];
            $displayData['hair'] = $displayData['hair_old'];
            $displayData['leftEye'] = $displayData['leftEye_old'];
            $displayData['rightEye'] = $displayData['rightEye_old'];
            $displayData['specialFeatures'] = $displayData['specialFeatures_old'];
        }
    }


    if (!$displayData) {
        echo "<div class='signupdiv'><p class='error'>" . t('HD_ERR_404') . "</p></div>";
        exit();
    }

    // 2. DATA RECOVERY LOGIC (Only for History Mode)
    if ($revisionId > 0) {
        $recoveryMap = [
            'image' => 'image_old', 'usersName' => 'usersName_old', 'birthday' => 'birthday_old',
            'gender' => 'gender_old', 'height' => 'height_old', 'hair' => 'hair_old',
            'leftEye' => 'leftEye_old', 'rightEye' => 'rightEye_old', 'specialFeatures' => 'specialFeatures_old'
        ];

        // FIX: Ensure we have a valid date string for the comparison to avoid "Incorrect DATE value: ''"
        $safeTime = (!empty($currentTime)) ? $currentTime : date("Y-m-d H:i:s");

        foreach ($recoveryMap as $uiKey => $dbOldKey) {
            // THE FIX: Only recover if the field is strictly NULL. 
            // If it is an empty string, it means the user deleted the data on purpose.
            if ($displayData[$uiKey] === null) {
                $sqlRec = "SELECT $dbOldKey FROM users_old 
                           WHERE uid_old = ? AND start_old < ? 
                           AND $dbOldKey IS NOT NULL 
                           ORDER BY start_old DESC LIMIT 1";
                
                $stmtRec = $conn->prepare($sqlRec);
                $stmtRec->bind_param("is", $h_user, $safeTime);
                $stmtRec->execute();
                $resRec = $stmtRec->get_result()->fetch_assoc();
                
                // Assign found value or fallback to empty string if no history exists
                $displayData[$uiKey] = $resRec[$dbOldKey] ?? '';
            }
        }
    }


    // --- Block Status & UI Variables ---
    $isBlocked = false;
    $checkSql = "SELECT 1 FROM lists WHERE ownerId = ? AND targetId = ? AND listType = 0 LIMIT 1";
    $cStmt = $conn->prepare($checkSql);
    $cStmt->bind_param("ii", $_SESSION['userid'], $h_user);
    $cStmt->execute();
    if ($cStmt->get_result()->num_rows > 0) { $isBlocked = true; }

    $h_name = $displayData['usersName'];
    $h_img_data = $displayData['image'];
    $h_gender = $displayData['gender'];
    $h_height = $displayData['height'];
    $h_hair = $displayData['hair'];
    $h_lefteye = $displayData['leftEye'];
    $h_righteye = $displayData['rightEye'];
    $h_specialfeatures = $displayData['specialFeatures'];

    // Format Birthday
    $dateB = new DateTime($displayData['birthday']);
    $h_birthday = $dateB->format("j") . " " . t("M_" . $dateB->format("n")) . " " . $dateB->format("Y");



    // --- TIME NAVIGATION LOOKUP ---
    // Prev: The newest record strictly older than current
    $sqlPrev = "SELECT usersOldId FROM users_old WHERE uid_old = ? AND start_old < ? ORDER BY start_old DESC LIMIT 1";
    $stP = $conn->prepare($sqlPrev); $stP->bind_param("is", $h_user, $currentTime); $stP->execute();
    $prevRev = $stP->get_result()->fetch_assoc()['usersOldId'] ?? null;

    // Next: The oldest record strictly newer than current
    $sqlNext = "SELECT usersOldId FROM users_old WHERE uid_old = ? AND start_old > ? ORDER BY start_old ASC LIMIT 1";
    $stN = $conn->prepare($sqlNext); $stN->bind_param("is", $h_user, $currentTime); $stN->execute();
    $nextRev = $stN->get_result()->fetch_assoc()['usersOldId'] ?? 0; // 0 = current

    // --- Financial Calculations (Geometric/Decay) ---
    // Geometric Series Calculation using the resolved date
    // Fallback to current time if $currentTime is null to prevent the DateTime error
    $h_date0 = new DateTime($currentTime ?? 'now'); 
    $h_date1 = new DateTime();
    $h_diff = $h_date1->diff($h_date0);
    $h_hours = ($h_diff->days * 24) + $h_diff->h;
    $h_powN = pow(0.99995, $h_hours);
    $h_owncoin = (1000 * $h_powN) + ((1 - $h_powN) / (1 - 0.99995));

    // Simple loop for Transaction Decay
    $h_spentcoins = 0; $h_receivedcoins = 0;
    $sqlT = "SELECT giver, receiver, amount, time_stamp FROM transactions WHERE receiver = ? OR giver = ?";
    $stmtT = $conn->prepare($sqlT); $stmtT->bind_param("ii", $h_user, $h_user); $stmtT->execute();
    $resT = $stmtT->get_result();
    while ($t_row = $resT->fetch_assoc()) {
        $t_diff = (new DateTime())->diff(new DateTime($t_row["time_stamp"]));
        $t_factor = pow(0.99995, ($t_diff->days * 24) + $t_diff->h);
        $amt = (float)$t_row["amount"] * $t_factor;
        ($t_row["giver"] == $h_user) ? $h_spentcoins += $amt : $h_receivedcoins += $amt;
    }
    $h_availablecoins = $h_owncoin + $h_receivedcoins - $h_spentcoins;

    // --- Statistics Query ---
    $queryS = "SELECT 
                COUNT(CASE WHEN giver = ? THEN 1 END) as h_giver,
                COUNT(DISTINCT CASE WHEN giver = ? THEN receiver END) as h_giver_unique,
                COUNT(CASE WHEN receiver = ? THEN 1 END) as h_receiver,
                COUNT(DISTINCT CASE WHEN receiver = ? THEN giver END) as h_receiver_unique,
                COUNT(CASE WHEN giver = ? AND receiver = ? THEN 1 END) as m_giver,
                COUNT(CASE WHEN receiver = ? AND giver = ? THEN 1 END) as m_receiver
                FROM transactions WHERE giver = ? OR receiver = ?";
    $stmtS = $conn->prepare($queryS);
    $stmtS->bind_param("iiiiiiiiii", $h_user, $h_user, $h_user, $h_user, $_SESSION['userid'], $h_user, $_SESSION['userid'], $h_user, $h_user, $h_user);
    $stmtS->execute();
    $stats = $stmtS->get_result()->fetch_assoc();

    $calcCurve = function($val) {
        if ($val <= 0) return 0;
        return (7.427 * log($val + 0.008)) + 35.85;
    };
    $trust_score = ($calcCurve($stats['m_giver']) * 0.37) + ($calcCurve($stats['m_receiver']) * 0.16) + ($calcCurve($stats['h_receiver']) * 0.12) + ($calcCurve($stats['h_giver']) * 0.04) + ($calcCurve($stats['h_receiver_unique']) * 0.22) + ($calcCurve($stats['h_giver_unique']) * 0.02) + ($calcCurve($h_hours) * 0.07);
    $h_final_trust = max(0.1, min(99.9, round($trust_score, 0)));




        // --- 2. HIGHLIGHT DETECTION LOGIC (CONSOLIDATED) ---
    
    // Define the helper function locally so humandetails can use it
    if (!function_exists('hasChanged')) {
        function hasChanged($field, $currentVal, $conn, $userId) {
            $map = [
                'name' => 'usersName_old', 'image' => 'image_old', 'birthday' => 'birthday_old',
                'gender' => 'gender_old', 'height' => 'height_old', 'hair' => 'hair_old',
                'lefteye' => 'leftEye_old', 'righteye' => 'rightEye_old', 'specialfeatures' => 'specialFeatures_old'
            ];
            if (!isset($map[$field])) return false;
            $oldKey = $map[$field];

            // Deep lookup for the most recent historical record that isn't NULL
            $sql = "SELECT $oldKey FROM users_old WHERE uid_old = ? AND $oldKey IS NOT NULL ORDER BY start_old DESC LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$res) return false;
            return (string)$currentVal !== (string)$res[$oldKey];
        }
    }

    $highlights = [];
    $fields = ['name', 'image', 'birthday', 'gender', 'height', 'hair', 'lefteye', 'righteye', 'specialfeatures'];

    // Check if any history exists at all for this user
    $sqlAny = "SELECT 1 FROM users_old WHERE uid_old = ? LIMIT 1";
    $stA = $conn->prepare($sqlAny);
    $stA->bind_param("i", $h_user);
    $stA->execute();
    $historyExists = $stA->get_result()->fetch_assoc() ? true : false;

    foreach ($fields as $f) {
        if (!$historyExists) {
            // No history records? Light up everything red
            $highlights[$f] = true;
        } elseif ($revisionId > 0) {
            // --- HISTORY MODE ---
            if ($f === 'name') {
                $sqlPrev = "SELECT usersName_old FROM users_old 
                            WHERE uid_old = ? AND start_old < ? 
                            ORDER BY start_old DESC LIMIT 1";
                $stP = $conn->prepare($sqlPrev);
                $stP->bind_param("is", $h_user, $currentTime);
                $stP->execute();
                $prevResult = $stP->get_result()->fetch_assoc();
                
                if ($prevResult) {
                    $highlights['name'] = (trim($displayData['usersName']) !== trim($prevResult['usersName_old']));
                } else {
                    $highlights['name'] = true; 
                }
            } else {
                $dbOldKey = [
                    'image' => 'image_old', 'birthday' => 'birthday_old',
                    'gender' => 'gender_old', 'height' => 'height_old', 'hair' => 'hair_old',
                    'lefteye' => 'leftEye_old', 'righteye' => 'rightEye_old', 'specialfeatures' => 'specialFeatures_old'
                ][$f];
                $highlights[$f] = (isset($rawChanges[$dbOldKey]) && $rawChanges[$dbOldKey] !== null);
            }
        } else {
            // --- CURRENT MODE (rev=0) ---
            $valToCompare = [
                'name' => $h_name, 
                'image' => $h_img_data, 
                'birthday' => $displayData['birthday'], 
                'gender' => $h_gender, 
                'height' => $h_height, 
                'hair' => $h_hair,
                'lefteye' => $h_lefteye, 
                'righteye' => $h_righteye, 
                'specialfeatures' => $h_specialfeatures
            ][$f];
            
            $highlights[$f] = hasChanged($f, $valToCompare, $conn, $h_user);
        }
    }



    //================================================
    //                SHOW SCREEN
    //================================================

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";

            echo "<div class='medium_line'></div>";
            
            echo "<div class='button4-row'>";


                // Column 1: Privacy Toggle (Allow/Block)
                echo "<div class='button4-column1'>";
                    $myId = (int)$_SESSION['userid']; 
                    $targetId = (int)$h_user;

                    // THE FIX: Only process and show privacy buttons if viewing SOMEONE ELSE
                    if ($myId !== $targetId) {
                        $encodedFrom = urlencode($from_page);

                        // Fetch YOUR whitelist mode
                        $sqlMode = "SELECT useWhitelist FROM users WHERE usersId = ?";
                        $stmtM = $conn->prepare($sqlMode);
                        $stmtM->bind_param("i", $myId);
                        $stmtM->execute();
                        $myMode = (int)($stmtM->get_result()->fetch_assoc()['useWhitelist'] ?? 0);

                        if ($myMode === 1) {
                            // WHITELIST LOGIC
                            $checkW = "SELECT 1 FROM lists WHERE ownerId = ? AND targetId = ? AND listType = 1 LIMIT 1";
                            $stmtW = $conn->prepare($checkW);
                            $stmtW->bind_param("ii", $myId, $targetId); 
                            $stmtW->execute();
                            $isOnWhitelist = $stmtW->get_result()->num_rows > 0;

                            if ($isOnWhitelist) {
                                echo "<a href='includes/list-handler.inc.php?action=remove_white&target=$targetId&from=$encodedFrom'>";
                                    echo "<button type='button' class='error-button'>".t('HD_BLOCK')."</button>";
                                echo "</a>";
                            } else {
                                echo "<a href='includes/list-handler.inc.php?action=allow_white&target=$targetId&from=$encodedFrom'>";
                                    echo "<button type='button' class='login-button'>".t('HD_UNBLOCK')."</button>";
                                echo "</a>";
                            }
                        } else {
                            // BLACKLIST LOGIC
                            if ($isBlocked) {
                                echo "<a href='includes/list-handler.inc.php?action=unblock&target=$targetId&from=$encodedFrom'>";
                                    echo "<button type='button' class='login-button'>".t('HD_UNBLOCK')."</button>";
                                echo "</a>";
                            } else {
                                echo "<a href='includes/list-handler.inc.php?action=block_direct&target=$targetId&from=$encodedFrom'>";
                                    echo "<button type='button' class='error-button'>".t('HD_BLOCK')."</button>";
                                echo "</a>";
                            }
                        }
                    } else {
                        // If it's the user looking at themselves, we leave this column empty
                        // to maintain the 3-column layout spacing.
                        echo "&nbsp;";
                    }
                echo "</div>";


                // Column 2: Title
                echo "<div class='button4-column2'>";
                    echo "<p class='title'>".mb_strtoupper(t('HD_TITLE'), 'UTF-8')."</p>";
                echo "</div>";

                // Column 3: Back Button
                echo "<div class='button4-column3'>";
                    echo "<a href='$from_page'><button type='button' class='login-button'>".t('PR_BACK')."</button></a>";
                echo "</div>";

            echo "</div>"; 
            echo "<div class='medium_line'></div>";

            // --- IMAGE DISPLAY ---
            // echo "<div class='button9-row'>";
            //     echo "<div class='button9-column1'>";
            //         echo "<p class='title'><img src='" . $h_img_data . "' class='humanicon' /></p>";
            //     echo "</div>";
            // echo "</div>";


            // --- IMAGE DISPLAY ---
            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'>";
                    
                    // Determine if the image should flash based on our previous logic
                    $imgFlashClass = ($highlights['image'] ?? false) ? "highlight-image-contour" : "";

                    // Combine the base class 'humanicon' with the optional flash class
                    echo "<p class='title'><img src='" . $h_img_data . "' class='humanicon $imgFlashClass' /></p>";
                    
                echo "</div>";
            echo "</div>";


            echo "<div class='small_line'></div>";

            // echo "<div class='button9-row'>";
            //     echo "<div class='button9-column1'>";
            //         echo "<p class='title'>" . htmlspecialchars($h_name) . "</p>";
            //     echo "</div>";
            // echo "</div>";


            // --- NAME DISPLAY ---
            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'>";
                    $nameFlashClass = ($highlights['name'] ?? false) ? "highlight-text" : "";
                    echo "<p class='title $nameFlashClass'>" . htmlspecialchars($h_name) . "</p>";
                echo "</div>";
            echo "</div>";

            echo "<div class='medium_line'></div>";

            echo "<div class='button5-row'>";
                echo "<div class='button5-column1'>";
                    echo "<label class='form__label'>".t('HD_BALANCE')."</label>";
                    $returnPath = urlencode("humandetails.php?user=" . (int)$h_user . "&rev=" . (int)$revisionId);

                    echo "<a href='transactions.php?user=" . (int)$h_user . "&from=$returnPath' class='balance-link'>";
                        $formattedBalance = ($h_availablecoins > 99999999.99) ? number_format($h_availablecoins, 0) : number_format($h_availablecoins, 2);
                        echo "<div class='form__numbers2' style='color:var(--button); cursor:pointer;'>";
                            echo $formattedBalance . " ᕫ";
                        echo "</div>";
                    echo "</a>";
                echo "</div>";
                echo "<div class='button5-column2'></div>";
                echo "<div class='button5-column3'>";
                    echo "<label class='form__label2'>".t('HD_ACCOUNT')."</label><br>";
                    $padded = str_pad($h_user, 10, "0", STR_PAD_LEFT);
                    $displayNum = substr($padded, 0, 1) . " " . substr($padded, 1, 3) . " " . substr($padded, 4, 3) . " " . substr($padded, 7, 3);
                    echo "<div class='form__numbers2'>" . $displayNum . "</div>";
                echo "</div>";
            echo "</div>";


            echo "<div class='small_line'></div>";
            echo "<div class='button5-row'>";
                // Birthday Column
                echo "<div class='button5-column1'>";
                    echo "<label class='form__label2'>".t('SU_BD')."</label><br>";
                    
                    // Use highlight-change for background flash
                    $bdFlash = ($highlights['birthday'] ?? false) ? "highlight-change" : "";
                    echo "<div class='form__numbers2 $bdFlash'>$h_birthday</div>";
                echo "</div>";

                echo "<div class='button5-column2'></div>";

                // Gender Column
                echo "<div class='button5-column3'>";
                    echo "<label class='form__label2'>".t('SU_GN')."</label><br>";
                    
                    // Use highlight-change for background flash
                    $gnFlash = ($highlights['gender'] ?? false) ? "highlight-change" : "";
                    $gender_key = ($h_gender == 0) ? 'SU_ML' : (($h_gender == 1) ? 'SU_FM' : 'SU_OT');
                    echo "<div class='form__text2 $gnFlash'>".t($gender_key)."</div>";
                echo "</div>";
            echo "</div>";


            echo "<div class='small_line'></div>";
            echo "<div class='button5-row'>";
                // Height Column
                echo "<div class='button5-column1'>";
                    echo "<label class='form__label2'>".t('SU_HT')."</label><br>";
                    
                    // Background flash for height
                    $hgtFlash = ($highlights['height'] ?? false) ? "highlight-change" : "";
                    echo "<div class='form__numbers2 $hgtFlash'>$h_height</div>";
                echo "</div>";

                echo "<div class='button5-column2'></div>";

                // Hair Color Column
                echo "<div class='button5-column3'>";
                    echo "<label class='form__label2'>".t('SU_HR')."</label><br>";
                    
                    // Background flash for hair color
                    $hairFlash = ($highlights['hair'] ?? false) ? "highlight-change" : "";
                    echo "<div class='form__text2 $hairFlash'>".t("HC_$h_hair")."</div>";
                echo "</div>";
            echo "</div>";


            echo "<div class='small_line'></div>";
            echo "<div class='button5-row'>";
                // Left Eye Column
                echo "<div class='button5-column1'>";
                    echo "<label class='form__label2'>".t('SU_LE')."</label><br>";
                    
                    // Background flash for Left Eye
                    $leFlash = ($highlights['lefteye'] ?? false) ? "highlight-change" : "";
                    echo "<div class='form__text2 $leFlash'>".t("EC_$h_lefteye")."</div>";
                echo "</div>";

                echo "<div class='button5-column2'></div>";

                // Right Eye Column
                echo "<div class='button5-column3'>";
                    echo "<label class='form__label2'>".t('SU_RE')."</label><br>";
                    
                    // Background flash for Right Eye
                    $reFlash = ($highlights['righteye'] ?? false) ? "highlight-change" : "";
                    echo "<div class='form__text2 $reFlash'>".t("EC_$h_righteye")."</div>";
                echo "</div>";
            echo "</div>";



            echo "<div class='small_line'></div>";
            if (!empty($h_specialfeatures)) {
                echo "<label class='form__label'>".t('SU_SF')."</label>";
                
                // Determine if special features should highlight
                $sfFlash = ($highlights['specialfeatures'] ?? false) ? "highlight-change" : "";

                echo "<textarea class='special-features $sfFlash' id='specialfeatures' readonly style='resize: none;'>" . htmlspecialchars($h_specialfeatures) . "</textarea>";
            }


            echo "<div class='small_line'></div>";

            // --- STATISTICS SECTION ---
            // Only show if revisionId is 0 (Current record)
            if ($revisionId === 0) {
                echo "<label class='form__label'>".t('HD_STATS')."</label>";
                
                $h_days = $h_hours / 24;
                $vdays = ($h_days < 10) ? number_format($h_days, 1) : number_format($h_days, 0);
                
                $p_others = $stats['h_giver'] - $stats['m_receiver'];
                $r_others = $stats['h_receiver'] - $stats['m_giver'];

                $h_statistics = t('ST_PART') . " " . $vdays . " " . t('ST_DAYS') . "    " . t('ST_TRUST') . " " . $h_final_trust . "%\n" . 
                                t('ST_PAID_YOU') . ": " . $stats['m_receiver'] . ", " . t('ST_FROM_YOU') . ": " . $stats['m_giver'] . "\n" . 
                                t('ST_PAID_OTHERS') . " " . $p_others . ", " . t('ST_RECEIVED') . ": " . $r_others . "\n" . 
                                t('ST_UNI_PAID') . ": " . $stats['h_giver_unique'] . ", " . t('ST_UNI_REC') . ": " . $stats['h_receiver_unique'];

                echo "<textarea 
                    class='special-features' 
                    id='specialfeatures_stats' 
                    readonly 
                    style='resize: none;'>" . htmlspecialchars($h_statistics) . "</textarea>";
                
                // Add a line under stats if it's visible
                echo "<div class='small_line'></div>";
            }



            // FINAL FOOTER NAVIGATION
            // Use $currentTime instead of the array key directly
            $footerTimestamp = strtotime($currentTime ?? 'now');
            $dayPart = date("d", $footerTimestamp);
            $monthPart = t("M_" . date("n", $footerTimestamp));
            $yearPart = date("Y", $footerTimestamp);

            $formattedFooterDate = "$dayPart $monthPart $yearPart";

            echo "<div class='full-screen-line'></div>";
            echo "<div class='hd-footer-row'>";
                echo "<div class='hd-date-group'>";
                    
                    // BACK ARROW (<) - Older History
                    if ($prevRev) {
                        echo "<a href='humandetails.php?user=$h_user&rev=$prevRev&from=".urlencode($from_page)."'><button type='button' class='time-nav-button'>&nbsp;&nbsp;&nbsp;&lt;&nbsp;&nbsp;&nbsp;</button></a>";
                    } else {
                        echo "<button type='button' class='time-nav-button nav-disabled' disabled>&nbsp;&nbsp;&nbsp;&lt;&nbsp;&nbsp;&nbsp;</button>";
                    }
  
                    echo "<div class='hd-date-display'>$formattedFooterDate</div>";

                    $isSelf = ((int)$_SESSION['userid'] === (int)$h_user);

                    // FORWARD ARROW (>) logic
                    if ($revisionId !== 0) {
                        // 1. We are currently looking at HISTORY (users_old)
                        if ($nextRev === 0 && $isSelf) {
                            // If next record is 'Current' AND it's ME, go straight to Profile
                            echo "<a href='profile.php'><button type='button' class='time-nav-button'>&nbsp;&nbsp;&nbsp;&gt;&nbsp;&nbsp;&nbsp;</button></a>";
                        } else {
                            // Otherwise, move forward to the next historical record or the current record
                            echo "<a href='humandetails.php?user=$h_user&rev=$nextRev&from=".urlencode($from_page)."'><button type='button' class='time-nav-button'>&nbsp;&nbsp;&nbsp;&gt;&nbsp;&nbsp;&nbsp;</button></a>";
                        }
                    } else {
                        // 2. We are currently looking at the CURRENT record (users table)
                        if ($isSelf) {
                            // If it's ME, the arrow takes me back to my profile
                            echo "<a href='profile.php'><button type='button' class='time-nav-button'>&nbsp;&nbsp;&nbsp;&gt;&nbsp;&nbsp;&nbsp;</button></a>";
                        } else {
                            // THE FIX: If it's SOMEONE ELSE, disable the arrow at the current record
                            echo "<button type='button' class='time-nav-button nav-disabled' disabled>&nbsp;&nbsp;&nbsp;&gt;&nbsp;&nbsp;&nbsp;</button>";
                        }
                    }


                echo "</div>";

                echo "<div class='hd-download-group'>";

                    // Passing 'rev' ensures the PDF page knows which historic version to show
                    echo "<a href='download-pdf.php?user=$h_user&rev=$revisionId&from=human' class='btn-gap-wide'>";
                        echo "<button type='button' class='login-button'>PDF</button>";
                    echo "</a>";

                    // CSV Button
                    echo "<a href='download-csv.php?user=$h_user' target='_blank' class='csv-edge-margin'>";
                        echo "<button type='button' class='login-button'>CSV</button>";
                    echo "</a>";

                echo "</div>";
            echo "</div>";

        echo "</div>";
    echo "</div>"; // close field-wrapper and centerContainer
    ob_end_flush();
?>
