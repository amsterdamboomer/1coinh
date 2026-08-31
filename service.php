<?php
require 'header.php';

if (!isServiceAdmin()) {
    header("Location: index2.php");
    exit();
}

// --- 1. HANDLE EMAIL UPDATE ACTION ---
$updateMsg = "";
if (isset($_POST['update_email'])) {
    $searchTerm = trim($_POST['identifier']);
    $newEmail = trim($_POST['new_email']);

    if (!empty($searchTerm) && filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        // This query checks if the input matches EITHER the numeric ID or the string UID
        $sqlUpd = "UPDATE users SET usersEmail = ? WHERE usersId = ? OR usersUid = ?";
        $stmtUpd = $conn->prepare($sqlUpd);
        
        // We bind the search term twice: once as an integer (for usersId) and once as a string (for usersUid)
        $searchInt = (int)$searchTerm; 
        $stmtUpd->bind_param("sis", $newEmail, $searchInt, $searchTerm);
        
        if ($stmtUpd->execute() && $stmtUpd->affected_rows > 0) {
            $updateMsg = "<p style='color: #00ff00; text-align: center;'>Success: Email updated to $newEmail</p>";
        } else {
            $updateMsg = "<p style='color: #ff0000; text-align: center;'>Error: User not found or no change made.</p>";
        }
    } else {
        $updateMsg = "<p style='color: #ff0000; text-align: center;'>Error: Invalid data provided.</p>";
    }
}


// --- 2. FETCH NEWSLETTER LIST ---
$sql = "SELECT usersEmail FROM users WHERE newsletter = 1";
$result = mysqli_query($conn, $sql);
$emails = [];
while ($row = mysqli_fetch_assoc($result)) {
    $emails[] = $row['usersEmail'];
}
$emailList = implode("\n", $emails);

echo "<div id='centerContainer'>";
    echo "<div class='field-wrapper'>";
        echo "<p class='title' style='text-align: center; width: 100%;'>" . mb_strtoupper(t('Service'), 'UTF-8') . "</p>";
        echo "<div class='medium_line'></div>";
        
        // --- SECTION: NEWSLETTER ---
        echo "<label class='form__label'>Newsletter emails users</label><br>";
        echo "<textarea id='emailArea' class='special-features selectable-text' style='height: 400px; resize: none;'>$emailList</textarea>";
        
        echo "<div class='small_line'></div>";
        echo "<div class='button-row' style='display: flex; justify-content: center;'>";
            echo "<button type='button' class='login-button' onclick='copyAllEmails()'>SELECT ALL & COPY</button>";
        echo "</div>";

        echo "<div class='medium_line'></div>";

        // --- SECTION: EMAIL CORRECTION ---
        echo "<p class='title' style='font-size: 1.2rem; text-align: center;'>" . mb_strtoupper("Fix Email Typo", 'UTF-8') . "</p>";
        if ($updateMsg) echo "<div style='text-align: center;'>$updateMsg</div>";
        
        echo "<form method='POST' style='width: 100%; text-align: center;'>";
            // RESTORED: This input was missing in your last snippet
            echo "<input type='text' name='identifier' class='form__input' placeholder='Username or uid' style='margin-bottom: 10px;' required><br>";
            echo "<div class='medium_line'></div>";
            
            echo "<input type='email' name='new_email' class='form__input' placeholder='Correct Email Address' style='margin-bottom: 10px;' required><br>";
            echo "<div class='button-row' style='display: flex; justify-content: center;'>";
                echo "<button type='submit' name='update_email' class='login-button'>UPDATE EMAIL</button>";
            echo "</div>";
        echo "</form>";

        echo "<div class='medium_line'></div>";
        
        // --- SECTION: BACK ---
        echo "<div class='button-row' style='display: flex; justify-content: center;'>";
            echo "<a href='index2.php'><button type='button' class='login-button'>" . t('PR_BACK') . "</button></a>";
        echo "</div>";
    echo "</div>";
echo "</div>";
?>


<script>
function copyAllEmails() {
    const copyText = document.getElementById("emailArea");
    copyText.select();
    copyText.setSelectionRange(0, 99999); // For mobile support
    navigator.clipboard.writeText(copyText.value).then(() => {
        alert("All emails copied to clipboard!");
    }).catch(err => {
        // Fallback for older browsers or insecure contexts
        document.execCommand("copy");
        alert("All emails copied!");
    });
}
</script>

<?php
echo "</body></html>";
?>

