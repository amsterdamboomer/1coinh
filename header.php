<?php
    ob_start();
    //=====================  DEBUG LINES ===================================
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    echo "<style>*{user-select:text !important;-webkit-user-select:text !important;-moz-user-select:text !important;-ms-user-select:text !important;}</style>";
    //======================================================================

    // 1. Setup dynamic directory paths FIRST
    $dir = dirname($_SERVER['PHP_SELF']);
    $baseHref = ($dir === DIRECTORY_SEPARATOR) ? "/" : rtrim($dir, '/\\') . "/";
    $sessionPath = $baseHref; 

    // 2. Simplified Session Start (Fixed to Root)
    if (session_status() === PHP_SESSION_NONE) {
        // Use '/' instead of $sessionPath to ensure the cookie works everywhere
        session_set_cookie_params(['path' => '/', 'samesite' => 'Lax']);
        session_start();
    }

    // --- NEW: ABORT LANGUAGE CLEANUP ---
    if (isset($_GET['abort_lang'])) {
        unset($_SESSION['form_data']['temp_language']);
    }

    // 3. Optimized Timeout Logic
    $inactivity_limit = 900; 
    if (isset($_SESSION['userid'])) {
        $now = time();
        $last = $_SESSION['last_activity'] ?? $now;

        if (($now - $last) > $inactivity_limit) {
            session_unset();
            session_destroy();
            header("Location: " . $baseHref . "index2.php?error=timeout");
            exit();
        }
        $_SESSION['last_activity'] = $now;
    }

    require_once "includes/dbh.inc.php";
    require_once "includes/functions.inc.php"; 

    $m_availablecoins = 0;
    $userData = null;
    $trueJoinedDate = "";

    if (isset($_SESSION["userid"])) {
        $m_user = $_SESSION["userid"];
        $userData = getUserData($conn, $m_user);
        
        if ($userData) {
            $m_availablecoins = calculateUserBalance($conn, $m_user);
            $sqlDate = "SELECT MIN(join_date) as joined FROM (
                            SELECT start as join_date FROM users WHERE usersId = ?
                            UNION ALL
                            SELECT start_old as join_date FROM users_old WHERE uid_old = ?
                        ) as combined_dates";
            $stmtD = $conn->prepare($sqlDate);
            $stmtD->bind_param("ii", $m_user, $m_user);
            $stmtD->execute();
            $dateData = $stmtD->get_result()->fetch_assoc();
            $trueJoinedDate = $dateData['joined'];
        } else {
            session_unset();
            session_destroy();
            header("Location: index2.php");
            exit();
        }
    }

    $supportedLangs = ['ah','ar','am','az','by','be','bo','bg','ca','ch','cz','se','da','de','en','es','fp','fr','ir','gr','ha','he','hi','cr','ig','in','ic','it','ja','ka','kh','ki','sh','co','ko','kg','la','lv','lt','hu','mg','ma','ml','mo','bu','ne','np','no','or','pa','pe','po','pt','ro','ru','zi','al','sl','sk','so','fi','sw','ta','th','vi','tu','ur','yo'];

    // 1. Determine the Saved (Permanent) Language
    if (isset($userData) && !empty($userData['language'])) {
        $permanentLang = $userData['language'];
        
        // Save the logged-in user's language into a persistent cookie
        if (!isset($_COOKIE['site_lang']) || $_COOKIE['site_lang'] !== $permanentLang) {
            setcookie('site_lang', $permanentLang, [
                'expires' => time() + (365 * 24 * 60 * 60), // 1 year
                'path' => '/',
                'samesite' => 'Lax'
            ]);
        }
    } else {
        // When logged out, prioritize the browser cookie over automatic detection
        $permanentLang = $_COOKIE['site_lang'] ?? $_SESSION['user_lang'] ?? detectBestLanguage($supportedLangs, 'en');
    }
    $currentLang = $_SESSION['form_data']['temp_language'] ?? $permanentLang;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo t('TITLE'); ?></title>
    <link rel="icon" type="image/x-icon" href="download/favicon.ico">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" type="text/css" href="<?php echo $baseHref; ?>css/reset.css">        
    <link rel="stylesheet" type="text/css" href="<?php echo $baseHref; ?>css/main.css">
    <meta name="format-detection" content="telephone=no">
</head>
<body>
<div id="loading-overlay" class="loader-wrapper" style="display: flex;">
    <div class="loader"></div>
    <div class="loader-text" id="loader-msg">Connecting...</div>
