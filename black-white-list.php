<?php
    require "header.php";

    $myId = $_SESSION['userid'];
    // 0 = Blacklist mode, 1 = Whitelist mode
    $useWhitelist = $userData['useWhitelist'] ?? 0; 
    
    // Set dynamic titles using keys
    $listTitleKey = $useWhitelist ? 'BW_WHITE_LIST' : 'BW_BLACK_LIST';
    $listTitle = t($listTitleKey);

    // Capture the UI state (X)
    $X = isset($_GET['button']) ? (int)$_GET['button'] : 1;
    $selectedTargets = $_POST['selected_targets'] ?? [];

    // Fetch the current list from the database
    $sql = "SELECT l.*, u.usersName, u.image 
            FROM lists l 
            JOIN users u ON l.targetId = u.usersId 
            WHERE l.ownerId = ? AND l.listType = ?
            ORDER BY u.usersName ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $myId, $useWhitelist);
    $stmt->execute();
    $listResult = $stmt->get_result();

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='medium_line'></div>";
            
            // --- 1. TITLE ROW ---
            echo "<div class='button4-row'>";
                echo "<div class='button4-column1'></div>";
                echo "<div class='button4-column2' style='display:flex; flex-direction:column; align-items:center;'>";
                    echo "<p class='title'>".t('BW_TITLE_CONTACTS')."</p>";
                    echo "<p class='display-num' style='margin-top:-5px; color:var(--text); text-transform: uppercase;'>$listTitle</p>";
                echo "</div>";
                echo "<div class='button4-column3'><a href='profile.php'><button class='login-button'>".t('BW_BTN_BACK')."</button></a></div>";
            echo "</div>";
            echo "<div class='full_line'></div>";

            // --- 2. MAIN MANAGEMENT VIEW (X=1) ---
            if ($X == 1) {
                echo "<form action='black-white-list.php?button=2' method='POST' id='managementForm'>";
                    if ($listResult->num_rows > 0) {
                        echo "<div class='management-button-row'>";
                            echo "<div class='manage-btn-col'>";
                                echo "<button type='submit' id='delete-btn' class='login-button'>".t('BW_BTN_DELETE')."</button>";
                            echo "</div>";
                            echo "<div class='manage-btn-col' style='text-align: right;'>";
                                echo "<button type='button' id='toggleSelect' class='login-button' onclick='toggleAll()'>".t('BW_BTN_SELECT_ALL')."</button>";
                            echo "</div>";
                        echo "</div>";
                        echo "<div class='medium_line'></div>";
                    }


                  // --- THE LISTBOX ---
                    echo "<div class='listbox-container' style='max-height: 450px;'>";

                    if ($listResult->num_rows == 0) {
                        echo "<p class='feedback' style='padding:20px;'>".sprintf(t('BW_EMPTY_MSG'), $listTitle)."</p>";
                    } else {
                        $t_row = null;
                        while ($t_row = $listResult->fetch_assoc()) {
                            $c_id   = $t_row['targetId'];
                            $c_name = $t_row['usersName'];
                            $c_img  = $t_row['image'];

                            echo "<div class='manage-row'>";
                                echo "<div class='col-check'>";
                                    echo "<input type='checkbox' name='selected_targets[]' class='manage-checkbox' value='".(int)$c_id."' onchange='updateUIState()'>";
                                echo "</div>";
                                echo "<div class='col-img'>";
                                    echo "<a href='humandetails.php?user=".(int)$c_id."&from=black-white-list.php'>";
                                        echo "<img src='".$c_img."' class='mainusericon' style='width: 50px; height: 50px;'>";
                                    echo "</a>";
                                echo "</div>";
                                echo "<div class='col-name'>".htmlspecialchars($c_name)."</div>";
                            echo "</div>";
                            unset($c_id, $c_name, $c_img);
                        }
                    }
                    echo "</div>";
                echo "</form>";
            }

            // --- 3. FEEDBACK / CONFIRMATION SECTION (X=2) ---
            echo "<div class='signupdiv'>";
                if ($X == 2) {
                    if (empty($selectedTargets)) {
                        echo "<p class='error'>".t('BW_ERR_NO_SELECTION')."</p>";
                        echo "<div class='small_line'></div>";
                        echo "<div class='centered-button-wrap'>";
                            echo "<a href='black-white-list.php'><button class='login-button'>".t('BW_BTN_BACK')."</button></a>";
                        echo "</div>";
                    } else {
                        $count = count($selectedTargets);
                        $hasRequests = false;
                        $displayName = "";

                        $placeholders = implode(',', array_fill(0, $count, '?'));
                        $sqlCheck = "SELECT 1 FROM proposals 
                                     WHERE (giver = ? AND receiver IN ($placeholders)) 
                                     OR (receiver = ? AND giver IN ($placeholders)) LIMIT 1";
                        $stmtC = $conn->prepare($sqlCheck);
                        $types = 'i' . str_repeat('i', $count) . 'i' . str_repeat('i', $count);
                        $params = array_merge([$myId], $selectedTargets, [$myId], $selectedTargets);
                        $stmtC->bind_param($types, ...$params);
                        $stmtC->execute();
                        if ($stmtC->get_result()->num_rows > 0) { $hasRequests = true; }

                        // 2. Action Word Keys
                        $actionKey = $useWhitelist ? "BW_ACT_BLOCK" : "BW_ACT_UNBLOCK";
                        $actionWord = t($actionKey);

                        if ($count === 1) {
                            $sqlN = "SELECT usersName FROM users WHERE usersId = ?";
                            $stmtN = $conn->prepare($sqlN);
                            $stmtN->bind_param("i", $selectedTargets[0]); 
                            $stmtN->execute();
                            $displayName = $stmtN->get_result()->fetch_assoc()['usersName'] ?? t('BW_PERSON');
                            // Translation: "Block [Name]?" or "Unblock [Name]?"
                            echo "<p class='feedback'>".sprintf(t('BW_CONFIRM_SINGLE'), $actionWord, $displayName)."</p>";
                        } else {
                            // Translation: "Block entire selection (5 people)?"
                            echo "<p class='feedback'>".sprintf(t('BW_CONFIRM_MULTI'), $actionWord, $count)."</p>";
                        }

                        if ($hasRequests && $useWhitelist) {
                            echo "<p class='feedback'>".t('BW_CONFIRM_DEL_REQ')."</p>";
                        }

                        echo "<form action='includes/list-handler.inc.php' method='POST'>";
                            foreach ($selectedTargets as $tId) {
                                echo "<input type='hidden' name='selected_targets[]' value='".(int)$tId."'>";
                            }
                            echo "<input type='hidden' name='list_type' value='$useWhitelist'>";
                            echo "<div class='small_line'></div>";
                            echo "<div class='centered-button-wrap'>";
                                echo "<button type='submit' name='action' value='delete_selected' class='login-button'>".t('BW_BTN_PROCEED')."</button>";
                            echo "</div>";
                        echo "</form>";
                    }
                }
            echo "</div>";
            echo "<div class='full_line'></div>";
        echo "</div>";
    echo "</div>";
