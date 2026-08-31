<?php
session_start();

if (isset($_POST["reset-request-submit"])) {
    require 'dbh.inc.php';
    require 'functions.inc.php'; 

    // This remains as the fallback (screen language)
    $language = isset($_POST["lang"]) ? $_POST["lang"] : 'en'; 
    $_SESSION["language"] = $language;
    $lang = $language; 

	$selector = bin2hex(random_bytes(8));
	$token = random_bytes(32);
	$url = "www.1coinh.com/create-new-password.php?selector=" . $selector . "&validator=" . bin2hex($token);
	$expires = date("U") + 3600;
	
	$userEmail = $_POST["email"];
	
	//======================
	//check if email exists!
	//======================
	$dbaseEmail = "";
    // 1. UPDATED: Select the 'language' column
	$sql = "SELECT usersEmail, language FROM users WHERE (usersEmail = ?);";
	$stmt = mysqli_stmt_init($conn);
	if (!mysqli_stmt_prepare($stmt, $sql)) {
	  	header("location: ../reset-password.php?error=dbase");
	  	exit();
	}
	mysqli_stmt_bind_param($stmt, "s", $userEmail);
	mysqli_stmt_execute($stmt);
	$resultData = mysqli_stmt_get_result($stmt);
	
	if ($row = mysqli_fetch_assoc($resultData)) {
	 	$dbaseEmail = $row["usersEmail"];
        // 2. UPDATED: If the user has a language set in DB, use it for the email
        if (!empty($row["language"])) {
            $lang = $row["language"];
        }
	}
    mysqli_stmt_close($stmt);

	if (!($dbaseEmail == $userEmail)) {
		header("location: ../reset-password.php?error=email");
	    exit();
	}

	$sql = "DELETE FROM pwdReset WHERE pwdResetEmail=?;";
	$stmt = mysqli_stmt_init($conn);
	if (!mysqli_stmt_prepare($stmt, $sql)) {
		header("location: ../reset-password.php?error=dbase");
	    exit();
	} else {
		mysqli_stmt_bind_param($stmt, "s", $userEmail);
		mysqli_stmt_execute($stmt);
	}

	$sql = "INSERT INTO pwdReset (pwdResetEmail, pwdResetSelector, pwdResetToken, pwdResetExpires) VALUES (?, ?, ?, ?);";
	$stmt = mysqli_stmt_init($conn);
	if (!mysqli_stmt_prepare($stmt, $sql)) {
		header("location: ../reset-password.php?error=dbase");
	    exit();
	} else {
		$hashedToken = password_hash($token, PASSWORD_DEFAULT);
		mysqli_stmt_bind_param($stmt, "ssss", $userEmail, $selector, $hashedToken, $expires);
		mysqli_stmt_execute($stmt);
	}
	mysqli_stmt_close($stmt);
	
	//=================================================
	//                       EMAIL
	//=================================================
	$to = $userEmail;

	$tr = function($key, $lang) use ($translations) {
	    return $translations[$lang][$key] ?? $translations['en'][$key] ?? $key;
	};

	$subject = $tr('RS_SUBJECT', $lang);

    $abundomyLink = '<a href="https://abundomy.com">Abundomy.com</a>';

    $message = '<html><body>';
    $message .= '<p>' . $tr('RS_BODY_1', $lang) . '</p>';
    $message .= '<p>' . $tr('RS_BODY_2', $lang) . ':<br>';
    $message .= '<a href="https://' . $url . '">https://' . $url . '</a></p>';
    
    $footerLink = str_replace('abundomy.com', $abundomyLink, $tr('PY_FOOTER', $lang));
    $message .= '<br><br><p>' . $footerLink . '</p>';
    $message .= '</body></html>';

    $headers = "From: Abundomy Money <info@1coinh.com>\r\n";
    $headers .= "Reply-To: info@1coinh.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

	if (mail($to, $subject, $message, $headers)) {
        mysqli_close($conn);
		header("Location: ../reset-password.php?error=none");
	} else {
        mysqli_close($conn);
		header("Location: ../reset-password.php?error=dbase");
	}
	exit();

} else {
	header("Location: ../index2.php");
	exit();
}