</div>    
<div class="full-width-header-wrapper">
    <header class='header-main'>
        <div class='menu-column1'>
            <?php 
            $currentFile = basename($_SERVER['PHP_SELF']);
            if ($currentFile === 'index2.php'): 
            ?>
                <!-- On index2.php: Show Home icon linking to index.php -->
                <a href="<?php echo $baseHref; ?>index.php">
                    <img src="<?php echo $baseHref; ?>img/Home_70x70.png" 
                         alt="Home" 
                         style="width: clamp(35px, 12.15vw, 70px); 
                                height: clamp(35px, 12.15vw, 70px); 
                                display: block;
                                transition: filter 0.3s ease;">
                </a>
            <?php else: ?>
                <!-- On all other pages: Show 1CoinH logo linking to index2.php -->
                <a href="<?php echo $baseHref; ?>index2.php">
                    <div class='header-main-index-logo'></div>
                </a>
            <?php endif; ?>
        </div>       
        <?php if ($userData): ?>
            <!-- LOGGED IN VIEW -->
            <div class='menu-column2'>
                <div style="position: relative;">
                    <div id="balance-float"></div>
                    <a href='transactions.php' class='amount-container2' id='balance-anchor' style="display: flex; flex-direction: column; align-items: center; text-align: center;">
                        <?php
                        $padded = str_pad($userData["usersId"], 10, "0", STR_PAD_LEFT);
                        $displayNum = substr($padded, 0, 1) . " " . 
                                      substr($padded, 1, 3) . " " . 
                                      substr($padded, 4, 3) . " " . 
                                      substr($padded, 7, 3);
                        ?>
                        <span class='display-num'><?php echo $displayNum; ?></span>
                        <span class='amount' id='header-balance'><?php echo number_format($m_availablecoins, 2); ?> ᕫ</span>
                    </a>
                </div>
            </div>
            <div class='menu-column3' id='human'>
                <a href='profile.php'><img src='<?php echo $userData['image']; ?>' id='b64img' class='mainusericon'/></a>
            </div>

        <?php else: ?>
            <!-- LOGGED OUT VIEW -->
            <div class='menu-column2'>
                <a href='https://abundomy.com' target='_blank' class='title-link' style='text-decoration:none; color:inherit;'>
                    <?php echo mb_strtoupper(t('TITLE'), 'UTF-8'); ?>
                </a>
            </div>
            <!-- LOGGED OUT VIEW -->
            <div class='menu-column3' id='human' style="display: flex; justify-content: center; align-items: center; min-width: 70px;">
                
                <!-- UPGRADED: True 70x70px Avatar Container Box -->
                <div id="logged-out-avatar-wrap" style="display: none; width: 70px; height: 70px; border-radius: 50%; overflow: hidden; flex-shrink: 0; transition: transform 0.2s ease;">
                    <img id="logged-out-avatar-img" src="" style="width: 100%; height: 100%; object-fit: cover;">
                </div>

                <!-- 1. The Circular JPG Language Flag Icon Container -->
                <div id="header-flag-wrap" style="display: block;">
                </div>

                <script>
                    (function() {
                        const localPhoto = localStorage.getItem('image1');
                        
                        if (localPhoto && localPhoto.trim() !== "") {
                            const checkElements = setInterval(() => {
                                const avatarImg = document.getElementById('logged-out-avatar-img');
                                const avatarWrap = document.getElementById('logged-out-avatar-wrap');
                                const flagWrap = document.getElementById('header-flag-wrap');
                                
                                if (avatarImg && avatarWrap && flagWrap) {
                                    // 1. Force mount the profile image into the tag source
                                    avatarImg.src = localPhoto;
                                    
                                    // 2. Hide the language flag container entirely
                                    flagWrap.style.setProperty('display', 'none', 'important');
                                    
                                    // 3. Make the 70x70 profile image layout visible
                                    avatarWrap.style.setProperty('display', 'block', 'important');
                                    
                                    clearInterval(checkElements);
                                }
                            }, 5);
                            setTimeout(() => clearInterval(checkElements), 2000);
                        }
                    })();
                </script>
            </div> <!-- Explicitly closes menu-column3 for logged out users -->

        <?php endif; ?>

    </header>
</div>


