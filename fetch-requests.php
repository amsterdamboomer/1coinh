<?php
// fetch-requests.php
session_start(['read_and_close' => true]);

require "includes/dbh.inc.php";
require "includes/functions.inc.php";

// Define $currentLang globally so the t() function inside getProposalsHTML can see it
global $currentLang;

if (isset($_SESSION["userid"])) {
    // 1. Fetch user data to get their preferred language from the DB
    $u_data = getUserData($conn, $_SESSION["userid"]);
    
    // 2. Set the global language variable
    $currentLang = (!empty($u_data['language'])) ? $u_data['language'] : 'en';

    // 3. Now the t() function will correctly return "NIEUW VERZOEK"
    echo getProposalsHTML($conn, $_SESSION["userid"]);
}
