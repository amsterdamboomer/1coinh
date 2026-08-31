<?php

if (isset($_POST["reset-password-submit"])) {
    $selector = $_POST["selector"];
    $validator = $_POST["validator"];
    $password = $_POST["pwd"];
    $passwordRepeat = $_POST["pwd-repeat"];

    // 1. Check for empty fields
    if (empty($password) || empty($passwordRepeat)) {
        header("Location: ../create-new-password.php?selector=$selector&validator=$validator&newpwd=empty");
        exit();
    } 
    // 2. Check if passwords match
    else if ($password != $passwordRepeat) {
        header("Location: ../create-new-password.php?selector=$selector&validator=$validator&newpwd=pwdnotsame");
        exit();
    }
    // 2.5 Check for strong password
    else {
        $passwordRegex = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]).{8,}$/';
        if (!preg_match($passwordRegex, $password)) {
            header("Location: ../create-new-password.php?selector=$selector&validator=$validator&newpwd=weakpassword");
            exit();
        }
    }

    $currentDate = date("U");
    require 'dbh.inc.php';

    // 3. Verify the selector and check expiration
    $sql = "SELECT * FROM pwdReset WHERE pwdResetSelector=? AND pwdResetExpires >= ?";
    $stmt = mysqli_stmt_init($conn);
    if (!mysqli_stmt_prepare($stmt, $sql)) {
        header("Location: ../create-new-password.php?selector=$selector&validator=$validator&newpwd=databaseerror");
        exit();
    } else {
        mysqli_stmt_bind_param($stmt, "ss", $selector, $currentDate);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (!$row = mysqli_fetch_assoc($result)) {
            header("Location: ../create-new-password.php?newpwd=expired");
            exit();
        } else {
            // 4. Verify the Token
            $tokenBin = hex2bin($validator);
            $tokenCheck = password_verify($tokenBin, $row["pwdResetToken"]);

            if ($tokenCheck === false) {
                header("Location: ../create-new-password.php?newpwd=invalidtoken");
                exit();
            } elseif ($tokenCheck === true) {
                $tokenEmail = $row['pwdResetEmail'];

                // 5. Update user password
                $sql = "UPDATE users SET usersPwd=? WHERE usersEmail=?;";
                $stmt = mysqli_stmt_init($conn);
                if (!mysqli_stmt_prepare($stmt, $sql)) {
                    header("Location: ../create-new-password.php?selector=$selector&validator=$validator&newpwd=databaseerror");
                    exit();
                } else {
                    $newPwdHash = password_hash($password, PASSWORD_DEFAULT);
                    mysqli_stmt_bind_param($stmt, "ss", $newPwdHash, $tokenEmail);
                    mysqli_stmt_execute($stmt);

                    // 6. Delete the reset request from database
                    $sql = "DELETE FROM pwdReset WHERE pwdResetEmail=?;";
                    $stmt = mysqli_stmt_init($conn);
                    if (!mysqli_stmt_prepare($stmt, $sql)) {
                        header("Location: ../create-new-password.php?newpwd=databaseerror");
                        exit();
                    } else {
                        mysqli_stmt_bind_param($stmt, "s", $tokenEmail);
                        mysqli_stmt_execute($stmt);
                        header("Location: ../index2.php?error=none_passwordupdated");
                        exit();
                    }
                }
            }
        }
    }
} else {
    header("Location: ../index2.php");
    exit();
}