<?php if ($userData): ?>
    <script>
        // Data for JS Heartbeat
        var signupDate = '<?php echo $trueJoinedDate; ?>'; 
        var decayRate = 0.99995;
        var currentUserId = <?php echo (int)$userData['usersId']; ?>;
        var lastSeenTid = 0; 
        var lastProposalCount = -1; 

        // --- AUTO LOGOUT LOGIC ---
        let idleTimer;
        const fifteenMinutes = 150 * 60 * 1000;

        function resetIdleTimer() {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                window.location.href = "includes/logout.inc.php?reason=timeout";
            }, fifteenMinutes);
        }

        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetIdleTimer);
        });
        resetIdleTimer();

        // --- HEARTBEAT FUNCTIONS ---
        async function checkLiveBalance() {
            // Optimization: Don't ping the server if the tab is hidden
            if (document.hidden) return;

            try {
                const response = await fetch('check-balance.php');
                const data = await response.json();

                if (data.status === 'success' && data.userId == currentUserId) {
                    const balanceEl = document.getElementById('header-balance');
                    if (!balanceEl) return;

                    const screenBalance = parseFloat(balanceEl.innerText.replace(/[^\d.-]/g, ''));
                    const newBalance = parseFloat(data.balance);

                    // Trigger animations based on data changes
                    if (data.lastTid != lastSeenTid && lastSeenTid !== 0) {
                        animateTransaction(data);
                    } else if (Math.abs(newBalance - screenBalance) > 0.009) {
                        animateBalanceChange(newBalance);
                    }
                    
                    // Refresh proposal list if count changed
                    const proposalArea = document.getElementById('proposal-list-area');
                    if (proposalArea && data.proposalCount !== lastProposalCount) {
                        updateRequestList();
                        lastProposalCount = data.proposalCount;
                    }

                    lastSeenTid = data.lastTid;
                }
            } catch (error) {
                // Silently fail if network is busy
            }
        }

        async function updateRequestList() {
            try {
                const response = await fetch('fetch-requests.php');
                const html = await response.text();
                const proposalArea = document.getElementById('proposal-list-area');
                if (proposalArea && html.trim() !== "") {
                    proposalArea.innerHTML = html;
                }
            } catch (error) {}
        }
        
        function animateBalanceChange(newVal) {
            const el = document.getElementById('header-balance');
            if (!el) return;

            el.classList.add('animate-explode');

            setTimeout(() => { 
                // This creates the "1,000.00" format to match your PHP
                const formatted = new Intl.NumberFormat('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(newVal);

                el.innerText = formatted + " ᕫ"; 
            }, 400);

            setTimeout(() => { el.classList.remove('animate-explode'); }, 1000);
        }


        function animateTransaction(data) {
            const floatEl = document.getElementById('balance-float');
            const balanceEl = document.getElementById('header-balance');
            if (!floatEl || !balanceEl) return;

            const color = data.type === 'sent' ? 'var(--error)' : '#00BFFF';
            const sign = data.type === 'sent' ? '-' : '+';
            
            floatEl.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; background: rgba(0,0,0,0.8); padding: 5px 12px; border-radius: 25px; border: 1px solid var(--text);">
                    <img src="${data.image}" style="width:30px; height:30px; border-radius:50%; border:2px solid white; object-fit:cover;">
                    <span style="color:${color}; font-weight:bold; font-family:roboto_bold;">${sign}${parseFloat(data.amount).toFixed(2)}</span>
                </div>
            `;

            balanceEl.classList.add('animate-explode');
            floatEl.classList.add('animate-float');
            
            setTimeout(() => { balanceEl.innerText = parseFloat(data.balance).toFixed(2) + " ᕫ"; }, 400);

            setTimeout(() => {
                balanceEl.classList.remove('animate-explode');
                floatEl.classList.remove('animate-float');
                floatEl.innerHTML = ""; 
            }, 3000);
        }

        setInterval(checkLiveBalance, 5000);
    </script>
<?php endif; ?>

<script>
    function setLoader(visible, text = "Connecting...") {
        const overlay = document.getElementById('loading-overlay');
        const msg = document.getElementById('loader-msg');
        if (!overlay) return;

        if (visible) {
            if (msg) msg.innerText = text;
            overlay.style.setProperty("display", "flex", "important");
        } else {
            overlay.style.setProperty("display", "none", "important");
        }
    }

    // Kill on load
    window.addEventListener('load', () => setLoader(false));
    
    // Kill if DOM is already ready (for fast localhost)
    if (document.readyState === 'complete') setLoader(false);

    // Navigation logic
    window.addEventListener('beforeunload', () => {
        setTimeout(() => { setLoader(true, "Connecting..."); }, 1500);
    });

    // Connectivity logic
    window.addEventListener('offline', () => setLoader(true, "No Internet Connection"));
    window.addEventListener('online', () => setLoader(false));

    // Service Worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./sw.js').catch(() => {});
    }
</script>