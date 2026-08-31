<?php
    include_once "header.php";

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $_SESSION['form_data'] = $_POST;

        // if (isset($_POST['go_to_image'])) {
        //     header("Location: image.php?return=signup");
        //     exit();
        // }

        if (isset($_POST['go_to_image'])) {
            $_SESSION['form_data'] = $_POST; // Ensure it's assigned
            session_write_close();           // Force the save to disk
            header("Location: image.php?return=signup");
            exit();
        }



        if (isset($_POST['submit'])) { 
            header("Location: includes/signup.inc.php");
            exit();
        }
    }

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<form action='includes/signup.inc.php' method='post' id='signupForm'>";
                //save language in signup form:
                echo "<input type='hidden' name='language' value=";
                echo htmlspecialchars($currentLang);
                echo ">";

                echo "<div class='full_line'></div>";

                echo "<div class='button4-row'>";
                    echo "<div class='button4-column1'>";
                        echo "<button type='submit' name='submit' class='login-button'>".t('SU_SUBMIT')."</button>";
                    echo "</div>";
                    echo "<div class='button4-column2'>";
                        echo '<p class="title">'.t('SU_YOU').'</p>';
                    echo "</div>";
                    echo "<div class='button4-column3'>";
                        echo "<a href='index2.php'><button type='button' class='login-button'>".t('SU_CANCEL')."</button></a>";
                    echo "</div>";
                echo "</div>";

                echo "<div class='small_line'></div>";
                echo "<div>";
                
                    echo "<label class='form__label'>".t('SU_FN')."</label><br>";
                    $val = $_SESSION['form_data']['name'] ?? '';
                    echo "<input type='text' class='form__input' id='name' name='name' value='".htmlspecialchars($val)."' spellcheck='false' autocomplete='name' placeholder='>3' required='' />";

                    echo "<div class='full_line'></div>";
                    echo "<button type='submit' name='go_to_image' formaction='signup.php' formnovalidate class='photo-button'>".t('SU_IMG')."</button>";
                    echo "<input type='hidden' name='image_source' id='image_source_input' value=''>";

                    echo "<label class='form__label'>".t('SU_EM')."</label><br>";
                    $val = $_SESSION['form_data']['email'] ?? '';
                    echo "<input type='email' class='form__input' id='email' name='email' value='".htmlspecialchars($val)."' spellcheck='false' autocomplete='email' required='' />";

                    echo "<label class='form__label'>".t('SU_UN')."</label><br>";
                    $val = $_SESSION['form_data']['uid'] ?? '';
                    echo "<input type='text' class='form__input' id='uid' name='uid' value='".htmlspecialchars($val)."' spellcheck='false' autocomplete='username' placeholder='>3 az/AZ/09/_' required='' />";

                    echo "<div class='button5-row'>";
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label2'>".t('SU_PW')."</label><br>";
                            echo "<input type='password' class='form__input2' id='pwd' name='pwd' spellcheck='false' autocomplete='new-password' placeholder='>7 az+AZ+09+!@' required='' />";
                        echo "</div>";
                        echo "<div class='button5-column2'></div>";
                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label2'>".t('SU_PW2')."</label><br>";
                            echo "<input type='password' class='form__input2' id='pwdrepeat' name='pwdrepeat' spellcheck='false' autocomplete='new-password' required='' />";
                        echo "</div>";
                    echo "</div>";

                    echo "<div class='button5-row'>";
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label2'>".t('SU_BD')."</label><br>";
                            $val = $_SESSION['form_data']['birthday'] ?? '';
                            echo "<input type='date' id='birthday' name='birthday' value='$val' min='1900-01-01' max='2050-01-01' required='' />";
                        echo "</div>";
                        echo "<div class='button5-column2'></div>";
                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label'>".t('SU_GN')."</label><br>";
                            echo "<div class='select-wrap'>";
                                $val = $_SESSION['form_data']['gender'] ?? '';
                                echo "<select class='gender-selector' name='gender' id='gender' required>";
                                    echo "<option value='0' " . ($val == '0' ? 'selected' : '') . ">".t('SU_ML')."</option>";
                                    echo "<option value='1' " . ($val == '1' ? 'selected' : '') . ">".t('SU_FM')."</option>";
                                    echo "<option value='2' " . ($val == '2' ? 'selected' : '') . ">".t('SU_OT')."</option>";
                                echo "</select>";
                            echo "</div>";
                        echo "</div>";
                    echo "</div>";

                    echo "<div class='button5-row'>";
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label'>".t('SU_HT')."</label><br>";
                            $val = $_SESSION['form_data']['height'] ?? '';
                            echo "<input type='text' class='height_input' id='height' name='height' placeholder='".t('SU_PH_H')."' value='$val' required='' />";
                        echo "</div>";
                        echo "<div class='button5-column2'></div>";
                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label'>".t('SU_HR')."</label><br>";
                            echo "<div class='select-wrap-hair'>";
                                $valH = $_SESSION['form_data']['hair'] ?? '17';
                                echo "<select class='hair-selector' name='hair' id='hair' required>";
                                    for ($i = 0; $i <= 19; $i++) {
                                        $selected = ($valH == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>" . t("HC_$i") . "</option>";
                                    }
                                echo "</select>";
                            echo "</div>";
                        echo "</div>";
                    echo "</div>";
                    echo "<div class='button5-row'>";
                        // LEFT EYE
                        echo "<div class='button5-column1'>";
                            echo "<label class='form__label2'>".t('SU_LE')."</label><br>";
                            echo "<div class='select-wrap-hair'>";
                                $valLE = $_SESSION['form_data']['lefteye'] ?? '2';
                                echo "<select class='hair-selector' name='lefteye' id='lefteye' required>";
                                    for ($i = 0; $i <= 11; $i++) {
                                        $selected = ($valLE == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>" . t("EC_$i") . "</option>";
                                    }
                                echo "</select>";
                            echo "</div>";
                        echo "</div>";
                        


                        
                        echo "<div class='button5-column2'></div>";
                        
                        // RIGHT EYE
                        echo "<div class='button5-column3'>";
                            echo "<label class='form__label2'>".t('SU_RE')."</label><br>";
                            echo "<div class='select-wrap-hair'>";
                                $valRE = $_SESSION['form_data']['righteye'] ?? '2';
                                echo "<select class='hair-selector' name='righteye' id='righteye' required>";
                                    for ($i = 0; $i <= 11; $i++) {
                                        $selected = ($valRE == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>" . t("EC_$i") . "</option>";
                                    }
                                echo "</select>";
                            echo "</div>";
                        echo "</div>";
                    echo "</div>";

                    echo "<label class='form__label'>".t('SU_SF')."</label><br>";
                    $valSF = $_SESSION['form_data']['specialfeatures'] ?? '';
                    echo "<textarea class='special-features2' id='specialfeatures' name='specialfeatures' spellcheck='false' autocomplete='off' placeholder='".t('SU_PH_SF')."'>" . htmlspecialchars($valSF) . "</textarea>";

                echo "</div>"; // Close main input container
                echo "<br>";
            echo "</form>";

            echo "<br>";
            echo "<div class='signupdiv'>";
                if (isset($_GET["error"])) {
                    $error = $_GET["error"];

                    // 1. Display specific error/success message
                    if ($error == "emptyinput") {
                        echo "<p class='error'>" . t('SU_ERR_EMPTY') . "</p>";
                    }
                    else if ($error == "invaliduid") {
                        echo "<p class='error'>" . t('SU_ERR_UID') . "</p>";
                    }
                    else if ($error == "invalidemail") {
                        echo "<p class='error'>" . t('SU_ERR_EMAIL') . "</p>";
                    }
                    else if ($error == "passwordsdontmatch") {
                        echo "<p class='error'>" . t('SU_ERR_MATCH') . "</p>";
                    }
                    else if ($error == "stmtfailed") {
                        echo "<p class='error'>" . t('SU_ERR_STMT') . "</p>";
                    }
                    else if ($error == "usernametaken") {
                        echo "<p class='error'>" . t('SU_ERR_TAKEN') . "</p>";
                    }
                    else if ($error == "invalidimage") {
                        echo "<p class='error'>" . t('SU_ERR_IMG') . "</p>";
                    }
                    else if ($error == "invalidbirthday") {
                        echo "<p class='error'>" . t('SU_ERR_BDAY') . "</p>";
                    }
                    else if ($error == "invalidheight") {
                        echo "<p class='error'>" . t('SU_ERR_HEIGHT') . "</p>";
                    }
                    else if ($error == "invalidinput") {
                        echo "<p class='error'>" . t('SU_ERR_INPUT') . "</p>";
                    }
                    else if ($error == "none") {
                        echo "<p class='feedback'>" . t('SU_SUCCESS') . "</p>";
                    }

                    // 2. Append "Please try again!" for all error codes (anything other than "none")
                    if ($error !== "none") {
                        echo "<p class='error'>" . t('SU_TRY') . "</p>";

                        // Help Email Button
                        echo "<div class='help-button-container'>";
                            // Added ?Subject= and removed the incorrect ://
                            echo "<a href='mailto:info@abundomy.com?Subject=" . rawurlencode(t('SU_HELP_SUBJECT')) . "' target='_blank'>";
                                echo "<button type='button' class='login-button'>" . t('SU_HELP_BTN') . " (info@abundomy.com)</button>";
                            echo "</a>";
                        echo"</div>";
                    }

                }
            echo "</div>";
        echo "</div>"; // Close field-wrapper
    echo "</div>"; // Close centerContainer
?>
<script>
    document.getElementById('signupForm').addEventListener('submit', function() {
        // 1. SAVE PASSWORDS RIGHT BEFORE REDIRECTING TO PHOTO SECTION
        const pwdEl = document.getElementById('pwd');
        const pwdRepeatEl = document.getElementById('pwdrepeat');
        if (pwdEl && pwdRepeatEl) {
            sessionStorage.setItem('temp_pwd', pwdEl.value);
            sessionStorage.setItem('temp_pwdrepeat', pwdRepeatEl.value);
        }

        // FIXED: Pull directly from localStorage instead of a non-existent 'b64img' node
        const savedImagePayload = localStorage.getItem('image1');
        const hiddenInputTarget = document.getElementById('image_source_input');
        if (savedImagePayload && hiddenInputTarget) {
            hiddenInputTarget.value = savedImagePayload;
        }
    });
</script>


</body>
</html>
