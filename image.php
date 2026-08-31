<?php
    require "header.php";

    echo "<div id='centerContainer'>";
        echo "<div class='field-wrapper'>";
            echo "<div class='medium_line'></div>";
            echo "<div class='button4-row'>";
                echo "<div class='button4-column1'>";
                echo "</div>";
                echo "<div class='button4-column2'>";
                    // TITLE: PHOTO (Forced to Full Caps)
                    echo '<p class="title">' . mb_strtoupper(t('IMG_TITLE'), 'UTF-8') . '</p>';
                echo "</div>";
                echo "<div class='button4-column3'>";
                     // CANCEL BUTTON
                     echo "<button type='button' id='cancelBtn' class='login-button'>" . t('IMG_CANCEL') . "</button>";
                echo "</div>";
            echo "</div>";
            echo "<div class='medium_line'></div>";
            echo "<div class='button9-row'>";
                echo "<div class='button9-column1'>";
                    // INSTRUCTIONS: Sentence Case
                    echo "<span class='feedback' id='receiving'>" . t('IMG_INSTR') . "</span><br><br>";
                echo "</div>";
            echo "</div>";
            echo "<div class='medium_line'></div>";
        echo "</div>";
    echo "</div>";

    echo "<div class='parent-container'>";
        echo "<div>";
            echo "<table cellspacing='0' cellpadding='0' id='maintable' class='tableclass1'>";
                echo "<tr>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='zoomout' readonly /><img src = 'img/ZoomOut_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='brightnessmax' readonly /><img src = 'img/BrightnessMax_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' onselectstart='return false;' onmousedown='return false;' id='contrastmax' readonly /><img src = 'img/ContrastMax_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='colormax' readonly /><img src = 'img/ColorMax_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                echo "</tr>";
                echo "<tr>";
                    echo "<td style='height: 5px !important; background-color: #162042;'></td>";
                    echo "<td style='height: 5px !important; background-color: #162042;'></td>";
                    echo "<td style='height: 5px !important; background-color: #162042;'></td>";
                    echo "<td style='height: 5px !important; background-color: #162042;'></td>";
                echo "</tr>";
                echo "<tr>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='zoomin' readonly /><img src = 'img/ZoomIn_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='brightnessmin' readonly /><img src = 'img/BrightnessMin_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='contrastmin' readonly /><img src = 'img/ContrastMin_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                    echo "<td class='dark' style='height: 50px;'>";
                        echo "<button type='button' class='image-button2' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='colormin' readonly /><img src = 'img/ColorMin_Icon_35x35.png' visibility = 'hidden'/></button>";
                    echo "</td>";
                echo "</tr>";
            echo "</table>";
        echo "</div>";

        echo "<div class='makecenter' style='position: relative; width: 350px; height: 250px;'>";
            echo "<canvas id='canvas' class='canvasclass'></canvas>";
            
            // THE LIVE VIDEO DISPLAY OVERLAY (Sits right on top of the canvas during preview)
            echo "<video id='webcamVideo' autoplay playsinline style='display: none; width: 350px; height: 250px; object-fit: cover; border-radius: 4px; position: absolute; top: 0; left: 0; z-index: 10;'></video>";
        echo "</div>";

        echo "<div>";
            echo "<table cellspacing='0' cellpadding='0' id='maintable' class='tableclass1'>";
                echo "<tr>";
                    echo "<td class='dark' style='width: 175px; height: 50px;'>";
                        // RESET BUTTON
                        echo "<button type='button' class='image-button' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='text_05' readonly /><img src = 'img/Reset_Icon_70x70.png' class='iconclass35' id='reset'/><span class='image-text'>" . t('IMG_RESET') . "</span></button>";
                    echo "</td>";
                    echo "<td class='dark' style='width: 175px; height: 50px;'>";
                        // ROTATE BUTTON
                        echo "<button type='button' class='image-button' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='text_04' readonly /><img src = 'img/Rotate_Icon_70x70.png' class='iconclass35' id='rotate'/><span  class='image-text'>" . t('IMG_ROTATE') . "</span></button>";
                    echo "</td>";
                echo "</tr>";
                echo "<tr>";
                    echo "<td class='dark' style='width: 175px; height: 50px;'>";
                        // GET IMAGE BUTTON (Triggers standard file explorer)
                        echo "<button type='button' class='image-button' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='text_02' readonly /><img src = 'img/Upload_Icon_70x70.png' class='iconclass35' id='save'/><span  class='image-text'>" . t('IMG_GET') . "</span></button>";
                    echo "</td>";
                    echo "<td class='dark' style='width: 175px; height: 50px;'>";
                        // ADAPTIVE SLOT (Launches as SNAP, transforms into ABORT if an active asset loads)
                        echo "<button type='button' class='image-button' unselectable='on' onselectstart='return false;' onmousedown='return false;' id='text_06' readonly />";
                            echo "<img src='img/Camera_Icon_70x70.png' class='iconclass35' id='return'/>";
                            
                            // FIXED: Added the translation call to draw your custom 'ne' Dutch dictionary data instantly!
                            echo "<span id='returnspan' class='image-text'>" . t('IMG_SNAP') . "</span>";
                            
                        echo "</button>";
                    echo "</td>";

                echo "</tr>";
            echo "</table>";
        echo "</div>";
    echo "</div>";

    // Hidden System Input Elements Container
    echo "<div style='display:none;'>";
        echo "<input type='file' id='loadpicture' value='XX' accept='image/*'/>";
        echo "<input type='file' id='camerapicture' accept='image/*' capture='user'/>";
    echo "</div>";

    echo "<input type='range' min='0' max='100' value='50' id='myRange' visibility='hidden' readonly style='display: none'/>";
    echo "<input type='range' min='0' max='100' value='50' id='myRange1' visibility='hidden' readonly style='display: none'/>";
    echo "<input type='range' min='0' max='100' value='50' id='myRange2' visibility='hidden' readonly style='display: none'/>";
    echo "<input type='range' min='0' max='100' value='50' id='myRange3' visibility='hidden' readonly style='display: none'/>";
    
    echo "<canvas id='canvas2' width='350' height='250' style='display: none'></canvas>";
    
    // LIVE STREAMING DESKTOP VIEWPORT ELEMENT PLACEHOLDER
    echo "<video id='webcamVideo' autoplay playsinline style='display: none; width: 350px; height: 250px; object-fit: cover;'></video>";
    // We let PHP translate the words ONCE on boot, and save them as plain JS variables
    echo "<script>";
        echo "var textSnap = '" . addslashes(t('IMG_SNAP')) . "';";
        echo "var textAbort = '" . addslashes(t('IMG_ABORT')) . "';";
        echo "var textCaption = '" . addslashes(t('IMG_CAPTION')) . "';";
        echo "var textGet = '" . addslashes(t('IMG_GET')) . "';";
    echo "</script>";

    echo "<script src='js/image.js' charset='utf-8'></script>";

?>
</body>
</html>