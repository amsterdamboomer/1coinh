<?php
//batch 1
    include_once "header.php";
    if (!isset($_SESSION["userid"])) {
        header("Location: index2.php?error=notloggedin");
        exit();
    }

    $from_page = filter_input(INPUT_GET, 'from', FILTER_SANITIZE_URL) ?: "index2.php";
    $userId = $_SESSION["userid"];

    // --- 1. ALWAYS FETCH CURRENT DB DATA ---
    // This ensures $userData and 'image' are never undefined, even after a POST/Save
    $sql = "SELECT * FROM users WHERE usersId = ?;";
    $stmt = mysqli_stmt_init($conn);
    if (mysqli_stmt_prepare($stmt, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $resultData = mysqli_stmt_get_result($stmt);
        $userData = mysqli_fetch_assoc($resultData);
        mysqli_stmt_close($stmt);
    }

    if (!$userData) {
        exit("User not found.");
    }

    $savedDbLang = $userData['language'];

    // --- 2. INITIALIZE SESSION DATA ---
    // If it's the first time visiting or just after a fresh login, populate session from DB
    if (!isset($_SESSION['form_data']['name'])) {
        $pendingLang = $_SESSION['form_data']['temp_language'] ?? null;
        $_SESSION['image'] = $userData['image'];
        $_SESSION['form_data'] = [
            'name'            => $userData['usersName'],
            'email'           => $userData['usersEmail'],
            'uid'             => $userData['usersUid'],
            'image'           => $userData['image'],
            'birthday'        => $userData['birthday'],
            'gender'          => $userData['gender'],
            'height'          => $userData['height'],
            'hair'            => $userData['hair'],
            'lefteye'         => $userData['leftEye'],
            'righteye'        => $userData['rightEye'],
            'specialfeatures' => $userData['specialFeatures'],
            'newsletter'      => $userData['newsletter'],
            'paymentEmails'   => $userData['paymentEmails']
        ];
        if ($pendingLang) {
            $_SESSION['form_data']['temp_language'] = $pendingLang;
        }
        $clearLocalImage = true; 
    }

    // Safety: Ensure 'image' key exists even if session was partially cleared
    if (!isset($_SESSION['form_data']['image'])) {
        $_SESSION['form_data']['image'] = $userData['image'];
    }

    // --- 3. HISTORY FOR HIGHLIGHTING ---
    $lastOld = null;
    $sqlLast = "SELECT * FROM users_old WHERE uid_old = ? ORDER BY start_old DESC LIMIT 1";
    $stmtL = $conn->prepare($sqlLast);
    $stmtL->bind_param("i", $userId);
    $stmtL->execute();
    $lastOld = $stmtL->get_result()->fetch_assoc();



    function hasChanged($field, $currentVal, $conn, $userId) {
        $map = [
            'name' => 'usersName_old', 'image' => 'image_old', 'birthday' => 'birthday_old',
            'gender' => 'gender_old', 'height' => 'height_old', 'hair' => 'hair_old',
            'lefteye' => 'leftEye_old', 'righteye' => 'rightEye_old', 'specialfeatures' => 'specialFeatures_old'
        ];
        
        if (!isset($map[$field])) return false;
        $oldKey = $map[$field];

        // Deep lookup to find the most recent historical record that isn't NULL
        $sql = "SELECT $oldKey FROM users_old WHERE uid_old = ? AND $oldKey IS NOT NULL ORDER BY start_old DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$res) return false;

        // Compare strings (e.g. "1.8" vs "1.9")
        return (string)$currentVal !== (string)$res[$oldKey];
    }


    // POST HANDLING
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Merge POST data into existing session so we don't lose temp_language
        $_SESSION['form_data'] = array_merge($_SESSION['form_data'] ?? [], $_POST);
        
        $encodedFrom = urlencode($from_page);

        if (isset($_POST['go_to_language'])) {
            $deep_return = urlencode("profile.php?from=" . $encodedFrom);
            header("Location: set-language.php?temp=1&from=$deep_return");
            exit();
        }

        if (isset($_POST['go_to_image'])) {
            header("Location: image.php?return=profile&from=$encodedFrom");
            exit();
        }
    }


    echo "<div id='centerContainer'>";   
        echo "<div class='field-wrapper'>";
            // We encode it so the URL parameters don't break the form action string
            $encodedFrom = urlencode($from_page);
            // Change your form tag to include the 'from' parameter
            echo "<form action='includes/profile.inc.php?from=$encodedFrom' method='post' id='profileForm'>";

                echo "<div class='medium_line'></div>";
                echo "<div class='button4-row'>";
                    echo "<div class='button4-column1'>";
                        // Change 'Save' to t('PR_SAVE')
                        echo "<button type='submit' name='submit' class='login-button'>".t('PR_SAVE')."</button>";
                    echo "</div>";
                    echo "<div class='button4-column2'>";
                        // Change 'YOU' to t('SU_YOU')
                        //echo '<p class="title">'.t('SU_YOU').'</p>';
                        echo '<p class="title">' . mb_strtoupper(t('SU_YOU'), 'UTF-8') . '</p>';

                    echo "</div>";

                    echo "<div class='button4-column3'>";
                        $connector = (strpos($from_page, '?') !== false) ? '&' : '?';
                        echo "<a href='" . $baseHref . $from_page . $connector . "abort_lang=1'>";
                            echo "<button type='button' class='login-button' onclick='localStorage.removeItem(\"image1\");'>" . t('PR_BACK') . "</button>";
                        echo "</a>";
                    echo "</div>";

                echo "</div>";



