<?php
session_start(); // <--- CRITICAL: Add this or the session won't save!

if (isset($_POST["submit"])) {
    // SAVE DATA TO SESSION IMMEDIATELY SO IT'S NOT LOST ON ERROR
    $_SESSION['form_data'] = $_POST;

    //==============================
    $name     = trim($_POST["name"]);
    $email    = trim($_POST["email"]);
    $username = trim($_POST["uid"]);
    $pwd = $_POST["pwd"];
    $pwdRepeat = $_POST["pwdrepeat"];
    
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
    $birthday = $_POST["birthday"];
    $gender = $_POST["gender"];
    $height = $_POST["height"];
    $hair = $_POST["hair"];
    $lefteye = $_POST["lefteye"];
    $righteye = $_POST["righteye"];
    $specialfeatures = $_POST["specialfeatures"];
    $language = $_POST["language"] ?? "en"; 

    require_once 'dbh.inc.php';
    require_once 'functions.inc.php';

    // Core validation checks rules matching system errors
    if (emptyInputSignup($name, $email, $username, $pwd, $pwdRepeat) !== false) {
        header("location: ../signup.php?error=emptyinput");
        exit();
    }
    if (invalidUid($username) !== false) {
        header("location: ../signup.php?error=invaliduid");
        exit();
    }
    if (invalidEmail($email) !== false) {
        header("location: ../signup.php?error=invalidemail");
        exit();
    }
    if (pwdMatch($pwd, $pwdRepeat) !== false) {
        header("location: ../signup.php?error=passwordsdontmatch");
        exit();
    }
    if (uidExists($conn, $username, $email) !== false) {
        header("location: ../signup.php?error=usernametaken");
        exit();
    }
    if (invalidImage($image) !== false) {
        header("location: ../signup.php?error=invalidimage");
        exit();
    }
    if (invalidBirthday($birthday) !== false) {
        header("location: ../signup.php?error=invalidbirthday");
        exit();
    }
    if (invalidHeight($height) !== false) {
        header("location: ../signup.php?error=invalidheight");
        exit();
    }
    if (invalidInput($name, $email, $username, $pwd, $image, $specialfeatures) !== false) {
        header("location: ../signup.php?error=invalidinput");
        exit();
    }

    // 1. Create user inside the relational database engine tracks
    createUser($conn, $name, $email, $username, $pwd, $image, $birthday, $gender, $height, $hair, $lefteye, $righteye, $specialfeatures, $language);

    // 2. Clear out temp session form caches since creation completed safely
    unset($_SESSION['form_data']);

    // 3. Render client-side cache purger layout to swap user image back to flag icon
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Completing Registration...</title>
        <style>
            body { background-color: #0c1117; color: #ffffff; font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .loader { border: 4px solid rgba(255,255,255,0.1); border-top: 4px solid #058C2E; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 20px; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .wrap { display: flex; flex-direction: column; align-items: center; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <div class="loader"></div>
            <div>Finalizing Account Settings...</div>
        </div>
        <script>
            // Instantly wipe the local cache so the index page header falls back to flag view state
            localStorage.removeItem('image1');
            
            // Redirect smoothly to your default dashboard landing view parameters
            window.location.href = "../index2.php?error=none";
        </script>
    </body>
    </html>
    <?php
    exit();
} else {
    header("location: ../signup.php");
    exit();
}
