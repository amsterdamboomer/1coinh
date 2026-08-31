<?php
    session_start();

    if (isset($_POST["submit"])) {
        require_once 'dbh.inc.php';
        require_once 'functions.inc.php';

        $userId = $_SESSION["userid"];

        // --- 1. SAVE PERMANENT LANGUAGE & WHITELIST (Stand-alone) ---
        if (isset($_SESSION['form_data']['temp_language'])) {
            $newLang = $_SESSION['form_data']['temp_language'];
            
            $sqlLang = "UPDATE users SET language = ? WHERE usersId = ?;";
            $stmtLang = mysqli_stmt_init($conn);
            if (mysqli_stmt_prepare($stmtLang, $sqlLang)) {
                mysqli_stmt_bind_param($stmtLang, "si", $newLang, $userId);
                mysqli_stmt_execute($stmtLang);
                mysqli_stmt_close($stmtLang);
                
                // Update active session so header.php sees the change immediately
                $_SESSION['user_lang'] = $newLang;
                setcookie('site_lang', $newLang, time() + (86400 * 30), "/");
            }
            // Clear the draft choice
            unset($_SESSION['form_data']['temp_language']);
        }

        // --- 2. SAVE FORM DATA TO SESSION (After language cleanup) ---
        $_SESSION['form_data'] = $_POST;

        // ------------------------------------

        $name      = $_POST["name"];
        $email     = $_POST["email"];
        $username  = $_POST["uid"];
        //===================================
        // secure image format from breaking
        //===================================
        $rawImage = $_POST['image_source'] ?? '';

        // If the string contains data but spaces replaced the plus signs, fix them
        if (strpos($rawImage, ' ') !== false && strpos($rawImage, 'data:image') !== false) {
            $rawImage = str_replace(' ', '+', $rawImage);
        }
        $image = $rawImage;
        //===================================
        $birthday  = $_POST["birthday"];
        $gender    = $_POST["gender"];
        $height    = $_POST["height"];
        $hair      = $_POST["hair"];
        $lefteye   = $_POST["lefteye"];
        $righteye  = $_POST["righteye"];
        $specialfeatures = $_POST["specialfeatures"];

        // --- Format height before validation and updates ---
        $m = ""; 
        $fi = "";
        checkHeight($height, $m, $fi);
        if ($fi !== "") {
            $height = $fi;
        } elseif ($m !== "") {
            $height = $m;
        }
        $_SESSION['form_data']['height'] = $height; 

        // --------------------------------------------------------

        // --- 1. VALIDATION CHECKS (Restored) ---
        if (emptyInputProfile($name, $email, $username) !== false) {
            header("location: ../profile.php?error=emptyinput");
            exit();
        }
        if (invalidUid($username) !== false) {
            header("location: ../profile.php?error=invaliduid");
            exit();
        }
        if (invalidEmail($email) !== false) {
            header("location: ../profile.php?error=invalidemail");
            exit();
        }
        if (invalidBirthday($birthday) !== false) {
            header("location: ../profile.php?error=invalidbirthday");
            exit();
        }
        if (invalidHeight($height) !== false) {
            header("location: ../profile.php?error=invalidheight");
            exit();
        }
        if (invalidImage($image) !== false) {
            header("location: ../profile.php?error=invalidimage");
            exit();
        }
        if (invalidInput($name, $email, $username, $height, $image, $specialfeatures) !== false) {
            header("location: ../profile.php?error=invalidinput");
            exit();
        }
        // --- 2. IDENTITY CHECK ---
        $existingUser = uidExists($conn, $username, $email);
        if ($existingUser !== false && $existingUser["usersId"] != $userId) {
            header("location: ../profile.php?error=usernametaken");
            exit();
        }

        // --- 3. FETCH CURRENT DATA ---
        $sql = "SELECT * FROM users WHERE usersId = ?;";
        $stmt = mysqli_stmt_init($conn);
        if (!mysqli_stmt_prepare($stmt, $sql)) {
            header("location: ../profile.php?error=stmtfailed");
            exit();
        }
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $resultData = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($resultData)) {
            $currentUser = $row;
        } else {
            header("location: ../profile.php?error=usernotfound");
            exit();
        }
        mysqli_stmt_close($stmt); 


        // --- 3. CHECK FOR PHYSICAL CHANGES (OPTIMIZATION) ---
        $physicalDataChanged = (
            $name !== $currentUser['usersName'] ||
            $image !== $currentUser['image'] ||
            $birthday !== $currentUser['birthday'] ||
            (int)$gender !== (int)$currentUser['gender'] ||
            $height !== $currentUser['height'] ||
            (int)$hair !== (int)$currentUser['hair'] ||
            (int)$lefteye !== (int)$currentUser['leftEye'] ||
            (int)$righteye !== (int)$currentUser['rightEye'] ||
            $specialfeatures !== $currentUser['specialFeatures']
        );

        // Track if sensitive data changed
        $emailChanged = ($email !== $currentUser['usersEmail']);
        $uidChanged = ($username !== $currentUser['usersUid']);

        // If absolutely nothing changed, just go back
        if (!$physicalDataChanged && !$emailChanged && !$uidChanged) {
            header("location: ../profile.php?error=none");
            exit();
        }

        // --- 4. EXECUTE PHYSICAL DATA UPDATE (IF ANY) ---
        if ($physicalDataChanged) {
            // 1. Check for first update baseline
            $firstUpdate = true;
            $sqlHistory = "SELECT 1 FROM users_old WHERE uid_old = ? LIMIT 1;";
            $stmtHist = $conn->prepare($sqlHistory);
            $stmtHist->bind_param("i", $userId);
            $stmtHist->execute();
            if ($stmtHist->get_result()->fetch_assoc()) { $firstUpdate = false; }
            $stmtHist->close();

            // Initialize all as NULL
            $auditImage = $auditBirthday = $auditGender = $auditHeight = $auditHair = $auditLeftEye = $auditRightEye = $auditSpecs = null;
            $auditName = $currentUser['usersName'];

            if ($firstUpdate) {
                // Save full baseline
                $auditImage    = $currentUser['image'];
                $auditBirthday = $currentUser['birthday'];
                $auditGender   = (int)$currentUser['gender'];
                $auditHeight   = $currentUser['height'];
                $auditHair     = (int)$currentUser['hair'];
                $auditLeftEye  = (int)$currentUser['leftEye'];
                $auditRightEye = (int)$currentUser['rightEye'];
                $auditSpecs    = $currentUser['specialFeatures'];
            } else {
                // SUBSEQUENT: Compare Current DB vs Last Saved (Non-NULL) Archive
                $fieldsToCompare = [
                    'image' => 'image_old', 'birthday' => 'birthday_old', 'gender' => 'gender_old', 
                    'height' => 'height_old', 'hair' => 'hair_old', 'leftEye' => 'leftEye_old', 
                    'rightEye' => 'rightEye_old', 'specialFeatures' => 'specialFeatures_old'
                ];

                foreach ($fieldsToCompare as $currentKey => $oldKey) {
                    $sql = "SELECT $oldKey FROM users_old WHERE uid_old = ? AND $oldKey IS NOT NULL ORDER BY start_old DESC LIMIT 1";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res = $stmt->get_result()->fetch_assoc();
                    $lastArchived = $res[$oldKey] ?? null;
                    $currentDB = $currentUser[$currentKey];

                    $isDiff = false;
                    if (in_array($currentKey, ['gender', 'hair', 'leftEye', 'rightEye'])) {
                        if ((int)$currentDB !== (int)$lastArchived) $isDiff = true;
                    } else {
                        if (trim($currentDB ?? '') !== trim($lastArchived ?? '')) $isDiff = true;
                    }

                    if ($isDiff) {
                        if ($currentKey === 'image') $auditImage = $currentDB;
                        if ($currentKey === 'birthday') $auditBirthday = $currentDB;
                        if ($currentKey === 'gender') $auditGender = $currentDB;
                        if ($currentKey === 'height') $auditHeight = $currentDB;
                        if ($currentKey === 'hair') $auditHair = $currentDB;
                        if ($currentKey === 'leftEye') $auditLeftEye = $currentDB;
                        if ($currentKey === 'rightEye') $auditRightEye = $currentDB;
                        if ($currentKey === 'specialFeatures') $auditSpecs = $currentDB;
                    }
                    $stmt->close();
                }
            }

            // 2. Final Archive Save
            $sqlOld = "INSERT INTO users_old (usersName_old, uid_old, image_old, birthday_old, gender_old, height_old, hair_old, leftEye_old, rightEye_old, specialFeatures_old, start_old, hash_old) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);";
            $stO = $conn->prepare($sqlOld);
            $stO->bind_param("sissssssssss", $auditName, $userId, $auditImage, $auditBirthday, $auditGender, $auditHeight, $auditHair, $auditLeftEye, $auditRightEye, $auditSpecs, $currentUser['start'], $currentUser['hash']);
            $stO->execute();
            $stO->close();

            // 3. UPDATE Main Table with NEW data
            $startDate = date("Y-m-d H:i:s"); 
            $lastHash = $currentUser['hash'];

            // Ensure hash uses consistent types
            $dataToHash = $name . $image . $birthday . (int)$gender . $height . (int)$hair . (int)$lefteye . (int)$righteye . $specialfeatures . $startDate . $lastHash;
            $newHash = hash('sha256', $dataToHash);

            $sqlUpdate = "UPDATE users SET usersName=?, image=?, birthday=?, gender=?, height=?, hair=?, leftEye=?, rightEye=?, specialFeatures=?, start=?, lastHash=?, hash=? WHERE usersId=?;";
            $stmtUpd = $conn->prepare($sqlUpdate);
            // Integer variables for "i" binding
            $genderVal = (int)$gender; $hairVal = (int)$hair; $leVal = (int)$lefteye; $reVal = (int)$righteye;

            $stmtUpd->bind_param("sssisiiissssi", 
                $name, $image, $birthday, $genderVal, $height, $hairVal, 
                $leVal, $reVal, $specialfeatures, $startDate, $lastHash, $newHash, $userId
            );

            if (!$stmtUpd->execute()) {
                die("Main Table Update Failed: " . $stmtUpd->error);
            }
            $stmtUpd->close();

            $_SESSION['image'] = $image;
        }

        // --- 5. HANDLING SENSITIVE CHANGES (EMAIL/UID) ---
        if ($emailChanged || $uidChanged) {
            $token = bin2hex(random_bytes(16)); 
            $requestedAt = date("Y-m-d H:i:s");

            $sqlDelete = "DELETE FROM pending_changes WHERE usersId = ?;";
            $stmtDel = mysqli_stmt_init($conn);
            mysqli_stmt_prepare($stmtDel, $sqlDelete);
            mysqli_stmt_bind_param($stmtDel, "i", $userId);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);

            $sqlPending = "INSERT INTO pending_changes (usersId, newEmail, newUid, token, requestedAt) VALUES (?, ?, ?, ?, ?);";
            $stmtPending = mysqli_stmt_init($conn);
            if (mysqli_stmt_prepare($stmtPending, $sqlPending)) {
                $storeEmail = $emailChanged ? $email : null;
                $storeUid = $uidChanged ? $username : null;
                mysqli_stmt_bind_param($stmtPending, "issss", $userId, $storeEmail, $storeUid, $token, $requestedAt);
                mysqli_stmt_execute($stmtPending);
                mysqli_stmt_close($stmtPending);


                //=========================================================
                //                       EMAIL (Translated)
                //=========================================================
                global $translations;
                $lang = $_SESSION['user_lang'] ?? ($currentUser['language'] ?? 'en');

                $tr = function($key, $lang) use ($translations) {
                    return $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
                };

                $url = "www.1coinh.com/confirm-profile.php?token=" . $token;
                $headers = "From: Abundomy Money <info@1coinh.com>\r\n";
                $headers .= "Reply-To: info@1coinh.com\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

                $bookInfo = '<br><br><p>' . $tr('PY_FOOTER', $lang) . ' <a href="https://abundomy.com">abundomy.com</a>.</p>';

                // --- 1. Security Alert Email (ONLY if Email Address changed) ---
                if ($emailChanged) {
                    $noChange = $tr('PR_NO_CHANGE', $lang);
                    $messageOld = '<html><body>';
                    $messageOld .= '<p>' . sprintf($tr('PR_HELLO', $lang), htmlspecialchars($currentUser['usersName'])) . '</p>';
                    $messageOld .= '<p>' . $tr('PR_REQUEST_MADE', $lang) . '</p>';
                    $messageOld .= '<ul>';
                    $messageOld .= '<li><b>' . $tr('PR_LBL_NEW_UID', $lang) . ':</b> ' . ($uidChanged ? htmlspecialchars($username) : $noChange) . '</li>';
                    $messageOld .= '<li><b>' . $tr('PR_LBL_NEW_EMAIL', $lang) . ':</b> ' . htmlspecialchars($email) . '</li>';
                    $messageOld .= '</ul>';
                    $messageOld .= '<p>' . $tr('PR_SECURITY_TEXT', $lang) . '</p>';
                    $messageOld .= $bookInfo . '</body></html>';

                    mail($currentUser['usersEmail'], $tr('PR_SUBJ_ALERT', $lang), $messageOld, $headers);
                }

                // --- 2. Confirmation Link Email ---
                $toNew = $emailChanged ? $email : $currentUser['usersEmail'];
                $messageNew = '<html><body>';
                $messageNew .= '<p>' . $tr('PR_CLICK_LINK', $lang) . '</p>';
                $messageNew .= '<p><a href="https://' . $url . '">https://' . $url . '</a></p>';
                $messageNew .= '<p>' . $tr('PR_EXPIRY', $lang) . '</p>';
                // Added the "neglect" sentence here:
                $messageNew .= '<p><i>' . $tr('PR_NEGLECT', $lang) . '</i></p>';
                $messageNew .= $bookInfo . '</body></html>';

                $mailSuccess = mail($toNew, $tr('PR_SUBJ_CONFIRM', $lang), $messageNew, $headers);
                //=========================================================

                // Handle Redirects based on mail success
                // THE FIX: Check for BOTH email and UID changes, as both require verification
                if ($emailChanged || $uidChanged) {
                    if ($mailSuccess) {
                        // Clear session to force a fresh login with new credentials later
                        session_unset();
                        session_destroy();
                        
                        // Redirect to index with the specific error code for the feedback message
                        header("location: ../index2.php?error=checkemail");
                        exit();
                    } else {
                        // If mail fails, return to profile to try again
                        $fromPath = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_URL) ?: "index2.php";
                        header("location: ../profile.php?error=dbase&from=" . urlencode($fromPath));
                        exit();
                    }
                }

            }
        }

        // 2. Default success (Standard profile update)
        $fromPath = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_URL) ?: "index2.php";
        if (strpos($fromPath, '../') !== 0) { $fromPath = "../" . $fromPath; }
        
        // SAVE SESSION BEFORE REDIRECT
        session_write_close(); 
        header("location: " . $fromPath);
        exit();
    } else {
        $fromPath = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_URL) ?: "profile.php";
        if (strpos($fromPath, '../') !== 0) { $fromPath = "../" . $fromPath; }
        $encodedFrom = urlencode($fromPath);
        
        // SAVE SESSION BEFORE REDIRECT
        session_write_close(); 
        header("location: ../profile.php?error=invalidaccess&from=$encodedFrom");
        exit();
    }