//batch 2
                echo "<div>";
                    echo "<label class='form__label'>".t('SU_FN')."</label><br>";
                    $val = $_SESSION['form_data']['name'] ?? '';
                    // Updated to 4 arguments
                    $nameClass = hasChanged('name', $val, $conn, $userId) ? "form__input highlight-change" : "form__input";
                    echo "<input type='text' class='$nameClass' id='name' name='name' value='".htmlspecialchars($val)."' spellcheck='false' autocomplete='name' autocapitalize='off' autocorrect='off' placeholder='>3' required='' />";
                    echo "<div class='full_line'></div>";

                    // Updated to 4 arguments
                    $imageClass = hasChanged('image', $_SESSION['form_data']['image'], $conn, $userId) ? "photo-button highlight-change-btn" : "photo-button";
                    echo "<button type='submit' name='go_to_image' formaction='profile.php' formnovalidate class='$imageClass'>".t('PR_CHANGE')."</button>";

                    // Add this inside the <form> tag
                    // Keep these two - they provide the "Old" vs "New" comparison for JS
                    echo "<input type='hidden' name='db_lang' value='" . htmlspecialchars($userData['language']) . "'>";
                    echo "<input type='hidden' name='current_lang' value='" . htmlspecialchars($currentLang) . "'>";

                    echo "<input type='hidden' name='image_source' id='image_source_input' value=''>";
                    echo "<div class='small_line'></div>";

                    echo "<label class='form__label'>".t('SU_EM')."</label><br>";
                    $val = $_SESSION['form_data']['email'] ?? '';
                    echo "<input type='email' class='form__input' id='email' name='email' value='".htmlspecialchars($val)."' spellcheck='false' autocomplete='email' autocapitalize='off' autocorrect='off' required='' />";
                
                    echo "<div class='small_line'></div>";

                    echo "<label class='form__label'>".t('SU_UN')."</label><br>";
                    $val = $_SESSION['form_data']['uid'] ?? '';
                    echo "<input type='text' class='form__input' id='uid' name='uid' value='".htmlspecialchars($val)."' spellcheck='false' autocomplete='username' autocapitalize='off' autocorrect='off' placeholder='>3 az/AZ/09/_' required='' />";
                    
                    echo "<div class='small_line'></div>";

                    echo "<div class='button5-row'>";
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label2'>".t('SU_BD')."</label><br>";

                            $val = $_SESSION['form_data']['birthday'] ?? '';
                            // Updated to 4 arguments
                            $dateClass = hasChanged('birthday', $val, $conn, $userId) ? "highlight-change" : "";
                            echo "<input type='date' class='[type=\"date\"] $dateClass' id='birthday' name='birthday' value='$val' min='1900-01-01' max='2050-01-01' required='' />";

                        echo "</div>";

                        echo "<div class='button5-column2'></div>";

                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label'>".t('SU_GN')."</label><br>";

                            $genVal = $_SESSION['form_data']['gender'] ?? 0;
                            // Updated to 4 arguments
                            $genFlash = hasChanged('gender', $genVal, $conn, $userId) ? "highlight-change" : "";
                            echo "<div class='select-wrap $genFlash'>"; 
                                echo "<select name='gender' class='dropdown-selector-animated'>";
                                    echo "<option value='0' ".($genVal == 0 ? "selected" : "").">".t('SU_ML')."</option>";
                                    echo "<option value='1' ".($genVal == 1 ? "selected" : "").">".t('SU_FM')."</option>";
                                    echo "<option value='2' ".($genVal == 2 ? "selected" : "").">".t('SU_OT')."</option>";
                                echo "</select>";
                            echo "</div>";

                        echo "</div>";
                    echo "</div>";

                    echo "<div class='small_line'></div>";
                    echo "<div class='button5-row'>";
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label'>".t('SU_HT')."</label><br>";

                            $valH = $_SESSION['form_data']['height'] ?? '';
                            // Already using 4 arguments
                            $hgtFlash = hasChanged('height', $valH, $conn, $userId) ? "highlight-change" : "";
                            echo "<input type='text' class='height_input $hgtFlash' id='height' name='height' value='".htmlspecialchars($valH)."' placeholder='".t('SU_PH_H')."' required='' />";

                        echo "</div>";
                        echo "<div class='button5-column2'></div>";
                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label'>".t('SU_HR')."</label><br>";

                            $hairVal = $_SESSION['form_data']['hair'] ?? '0';
                            // Updated to 4 arguments
                            $hairFlash = hasChanged('hair', $hairVal, $conn, $userId) ? "highlight-change" : "";
                            echo "<div class='select-wrap-hair $hairFlash'>";
                                echo "<select class='dropdown-selector-animated' name='hair' id='hair' required>";
                                    for ($i = 0; $i <= 13; $i++) {
                                        $selected = ($hairVal == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>" . t("HC_$i") . "</option>";
                                    }
                                echo "</select>";
                            echo "</div>";

                        echo "</div>";
                    echo "</div>";



//batch 3
                    echo "<div class='small_line'></div>";
                    // LEFT EYE
                    echo "<div class='button5-row'>";
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label2'>".t('SU_LE')."</label><br>";

                            $valL = $_SESSION['form_data']['lefteye'] ?? '2';
                            // Updated to 4 arguments for deep history lookup
                            $leFlash = hasChanged('lefteye', $valL, $conn, $userId) ? "highlight-change" : "";

                            echo "<div class='select-wrap-hair $leFlash'>";
                                echo "<select class='dropdown-selector-animated' name='lefteye' id='lefteye' required>";
                                    for ($i = 0; $i <= 11; $i++) {
                                        $selected = ($valL == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>" . t("EC_$i") . "</option>";
                                    }
                                echo "</select>";
                            echo "</div>";

                        echo "</div>";
                        
                        echo "<div class='button5-column2'></div>";
                        // RIGHT EYE
                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label2'>".t('SU_RE')."</label><br>";

                            $valR = $_SESSION['form_data']['righteye'] ?? '2';
                            // Updated to 4 arguments for deep history lookup
                            $reFlash = hasChanged('righteye', $valR, $conn, $userId) ? "highlight-change" : "";

                            echo "<div class='select-wrap-hair $reFlash'>";
                                echo "<select class='dropdown-selector-animated' name='righteye' id='righteye' required>";
                                    for ($i = 0; $i <= 11; $i++) {
                                        $selected = ($valR == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>" . t("EC_$i") . "</option>";
                                    }
                                echo "</select>";
                            echo "</div>";

                        echo "</div>";
                    echo "</div>";

                    echo "<div class='small_line'></div>";

                    echo "<div class='special-features-row'>";
                        echo "<label class='form__label'>".t('SU_SF')."</label><br>";
                        $valSF = $_SESSION['form_data']['specialfeatures'] ?? '';
                        
                        // Final 4-argument update
                        $sfFlash = hasChanged('specialfeatures', $valSF, $conn, $userId) ? "highlight-change" : "";

                        echo "<textarea class='special-features2 $sfFlash' id='specialfeatures' name='specialfeatures' spellcheck='false' autocomplete='off' placeholder='".t('SU_PH_SF')."'>".htmlspecialchars($valSF)."</textarea>";
                    echo "</div>";

                echo "</div>"; // Close the main div wrapping the inputs

                // --- START LANGUAGE BUTTON SECTION ---
                echo "<label class='form__label' style='display:block'>".t('PR_LANG')."</label>";

                $langNames = ["ah" => "አማርኛ Amharic", "ar" => "عربي Arabic", "am" => "Armenian", "az" => "Azərbaycan", "by" => "беларускі", "be" => "বাংলা", "bo" => "Bosanski", "bg" => "български", "ca" => "廣州話", "ch" => "簡體中文", "cz" => "čeština", "se" => "Cрпски", "da" => "Dansk", "de" => "Deutsch", "en" => "English", "es" => "Espagnol", "fp" => "Filipino", "fr" => "Français", "ir" => "Gaeilge", "gr" => "ελληνικά", "ha" => "Hausa", "he" => "עִברִيت", "hi" => "हिंदी", "cr" => "Hrvatski", "ig" => "Igbo", "in" => "Indonesia", "ic" => "íslenskur", "it" => "Italiano", "ja" => "日本語", "ka" => "қазақ", "kh" => "Khmer", "ki" => "Kinyarwanda", "sh" => "Kiswahili", "co" => "Kituba", "ko" => "한국인", "kg" => "Kyrgyz", "la" => "ພາသာລາว", "lv" => "Latviski", "lt" => "Lietuvių", "hu" => "Magyar", "mg" => "Malagasy", "ma" => "Marathi", "ml" => "Melayu", "mo" => "Монгол", "bu" => "မြန်မာ", "ne" => "Nederlands", "np" => "नेपाली", "no" => "Norsk", "or" => "Oromoo", "pa" => "Pashto", "pe" => "Persian", "po" => "Polski", "pt" => "Português", "ro" => "Română", "ru" => "Русский", "zi" => "Setswana", "al" => "Shqiptare", "sl" => "Slovenski", "sk" => "Slovenský", "so" => "Soomaali", "fi" => "Suomalainen", "sw" => "Svenska", "ta" => "தமிழ்", "th" => "แบบไทย", "vi" => "Tiếng Việt", "tu" => "Türkçe", "ur" => "اردو", "yo" => "Yoruba"];
                $currentDisplay = $langNames[$currentLang] ?? "Select Language";

                // Standardize active language code context to lowercase
                $profileFlagCode = strtolower($currentLang);
                
                // Enforce your verified asset routing mappings
                switch ($profileFlagCode) {
                    case 'or':
                        $profileFlagFile = 'flag_ah.jpg';
                        break;
                    case 'ca':
                        $profileFlagFile = 'flag_ch.jpg';
                        break;
                    default:
                        $profileFlagFile = 'flag_' . $profileFlagCode . '.jpg';
                        break;
                }

                // Render the form button wrapper container utilizing the circular .jpg layout
                echo "<button type='submit' name='go_to_language' formaction='profile.php' formnovalidate style='text-decoration:none; background:none; border:none; width:100%; padding:0; cursor:pointer;'>";
                    echo "<div class='profile-lang-button'>";
                        echo "<div class='profile-lang-flag'>";
                            
                            // Draws your rounded flag template framing circle cleanly
                            echo "<span class='flag-crop-container page-list-flag' style='display:inline-block;'>";
                                echo "<img src='" . $baseHref . "img/" . $profileFlagFile . "' alt='Current Language Flag' class='flag-img-round'>";
                            echo "</span>";
                            
                        echo "</div>";
                        echo "<span class='profile-lang-text'>$currentDisplay</span>";
                    echo "</div>";
                echo "</button>";



                echo "<div class='small_line'></div>";
                // --- PRIVACY & LISTS SECTION ---
                // First Row: Manage list button using photo-button class
                echo "<label class='form__label2'>" . t('PR_SPAM') . "</label><br>";
                echo "<div class='small_line'></div>";
                $listName = ($userData['useWhitelist'] == 1) ? t('PR_WL_NAME') : t('PR_BL_NAME');
                echo "<a href='black-white-list.php'>";
                    echo "<button type='button' id='manage-list-btn' class='photo-button' style='width:100%;'>" . t('PR_MANAGE') . " $listName</button>";
                echo "</a>";

                // Second Row: Switch with feedback text on the right
                echo "<div class='medium_line'></div>";
                echo "<div class='button-full-row'>
                        <div class='switch-container'>
                            <label class='switch'>
                                <input type='checkbox' id='whitelist-toggle' onchange='toggleWhitelist(this.checked)' ".($userData['useWhitelist'] == 1 ? "checked" : "").">
                                <span class='slider round'></span>
                            </label>
                            <span class='feedback5'>".t('PR_WL_LABEL')."</span>
                        </div>
                      </div>";





//batch 4

                // Newsletter Toggle
                echo "<div class='medium_line'></div>";
                echo "<div class='button-full-row'>
                        <div class='switch-container'>
                            <label class='switch'>
                                <input type='checkbox' onchange='updatePreference(\"newsletter\", this.checked)' ".($userData['newsletter'] == 1 ? "checked" : "").">
                                <span class='slider round'></span>
                            </label>
                            <span class='feedback5'>".t('PR_LBL_NEWS')."</span>
                        </div>
                      </div>";

                // Payment Emails Toggle - FIXED: variable name and function argument
                echo "<div class='medium_line'></div>";
                echo "<div class='button-full-row'>
                        <div class='switch-container'>
                            <label class='switch'>
                                <input type='checkbox' onchange='updatePreference(\"paymentEmails\", this.checked)' ".($userData['paymentEmails'] == 1 ? "checked" : "").">
                                <span class='slider round'></span>
                            </label>
                            <span class='feedback5'>".t('PR_LBL_PAY_EM')."</span>
                        </div>
                      </div>";

            echo "</form>";

            echo "</form>";
            echo "<div class='full_line'></div>";
            // --- ERROR / SUCCESS MESSAGES ---
            echo "<br><div class='signupdiv'>";
            if (isset($_GET["error"])) {
                $error = $_GET["error"];
                switch ($error) {
                    case "emptyinput":      $msg = t('SU_ERR_EMPTY'); break;
                    case "invaliduid":      $msg = t('SU_ERR_UID'); break;
                    case "invalidemail":    $msg = t('SU_ERR_EMAIL'); break;
                    case "stmtfailed":      $msg = t('SU_ERR_STMT'); break;
                    case "usernametaken":   $msg = t('SU_ERR_TAKEN'); break;
                    case "invalidimage":    $msg = t('SU_ERR_IMG'); break;
                    case "invalidbirthday": $msg = t('SU_ERR_BDAY'); break;
                    case "invalidheight":   $msg = t('SU_ERR_HEIGHT'); break;
                    case "invalidinput":    $msg = t('SU_ERR_INPUT'); break;
                    case "invalidaccess":   $msg = t('PR_ERR_ACCESS'); break;
                    case "dbase":           $msg = t('PR_ERR_DB'); break;
                    case "none":            $msg = t('PR_SUCCESS'); break;
                    default:                $msg = t('PR_ERR_UNK'); break;
                }
                echo "<p class='".($error == 'none' ? 'feedback' : 'error')."'>$msg</p>";
                if ($error !== "none") {
                    echo "<p class='error'>" . t('SU_TRY') . "</p>";

                    // Help Email Button
                    echo "<div class='help-button-container'>";
                        // Added ?Subject= and removed the incorrect ://
                        echo "<a href='mailto:info@abundomy.com?Subject=" . rawurlencode(t('PR_HELP_SUBJECT')) . "' target='_blank'>";

                            echo "<button type='button' class='login-button'>" . t('SU_HELP_BTN') . " (info@abundomy.com)</button>";
                        echo "</a>";
                    echo"</div>";
                }
            }
            echo "</div>";


            // GET FOOTER DATA
            $footerData = getProfileFooterData($conn, $userId, $userData['start']);

            echo "<div class='full-screen-line'></div>";

            echo "<div class='profile-footer-row'>";
                // Date
                echo "<div class='footer-item footer-date'>" . $footerData['date'] . "</div>";
                
                // PDF Button
                echo "<div class='footer-item'>";
                    echo "<a href='download-pdf.php'><button type='button' class='login-button'>PDF</button></a>";
                echo "</div>";
                
                // CSV Button
                echo "<div class='footer-item'>";
                    echo "<a href='download-csv.php?user=$userId' target='_blank'>";
                        echo "<button type='button' class='login-button'>CSV</button>";
                    echo "</a>";
                echo "</div>";


                // History Button (Conditional)
                if ($footerData['hasHistory']) {
                    // 1. Define the return path (the current page)
                    $fromPath = urlencode("profile.php");
                    
                    // 2. Build the URL manually to ensure it uses 'user=' instead of 'id='
                    // We use the userId variable available in your profile.php scope
                    $historyUrlWithReturn = "humandetails.php?user=" . $userId . "&from=" . $fromPath;

                    echo "<div class='footer-item'>";
                        echo "<a href='" . $historyUrlWithReturn . "'>";
                            echo "<button type='button' class='login-button'>" . $footerData['historyText'] . "</button>";
                        echo "</a>";
                    echo "</div>";
                }



            echo "</div>";
       echo "</div>"; // field-wrapper
    echo "</div>"; // centerContainer

?>




<!-- // batch 5 -->
<script>
    // --- 1. Global State Variables ---
    let dbState = "";
    // Capture the DB image separately as a fixed reference
    const dbImage = "<?php echo !empty($userData['image']) ? $userData['image'] : 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAMAAAADCAYAAABWKLW/AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQImWMUl3f8zwAFTAxIAIUDADSCAXwCfI//AAAAAElFTkSuQmCC'; ?>";

    // --- 2. Image Management (Fixed: Must be defined before DOMContentLoaded) ---
    function loadImage() {
        <?php if (isset($clearLocalImage) && $clearLocalImage === true): ?>
            localStorage.removeItem('image1');
        <?php endif; ?>

        const image1 = localStorage.getItem('image1');
        const b64img = document.getElementById('b64img');
        
        if (b64img) {
            b64img.src = image1 ? image1 : dbImage;
            b64img.style.display = 'block';
        }
    }

    // --- 3. State & Change Detection ---
    const getFullState = (useDbValues = false) => {
        const form = document.getElementById('profileForm');
        if (!form) return "";
        
        const data = new FormData(form);
        const img = document.getElementById('b64img');
        
        if (useDbValues) {
            // Force the baseline comparison to use DB values
            data.set('current_photo_src', dbImage);
            data.set('current_lang', data.get('db_lang'));
        } else {
            // Capture current state for comparison
            if (img) data.append('current_photo_src', img.src);
        }
        
        return JSON.stringify(Array.from(data.entries()).sort());
    };

    const checkChanges = () => {
        const saveBtn = document.querySelector('button[name="submit"]');
        if (!saveBtn) return;
        
        const currentState = getFullState(false);
        // Enable button if current form (session/input) differs from DB baseline
        saveBtn.disabled = (currentState === dbState);
    };

    // --- 4. Initialization ---
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('profileForm');
        
        // Load the image first
        loadImage();
        
        // 1. Capture the Baseline (The "Database" truth)
        dbState = getFullState(true); 
        
        // 2. Initial check (Enables button if language came back changed)
        checkChanges();

        if (form) {
            // 'input' for text, 'change' for dropdowns/selects
            form.addEventListener('input', checkChanges);
            form.addEventListener('change', checkChanges);
        }
    });

    // --- 5. Submit & Helpers ---
    const profileForm = document.getElementById('profileForm');
    if (profileForm) {
        profileForm.addEventListener('submit', function() {
            const img = document.getElementById('b64img');
            if (img) {
                document.getElementById('image_source_input').value = img.src;
            }
        });
    }

    function toggleWhitelist(isEnabling) {
        const wlName = "<?php echo t('PR_WL_NAME'); ?>";
        const blName = "<?php echo t('PR_BL_NAME'); ?>";
        const manageTxt = "<?php echo t('PR_MANAGE'); ?>";
        const btn = document.getElementById('manage-list-btn');
        if (btn) { btn.innerText = manageTxt + ' ' + (isEnabling ? wlName : blName); }

        if (isEnabling) {
            const confirmMsg = <?php echo json_encode(str_replace('\n', "\n", t('PR_WL_CONFIRM'))); ?>;
            if (confirm(confirmMsg)) {
                window.location.href = 'includes/list-handler.inc.php?action=toggle&mode=1&import=1';
            } else {
                window.location.href = 'includes/list-handler.inc.php?action=toggle&mode=1&import=0';
            }
        } else {
            window.location.href = 'includes/list-handler.inc.php?action=toggle&mode=0';
        }
    }

    function updatePreference(type, isEnabling) {
        const mode = isEnabling ? 1 : 0;
        window.location.href = 'includes/pref-handler.inc.php?type=' + type + '&mode=' + mode;
    }

    window.addEventListener('keydown', function(e) {
        if ((e.key === 'Enter' || e.keyCode === 13) && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            return false;
        }
    }, false);
</script>




</body>
</html>