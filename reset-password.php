<?php
    require "header.php";
    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'>";
                    echo "<p class='title'>".t('RP_TITLE')."</p><br>";
                echo "</div>";
            echo "</div>";
            echo "<div class='full_line'></div>";
            echo "<p class='feedback'>".t('RP_INSTRUCT_1')."</p>";
            echo "<p class='feedback'>".t('RP_INSTRUCT_2')."</p>";
            echo "<p class='feedback'>".t('RP_INSTRUCT_3')."</p>";
            echo "<div class='full_line'></div>";
            echo "<form action='includes/reset-request.inc.php' method='post'>";
                echo "<label for='email' class='form__label'>".t('RP_LABEL_EMAIL')."</label><br>";
                echo "<input type='text' class='form__input' name='email' autocomplete='email' autocapitalize='off' autocorrect='off' spellcheck='false' required='' />";
                echo "<div class='full_line'></div>";
                echo "<div class='full_line'></div>";
                echo "<div class='button3-row'>";
                    echo "<div class='button3-column1'>";
                        echo "<button type='submit' class='login-button' name='reset-request-submit'>".t('RP_BTN_SEND')."</button>";
                    echo "</div>";
                    echo "<div class='button3-column2'>";
                        $btnKey = (isset($_GET["error"]) && $_GET["error"] == "none") ? 'RP_BTN_BACK' : 'RP_BTN_CANCEL';
                        echo "<a href='index2.php'><button type='button' class='login-button'>".t($btnKey)."</button></a>";
                    echo "</div>";
                echo "</div>";
            echo "</form>";
            echo "<br>";
            
            if (isset($_GET["error"])) {
                echo "<div class='full_line'></div>";
                if ($_GET["error"] == "none") {
                    echo "<p class='feedback'>".t('RP_SUCCESS')."</p>";
                } else {
                    // Display error messages
                    if ($_GET["error"] == "email") {
                        echo "<p class='error'>".t('RP_ERR_EMAIL')."</p>";
                    } else if ($_GET["error"] == "dbase") {
                        echo "<p class='error'>".t('RP_ERR_DB')."</p>";
                        echo "<p class='error'>".t('RP_ERR_TRY_AGAIN')."</p>";
                    }

                    // Show Help Button for any error (email not found or DB error)
                    echo "<div class='help-button-container' style='display: flex; justify-content: center; margin-top: 20px;'>";
                        echo "<a href='mailto:info@abundomy.com?Subject=" . rawurlencode(t('PR_HELP_SUBJECT')) . "' target='_blank'>";
                            echo "<button type='button' class='login-button'>" . t('SU_HELP_BTN') . " (info@abundomy.com)</button>";
                        echo "</a>";
                    echo "</div>";
                }
            }
        echo "</div>";
    echo "</div>";
?>