?>

<script>
    function updateUIState() {
        const checkboxes = document.querySelectorAll('.manage-checkbox');
        const deleteBtn = document.getElementById('delete-btn');
        const toggleBtn = document.getElementById('toggleSelect');
        
        if (checkboxes.length === 0) {
            if (deleteBtn) deleteBtn.disabled = true;
            return; 
        }

        const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        
        if (deleteBtn) {
            if (checkedCount === 0) {
                deleteBtn.disabled = true;
                deleteBtn.style.opacity = "0.3";
                deleteBtn.style.cursor = "not-allowed";
                deleteBtn.style.filter = "grayscale(1)";
            } else {
                deleteBtn.disabled = false;
                deleteBtn.style.opacity = "1";
                deleteBtn.style.cursor = "pointer";
                deleteBtn.style.filter = "grayscale(0)";
            }
        }

        if (toggleBtn) {
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            // Dynamic text for JS toggle
            toggleBtn.innerText = allChecked ? '<?php echo t('BW_BTN_DESELECT_ALL'); ?>' : '<?php echo t('BW_BTN_SELECT_ALL'); ?>';
        }
    }

    function toggleAll() {
        const checkboxes = document.querySelectorAll('.manage-checkbox');
        const toggleBtn = document.getElementById('toggleSelect');
        if (!toggleBtn || checkboxes.length === 0) return;

        const shouldSelect = (toggleBtn.innerText === '<?php echo t('BW_BTN_SELECT_ALL'); ?>');
        checkboxes.forEach(cb => cb.checked = shouldSelect);
        updateUIState();
    }

    document.addEventListener('DOMContentLoaded', updateUIState);
</script>
</body>
</html>