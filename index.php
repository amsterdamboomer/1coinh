<?php
    include_once "header.php"; 

    if (isset($_SESSION['form_data'])) {
        unset($_SESSION['form_data']);
    }

    echo "<div id='centerContainer'>";
        if ($userData) {
            $myId = $_SESSION["userid"];

            echo "<div class='field-wrapper'>";
                // Clear temporary session data
                $listKeys = ['start1', 'total', 'searchname', 'searchnumber', 'findRequest'];
                foreach ($listKeys as $key) {
                    if (isset($_SESSION[$key])) unset($_SESSION[$key]);
                }
                for ($i = 1; $i <= 20; $i++) {
                    $idx = sprintf("%02d", $i);
                    unset($_SESSION["r$idx-imag"], $_SESSION["r$idx-name"], $_SESSION["r$idx-numb"]);
                }

                echo "<div class='medium_line'></div>";
                echo "<div class='index-title-row'>";
                    echo "<div class='index-title-column index-title-column1'>";
                        echo "<a href='https://abundomy.com' target='_blank' class='title-link'>";
                            // BRAND TITLE (Dynamic)
                            echo "<p class='feedback2'>" . mb_strtoupper(t('TITLE'), 'UTF-8') . "</p>";
                        echo "</a>";
                    echo "</div>"; 
                    echo "<div class='index-title-column index-title-column2'>";
                        echo "<a href='includes/logout.inc.php'><button type='button' class='login-button'>".t('LOGOUT')."</button></a>";
                    echo "</div>";
                echo "</div>";
                echo "<div class='full_line'></div>";
                
                // Status Messages
                echo "<div class='signupdiv' id='status-message-container'>";
                if (isset($_GET["success"])) {
                    if ($_GET["success"] == "paid") {
                         echo "<p class='feedback'>".t('S_PAID')."</p>";
                    } else if ($_GET["success"] == "deleted") {
                         echo "<p class='feedback'>".t('S_DELETED')."</p>";
                    } else if ($_GET["success"] == "blocked" || $_GET["success"] == "blocked_and_cleared") {
                         echo "<p class='feedback'>".t('S_BLOCKED')."</p>";
                    }
                }
                echo "</div>";

                if (isset($_GET["success"])) {
                    echo "<script>
                        setTimeout(function() {
                            var msg = document.getElementById('status-message-container');
                            if (msg) {
                                msg.style.transition = 'opacity 1s ease';
                                msg.style.opacity = '0';
                                setTimeout(function() { msg.innerHTML = ''; msg.style.opacity = '1'; }, 1000);
                            }
                        }, 5000);
                    </script>";
                }

                echo "<div class='full_line'></div>";

                echo "<div id='proposal-list-area'>";
                    echo getProposalsHTML($conn, $myId);
                echo "</div>";

                echo "<br><br>";

                if (isServiceAdmin()) {
                    echo "<div class='large_line'></div>";
                    echo "<div class='large_line'></div>";
                    // Centered Service Button Row
                    echo "<div class='button-row' style='display: flex; justify-content: center;'>";
                        echo "<a href='service.php'><button type='button' class='login-button'>Service</button></a>";
                    echo "</div>";
                }

            echo "</div>";
        } else {
            //===========================================
            //==         user is not logged in         ==
            //===========================================
            echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    // 1. Wipe the image from memory
                    localStorage.removeItem('image1');
                    
                    // 2. Hide the preview element and clear its source
                    var img = document.getElementById('b64img');
                    if (img) { 
                        img.src = ''; 
                        img.style.display = 'none';
                    }
                    
                    // 3. Force the language flag container to show again
                    var flag = document.getElementById('header-flag-wrap');
                    if (flag) {
                        flag.style.display = 'block';
                    }
                });
            </script>";
            echo "<div class='field-wrapper'>";
                echo "<form action='includes/login.inc.php' method='post'>";
                    echo "<div class='full_line'></div>";
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";
                            echo "<p class='title'>".t('LOGIN')."</p><br>";
                        echo "</div>";
                    echo "</div>";
                    echo "<div class='full_line'></div>";
                    echo "<label class='form__label'>".t('USER_EM')."</label><br>";
                    echo "<input type='text' class='form__input' id='logintext' name='uid' autocomplete='username' autocapitalize='off' autocorrect='off' spellcheck='false'  required /><br>";
                    echo "<div class='small_line'></div>";
                    echo "<label class='form__label'>".t('PWD')."</label><br>";
                    echo "<input type='password' class='form__input' id='pwd' name='pwd' autocomplete='current-password' autocapitalize='off' autocorrect='off' spellcheck='false' required /><br>";
                    echo "<br>";
                    echo "<div class='button10-row'>";
                        echo "<div class='button10-column1'>";
                            echo "<a href='signup.php'><button type='button' class='login-button-small' onclick=\"localStorage.removeItem('image1');\">".t('SIGNUP')."</button></a>";
                        echo "</div>";
                        echo "<div class='button10-column2'>";
                            echo "<a href='reset-password.php'><button type='button' class='login-button-small'>".t('RESET')."</button></a>";
                        echo "</div>";
                        echo "<div class='button10-column3'>";
                            echo "<button type='submit' class='login-button' name='submit' id='submit'>".t('LOGIN_BTN')."</button>";
                        echo "</div>";
                    echo "</div>";
                    echo "<br>";
                echo "</form>";

                echo "<div class='signupdiv' id='status-message-container-login'>";
                    if (isset($_GET["newpwd"]) && $_GET["newpwd"] == "passwordupdated") {
                        echo '<p class="feedback">'.t('S_PWD_RESET').'</p>';
                    }
                    if (isset($_GET["error"])) {
                        $error = $_GET["error"];
                        
                        // --- NEW FEEDBACK MESSAGES ---
                        if ($error == "checkemail") {
                            echo "<p class='feedback'>".t('PR_MSG_CHECK_EMAIL')."</p>";
                        }
                        else if ($error == "profileready") {
                            echo "<p class='feedback'>".t('PR_MSG_LOGIN_NOW')."</p>";
                        }
                        // --- EXISTING ERROR MESSAGES ---
                        else if ($error == "emptyinput") echo "<p class='error'>".t('E_EMPTY')."</p>";
                        else if ($error == "wronglogin") echo "<p class='error'>".t('E_LOGIN')."</p>";
                        else if ($error == "timeout") echo "<p class='error'>".t('E_TIMEOUT')."</p>";
                    }
                echo "</div>";

                if (isset($_GET["newpwd"]) || isset($_GET["error"])) {
                    echo "<script>
                        setTimeout(function() {
                            var msg = document.getElementById('status-message-container-login');
                            if (msg) {
                                msg.style.transition = 'opacity 1s ease';
                                msg.style.opacity = '0';
                                setTimeout(function() { msg.innerHTML = ''; msg.style.opacity = '1'; }, 1000);
                            }
                        }, 8000); // Increased to 8s because these specific messages are longer to read
                    </script>";
                }

                echo "<div class='large_line'></div>";
                echo "<div style='text-align: center;'>";
                    echo "<a href='https://abundomy.com' target='_blank' class='download-link'>".t('BOOK')."</a>";
                echo "</div>";
                echo "<div style='text-align: center;'>";
                    echo "<a href='https://buymeacoffee.com/teunvansambeek' target='_blank' class='download-link'>".t('SUPPORT')."&nbsp&nbsp&nbsp&nbsp</a>";
                    echo "<a href='mailto:info@abundomy.com?Subject=A About Abundomy Money' target='_blank' class='download-link'>&nbsp&nbsp&nbsp&nbsp".t('CONTACT')."</a>";
                echo "</div>";

                echo "<div style='text-align: center;'>";
                    echo "<a href='downloads/abundomy_money.zip' download class='download-link'>".t('CODE')."</a>";
                echo "</div>";

                // Centered GitLab Link
                echo "<div class='dev-link-container'>";
                    echo "<img src='img/gitlab_70x70.png' class='dev-link-icon' alt='GitLab'>";
                    echo "<a href='https://gitlab.com/1coinh/abundomy-money' target='_blank' class='download-link'>" . t('JOIN_DEV') . "</a>";
                echo "</div>";

            echo "</div>"; 
        } 
    echo "</div>";
?>
</body>
</html>