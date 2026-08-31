<?php
require 'header.php';

// 1. Get Target User Data & Return Logic
$viewId = isset($_GET['user']) ? (int)$_GET['user'] : $_SESSION['userid'];
$fromPage = isset($_GET['from']) ? $_GET['from'] : 'transactions.php';
$revId = isset($_GET['rev']) ? (int)$_GET['rev'] : 0;

if ($fromPage == 'profile.php') {
    $backLink = "profile.php";
} elseif ($fromPage == 'humandetails.php') {
    $backLink = "humandetails.php?user=$viewId" . ($revId ? "&rev=$revId" : "");
} else {
    $y = isset($_GET['year']) ? (int)$_GET['year'] : date("Y");
    $m = isset($_GET['month']) ? (int)$_GET['month'] : date("n");
    $backLink = "transactions.php?user=$viewId&year=$y&month=$m";
}

// 2. Database Checks (Ranges)
// Check for Historic Records
$stmtHist = $conn->prepare("SELECT COUNT(*) as count FROM users_old WHERE uid_old = ?");
$stmtHist->bind_param("i", $viewId);
$stmtHist->execute();
$hasHistory = ($stmtHist->get_result()->fetch_assoc()['count'] > 0);

// Check Transaction Range (Absolute min/max)
$stmtRange = $conn->prepare("SELECT MIN(time_stamp) as min_t, MAX(time_stamp) as max_t FROM transactions WHERE giver = ? OR receiver = ?");
$stmtRange->bind_param("ii", $viewId, $viewId);
$stmtRange->execute();
$range = $stmtRange->get_result()->fetch_assoc();

$firstT = $range['min_t'] ? date("Y-m-d", strtotime($range['min_t'])) : null;
$lastT = $range['max_t'] ? date("Y-m-d", strtotime($range['max_t'])) : null;

// 3. Initial Date Calculation
if (isset($_GET['year']) && isset($_GET['month'])) {
    $y = (int)$_GET['year']; $m = (int)$_GET['month'];
    $dateFromValue = date("Y-m-d", mktime(0, 0, 0, $m, 1, $y));
    $dateToValue = date("Y-m-d", mktime(0, 0, 0, $m + 1, 0, $y));
} else {
    $currentYear = date("Y");
    $dateFromValue = $currentYear . "-01-01";
    $dateToValue = $currentYear . "-12-31";
}

// Check if the switch should be pre-checked (if current view already covers everything)
$isCurrentlyAll = false;
if ($firstT && $lastT) {
    if ($dateFromValue <= $firstT && $dateToValue >= $lastT) {
        $isCurrentlyAll = true;
    }
}

// Fetch basic user info
$sql = "SELECT usersName, image FROM users WHERE usersId = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $viewId);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
?>

