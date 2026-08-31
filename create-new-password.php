<?php
    require "header.php";
    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='full_line'></div>";
            
            if (!isset($_GET["selector"]) || !isset($_GET["validator"])) {
                echo "<div class='full_line'></div>";
                echo "<div class='button9-row'>";
                    echo "<div class='button9-column1'>";
                        echo "<p class='title'>".t('NP_TITLE_RESET_ERR')."</p><br>";
                    echo "</div>";
                echo "</div>";
                echo "<div class='full_line'></div>";
                echo "<div class='button3-row'>";
                    echo "<div class='button3-column1'></div>";
                    echo "<div class='button3-column2'>";
                        echo "<br><a href='reset-password.php'><button type='button' class='login-button'>".t('NP_BTN_BACK')."</button></a>";
                    echo "</div>";
                echo "</div>";
                echo "<div class='full_line'></div>";

                if (isset($_GET["newpwd"]) && $_GET["newpwd"] == "expired") {
                    echo "<p class='error'>".t('NP_ERR_EXPIRED')."</p><br>";
                    echo "<p class='error'>".t('NP_ERR_NEW_REQ')."</p>";
                } elseif (isset($_GET["newpwd"]) && $_GET["newpwd"] == "invalidtoken") {
                    echo "<p class='error'>".t('NP_ERR_INVALID_TOKEN')."</p><br>";
                    echo "<p class='error'>".t('NP_ERR_NEW_REQ')."</p>";
                } else {
                    echo "<p class='error'>".t('NP_ERR_NOT_FOUND')."</p><br>";
                    echo "<p class='error'>".t('NP_ERR_NEW_REQ')."</p>";
                }
            } else {
                $selector = $_GET["selector"];
                $validator = $_GET["validator"];
                
                if (ctype_xdigit($selector) !== false && ctype_xdigit($validator) !== false) {
                    echo "<div class='full_line'></div>";
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";
                            echo "<p class='title'>".t('NP_TITLE_CREATE')."</p><br>";
                        echo "</div>";
                    echo "</div>";
                    echo "<div class='full_line'></div>";
                    echo "<form action='includes/reset-password.inc.php' method='post'>";
                        echo "<input type='hidden' name='selector' value='" . htmlspecialchars($selector) . "'>";
                        echo "<input type='hidden' name='validator' value='" . htmlspecialchars($validator) . "'>";
                        
                        echo "<div class='button5-row'>";
                            echo "<div class='button5-column1'>";
                                echo "<label class='form__label2'>".t('NP_LABEL_PWD')."</label><br>";
                                echo "<input type='password' class='form__input2' name='pwd' autocomplete='new-password' autocapitalize='off' spellcheck='false' autocorrect='off' required /><br>";
                            echo "</div>";
                            echo "<div class='button5-column2'></div>";
                            echo "<div class='button5-column3'>";
                                echo "<label class='form__label2'>".t('NP_LABEL_REPEAT')."</label><br>";
                                echo "<input type='password' class='form__input2' name='pwd-repeat' autocomplete='new-password' autocapitalize='off' spellcheck='false' autocorrect='off' required /><br>";
                            echo "</div>";
                        echo "</div>";
                        
                        echo "<div class='full_line'></div>";
                        
                        echo "<div class='button3-row'>";
                            echo "<div class='button3-column1'>";
                                echo "<button type='submit' class='login-button' name='reset-password-submit'>".t('NP_BTN_RESET')."</button>";
                            echo "</div>";
                            echo "<div class='button3-column2'>";
                                echo "<a href='index2.php'><button type='button' class='login-button'>".t('NP_BTN_CANCEL')."</button></a>";
                            echo "</div>";
                        echo "</div>";
                        
                        if (isset($_GET["newpwd"])) {
                            echo "<div class='full_line'></div>";
                            if ($_GET["newpwd"] == "empty") {
                                echo "<p class='error'>".t('NP_ERR_EMPTY')."</p>";
                                echo "<p class='error'>".t('NP_ERR_TRY_AGAIN')."</p>";
                            } elseif ($_GET["newpwd"] == "pwdnotsame") {
                                echo "<p class='error'>".t('NP_ERR_MISMATCH')."</p>";
                                echo "<p class='error'>".t('NP_ERR_TRY_AGAIN')."</p>";
                            } elseif ($_GET["newpwd"] == "weakpassword") {
                                // This is the new handler for the strong password check
                                echo "<p class='error'>".t('NP_ERR_WEAK')."</p>";
                                echo "<p class='error'>".t('NP_ERR_TRY_AGAIN')."</p>";
                            } elseif ($_GET["newpwd"] == "databaseerror") {
                                echo "<p class='error'>".t('NP_ERR_DB')."</p>";
                                echo "<p class='error'>".t('NP_ERR_TRY_LATER')."</p>";
                            }
                        }
                        echo "<div class='full_line'></div>";
                    echo "</form>"; 
                } else {
                    echo "<div class='full_line'></div>";
                    echo "<div class='button9-row'>";
                        echo "<div class='button9-column1'>";
                            echo "<p class='title'>".t('NP_TITLE_INVALID')."</p><br>";
                        echo "</div>";
                    echo "</div>";
                    echo "<div class='full_line'></div>";
                    echo "<p class='error'>".t('NP_ERR_INVALID_LINK')."</p>";
                    echo "<p class='error'>".t('NP_ERR_INVALID_LINK2')."</p>";
                    echo "<div class='full_line'></div>";
                }
            }
        echo "</div>";
    echo "</div>";
?>
</body>
</html>