<div id="centerContainer">
    <div class="field-wrapper">
        <div class="medium_line"></div>
        <p class="title" style="text-align: center; width: 100%;"><?php echo mb_strtoupper(t('PDF_TITLE'), 'UTF-8'); ?></p>
        <div class="medium_line"></div>
        
        <div style="display: flex; flex-direction: column; align-items: center; margin-bottom: 25px;">
            <img src="<?php echo htmlspecialchars($userData['image'] ?? ''); ?>" class="transactionicon2" style="width: 80px; height: 80px; margin-bottom: 10px;" />
            <p class="title" style="text-align: center; font-size: 1.2rem; width: 100%;"><?php echo htmlspecialchars($userData['usersName']); ?></p>
        </div>

        <form action="includes/generate-pdf.inc.php" method="POST" target="_blank">
            <input type="hidden" name="user_id" value="<?php echo $viewId; ?>">
            <input type="hidden" name="return_url" value="<?php echo urlencode($backLink); ?>">

            <!-- Switch 1: Full Personal History -->
            <div class='button-full-row'>
                <div class='switch-container'>
                    <label class='switch'>
                        <input type='checkbox' name="print_history" <?php echo $hasHistory ? "" : "disabled"; ?>>
                        <span class='slider round' style="<?php echo $hasHistory ? "" : "opacity:0.3;"; ?>"></span>
                    </label>
                    <span class='feedback5' style="<?php echo $hasHistory ? "" : "color:var(--disabled);"; ?>"><?php echo t('PDF_LBL_HISTORY'); ?></span>
                </div>
            </div>

            <div class="large_line"></div>

            <div class='button5-row'>
                <div class='button5-column1'>
                    <label class='form__label'><?php echo t('PDF_FROM'); ?></label><br>
                    <input type='date' id='dateFrom' name='date_from' value='<?php echo $dateFromValue; ?>' required />
                </div>
                <div class='button5-column2'></div>
                <div class='button5-column3'>
                    <label class='form__label'><?php echo t('PDF_TO'); ?></label><br>
                    <input type='date' id='dateTo' name='date_to' value='<?php echo $dateToValue; ?>' required />
                </div>
            </div>

            <div class="medium_line"></div>

            <!-- Switch 2: All Transactions -->
            <div class='button-full-row'>
                <div class='switch-container'>
                    <label class='switch'>
                        <input type='checkbox' name="download_all" id="allToggle" <?php echo ($firstT && $lastT) ? ($isCurrentlyAll ? "checked" : "") : "disabled"; ?>>
                        <span class='slider round' style="<?php echo ($firstT && $lastT) ? "" : "opacity:0.3;"; ?>"></span>
                    </label>
                    <span class='feedback5' style="<?php echo ($firstT && $lastT) ? "" : "color:var(--disabled);"; ?>"><?php echo t('PDF_LBL_ALL'); ?></span>
                </div>
            </div>

            <div class="large_line"></div>

            <div class="button-row" style="display: flex; justify-content: center;">
                <button type="submit" name="generate_pdf" class="login-button"><?php echo mb_strtoupper(t('PDF_BTN_DL'), 'UTF-8'); ?></button>
            </div>

            <div class="full_line"></div>

            <div class="button-row" style="display: flex; justify-content: center;">
                <a href="<?php echo $backLink; ?>"><button type="button" class="login-button"><?php echo t('PR_BACK'); ?></button></a>
            </div>
        </form>
    </div>
</div>

<script>
    // Store original dates and absolute min/max for the JS toggle
    const firstDate = "<?php echo $firstT; ?>";
    const lastDate = "<?php echo $lastT; ?>";
    const origFrom = "<?php echo $dateFromValue; ?>";
    const origTo = "<?php echo $dateToValue; ?>";

    const allToggle = document.getElementById('allToggle');
    const fromInput = document.getElementById('dateFrom');
    const toInput = document.getElementById('dateTo');

    // Helper function to enable/disable inputs
    function updateInputState() {
        if (allToggle.checked) {
            if (firstDate && lastDate) {
                fromInput.value = firstDate;
                toInput.value = lastDate;
            }
            fromInput.disabled = true;
            toInput.disabled = true;
            fromInput.style.opacity = "0.3";
            toInput.style.opacity = "0.3";
        } else {
            fromInput.disabled = false;
            toInput.disabled = false;
            fromInput.style.opacity = "1";
            toInput.style.opacity = "1";
        }
    }

    // 1. Logic for when Toggle is clicked
    allToggle.addEventListener('change', updateInputState);

    // 2. Logic for when Dates are changed manually (to turn toggle off if narrowed)
    function checkDateBoundaries() {
        if (!firstDate || !lastDate) return;

        // If user manually selects exactly the full range, check the box
        if (fromInput.value <= firstDate && toInput.value >= lastDate) {
            allToggle.checked = true;
        } else {
            allToggle.checked = false;
        }
        updateInputState();
    }

    fromInput.addEventListener('change', checkDateBoundaries);
    toInput.addEventListener('change', checkDateBoundaries);

    // 3. Run on page load to set initial state
    updateInputState();
</script>
