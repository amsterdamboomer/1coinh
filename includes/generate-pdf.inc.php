<?php
session_start();
require 'dbh.inc.php';

// 1. FETCH VIEWER LANGUAGE DIRECTLY
$viewerId = $_SESSION['userid'] ?? 0;
$stmtLang = $conn->prepare("SELECT language FROM users WHERE usersId = ?");
$stmtLang->bind_param("i", $viewerId);
$stmtLang->execute();
$uRow = $stmtLang->get_result()->fetch_assoc();
$L = $uRow['language'] ?? 'en'; // This is our reliable language pointer

// 2.a LOAD FUNCTIONS & TCPDF
require 'functions.inc.php';
require_once('../tcpdf/tcpdf.php');

//2.b Load Emoji Data from CSV
$emojiMap = [];
$csvPath = __DIR__ . '/../img/emojis.csv'; // Adjust path if needed
if (file_exists($csvPath)) {
    if (($handle = fopen($csvPath, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            $code = strtolower(str_replace('U+', '', $data[0]));
            // Strip any possible whitespace or quotes from the base64 string
            $base64 = trim($data[1]); 
            $emojiMap[$code] = $base64;
        }
        fclose($handle);
    }
}

function replaceEmojiWithImg($text) {
    global $emojiMap;
    if (empty($emojiMap)) return $text;

    // Regex for standard emoji ranges
    $emoji_regex = '/[\x{1F300}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

    return preg_replace_callback($emoji_regex, function($match) use ($emojiMap) {
        // Convert emoji char to lowercase hex (e.g. 1f600)
        $hex = bin2hex(mb_convert_encoding($match[0], 'UTF-32BE', 'UTF-8'));
        $hex = ltrim($hex, '0');
        $hex = strtolower($hex);

        if (isset($emojiMap[$hex])) {
            // TCPDF handles base64 data inside <img> tags very well
            return '<sub style="line-height:0;"><img src="' . $emojiMap[$hex] . '" width="13" height="13" /></sub>';
        }
        
        return $match[0]; // Return original char if no match in CSV
    }, $text);
}

// 3. DEFINE PDF TRANSLATION BYPASS
// Since global t() is failing, we use this local function
function tp($key) {
    global $translations, $L;
    return $translations[$L][$key] ?? $translations['en'][$key] ?? $key;
}

// 4. SECURITY & DATA COLLECTION
if (!isset($_POST['generate_pdf']) || !isset($_POST['user_id'])) {
    exit("Invalid Request");
}

$viewId = (int)$_POST['user_id'];
$dateFrom = $_POST['date_from'];
$dateTo = $_POST['date_to'];
$printHistory = isset($_POST['print_history']);
$downloadAll = isset($_POST['download_all']);

// 5. DATA FETCHING (Current User)
$stmtUser = $conn->prepare("SELECT * FROM users WHERE usersId = ?");
$stmtUser->bind_param("i", $viewId);
$stmtUser->execute();
$curr = $stmtUser->get_result()->fetch_assoc();

if (!$curr) { exit("User not found"); }

// 6. FORMATTING
$rawUid = str_pad($viewId, 10, "0", STR_PAD_LEFT);
$formattedAcc = substr($rawUid,0,1).' '.substr($rawUid,1,3).' '.substr($rawUid,4,3).' '.substr($rawUid,7,3);
$headerDate = date("d M Y H:i", strtotime($curr['start']));



// 7. AUTO-FONT HELPER
function autoFont($text, $color = '#000000') {
    if (empty($text)) return '-';
    
    // 1. Clean the raw text once to prevent HTML injection from the DB
    $text = htmlspecialchars($text);
    
    // 2. Inject Emoji images (This adds <img> tags which MUST remain raw HTML)
    $text = replaceEmojiWithImg($text);

    // 3. Apply Script Font Detection
    $font = 'roboto';

    // 3.1 Lao - Corrected from cid0lao to phetsarath
    if (preg_match('/[\x{0E80}-\x{0EFF}]/u', $text)) $font = 'phetsarath'; 
    // 3.2 Myanmar
    elseif (preg_match('/[\x{1000}-\x{109F}]/u', $text)) $font = 'padauk';
    // 3.3 Khmer
    elseif (preg_match('/[\x{1780}-\x{17FF}]/u', $text)) $font = 'hanuman';
    // 3.4 Japanese
    elseif (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) $font = 'cid0jp';
    // 3.5 Korean - Added detection
    elseif (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) $font = 'cid0kr';
    // 3.6 Chinese / Cantonese
    elseif (preg_match('/[\x{4E00}-\x{9FAF}]/u', $text)) $font = 'cid0cs';
    // 3.7 Global Unicode fallback (Bengali, Hindi, Amharic, etc.)
    elseif (preg_match('/[^\x00-\x7F\x80-\xFF]/u', $text)) $font = 'freeserif';
    
    // --- FIXED LINE BELOW: Removed htmlspecialchars() wrapper around $text ---
    return '<span style="font-family:'.$font.'; color:'.$color.';">' . $text . '</span>';
}


function fmt($v, $unitIcon, $isBold = false) {
    global $isRTL, $lre, $pdf_c;
    
    $c = ($v >= 0) ? '#0055aa' : '#aa0000';
    $s = ($v >= 0) ? '+' : '';
    $bOpen = $isBold ? '<b>' : '';
    $bClose = $isBold ? '</b>' : '';
    
    $num = $s . number_format($v, 2, '.', ' ');
    
    // THE FIX: Use FreeSerif for RTL to hide the control character boxes.
    // Use Roboto for everything else to keep the clean look.
    $font = $isRTL ? 'freeserif' : 'roboto';
    
    $inner = $lre . $num . '&nbsp;' . $unitIcon . $pdf_c;
    
    return '<span style="color:'.$c.'; font-family:'.$font.';">' . $bOpen . $inner . $bClose . '</span>';
}



// 8. LIVE BALANCE & JOIN DATE CALCULATION
$m_availablecoins = 0;
$trueJoinedDate = "";

// Calculate the real-time balance for the person we are looking at
$m_availablecoins = calculateUserBalance($conn, $viewId);

// Determine the absolute earliest start date across both tables
$sqlDate = "SELECT MIN(join_date) as joined FROM (
                SELECT start as join_date FROM users WHERE usersId = ?
                UNION ALL
                SELECT start_old as join_date FROM users_old WHERE uid_old = ?
            ) as combined_dates";
$stmtD = $conn->prepare($sqlDate);
$stmtD->bind_param("ii", $viewId, $viewId);
$stmtD->execute();
$dateData = $stmtD->get_result()->fetch_assoc();
$trueJoinedDate = $dateData['joined'];

// Update headerDate to use the true earliest joined date instead of just the current record
$headerDate = date("d M Y H:i", strtotime($trueJoinedDate));




// 1. Wrap everything in a Class to prevent Scope and Redeclaration crashes
class PDFTrans {
    private static $data = array(


// ah - Amharic
'ah' => array(
    'TITLE'=>'ትርፍኖሚ ገንዘብ','OVERVIEW'=>'የግብይት አጠቃላይ እይታ','FROM'=>'ከ','TO'=>'እስከ','ALL'=>'ሁሉንም ግብይቶች አትም','HIST'=>'ሙሉ የግል ታሪክን አትም','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ገ ፅ','BD'=>'የልደት ቀን','GN'=>'ጾታ','HT'=>'ቁመት','HR'=>'የፀጉር ቀለም','LE'=>'ግራ ዓይን','RE'=>'ቀኝ ዓይን','SF'=>'ልዩ ባህሪዎች','ML'=>'ወንድ','FM'=>'ሴት','OT'=>'ሌላ','PA'=>'ክፍያ','NO'=>'ምንም ግብይት አልተገኘም!',
    'HO'=>'ሰዓታት','RD'=>'ቅናሽ','CB'=>'የአሁኑ ቀሪ ሂሳብ','1PHR'=>'1ᕫ/ሰዓት','SO'=>'አንድነት','NB'=>'አዲስ ቀሪ ሂሳብ','NM'=>'ስም','ACC'=>'መለያ','ST'=>'መጀመሪያ',
    'M_1'=>'ጃን','M_2'=>'ፌብ','M_3'=>'ማር','M_4'=>'ኤፕ','M_5'=>'ሜይ','M_6'=>'ጁን','M_7'=>'ጁላይ','M_8'=>'ኦገ','M_9'=>'ሴፕ','M_10'=>'ኦክ','M_11'=>'ኖቬ','M_12'=>'ዲሴ',
    'HC_0'=>'ነጭ','HC_1'=>'ዝንጅብል ቀይ','HC_2'=>'አውበርን','HC_3'=>'ብላንድ','HC_4'=>'ቀላል ቼስትናት','HC_5'=>'መዳብ','HC_6'=>'ቀላል ብላንድ','HC_7'=>'ቼስትናት ቡናማ','HC_8'=>'ብር','HC_9'=>'መካከለኛ ብላንድ','HC_10'=>'ቀላል ቡናማ','HC_11'=>'ቲታን','HC_12'=>'ጥቁር ብላንድ','HC_13'=>'መካከለኛ ቡናማ','HC_14'=>'ግራጫ','HC_15'=>'ወርቃማ ብላንድ','HC_16'=>'ጥቁር ቡናማ','HC_17'=>'ጥቁር','HC_18'=>'ስትሮቤሪ ብላንድ','HC_19'=>'ፀጉር የሌለው',
    'EC_0'=>'አምበር','EC_1'=>'ሰማያዊ','EC_2'=>'ቡናማ','EC_3'=>'ግራጫ','EC_4'=>'አረንጓዴ','EC_5'=>'ሀዘል','EC_6'=>'ቀይ','EC_7'=>'ሰማያዊ ግራጫ','EC_8'=>'ሰማያዊ አረንጓዴ','EC_9'=>'አረንጓዴ ግራጫ','EC_10'=>'አረንጓዴ ቡናማ','EC_11'=>'ሰው ሰራሽ'
),

// ar - Arabic
'ar' => array(
    'TITLE'=>'وفراقتصاد مال','OVERVIEW'=>'نظرة عامة على المعاملات','FROM'=>'من','TO'=>'إلى','ALL'=>'طباعة كافة المعاملات','HIST'=>'طباعة السجل الشخصي الكامل','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ص ف ح ة','BD'=>'تاريخ الميلاد','GN'=>'الجنس','HT'=>'الطول','HR'=>'الشعر','LE'=>'العين اليسرى','RE'=>'العين اليمنى','SF'=>'ميزات خاصة','ML'=>'ذكر','FM'=>'أنثى','OT'=>'آخر','PA'=>'دفع','NO'=>'لم يتم العثور على معاملات!',
    'HO'=>'ساعات','RD'=>'تخفيض','CB'=>'الرصيد الحالي','1PHR'=>'١ᕫ/ساعة','SO'=>'تضامن','NB'=>'الرصيد الجديد','NM'=>'الاسم','ACC'=>'الحساب','ST'=>'بداية',
    'M_1'=>'يناير','M_2'=>'فبراير','M_3'=>'مارس','M_4'=>'أبريل','M_5'=>'مايو','M_6'=>'يونيو','M_7'=>'يوليو','M_8'=>'أغسطس','M_9'=>'سبتمبر','M_10'=>'أكتوبر','M_11'=>'نوفمبر','M_12'=>'ديسمبر',
    'HC_0'=>'أبيض','HC_1'=>'أحمر زنجبيلي','HC_2'=>'كستنائي محمر','HC_3'=>'أشقر','HC_4'=>'كستنائي فاتح','HC_5'=>'نحاسي','HC_6'=>'أشقر فاتح','HC_7'=>'بني كستنائي','HC_8'=>'فضي','HC_9'=>'أشقر متوسط','HC_10'=>'بني فاتح','HC_11'=>'تيتان','HC_12'=>'أشقر داكن','HC_13'=>'بني متوسط','HC_14'=>'رمادي','HC_15'=>'أشقر ذهبي','HC_16'=>'بني داكن','HC_17'=>'أسود','HC_18'=>'أشقر فراولة','HC_19'=>'بدون شعر',
    'EC_0'=>'كهرماني','EC_1'=>'أزرق','EC_2'=>'بني','EC_3'=>'رمادي','EC_4'=>'أخضر','EC_5'=>'عسلي','EC_6'=>'أحمر','EC_7'=>'أزرق رمادي','EC_8'=>'أزرق مخضر','EC_9'=>'أخضر رمادي','EC_10'=>'أخضر بني','EC_11'=>'طبي صناعي'
),

// am - Armenian
'am' => array(
    'TITLE'=>'Արատնտես Փող','OVERVIEW'=>'Գործարքների ակնարկ','FROM'=>'Սկսած','TO'=>'Մինչև','ALL'=>'Տպել բոլոր գործարքները','HIST'=>'Տպել ամբողջական անձնական պատմությունը','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;Է Ջ','BD'=>'Ծննդյան օր','GN'=>'Սեռ','HT'=>'Հասակ','HR'=>'Մազեր','LE'=>'Ձախ աչք','RE'=>'Աջ աչք','SF'=>'Հատուկ հատկանիշներ','ML'=>'Արական','FM'=>'Իգական','OT'=>'Այլ','PA'=>'Վճարում','NO'=>'Գործարքներ չեն գտնվել:',
    'HO'=>'Ժամեր','RD'=>'Կրճատում','CB'=>'Ընթացիկ մնացորդ','1PHR'=>'1ᕫ/Ժամ','SO'=>'Համերաշխություն','NB'=>'Նոր մնացորդ','NM'=>'Անուն','ACC'=>'Հաշիվ','ST'=>'Սկիզբ',
    'M_1'=>'Հուն','M_2'=>'Փետ','M_3'=>'Մար','M_4'=>'Ապր','M_5'=>'Մայ','M_6'=>'Հուն','M_7'=>'Հուլ','M_8'=>'Օգոս','M_9'=>'Սեպ','M_10'=>'Հոկ','M_11'=>'Նոյ','M_12'=>'Դեկ',
    'HC_0'=>'Սպիտակ','HC_1'=>'Կոճապղպեղի կարմիր','HC_2'=>'Շագանակագույն','HC_3'=>'Շիկահեր','HC_4'=>'Բաց շագանակագույն','HC_5'=>'Պղնձագույն','HC_6'=>'Բաց շիկահեր','HC_7'=>'Շագանակագույն','HC_8'=>'Արծաթագույն','HC_9'=>'Միջին շիկահեր','HC_10'=>'Բաց դարչնագույն','HC_11'=>'Տիտան','HC_12'=>'Մուգ շիկահեր','HC_13'=>'Միջին դարչնագույն','HC_14'=>'Մոխրագույն','HC_15'=>'Ոսկեգույն շիկահեր','HC_16'=>'Մուգ դարչնագույն','HC_17'=>'Սև','HC_18'=>'Ելակի շիկահեր','HC_19'=>'Առանց մազերի',
    'EC_0'=>'Սաթ','EC_1'=>'Կապույտ','EC_2'=>'Դարչնագույն','EC_3'=>'Մոխրագույն','EC_4'=>'Կանաչ','EC_5'=>'Պնդուկագույն','EC_6'=>'Կարմիր','EC_7'=>'Կապտամոխրագույն','EC_8'=>'Կապտականաչ','EC_9'=>'Կանաչամոխրագույն','EC_10'=>'Կանաչադարչնագույն','EC_11'=>'Պրոթեզ'
),

// az - Azerbaijani
'az' => array(
    'TITLE'=>'Boliqtisad Pulu','OVERVIEW'=>'Əməliyyat icmalı','FROM'=>'Kimdən','TO'=>'Kimə','ALL'=>'Bütün əməliyyatları çap et','HIST'=>'Tam şəxsi tarixçəni çap et','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S Ə H İ F Ə','BD'=>'Doğum tarixi','GN'=>'Cins','HT'=>'Boy','HR'=>'Saç','LE'=>'Sol göz','RE'=>'Sağ göz','SF'=>'Xüsusi xüsusiyyətlər','ML'=>'Kişi','FM'=>'Qadın','OT'=>'Digər','PA'=>'Ödəniş','NO'=>'Əməliyyat tapılmadı!',
    'HO'=>'Saatlar','RD'=>'Azalma','CB'=>'Cari balans','1PHR'=>'1ᕫ/Saat','SO'=>'Həmrəylik','NB'=>'Yeni balans','NM'=>'Ad','ACC'=>'Hesab','ST'=>'Başlama',
    'M_1'=>'Yan','M_2'=>'Fev','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'May','M_6'=>'İyun','M_7'=>'İyul','M_8'=>'Avq','M_9'=>'Sen','M_10'=>'Okt','M_11'=>'Noy','M_12'=>'Dek',
    'HC_0'=>'Ağ','HC_1'=>'Zəncəfil qırmızı','HC_2'=>'Qonur','HC_3'=>'Sarışın','HC_4'=>'Açıq şabalıdı','HC_5'=>'Mis','HC_6'=>'Açıq sarışın','HC_7'=>'Şabalıdı qəhvəyi','HC_8'=>'Gümüşü','HC_9'=>'Orta sarışın','HC_10'=>'Açıq qəhvəyi','HC_11'=>'Titan','HC_12'=>'Tünd sarışın','HC_13'=>'Orta qəhvəyi','HC_14'=>'Boz','HC_15'=>'Qızılı sarışın','HC_16'=>'Tünd qəhvəyi','HC_17'=>'Qara','HC_18'=>'Çiyələk sarışın','HC_19'=>'Saçsız',
    'EC_0'=>'Kəhrıba','EC_1'=>'Mavi','EC_2'=>'Qəhvəyi','EC_3'=>'Boz','EC_4'=>'Yaşıl','EC_5'=>'Ela','EC_6'=>'Qırmızı','EC_7'=>'Mavi boz','EC_8'=>'Mavi yaşıl','EC_9'=>'Yaşıl boz','EC_10'=>'Yaşıl qəhvəyi','EC_11'=>'Protez'
),

// by - Belarusian
'by' => array(
    'TITLE'=>'Дабраноміка Грошы','OVERVIEW'=>'Агляд транзакцый','FROM'=>'Ад','TO'=>'Да','ALL'=>'Друкаваць усе транзакцыі','HIST'=>'Друкаваць поўную асабістую гісторыю','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;С Т А Р О Н К А','BD'=>'Дата нараджэння','GN'=>'Пол','HT'=>'Рост','HR'=>'Валасы','LE'=>'Левае вока','RE'=>'Правае вока','SF'=>'Асаблівасці','ML'=>'Мужчынскі','FM'=>'Жаночы','OT'=>'Іншае','PA'=>'Аплата','NO'=>'Транзакцый не знойдзена!',
    'HO'=>'Гадзіны','RD'=>'Скарачэнне','CB'=>'Бягучы баланс','1PHR'=>'1ᕫ/Гадзіна','SO'=>'Салідарнасць','NB'=>'Новы баланс','NM'=>'Імя','ACC'=>'Рахунак','ST'=>'Пачатак',
    'M_1'=>'Сту','M_2'=>'Лют','M_3'=>'Сак','M_4'=>'Кра','M_5'=>'Май','M_6'=>'Чэр','M_7'=>'Ліп','M_8'=>'Жні','M_9'=>'Вер','M_10'=>'Кас','M_11'=>'Ліс','M_12'=>'Сне',
    'HC_0'=>'Белы','HC_1'=>'Рыжы','HC_2'=>'Каштанавы','HC_3'=>'Бландзін','HC_4'=>'Светла-каштанавы','HC_5'=>'Медны','HC_6'=>'Светлы бландзін','HC_7'=>'Каштанава-карычневы','HC_8'=>'Серабрысты','HC_9'=>'Сярэдні бландзін','HC_10'=>'Светла-карычневы','HC_11'=>'Тытан','HC_12'=>'Цёмны бландзін','HC_13'=>'Сярэдне-карычневы','HC_14'=>'Сівы','HC_15'=>'Залацісты бландзін','HC_16'=>'Цёмна-карычневы','HC_17'=>'Чорны','HC_18'=>'Клубнічны бландзін','HC_19'=>'Без валасоў',
    'EC_0'=>'Бурштынавы','EC_1'=>'Сіні','EC_2'=>'Карычневы','EC_3'=>'Шэры','EC_4'=>'Зялёны','EC_5'=>'Арэхавы','EC_6'=>'Чырвоны','EC_7'=>'Сіне-шэры','EC_8'=>'Сіне-зялёны','EC_9'=>'Зялёна-шэры','EC_10'=>'Зялёна-карычневы','EC_11'=>'Пратэз'
),

// be - Bengali
'be' => array(
    'TITLE'=>'প্রাচুর্যনীতি টাকা','OVERVIEW'=>'লেনদেনের ওভারভিউ','FROM'=>'থেকে','TO'=>'পর্যন্ত','ALL'=>'সমস্ত লেনদেন প্রিন্ট করুন','HIST'=>'সম্পূর্ণ ব্যক্তিগত ইতিহাস প্রিন্ট করুন','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;পৃ ষ্ঠা','BD'=>'জন্মদিন','GN'=>'লিঙ্গ','HT'=>'উচ্চতা','HR'=>'চুল','LE'=>'বাম চোখ','RE'=>'ডান চোখ','SF'=>'বিশেষ বৈশিষ্ট্য','ML'=>'পুরুষ','FM'=>'মহিলা','OT'=>'অন্যান্য','PA'=>'পেমেন্ট','NO'=>'কোনো লেনদেন পাওয়া যায়নি!',
    'HO'=>'ঘণ্টা','RD'=>'হ্রাস','CB'=>'বর্তমান ব্যালেন্স','1PHR'=>'১ᕫ/ঘণ্টা','SO'=>'সংহতি','NB'=>'নতুন ব্যালেন্স','NM'=>'নাম','ACC'=>'অ্যাকাউন্ট','ST'=>'শুরু',
    'M_1'=>'জানু','M_2'=>'ফেব্রু','M_3'=>'মার্চ','M_4'=>'এপ্রিল','M_5'=>'মে','M_6'=>'জুন','M_7'=>'জুলাই','M_8'=>'আগস্ট','M_9'=>'সেপ্টে','M_10'=>'অক্টো','M_11'=>'নভে','M_12'=>'ডিসে',
    'HC_0'=>'সাদা','HC_1'=>'আদা লাল','HC_2'=>'অবার্ন','HC_3'=>'সোনালী','HC_4'=>'হালকা চেস্টনাট','HC_5'=>'তামাটে','HC_6'=>'হালকা সোনালী','HC_7'=>'চেস্টনাট ব্রাউন','HC_8'=>'রূপালী','HC_9'=>'মাঝারি সোনালী','HC_10'=>'হালকা বাদামী','HC_11'=>'টাইটান','HC_12'=>'গাঢ় সোনালী','HC_13'=>'মাঝারি বাদামী','HC_14'=>'ধূসর','HC_15'=>'গোল্ড ব্লন্ড','HC_16'=>'গাঢ় বাদামী','HC_17'=>'কালো','HC_18'=>'স্ট্রবেরি ব্লন্ড','HC_19'=>'চুল নেই',
    'EC_0'=>'অ্যাম্বার','EC_1'=>'নীল','EC_2'=>'বাদামী','EC_3'=>'ধূসর','EC_4'=>'সবুজ','EC_5'=>'হ্যাজেল','EC_6'=>'লাল','EC_7'=>'নীল ধূসর','EC_8'=>'নীল সবুজ','EC_9'=>'সবুজ ধূসর','EC_10'=>'সবুজ বাদামী','EC_11'=>'প্রোস্থেসিস'
),

// bo - Bosnian (Bosnian is primarily Roman script, but Izonomija is the portmanteau)
'bo' => array(
    'TITLE'=>'Izonomija Novac','OVERVIEW'=>'Pregled transakcija','FROM'=>'Od','TO'=>'Do','ALL'=>'Ispiši sve transakcije','HIST'=>'Ispiši punu historiju','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S T R A N I C A','BD'=>'Rođendan','GN'=>'Spol','HT'=>'Visina','HR'=>'Kosa','LE'=>'Lijevo oko','RE'=>'Desno oko','SF'=>'Posebne karakteristike','ML'=>'Muško','FM'=>'Žensko','OT'=>'Ostalo','PA'=>'Plaćanje','NO'=>'Nema pronađenih transakcija!',
    'HO'=>'Sati','RD'=>'Smanjenje','CB'=>'Trenutni saldo','1PHR'=>'1ᕫ/Sat','SO'=>'Solidarnost','NB'=>'Novi saldo','NM'=>'Ime','ACC'=>'Račun','ST'=>'Početak',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Maj','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Avg','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Bijela','HC_1'=>'Crvenkasta','HC_2'=>'Kestenjasta','HC_3'=>'Plava','HC_4'=>'Svijetli kesten','HC_5'=>'Bakrena','HC_6'=>'Svijetlo plava','HC_7'=>'Smeđi kesten','HC_8'=>'Srebrna','HC_9'=>'Srednje plava','HC_10'=>'Svijetlo smeđa','HC_11'=>'Titan','HC_12'=>'Tamno plava','HC_13'=>'Srednje smeđa','HC_14'=>'Siva','HC_15'=>'Zlatno plava','HC_16'=>'Tamno smeđa','HC_17'=>'Crna','HC_18'=>'Jagodasto plava','HC_19'=>'Bez kose',
    'EC_0'=>'Jantarna','EC_1'=>'Plava','EC_2'=>'Smeđa','EC_3'=>'Siva','EC_4'=>'Zelena','EC_5'=>'Lješnjak','EC_6'=>'Crvena','EC_7'=>'Plavo-siva','EC_8'=>'Plavo-zelena','EC_9'=>'Zeleno-siva','EC_10'=>'Zeleno-smeđa','EC_11'=>'Proteza'
),

// bg - Bulgarian
'bg' => array(
    'TITLE'=>'Изономика Пари','OVERVIEW'=>'Преглед на транзакциите','FROM'=>'От','TO'=>'До','ALL'=>'Печат на всички транзакции','HIST'=>'Печат на пълна история','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;С Т Р А Н И Ц А','BD'=>'Рожден ден','GN'=>'Пол','HT'=>'Височина','HR'=>'Коса','LE'=>'Ляво око','RE'=>'Дясно око','SF'=>'Специални характеристики','ML'=>'Мъж','FM'=>'Жена','OT'=>'Друго','PA'=>'Плащане','NO'=>'Няма намерени транзакции!',
    'HO'=>'Часове','RD'=>'Намаление','CB'=>'Текущо салдо','1PHR'=>'1ᕫ/Час','SO'=>'Солидарност','NB'=>'Ново салдо','NM'=>'Име','ACC'=>'Сметка','ST'=>'Начало',
    'M_1'=>'Яну','M_2'=>'Фев','M_3'=>'Мар','M_4'=>'Апр','M_5'=>'Май','M_6'=>'Юни','M_7'=>'Юли','M_8'=>'Авг','M_9'=>'Сеп','M_10'=>'Окт','M_11'=>'Ное','M_12'=>'Дек',
    'HC_0'=>'Бяла','HC_1'=>'Рижа','HC_2'=>'Кестенява','HC_3'=>'Руса','HC_4'=>'Светъл кестен','HC_5'=>'Медна','HC_6'=>'Светло руса','HC_7'=>'Кестеняво кафява','HC_8'=>'Сребриста','HC_9'=>'Средно руса','HC_10'=>'Светло кафява','HC_11'=>'Титан','HC_12'=>'Тъмно руса','HC_13'=>'Средно кафява','HC_14'=>'Сива','HC_15'=>'Златисто руса','HC_16'=>'Тъмно кафява','HC_17'=>'Черна','HC_18'=>'Ягодово руса','HC_19'=>'Без коса',
    'EC_0'=>'Кехлибар','EC_1'=>'Сини','EC_2'=>'Кафяви','EC_3'=>'Сиви','EC_4'=>'Зелени','EC_5'=>'Лешникови','EC_6'=>'Червени','EC_7'=>'Синьо-сиви','EC_8'=>'Синьо-зелени','EC_9'=>'Зелено-сиви','EC_10'=>'Зелено-кафяви','EC_11'=>'Протеза'
),

// ca - Cantonese (Traditional Script)
'ca' => array(
    'TITLE'=>'豐盛濟 錢','OVERVIEW'=>'交易概覽','FROM'=>'由','TO'=>'至','ALL'=>'列印所有交易','HIST'=>'列印完整個人歷史','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;頁 碼','BD'=>'生日','GN'=>'性別','HT'=>'身高','HR'=>'頭髮','LE'=>'左眼','RE'=>'右眼','SF'=>'特別特徵','ML'=>'男','FM'=>'女','OT'=>'其他','PA'=>'支付','NO'=>'未發現交易！',
    'HO'=>'小時','RD'=>'扣減','CB'=>'目前餘額','1PHR' => '1ᕫ/小時','SO'=>'團結','NB'=>'新餘額','NM'=>'姓名','ACC'=>'賬戶','ST'=>'開始',
    'M_1'=>'一月','M_2'=>'二月','M_3'=>'三月','M_4'=>'四月','M_5'=>'五月','M_6'=>'六月','M_7'=>'七月','M_8'=>'八月','M_9'=>'九月','M_10'=>'十月','M_11'=>'十一月','M_12'=>'十二月',
    'HC_0'=>'白色','HC_1'=>'薑紅色','HC_2'=>'赤褐色','HC_3'=>'金髮','HC_4'=>'淺栗色','HC_5'=>'銅色','HC_6'=>'淺金髮','HC_7'=>'栗棕色','HC_8'=>'銀色','HC_9'=>'中金髮','HC_10'=>'淺棕色','HC_11'=>'鈦色','HC_12'=>'深金髮','HC_13'=>'中棕色','HC_14'=>'灰色','HC_15'=>'黃金髮','HC_16'=>'深棕色','HC_17'=>'黑色','HC_18'=>'草莓金髮','HC_19'=>'無頭髮',
    'EC_0'=>'琥珀色','EC_1'=>'藍色','EC_2'=>'棕色','EC_3'=>'灰色','EC_4'=>'綠色','EC_5'=>'榛色','EC_6'=>'紅色','EC_7'=>'藍灰色','EC_8'=>'藍綠色','EC_9'=>'綠灰色','EC_10'=>'綠棕色','EC_11'=>'義眼'
),

// ch - Chinese (Simplified Script)
'ch' => array(
    'TITLE'=>'丰盛济 钱','OVERVIEW'=>'交易概况','FROM'=>'从','TO'=>'至','ALL'=>'打印所有交易','HIST'=>'打印完整个人历史','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;页 码','BD'=>'生日','GN'=>'性别','HT'=>'身高','HR'=>'头发','LE'=>'左眼','RE'=>'右眼','SF'=>'特别特征','ML'=>'男','FM'=>'女','OT'=>'其他','PA'=>'支付','NO'=>'未发现交易！',
    'HO'=>'小时','RD'=>'扣减','CB'=>'当前余额','1PHR'=>'1ᕫ/小时','SO'=>'团结','NB'=>'新余额','NM'=>'姓名','ACC'=>'账户','ST'=>'开始',
    'M_1'=>'一月','M_2'=>'二月','M_3'=>'三月','M_4'=>'四月','M_5'=>'五月','M_6'=>'六月','M_7'=>'七月','M_8'=>'八月','M_9'=>'九月','M_10'=>'十月','M_11'=>'十一月','M_12'=>'十二月',
    'HC_0'=>'白色','HC_1'=>'姜红色','HC_2'=>'赤褐色','HC_3'=>'金发','HC_4'=>'浅栗色','HC_5'=>'铜色','HC_6'=>'浅金发','HC_7'=>'栗棕色','HC_8'=>'银色','HC_9'=>'中金发','HC_10'=>'浅棕色','HC_11'=>'钛色','HC_12'=>'深金发','HC_13'=>'中棕色','HC_14'=>'灰色','HC_15'=>'黄金发','HC_16'=>'深棕色','HC_17'=>'黑色','HC_18'=>'草莓金发','HC_19'=>'无头发',
    'EC_0'=>'琥珀色','EC_1'=>'蓝色','EC_2'=>'棕色','EC_3'=>'灰色','EC_4'=>'绿色','EC_5'=>'榛色','EC_6'=>'红色','EC_7'=>'蓝灰色','EC_8'=>'蓝绿色','EC_9'=>'绿灰色','EC_10'=>'绿棕色','EC_11'=>'义眼'
),

// cz - Czech
'cz' => array(
    'TITLE'=>'Hojnomika Peníze','OVERVIEW'=>'Přehled transakcí','FROM'=>'Od','TO'=>'Do','ALL'=>'Vytisknout všechny transakce','HIST'=>'Vytisknout celou historii','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S T R A N A','BD'=>'Narozeniny','GN'=>'Pohlaví','HT'=>'Výška','HR'=>'Vlasy','LE'=>'Levé oko','RE'=>'Pravé oko','SF'=>'Zvláštní znaky','ML'=>'Muž','FM'=>'Žena','OT'=>'Jiné','PA'=>'Platba','NO'=>'Nebyly nalezeny žádné transakce!',
    'HO'=>'Hodiny','RD'=>'Snížení','CB'=>'Aktuální zůstatek','1PHR'=>'1ᕫ/Hodina','SO'=>'Solidarita','NB'=>'Nový zůstatek','NM'=>'Jméno','ACC'=>'Účet','ST'=>'Začátek',
    'M_1'=>'Led','M_2'=>'Úno','M_3'=>'Bře','M_4'=>'Dub','M_5'=>'Kvě','M_6'=>'Črv','M_7'=>'Črc','M_8'=>'Srp','M_9'=>'Zář','M_10'=>'Říj','M_11'=>'Lis','M_12'=>'Pro',
    'HC_0'=>'Bílá','HC_1'=>'Zrzavá','HC_2'=>'Kaštanová','HC_3'=>'Blond','HC_4'=>'Světle kaštanová','HC_5'=>'Měděná','HC_6'=>'Světlá blond','HC_7'=>'Kaštanově hnědá','HC_8'=>'Stříbrná','HC_9'=>'Střední blond','HC_10'=>'Světle hnědá','HC_11'=>'Titan','HC_12'=>'Tmavá blond','HC_13'=>'Středně hnědá','HC_14'=>'Šedá','HC_15'=>'Zlatá blond','HC_16'=>'Tmavě hnědá','HC_17'=>'Černá','HC_18'=>'Jahodová blond','HC_19'=>'Bez vlasů',
    'EC_0'=>'Jantarová','EC_1'=>'Modrá','EC_2'=>'Hnědá','EC_3'=>'Šedá','EC_4'=>'Zelená','EC_5'=>'Oříšková','EC_6'=>'Červená','EC_7'=>'Modrošedá','EC_8'=>'Modrozelená','EC_9'=>'Zelenošedá','EC_10'=>'Zelenohnědá','EC_11'=>'Protéza'
),

// se - Serbian
'se' => array(
    'TITLE'=>'Изономија Новац','OVERVIEW'=>'Преглед трансакција','FROM'=>'Од','TO'=>'До','ALL'=>'Одштампај све трансакције','HIST'=>'Одштампај пуну историју','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;С Т Р А Н И Ц А','BD'=>'Рођендан','GN'=>'Пол','HT'=>'Висина','HR'=>'Коса','LE'=>'Лево око','RE'=>'Десно око','SF'=>'Посебне карактеристике','ML'=>'Мушко','FM'=>'Женско','OT'=>'Остало','PA'=>'Плаћање','NO'=>'Нема пронађених трансакција!',
    'HO'=>'Сати','RD'=>'Смањење','CB'=>'Тренутни салдо','1PHR'=>'1ᕫ/Сат','SO'=>'Солидарност','NB'=>'Нови салдо','NM'=>'Име','ACC'=>'Рачун','ST'=>'Почетак',
    'M_1'=>'Јан','M_2'=>'Феб','M_3'=>'Мар','M_4'=>'Апр','M_5'=>'Мај','M_6'=>'Јун','M_7'=>'Јул','M_8'=>'Авг','M_9'=>'Сеп','M_10'=>'Окт','M_11'=>'Нов','M_12'=>'Дец',
    'HC_0'=>'Бела','HC_1'=>'Риђа','HC_2'=>'Кестењаста','HC_3'=>'Плава','HC_4'=>'Светло кестењаста','HC_5'=>'Бакрна','HC_6'=>'Светло плава','HC_7'=>'Смеђи кестен','HC_8'=>'Сребрна','HC_9'=>'Средње плава','HC_10'=>'Светло смеђа','HC_11'=>'Титан','HC_12'=>'Тамно плава','HC_13'=>'Средње смеђа','HC_14'=>'Седа','HC_15'=>'Златно плава','HC_16'=>'Тамно смеђа','HC_17'=>'Црна','HC_18'=>'Јагодасто плава','HC_19'=>'Без косе',
    'EC_0'=>'Ћилибарска','EC_1'=>'Плава','EC_2'=>'Смеђа','EC_3'=>'Сива','EC_4'=>'Зелена','EC_5'=>'Лешник','EC_6'=>'Црвена','EC_7'=>'Плаво-сива','EC_8'=>'Плаво-зелена','EC_9'=>'Зелено-сива','EC_10'=>'Зелено-смеђа','EC_11'=>'Протеза'
),

// da - Danish
'da' => array(
    'TITLE'=>'Overflomi Penge','OVERVIEW'=>'Transaktionsoversigt','FROM'=>'Fra','TO'=>'Til','ALL'=>'Udskriv alle transaktioner','HIST'=>'Udskriv fuld historik','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S I D E','BD'=>'Fødselsdag','GN'=>'Køn','HT'=>'Højde','HR'=>'Hår','LE'=>'Venstre øje','RE'=>'Højre øje','SF'=>'Særlige træk','ML'=>'Mand','FM'=>'Kvinde','OT'=>'Andet','PA'=>'Betaling','NO'=>'Ingen transaktioner fundet!',
    'HO'=>'Timer','RD'=>'Reduktion','CB'=>'Nuværende saldo','1PHR'=>'1ᕫ/Time','SO'=>'Solidaritet','NB'=>'Ny saldo','NM'=>'Navn','ACC'=>'Konto','ST'=>'Start',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Maj','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Hvid','HC_1'=>'Ingefærrød','HC_2'=>'Rødbrun','HC_3'=>'Blond','HC_4'=>'Lys kastanje','HC_5'=>'Kobber','HC_6'=>'Lys blond','HC_7'=>'Kastanjebrun','HC_8'=>'Sølv','HC_9'=>'Mellemblond','HC_10'=>'Lys brun','HC_11'=>'Titan','HC_12'=>'Mørk blond','HC_13'=>'Mellembrun','HC_14'=>'Grå','HC_15'=>'Gyldenblond','HC_16'=>'Mørkebrun','HC_17'=>'Sort','HC_18'=>'Jordbærblond','HC_19'=>'Intet hår',
    'EC_0'=>'Rav','EC_1'=>'Blå','EC_2'=>'Brun','EC_3'=>'Grå','EC_4'=>'Grøn','EC_5'=>'Hassel','EC_6'=>'Rød','EC_7'=>'Blågrå','EC_8'=>'Blågrøn','EC_9'=>'Grågrøn','EC_10'=>'Grønbrun','EC_11'=>'Protese'
),

// de - German
'de' => array(
    'TITLE'=>'Abundomie Geld','OVERVIEW'=>'Transaktionsübersicht','FROM'=>'Von','TO'=>'Bis','ALL'=>'Alle Transaktionen drucken','HIST'=>'Vollständige Historie drucken','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S E I T E','BD'=>'Geburtstag','GN'=>'Geschlecht','HT'=>'Größe','HR'=>'Haare','LE'=>'Linkes Auge','RE'=>'Rechtes Auge','SF'=>'Besondere Merkmale','ML'=>'Männlich','FM'=>'Weiblich','OT'=>'Andere','PA'=>'Zahlung','NO'=>'Keine Transaktionen gefunden!',
    'HO'=>'Stunden','RD'=>'Reduzierung','CB'=>'Aktueller Kontostand','1PHR'=>'1ᕫ/Stunde','SO'=>'Solidarität','NB'=>'Neuer Kontostand','NM'=>'Name','ACC'=>'Konto','ST'=>'Start',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mär','M_4'=>'Apr','M_5'=>'Mai','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dez',
    'HC_0'=>'Weiß','HC_1'=>'Ingwerrot','HC_2'=>'Kastanienrot','HC_3'=>'Blond','HC_4'=>'Hellkastanie','HC_5'=>'Kupfer','HC_6'=>'Hellblond','HC_7'=>'Kastanienbraun','HC_8'=>'Silber','HC_9'=>'Mittelblond','HC_10'=>'Hellbraun','HC_11'=>'Titan','HC_12'=>'Dunkelblond','HC_13'=>'Mittelbraun','HC_14'=>'Grau','HC_15'=>'Goldblond','HC_16'=>'Dunkelbraun','HC_17'=>'Schwarz','HC_18'=>'Erdbeerblond','HC_19'=>'Keine Haare',
    'EC_0'=>'Bernstein','EC_1'=>'Blau','EC_2'=>'Braun','EC_3'=>'Grau','EC_4'=>'Grün','EC_5'=>'Haselnuss','EC_6'=>'Rot','EC_7'=>'Blaugrau','EC_8'=>'Blaugrün','EC_9'=>'Grüngrau','EC_10'=>'Grünbraun','EC_11'=>'Prothese'
),

// en - English
'en' => array(
    'TITLE'=>'Abundomy Money','OVERVIEW'=>'Transaction Overview','FROM'=>'From','TO'=>'To','ALL'=>'Print all transactions','HIST'=>'Print full personal history','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P A G E','BD'=>'Birthday','GN'=>'Gender','HT'=>'Height','HR'=>'Hair','LE'=>'Left Eye','RE'=>'Right Eye','SF'=>'Special Features','ML'=>'Male','FM'=>'Female','OT'=>'Other','PA'=>'Payment','NO'=>'No Transactions Found!',
    'HO'=>'Hours','RD'=>'Reduction','CB'=>'Current balance','1PHR'=>'1ᕫ/Hour','SO'=>'Solidarity','NB'=>'New Balance','NM'=>'Name','ACC'=>'Account','ST'=>'Start',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'May','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Oct','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'White','HC_1'=>'Ginger Red','HC_2'=>'Auburn','HC_3'=>'Blond','HC_4'=>'Light Chestnut','HC_5'=>'Copper','HC_6'=>'Light Blond','HC_7'=>'Chestnut Brown','HC_8'=>'Silver','HC_9'=>'Medium Blond','HC_10'=>'Light Brown','HC_11'=>'Titan','HC_12'=>'Dark Blond','HC_13'=>'Medium Brown','HC_14'=>'Gray','HC_15'=>'Gold Blond','HC_16'=>'Dark Brown','HC_17'=>'Black','HC_18'=>'Strawberry Blond','HC_19'=>'No Hair',
    'EC_0'=>'Amber','EC_1'=>'Blue','EC_2'=>'Brown','EC_3'=>'Gray','EC_4'=>'Green','EC_5'=>'Hazel','EC_6'=>'Red','EC_7'=>'Blue Gray','EC_8'=>'Blue Green','EC_9'=>'Green Gray','EC_10'=>'Green Brown','EC_11'=>'Prosthesis'
),

// es - Spanish
'es' => array(
    'TITLE'=>'Abundomia Dinero','OVERVIEW'=>'Resumen de transacciones','FROM'=>'Desde','TO'=>'Hasta','ALL'=>'Imprimir todas las transacciones','HIST'=>'Imprimir historial personal completo','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P Á G I N A','BD'=>'Cumpleaños','GN'=>'Género','HT'=>'Altura','HR'=>'Cabello','LE'=>'Ojo izquierdo','RE'=>'Ojo derecho','SF'=>'Características especiales','ML'=>'Masculino','FM'=>'Femenino','OT'=>'Otro','PA'=>'Pago','NO'=>'¡No se encontraron transacciones!',
    'HO'=>'Horas','RD'=>'Reducción','CB'=>'Saldo actual','1PHR'=>'1ᕫ/Hora','SO'=>'Solidaridad','NB'=>'Nuevo saldo','NM'=>'Nombre','ACC'=>'Cuenta','ST'=>'Inicio',
    'M_1'=>'Ene','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Abr','M_5'=>'May','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Ago','M_9'=>'Sep','M_10'=>'Oct','M_11'=>'Nov','M_12'=>'Dic',
    'HC_0'=>'Blanco','HC_1'=>'Pelirrojo jengibre','HC_2'=>'Castaño rojizo','HC_3'=>'Rubio','HC_4'=>'Castaño claro','HC_5'=>'Cobrizo','HC_6'=>'Rubio claro','HC_7'=>'Marrón castaño','HC_8'=>'Plateado','HC_9'=>'Rubio medio','HC_10'=>'Marrón claro','HC_11'=>'Titán','HC_12'=>'Rubio oscuro','HC_13'=>'Marrón medio','HC_14'=>'Gris','HC_15'=>'Rubio dorado','HC_16'=>'Marrón oscuro','HC_17'=>'Negro','HC_18'=>'Rubio fresa','HC_19'=>'Sin cabello',
    'EC_0'=>'Ámbar','EC_1'=>'Azul','EC_2'=>'Marrón','EC_3'=>'Gris','EC_4'=>'Verde','EC_5'=>'Avellana','EC_6'=>'Rojo','EC_7'=>'Azul grisáceo','EC_8'=>'Azul verdoso','EC_9'=>'Verde grisáceo','EC_10'=>'Verde amarronado','EC_11'=>'Prótesis'
),

// fp - Filipino
'fp' => array(
    'TITLE'=>'Kasagonomiya Pera','OVERVIEW'=>'Pangkalahatang-ideya ng Transaksyon','FROM'=>'Mula sa','TO'=>'Hanggang','ALL'=>'I-print ang lahat ng transaksyon','HIST'=>'I-print ang buong kasaysayan','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P A H I N A','BD'=>'Kaarawan','GN'=>'Kasarian','HT'=>'Tangkad','HR'=>'Buhok','LE'=>'Kaliwang Mata','RE'=>'Kanang Mata','SF'=>'Espesyal na Katangian','ML'=>'Lalaki','FM'=>'Babae','OT'=>'Iba pa','PA'=>'Bayad','NO'=>'Walang nahanap na transaksyon!',
    'HO'=>'Oras','RD'=>'Bawas','CB'=>'Kasalukuyang balanse','1PHR'=>'1ᕫ/Oras','SO'=>'Solidarity','NB'=>'Bagong Balanse','NM'=>'Pangalan','ACC'=>'Account','ST'=>'Simula',
    'M_1'=>'Ene','M_2'=>'Peb','M_3'=>'Mar','M_4'=>'Abr','M_5'=>'May','M_6'=>'Hun','M_7'=>'Hul','M_8'=>'Ago','M_9'=>'Set','M_10'=>'Okt','M_11'=>'Nob','M_12'=>'Dis',
    'HC_0'=>'Puti','HC_1'=>'Ginger Red','HC_2'=>'Auburn','HC_3'=>'Blond','HC_4'=>'Light Chestnut','HC_5'=>'Tanso','HC_6'=>'Light Blond','HC_7'=>'Chestnut Brown','HC_8'=>'Pilak','HC_9'=>'Medium Blond','HC_10'=>'Light Brown','HC_11'=>'Titan','HC_12'=>'Dark Blond','HC_13'=>'Medium Brown','HC_14'=>'Abuhin','HC_15'=>'Gold Blond','HC_16'=>'Dark Brown','HC_17'=>'Itim','HC_18'=>'Strawberry Blond','HC_19'=>'Walang Buhok',
    'EC_0'=>'Amber','EC_1'=>'Asul','EC_2'=>'Kayumanggi','EC_3'=>'Abuhin','EC_4'=>'Berde','EC_5'=>'Hazel','EC_6'=>'Pula','EC_7'=>'Blue Gray','EC_8'=>'Blue Green','EC_9'=>'Green Gray','EC_10'=>'Green Brown','EC_11'=>'Prosthesis'
),

// fr - French
'fr' => array(
    'TITLE'=>'Abondomie Argent','OVERVIEW'=>'Aperçu des transactions','FROM'=>'De','TO'=>'À','ALL'=>'Imprimer toutes les transactions','HIST'=>'Imprimer l’historique complet','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P A G E','BD'=>'Anniversaire','GN'=>'Genre','HT'=>'Taille','HR'=>'Cheveux','LE'=>'Œil gauche','RE'=>'Oeil droit','SF'=>'Signes particuliers','ML'=>'Masculin','FM'=>'Féminin','OT'=>'Autre','PA'=>'Paiement','NO'=>'Aucune transaction trouvée !',
    'HO'=>'Heures','RD'=>'Réduction','CB'=>'Solde actuel','1PHR'=>'1ᕫ/Heure','SO'=>'Solidarité','NB'=>'Nouveau solde','NM'=>'Nom','ACC'=>'Compte','ST'=>'Début',
    'M_1'=>'Jan','M_2'=>'Fév','M_3'=>'Mar','M_4'=>'Avr','M_5'=>'Mai','M_6'=>'Juin','M_7'=>'Juil','M_8'=>'Août','M_9'=>'Sep','M_10'=>'Oct','M_11'=>'Nov','M_12'=>'Déc',
    'HC_0'=>'Blanc','HC_1'=>'Roux','HC_2'=>'Auburn','HC_3'=>'Blond','HC_4'=>'Châtain clair','HC_5'=>'Cuivré','HC_6'=>'Blond clair','HC_7'=>'Châtain marron','HC_8'=>'Argent','HC_9'=>'Blond moyen','HC_10'=>'Brun clair','HC_11'=>'Titane','HC_12'=>'Blond foncé','HC_13'=>'Brun moyen','HC_14'=>'Gris','HC_15'=>'Blond doré','HC_16'=>'Brun foncé','HC_17'=>'Noir','HC_18'=>'Blond vénitien','HC_19'=>'Chauve',
    'EC_0'=>'Ambre','EC_1'=>'Bleu','EC_2'=>'Marron','EC_3'=>'Gris','EC_4'=>'Vert','EC_5'=>'Noisette','EC_6'=>'Rouge','EC_7'=>'Bleu-gris','EC_8'=>'Bleu-vert','EC_9'=>'Vert-gris','EC_10'=>'Vert-brun','EC_11'=>'Prothèse'
),

// ir - Irish
'ir' => array(
    'TITLE'=>'Flúirseagar Airgead','OVERVIEW'=>'Foramharc Idirbhirt','FROM'=>'Ó','TO'=>'Chuig','ALL'=>'Priontáil gach idirbheart','HIST'=>'Priontáil stair phearsanta iomlán','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;L E A T H A N A C H','BD'=>'Breithlá','GN'=>'Inscne','HT'=>'Airde','HR'=>'Gruaig','LE'=>'Súil Chlé','RE'=>'Súil Dheas','SF'=>'Gnéithe Speisialta','ML'=>'Fireann','FM'=>'Baineann','OT'=>'Eile','PA'=>'Íocaíocht','NO'=>'Ní bhfuarthas aon idirbhearta!',
    'HO'=>'Uaireanta','RD'=>'Laghdú','CB'=>'Iarmhéid reatha','1PHR'=>'1ᕫ/Uair','SO'=>'Dlúthpháirtíocht','NB'=>'Iarmhéid Nua','NM'=>'Ainm','ACC'=>'Cuntas','ST'=>'Tús',
    'M_1'=>'Ean','M_2'=>'Feabh','M_3'=>'Márta','M_4'=>'Aib','M_5'=>'Beal','M_6'=>'Meith','M_7'=>'Iúil','M_8'=>'Lún','M_9'=>'MFómh','M_10'=>'DFómh','M_11'=>'Samh','M_12'=>'Noll',
    'HC_0'=>'Bán','HC_1'=>'Rua','HC_2'=>'Donnrua','HC_3'=>'Fionn','HC_4'=>'Donn Éadrom','HC_5'=>'Copar','HC_6'=>'Fionn Éadrom','HC_7'=>'Donn an Chailis','HC_8'=>'Airgead','HC_9'=>'Fionn Meánach','HC_10'=>'Donn Geal','HC_11'=>'Tíotán','HC_12'=>'Fionn Dorcha','HC_13'=>'Donn Meánach','HC_14'=>'Liath','HC_15'=>'Fionn Órga','HC_16'=>'Donn Dorcha','HC_17'=>'Dubh','HC_18'=>'Fionn Sútha Talún','HC_19'=>'Gan Ghruaig',
    'EC_0'=>'Ambar','EC_1'=>'Gorm','EC_2'=>'Donn','EC_3'=>'Liath','EC_4'=>'Glas','EC_5'=>'Coll','EC_6'=>'Dearg','EC_7'=>'Gormliath','EC_8'=>'Gormghlas','EC_9'=>'Glasliath','EC_10'=>'Glasdhonn','EC_11'=>'Próistéis'
),

// gr - Greek
'gr' => array(
    'TITLE'=>'Αφθονομία Χρήματα','OVERVIEW'=>'Επισκόπηση Συναλλαγών','FROM'=>'Από','TO'=>'Προς','ALL'=>'Εκτύπωση όλων των συναλλαγών','HIST'=>'Εκτύπωση πλήρους ιστορικού','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;Σ Ε Λ Ι Δ Α','BD'=>'Γενέθλια','GN'=>'Φύλο','HT'=>'Ύψος','HR'=>'Μαλλιά','LE'=>'Αριστερό Μάτι','RE'=>'Δεξί Μάτι','SF'=>'Ιδιαίτερα Χαρακτηριστικά','ML'=>'Άνδρας','FM'=>'Γυναίκα','OT'=>'Άλλο','PA'=>'Πληρωμή','NO'=>'Δεν βρέθηκαν συναλλαγές!',
    'HO'=>'Ώρες','RD'=>'Μείωση','CB'=>'Τρέχον υπόλοιπο','1PHR'=>'1ᕫ/Ώρα','SO'=>'Αλληλεγγύη','NB'=>'Νέο Υπόλοιπο','NM'=>'Όνομα','ACC'=>'Λογαριασμός','ST'=>'Έναρξη',
    'M_1'=>'Ιαν','M_2'=>'Φεβ','M_3'=>'Μάρ','M_4'=>'Απρ','M_5'=>'Μάι','M_6'=>'Ιούν','M_7'=>'Ιούλ','M_8'=>'Αύγ','M_9'=>'Σεπ','M_10'=>'Οκτ','M_11'=>'Νοέ','M_12'=>'Δεκ',
    'HC_0'=>'Λευκό','HC_1'=>'Κόκκινο της Πιπερόριζας','HC_2'=>'Καστανόξανθο','HC_3'=>'Ξανθό','HC_4'=>'Ανοιχτό Καστανό','HC_5'=>'Χάλκινο','HC_6'=>'Ανοιχτό Ξανθό','HC_7'=>'Καστανό Σκούρο','HC_8'=>'Ασημί','HC_9'=>'Μεσαίο Ξανθό','HC_10'=>'Ανοιχτό Καφέ','HC_11'=>'Τιτάνιο','HC_12'=>'Σκούρο Ξανθό','HC_13'=>'Μεσαίο Καφέ','HC_14'=>'Γκρίζο','HC_15'=>'Χρυσό Ξανθό','HC_16'=>'Σκούρο Καφέ','HC_17'=>'Μαύρο','HC_18'=>'Ξανθό της Φράουλας','HC_19'=>'Χωρίς Μαλλιά',
    'EC_0'=>'Κεχριμπάρι','EC_1'=>'Μπλε','EC_2'=>'Καφέ','EC_3'=>'Γκρίζο','EC_4'=>'Πράσινο','EC_5'=>'Φουντουκί','EC_6'=>'Κόκκινο','EC_7'=>'Μπλε Γκρι','EC_8'=>'Μπλε Πράσινο','EC_9'=>'Πράσινο Γκρι','EC_10'=>'Πράσινο Καφέ','EC_11'=>'Πρόθεση'
),

// ha - Hausa
'ha' => array(
    'TITLE'=>'Yalwan-Arziki Kuɗi','OVERVIEW'=>'Bayanin Ma\'amala','FROM'=>'Daga','TO'=>'Zuwa','ALL'=>'Buga duk ma\'amaloli','HIST'=>'Buga cikakken tarihin mutum','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S H A F I','BD'=>'Ranar haihuwa','GN'=>'Jinsi','HT'=>'Tsayi','HR'=>'Gashi','LE'=>'Idon hagu','RE'=>'Idon dama','SF'=>'Abubuwa na musamman','ML'=>'Namiji','FM'=>'Mace','OT'=>'Wani','PA'=>'Biya','NO'=>'Ba a sami ma\'amala ba!',
    'HO'=>'Awanni','RD'=>'Ragi','CB'=>'Ma\'aunin yanzu','1PHR'=>'1ᕫ/Sa\'a','SO'=>'Haɗin kai','NB'=>'Sabuwar ma\'auni','NM'=>'Suna','ACC'=>'Asusu','ST'=>'Farawa',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Afi','M_5'=>'May','M_6'=>'Yun','M_7'=>'Yul','M_8'=>'Aga','M_9'=>'Sat','M_10'=>'Okt','M_11'=>'Nuw','M_12'=>'Dis',
    'HC_0'=>'Fari','HC_1'=>'Ja mai kalar citta','HC_2'=>'Kalar goro','HC_3'=>'Gashi mai kalar zinari','HC_4'=>'Kalar goro mai haske','HC_5'=>'Jan ƙarfe','HC_6'=>'Zinari mai haske','HC_7'=>'Kalar goro mai duhu','HC_8'=>'Azurfa','HC_9'=>'Zinari matsakaici','HC_10'=>'Kalar ƙasa mai haske','HC_11'=>'Titan','HC_12'=>'Zinari mai duhu','HC_13'=>'Kalar ƙasa matsakaici','HC_14'=>'Toka-toka','HC_15'=>'Zinari kalar zinariya','HC_16'=>'Kalar ƙasa mai duhu','HC_17'=>'Baƙi','HC_18'=>'Zinari kalar strawberry','HC_19'=>'Bashi da gashi',
    'EC_0'=>'Amba','EC_1'=>'Shuɗi','EC_2'=>'Kalar ƙasa','EC_3'=>'Toka-toka','EC_4'=>'Kore','EC_5'=>'Hasken ƙasa','EC_6'=>'Ja','EC_7'=>'Shuɗi mai toka','EC_8'=>'Shuɗi mai kore','EC_9'=>'Kore mai toka','EC_10'=>'Kore mai kalar ƙasa','EC_11'=>'Idon roba'
),

// he - Hebrew
'he' => array(
    'TITLE'=>'שפעלכלה כסף','OVERVIEW'=>'סקירת עסקאות','FROM'=>'מ-','TO'=>'עד','ALL'=>'הדפס את כל העסקאות','HIST'=>'הדפס היסטוריה אישית מלאה','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ע מ ו ד','BD'=>'תאריך לידה','GN'=>'מין','HT'=>'גובה','HR'=>'שיער','LE'=>'עין שמאל','RE'=>'עין ימין','SF'=>'מאפיינים מיוחדים','ML'=>'זכר','FM'=>'נקבה','OT'=>'אחר','PA'=>'תשלום','NO'=>'לא נמצאו עסקאות!',
    'HO'=>'שעות','RD'=>'הפחתה','CB'=>'יתרה נוכחית','1PHR'=>'1ᕫ/שעה','SO'=>'סולידריות','NB'=>'יתרה חדשה','NM'=>'שם','ACC'=>'חשבון','ST'=>'התחלה',
    'M_1'=>'ינואר','M_2'=>'פברואר','M_3'=>'מרץ','M_4'=>'אפריל','M_5'=>'מאי','M_6'=>'יוני','M_7'=>'יולי','M_8'=>'אוגוסט','M_9'=>'ספטמבר','M_10'=>'אוקטובר','M_11'=>'נובמבר','M_12'=>'דצמבר',
    'HC_0'=>'לבן','HC_1'=>'ג\'ינג\'י','HC_2'=>'ערמוני','HC_3'=>'בלונדיני','HC_4'=>'ערמוני בהיר','HC_5'=>'נחושת','HC_6'=>'בלונד בהיר','HC_7'=>'חום ערמוני','HC_8'=>'כסף','HC_9'=>'בלונד בינוני','HC_10'=>'חום בהיר','HC_11'=>'טיטאן','HC_12'=>'בלונד כהה','HC_13'=>'חום בינוני','HC_14'=>'אפור','HC_15'=>'בלונד זהוב','HC_16'=>'חום כהה','HC_17'=>'שחור','HC_18'=>'בלונד תות','HC_19'=>'ללא שיער',
    'EC_0'=>'ענבר','EC_1'=>'כחול','EC_2'=>'חום','EC_3'=>'אפור','EC_4'=>'ירוק','EC_5'=>'דבש','EC_6'=>'אדום','EC_7'=>'כחול-אפור','EC_8'=>'כחול-ירוק','EC_9'=>'ירוק-אפור','EC_10'=>'ירוק-חום','EC_11'=>'תותבת'
),

// hi - Hindi
'hi' => array(
    'TITLE'=>'प्रचुरवर्त धन','OVERVIEW'=>'लेनदेन का अवलोकन','FROM'=>'से','TO'=>'तक','ALL'=>'सभी लेनदेन प्रिंट करें','HIST'=>'पूर्ण व्यक्तिगत इतिहास प्रिंट करें','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;पृ ष्ठ','BD'=>'जन्मदिन','GN'=>'लिंग','HT'=>'लंबाई','HR'=>'बाल','LE'=>'बायीं आंख','RE'=>'दायीं आंख','SF'=>'विशेष विशेषताएं','ML'=>'पुरुष','FM'=>'महिला','OT'=>'अन्य','PA'=>'भुगतान','NO'=>'कोई लेनदेन नहीं मिला!',
    'HO'=>'घंटे','RD'=>'कटौती','CB'=>'वर्तमान शेष','1PHR'=>'1ᕫ/घंटा','SO'=>'एकजुटता','NB'=>'नया शेष','NM'=>'नाम','ACC'=>'खाता','ST'=>'प्रारंभ',
    'M_1'=>'जनवरी','M_2'=>'फरवरी','M_3'=>'मार्च','M_4'=>'अप्रैल','M_5'=>'मई','M_6'=>'जून','M_7'=>'जुलाई','M_8'=>'अगस्त','M_9'=>'सितंबर','M_10'=>'अक्टूबर','M_11'=>'नवंबर','M_12'=>'दिसंबर',
    'HC_0'=>'सफेद','HC_1'=>'लाल अदरक','HC_2'=>'ऑबर्न','HC_3'=>'सुनहरा','HC_4'=>'हल्का अखरोट','HC_5'=>'तांबा','HC_6'=>'हल्का सुनहरा','HC_7'=>'अखरोट भूरा','HC_8'=>'चांदी','HC_9'=>'मध्यम सुनहरा','HC_10'=>'हल्का भूरा','HC_11'=>'टाइटन','HC_12'=>'गहरा सुनहरा','HC_13'=>'मध्यम भूरा','HC_14'=>'धूसर','HC_15'=>'गोल्ड ब्लॉन्ड','HC_16'=>'गहरा भूरा','HC_17'=>'काला','HC_18'=>'स्ट्रॉबेरी ब्लॉन्ड','HC_19'=>'बाल नहीं',
    'EC_0'=>'एम्बर','EC_1'=>'नीला','EC_2'=>'भूरा','EC_3'=>'धूसर','EC_4'=>'हरा','EC_5'=>'हेज़ल','EC_6'=>'लाल','EC_7'=>'नीला धूसर','EC_8'=>'नीला हरा','EC_9'=>'हरा धूसर','EC_10'=>'हरा भूरा','EC_11'=>'कृत्रिम'
),

// cr - Croatian
'cr' => array(
    'TITLE'=>'Izonomija Novac','OVERVIEW'=>'Pregled transakcija','FROM'=>'Od','TO'=>'Do','ALL'=>'Ispiši sve transakcije','HIST'=>'Ispiši punu povijest','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S T R A N I C A','BD'=>'Rođendan','GN'=>'Spol','HT'=>'Visina','HR'=>'Kosa','LE'=>'Lijevo oko','RE'=>'Desno oko','SF'=>'Posebne značajke','ML'=>'Muško','FM'=>'Žensko','OT'=>'Ostalo','PA'=>'Plaćanje','NO'=>'Nema pronađenih transakcija!',
    'HO'=>'Sati','RD'=>'Smanjenje','CB'=>'Trenutni saldo','1PHR'=>'1ᕫ/Sat','SO'=>'Solidarnost','NB'=>'Novi saldo','NM'=>'Ime','ACC'=>'Račun','ST'=>'Početak',
    'M_1'=>'Sij','M_2'=>'Velj','M_3'=>'Ožu','M_4'=>'Tra','M_5'=>'Svi','M_6'=>'Lip','M_7'=>'Srp','M_8'=>'Kol','M_9'=>'Ruj','M_10'=>'Lis','M_11'=>'Stu','M_12'=>'Pro',
    'HC_0'=>'Bijela','HC_1'=>'Riđa','HC_2'=>'Kestenjasta','HC_3'=>'Plava','HC_4'=>'Svijetli kesten','HC_5'=>'Bakrena','HC_6'=>'Svijetlo plava','HC_7'=>'Smeđi kesten','HC_8'=>'Srebrna','HC_9'=>'Srednje plava','HC_10'=>'Svijetlo smeđa','HC_11'=>'Titan','HC_12'=>'Tamno plava','HC_13'=>'Srednje smeđa','HC_14'=>'Siva','HC_15'=>'Zlatno plava','HC_16'=>'Tamno smeđa','HC_17'=>'Crna','HC_18'=>'Jagodasto plava','HC_19'=>'Bez kose',
    'EC_0'=>'Jantarna','EC_1'=>'Plava','EC_2'=>'Smeđa','EC_3'=>'Siva','EC_4'=>'Zelena','EC_5'=>'Lješnjak','EC_6'=>'Crvena','EC_7'=>'Plavo-siva','EC_8'=>'Plavo-zelena','EC_9'=>'Zeleno-siva','EC_10'=>'Zeleno-smeđa','EC_11'=>'Proteza'
),

// ig - Igbo
'ig' => array(
    'TITLE'=>'Ụbanomiya Ego','OVERVIEW'=>'Nchịkọta Azụmahịa','FROM'=>'Site na','TO'=>'Ruo','ALL'=>'Bipụta azụmahịa niile','HIST'=>'Bipụta akụkọ onwe onye niile','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;I H E&nbsp;&nbsp;Ụ K W Ọ','BD'=>'Ụbọchị ọmụmụ','GN'=>'Nwaanyị ma ọ bụ nwoke','HT'=>'Ogologo','HR'=>'Ntutu isi','LE'=>'Anya ekpe','RE'=>'Anya nri','SF'=>'Ihe pụrụ iche','ML'=>'Nwoke','FM'=>'Nwaanyị','OT'=>'Ọzọ','PA'=>'Ịkwụ ụgwọ','NO'=>'Ahụghị azụmahịa ọ bụla!',
    'HO'=>'Aha awa','RD'=>'Mbelata','CB'=>'Ego fọdụrụ ugbu a','1PHR'=>'1ᕫ/Awa','SO'=>'Ịdị n\'otu','NB'=>'Ego ọhụrụ fọdụrụ','NM'=>'Aha','ACC'=>'Akaụntụ','ST'=>'Mmalite',
    'M_1'=>'Jen','M_2'=>'Feb','M_3'=>'Maachị','M_4'=>'Eprel','M_5'=>'Mee','M_6'=>'Juun','M_7'=>'Julaị','M_8'=>'Ọgọst','M_9'=>'Sept','M_10'=>'Ọkt','M_11'=>'Nov','M_12'=>'Dis',
    'HC_0'=>'Ọcha','HC_1'=>'Ntutu uhie ginger','HC_2'=>'Ntutu ruru nchara','HC_3'=>'Blond','HC_4'=>'Ntutu ruru nchara na-acha ọcha','HC_5'=>'Kọpa','HC_6'=>'Blond na-acha ọcha','HC_7'=>'Ntutu ruru nchara chestnut','HC_8'=>'Sịlịva','HC_9'=>'Blond dị n\'etiti','HC_10'=>'Nchara na-acha ọcha','HC_11'=>'Titanium','HC_12'=>'Blond gbara ọchịchịrị','HC_13'=>'Nchara dị n\'etiti','HC_14'=>'Ntụ ntụ','HC_15'=>'Blond ọlaedo','HC_16'=>'Nchara gbara ọchịchịrị','HC_17'=>'Oji','HC_18'=>'Blond strawberry','HC_19'=>'Isi mgbọrọgwụ',
    'EC_0'=>'Amber','EC_1'=>'Anụnụ anụnụ','EC_2'=>'Nchara','EC_3'=>'Ntụ ntụ','EC_4'=>'Ndụ ndụ','EC_5'=>'Hazel','EC_6'=>'Uhie','EC_7'=>'Anụnụ anụnụ-ntụ ntụ','EC_8'=>'Anụnụ anụnụ-ndụ ndụ','EC_9'=>'Ndụ ndụ-ntụ ntụ','EC_10'=>'Ndụ ndụ-nchara','EC_11'=>'Anya adịgboroja'
),

// in - Indonesian
'in' => array(
    'TITLE'=>'Limpahnomi Uang','OVERVIEW'=>'Ringkasan Transaksi','FROM'=>'Dari','TO'=>'Ke','ALL'=>'Cetak semua transaksi','HIST'=>'Cetak riwayat pribadi lengkap','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;H A L A M A N','BD'=>'Hari Ulang Tahun','GN'=>'Jenis Kelamin','HT'=>'Tinggi','HR'=>'Rambut','LE'=>'Mata Kiri','RE'=>'Mata Kanan','SF'=>'Fitur Khusus','ML'=>'Laki-laki','FM'=>'Perempuan','OT'=>'Lainnya','PA'=>'Pembayaran','NO'=>'Transaksi Tidak Ditemukan!',
    'HO'=>'Jam','RD'=>'Pengurangan','CB'=>'Saldo saat ini','1PHR'=>'1ᕫ/Jam','SO'=>'Solidaritas','NB'=>'Saldo Baru','NM'=>'Nama','ACC'=>'Akun','ST'=>'Mulai',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Mei','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Agu','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Des',
    'HC_0'=>'Putih','HC_1'=>'Merah Jahe','HC_2'=>'Auburn','HC_3'=>'Pirang','HC_4'=>'Chestnut Terang','HC_5'=>'Tembaga','HC_6'=>'Pirang Terang','HC_7'=>'Cokelat Chestnut','HC_8'=>'Perak','HC_9'=>'Pirang Sedang','HC_10'=>'Cokelat Terang','HC_11'=>'Titan','HC_12'=>'Pirang Gelap','HC_13'=>'Cokelat Sedang','HC_14'=>'Abu-abu','HC_15'=>'Pirang Emas','HC_16'=>'Cokelat Gelap','HC_17'=>'Hitam','HC_18'=>'Pirang Stroberi','HC_19'=>'Tanpa Rambut',
    'EC_0'=>'Amber','EC_1'=>'Biru','EC_2'=>'Cokelat','EC_3'=>'Abu-abu','EC_4'=>'Hijau','EC_5'=>'Hazel','EC_6'=>'Merah','EC_7'=>'Biru Abu-abu','EC_8'=>'Biru Hijau','EC_9'=>'Hijau Abu-abu','EC_10'=>'Hijau Cokelat','EC_11'=>'Prostesis'
),

// ic - Icelandic
'ic' => array(
    'TITLE'=>'Snægtarhag Peningar','OVERVIEW'=>'Yfirlit yfir færslur','FROM'=>'Frá','TO'=>'Til','ALL'=>'Prenta allar færslur','HIST'=>'Prenta alla persónusögu','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S Í Ð A','BD'=>'Afmælisdagur','GN'=>'Kyn','HT'=>'Hæð','HR'=>'Hár','LE'=>'Vinstra auga','RE'=>'Hægra auga','SF'=>'Sérstakir eiginleikar','ML'=>'Karlmaður','FM'=>'Kona','OT'=>'Annað','PA'=>'Greiðsla','NO'=>'Engar færslur fundust!',
    'HO'=>'Klukkustundir','RD'=>'Lækkun','CB'=>'Núverandi staða','1PHR'=>'1ᕫ/Klukkustund','SO'=>'Samstaða','NB'=>'Ný staða','NM'=>'Nafn','ACC'=>'Reikningur','ST'=>'Byrjun',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Maí','M_6'=>'Jún','M_7'=>'Júl','M_8'=>'Ágú','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nóv','M_12'=>'Des',
    'HC_0'=>'Hvítur','HC_1'=>'Engiferrauður','HC_2'=>'Rauðbrúnn','HC_3'=>'Ljóshærður','HC_4'=>'Ljóskastaníubrúnn','HC_5'=>'Kopar','HC_6'=>'Ljósljós','HC_7'=>'Kastaníubrúnn','HC_8'=>'Silfur','HC_9'=>'Milliljós','HC_10'=>'Ljósbrúnn','HC_11'=>'Títan','HC_12'=>'Dökkljós','HC_13'=>'Millibrúnn','HC_14'=>'Grár','HC_15'=>'Gullinn ljós','HC_16'=>'Dökkbrúnn','HC_17'=>'Svartur','HC_18'=>'Jarðarberjaljós','HC_19'=>'Hárlaus',
    'EC_0'=>'Rafgulur','EC_1'=>'Blár','EC_2'=>'Brúnn','EC_3'=>'Grár','EC_4'=>'Grænn','EC_5'=>'Hnotubrúnn','EC_6'=>'Rauður','EC_7'=>'Blágrár','EC_8'=>'Blágrænn','EC_9'=>'Grængrár','EC_10'=>'Grænbrúnn','EC_11'=>'Gerviauga'
),

// it - Italian
'it' => array(
    'TITLE'=>'Abbondomia Denaro','OVERVIEW'=>'Panoramica delle transazioni','FROM'=>'Da','TO'=>'A','ALL'=>'Stampa tutte le transazioni','HIST'=>'Stampa cronologia personale completa','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P A G I N A','BD'=>'Compleanno','GN'=>'Genere','HT'=>'Altezza','HR'=>'Capelli','LE'=>'Occhio sinistro','RE'=>'Occhio destro','SF'=>'Caratteristiche speciali','ML'=>'Maschio','FM'=>'Femmina','OT'=>'Altro','PA'=>'Pagamento','NO'=>'Nessuna transazione trovata!',
    'HO'=>'Ore','RD'=>'Riduzione','CB'=>'Saldo attuale','1PHR'=>'1ᕫ/Ora','SO'=>'Solidarietà','NB'=>'Nuovo Saldo','NM'=>'Nome','ACC'=>'Conto','ST'=>'Inizio',
    'M_1'=>'Gen','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Mag','M_6'=>'Giu','M_7'=>'Lug','M_8'=>'Ago','M_9'=>'Set','M_10'=>'Ott','M_11'=>'Nov','M_12'=>'Dic',
    'HC_0'=>'Bianco','HC_1'=>'Rosso Zenzero','HC_2'=>'Auburn','HC_3'=>'Biondo','HC_4'=>'Castano Chiaro','HC_5'=>'Rame','HC_6'=>'Biondo Chiaro','HC_7'=>'Castano Marrone','HC_8'=>'Argento','HC_9'=>'Biondo Medio','HC_10'=>'Marrone Chiaro','HC_11'=>'Titanio','HC_12'=>'Biondo Scuro','HC_13'=>'Marrone Medio','HC_14'=>'Grigio','HC_15'=>'Biondo Oro','HC_16'=>'Marrone Scuro','HC_17'=>'Nero','HC_18'=>'Biondo Fragola','HC_19'=>'Senza Capelli',
    'EC_0'=>'Ambra','EC_1'=>'Blu','EC_2'=>'Marrone','EC_3'=>'Grigio','EC_4'=>'Verde','EC_5'=>'Nocciola','EC_6'=>'Rosso','EC_7'=>'Blu Grigio','EC_8'=>'Blu Verde','EC_9'=>'Verde Grigio','EC_10'=>'Verde Marrone','EC_11'=>'Protesi'
),

// ja - Japanese
'ja' => array(
    'TITLE'=>'豊景経済 お金','OVERVIEW'=>'取引概要','FROM'=>'開始','TO'=>'終了','ALL'=>'すべての取引を印刷','HIST'=>'全個人履歴を印刷','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ペ ー ジ','BD'=>'誕生日','GN'=>'性別','HT'=>'身長','HR'=>'髪の色','LE'=>'左目','RE'=>'右目','SF'=>'特別な特徴','ML'=>'男性','FM'=>'女性','OT'=>'その他','PA'=>'支払い','NO'=>'取引は見つかりませんでした！',
    'HO'=>'時間','RD'=>'削減','CB'=>'現在の残高','1PHR'=>'1ᕫ/時間','SO'=>'連帯','NB'=>'新しい残高','NM'=>'名前','ACC'=>'口座','ST'=>'開始',
    'M_1'=>'1月','M_2'=>'2月','M_3'=>'3月','M_4'=>'4月','M_5'=>'5月','M_6'=>'6月','M_7'=>'7月','M_8'=>'8月','M_9'=>'9月','M_10'=>'10月','M_11'=>'11月','M_12'=>'12月',
    'HC_0'=>'白','HC_1'=>'ジンジャーレッド','HC_2'=>'赤褐色','HC_3'=>'ブロンド','HC_4'=>'ライトチェスナット','HC_5'=>'銅色','HC_6'=>'ライトブロンド','HC_7'=>'チェスナットブラウン','HC_8'=>'シルバー','HC_9'=>'ミディアムブロンド','HC_10'=>'ライトブラウン','HC_11'=>'チタン','HC_12'=>'ダークブロンド','HC_13'=>'ミディアムブラウン','HC_14'=>'グレー','HC_15'=>'ゴールドブロンド','HC_16'=>'ダークブラウン','HC_17'=>'黒','HC_18'=>'ストロベリーブロンド','HC_19'=>'髪なし',
    'EC_0'=>'琥珀色','EC_1'=>'青','EC_2'=>'茶色','EC_3'=>'グレー','EC_4'=>'緑','EC_5'=>'ヘーゼル','EC_6'=>'赤','EC_7'=>'ブルーグレー','EC_8'=>'ブルーグリーン','EC_9'=>'グリーングレー','EC_10'=>'グリーンブラウン','EC_11'=>'義眼'
),

// ka - Kazakh
'ka' => array(
    'TITLE'=>'Молномика Ақша','OVERVIEW'=>'Транзакцияларға шолу','FROM'=>'Бастап','TO'=>'Дейін','ALL'=>'Барлық транзакцияларды басып шығару','HIST'=>'Толық жеке тарихты басып шығару','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;Б Е Т','BD'=>'Туған күні','GN'=>'Жынысы','HT'=>'Бойы','HR'=>'Шашы','LE'=>'Сол көзі','RE'=>'Оң көзі','SF'=>'Ерекшеліктері','ML'=>'Ер','FM'=>'Әйел','OT'=>'Басқа','PA'=>'Төлем','NO'=>'Транзакциялар табылмады!',
    'HO'=>'Сағаттар','RD'=>'Азайту','CB'=>'Ағымдағы баланс','1PHR'=>'1ᕫ/Сағат','SO'=>'Ынтымақтастық','NB'=>'Жаңа баланс','NM'=>'Аты','ACC'=>'Шот','ST'=>'Бастау',
    'M_1'=>'Қаң','M_2'=>'Ақп','M_3'=>'Нау','M_4'=>'Сәу','M_5'=>'Мам','M_6'=>'Мау','M_7'=>'Шіл','M_8'=>'Там','M_9'=>'Қыр','M_10'=>'Қаз','M_11'=>'Қар','M_12'=>'Жел',
    'HC_0'=>'Ақ','HC_1'=>'Жирен','HC_2'=>'Қоңыр-жирен','HC_3'=>'Аққұба','HC_4'=>'Ашық каштан','HC_5'=>'Мыс','HC_6'=>'Ашық аққұба','HC_7'=>'Каштан қоңыр','HC_8'=>'Күміс','HC_9'=>'Орташа аққұба','HC_10'=>'Ашық қоңыр','HC_11'=>'Титан','HC_12'=>'Түнді аққұба','HC_13'=>'Орташа қоңыр','HC_14'=>'Сұр','HC_15'=>'Алтын аққұба','HC_16'=>'Түнді қоңыр','HC_17'=>'Қара','HC_18'=>'Құлпынай аққұба','HC_19'=>'Шашсыз',
    'EC_0'=>'Янтарь','EC_1'=>'Көк','EC_2'=>'Қоңыр','EC_3'=>'Сұр','EC_4'=>'Жасыл','EC_5'=>'Орман жаңғағы','EC_6'=>'Қызыл','EC_7'=>'Көк-сұр','EC_8'=>'Көк-жасыл','EC_9'=>'Жасыл-сұр','EC_10'=>'Жасыл-қоңыр','EC_11'=>'Протез'
),

// kh - Khmer
'kh' => array(
    'TITLE'=>'ផលសេដ្ឋី ប្រាក់','OVERVIEW'=>'ទិដ្ឋភាពទូទៅនៃប្រតិបត្តិការ','FROM'=>'ពី','TO'=>'ដល់','ALL'=>'បោះពុម្ពប្រតិបត្តិការទាំងអស់','HIST'=>'បោះពុម្ពប្រវត្តិរូបសង្ខេបទាំងស្រុង','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ទំ ព័ រ','BD'=>'ថ្ងៃខែឆ្នាំកំណើត','GN'=>'ភេទ','HT'=>'កម្ពស់','HR'=>'សក់','LE'=>'ភ្នែកឆ្វេង','RE'=>'ភ្នែកស្តាំ','SF'=>'លក្ខណៈពិសេស','ML'=>'ប្រុស','FM'=>'ស្រី','OT'=>'ផ្សេងៗ','PA'=>'ការទូទាត់','NO'=>'រកមិនឃើញប្រតិបត្តិការទេ!',
    'HO'=>'ម៉ោង','RD'=>'ការកាត់បន្ថយ','CB'=>'សមតុល្យបច្ចុប្បន្ន','1PHR'=>'១ᕫ/ម៉ោង','SO'=>'សាមគ្គីភាព','NB'=>'សមតុល្យថ្មី','NM'=>'ឈ្មោះ','ACC'=>'គណនី','ST'=>'ចាប់ផ្តើម',
    'M_1'=>'មករា','M_2'=>'កុម្ភៈ','M_3'=>'មីនា','M_4'=>'មេសា','M_5'=>'ឧសភា','M_6'=>'មិថុនា','M_7'=>'កក្កដា','M_8'=>'សីហា','M_9'=>'កញ្ញា','M_10'=>'តុលា','M_11'=>'វិច្ឆិកា','M_12'=>'ធ្នូ',
    'HC_0'=>'ស','HC_1'=>'ក្រហមខ្ញី','HC_2'=>'ត្នោតក្រហម','HC_3'=>'ទង់ដែង','HC_4'=>'ត្នោតខ្ចី','HC_5'=>'ស្ពាន់','HC_6'=>'ទង់ដែងខ្ចី','HC_7'=>'ត្នោតក្រម៉ៅ','HC_8'=>'ប្រាក់','HC_9'=>'ទង់ដែងមធ្យម','HC_10'=>'ត្នោតស្រាល','HC_11'=>'ទីតាន','HC_12'=>'ទង់ដែងចាស់','HC_13'=>'ត្នោតមធ្យម','HC_14'=>'ប្រផេះ','HC_15'=>'ទង់ដែងមាស','HC_16'=>'ត្នោតចាស់','HC_17'=>'ខ្មៅ','HC_18'=>'ទង់ដែងស្រ្តបឺរី','HC_19'=>'គ្មានសក់',
    'EC_0'=>'លឿងទុំ','EC_1'=>'ខៀវ','EC_2'=>'ត្នោត','EC_3'=>'ប្រផេះ','EC_4'=>'បៃតង','EC_5'=>'ហាសែល','EC_6'=>'ក្រហម','EC_7'=>'ខៀវប្រផេះ','EC_8'=>'ខៀវបៃតង','EC_9'=>'បៃតងប្រផេះ','EC_10'=>'បៃតងត្នោត','EC_11'=>'ភ្នែកសិប្បនិម្មិត'
),

// ki - Kinyarwanda
'ki' => array(
    'TITLE'=>'Abundomy Amafaranga','OVERVIEW'=>'Incamake y’ibyakozwe','FROM'=>'Kuva','TO'=>'Kugeza','ALL'=>'Subiramo ibyakozwe byose','HIST'=>'Andika amateka yose','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;U P A J I','BD'=>'Itariki y’amavuko','GN'=>'Igitsina','HT'=>'Uburebure','HR'=>'Umusatsi','LE'=>'Iryo bumoso','RE'=>'Iryo buryo','SF'=>'Ibiranga umuntu','ML'=>'Gabo','FM'=>'Gore','OT'=>'Ikindi','PA'=>'Ikwirakwiza','NO'=>'Nta bikorwa byabonetse!',
    'HO'=>'Amasaha','RD'=>'Ikinyuranyo','CB'=>'Asigaye ubu','1PHR'=>'1ᕫ/Isaha','SO'=>'Ubwuzuzanye','NB'=>'Ayasigaye mashya','NM'=>'Izina','ACC'=>'Kontu','ST'=>'Itangira',
    'M_1'=>'Mut','M_2'=>'Ghu','M_3'=>'Wer','M_4'=>'Mata','M_5'=>'Gic','M_6'=>'Kam','M_7'=>'Nyak','M_8'=>'Kan','M_9'=>'Nzer','M_10'=>'Ukwak','M_11'=>'Ugw','M_12'=>'Uzh',
    'HC_0'=>'Umweru','HC_1'=>'Igitaka kijya gutukura','HC_2'=>'Igitaka gijimye','HC_3'=>'Ikinyange','HC_4'=>'Igitaka cyerurutse','HC_5'=>'Umuringa','HC_6'=>'Ikinyange cyerurutse','HC_7'=>'Igitaka kijya k’umukara','HC_8'=>'Ifeza','HC_9'=>'Ikinyange giciriritse','HC_10'=>'Igitaka','HC_11'=>'Tiyitanyu','HC_12'=>'Ikinyange kijimye','HC_13'=>'Igitaka giciriritse','HC_14'=>'Ikirayi','HC_15'=>'Ikinyange cy’izahabu','HC_16'=>'Igitaka cy’umukara','HC_17'=>'Umukara','HC_18'=>'Ikinyange k’iroza','HC_19'=>'Nta musatsi',
    'EC_0'=>'Iri n’icunga','EC_1'=>'Ubururu','EC_2'=>'Igitaka','EC_3'=>'Ikirayi','EC_4'=>'Icyatsi','EC_5'=>'Ikinyugunyugu','EC_6'=>'Gutukura','EC_7'=>'Ubururu-kirayi','EC_8'=>'Ubururu-cyatsi','EC_9'=>'Icyatsi-kirayi','EC_10'=>'Icyatsi-gitaka','EC_11'=>'Iryo kwibandaho'
),

// sh - Swahili
'sh' => array(
    'TITLE'=>'Ukwachumi Pesa','OVERVIEW'=>'Maelezo ya Muamala','FROM'=>'Kutoka','TO'=>'Hadi','ALL'=>'Chapisha miamala yote','HIST'=>'Chapisha historia kamili','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;U K U R A S A','BD'=>'Siku ya kuzaliwa','GN'=>'Jinsia','HT'=>'Urefu','HR'=>'Nywele','LE'=>'Jicho la kushoto','RE'=>'Jicho la kulia','SF'=>'Sifa maalum','ML'=>'Mume','FM'=>'Mke','OT'=>'Nyingine','PA'=>'Malipo','NO'=>'Hakuna miamala iliyopatikana!',
    'HO'=>'Masaa','RD'=>'Punguzo','CB'=>'Salio la sasa','1PHR'=>'1ᕫ/Saa','SO'=>'Ushirikiano','NB'=>'Salio jipya','NM'=>'Jina','ACC'=>'Akaunti','ST'=>'Mwanzo',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Mei','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Ago','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Des',
    'HC_0'=>'Nyeupe','HC_1'=>'Nyekundu ya Tangawizi','HC_2'=>'Kahawia Nyekundu','HC_3'=>'Blondi','HC_4'=>'Chestnut Nyepesi','HC_5'=>'Shaba','HC_6'=>'Blondi Nyepesi','HC_7'=>'Kahawia ya Chestnut','HC_8'=>'Fedha','HC_9'=>'Blondi ya Kati','HC_10'=>'Kahawia Nyepesi','HC_11'=>'Titani','HC_12'=>'Blondi Iliyoiva','HC_13'=>'Kahawia ya Kati','HC_14'=>'Kijivu','HC_15'=>'Blondi ya Dhahabu','HC_16'=>'Kahawia Iliyoiva','HC_17'=>'Nyeusi','HC_18'=>'Blondi ya Strawberry','HC_19'=>'Bila Nywele',
    'EC_0'=>'Kahunzi','EC_1'=>'Buluu','EC_2'=>'Kahawia','EC_3'=>'Kijivu','EC_4'=>'Kijani','EC_5'=>'Hazel','EC_6'=>'Nyekundu','EC_7'=>'Buluu ya Kijivu','EC_8'=>'Buluu ya Kijani','EC_9'=>'Kijani ya Kijivu','EC_10'=>'Kijani ya Kahawia','EC_11'=>'Macho ya Bandia'
),

// co - Kituba
'co' => array(
    'TITLE'=>'Bimvwanomi Mbongo','OVERVIEW'=>'Mambu ya mbongo','FROM'=>'Tuka','TO'=>'Tii','ALL'=>'Nima mambu yonso','HIST'=>'Nima luzingu yonso','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;L U T I T I','BD'=>'Kilumbu ya mbutukulu','GN'=>'Bakala to nkento','HT'=>'Yinda','HR'=>'Nsuki','LE'=>'Disu ya kinkento','RE'=>'Disu ya kibakala','SF'=>'Bidimbu ya sipesiale','ML'=>'Bakala','FM'=>'Nkento','OT'=>'Ya nkaka','PA'=>'Futa','NO'=>'Me mona mambu mosi ve!',
    'HO'=>'Bangunga','RD'=>'Kukulula','CB'=>'Mbongo ya sasa','1PHR'=>'1ᕫ/Ngunga','SO'=>'Kuvukana','NB'=>'Mbongo ya mpa','NM'=>'Nkumbu','ACC'=>'Konto','ST'=>'Luyantiku',
    'M_1'=>'Ynz','M_2'=>'Fv','M_3'=>'Msu','M_4'=>'Avr','M_5'=>'My','M_6'=>'Yun','M_7'=>'Yul','M_8'=>'Agst','M_9'=>'Stb','M_10'=>'Okt','M_11'=>'Nvb','M_12'=>'Dsb',
    'HC_0'=>'Mpembé','HC_1'=>'Mbaki ya djinjer','HC_2'=>'Mbaki ya dusu','HC_3'=>'Nsuki ya mpembé-ya-tiya','HC_4'=>'Mbaki ya mpembé','HC_5'=>'Kwivre','HC_6'=>'Nsuki ya mpembé ya ngolo','HC_7'=>'Mbaki ya ndombe','HC_8'=>'Palata','HC_9'=>'Nsuki ya mpembé ya katikati','HC_10'=>'Mbaki ya fioti','HC_11'=>'Titan','HC_12'=>'Nsuki ya mpembé ya ndombe','HC_13'=>'Mbaki ya katikati','HC_14'=>'Mputulu','HC_15'=>'Nsuki ya wolo','HC_16'=>'Mbaki ya ndombe ya ngolo','HC_17'=>'Ndombe','HC_18'=>'Nsuki ya rose','HC_19'=>'Nsuki ve',
    'EC_0'=>'Anbre','EC_1'=>'Bulu','EC_2'=>'Mbaki','EC_3'=>'Mputulu','EC_4'=>'Kinkosi','EC_5'=>'Noizeti','EC_6'=>'Mbaki ya tiya','EC_7'=>'Bulu-mputulu','EC_8'=>'Bulu-kinkosi','EC_9'=>'Kinkosi-mputulu','EC_10'=>'Kinkosi-mbaki','EC_11'=>'Disu ya mpamba'
),

// ko - Korean
'ko' => array(
    'TITLE'=>'풍경제 돈','OVERVIEW'=>'거래 내역','FROM'=>'시작','TO'=>'종료','ALL'=>'모든 거래 인쇄','HIST'=>'전체 개인 기록 인쇄','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;페 이 ジ','BD'=>'생일','GN'=>'성별','HT'=>'키','HR'=>'머리카락 색','LE'=>'왼쪽 눈','RE'=>'오른쪽 눈','SF'=>'특이 사항','ML'=>'남성','FM'=>'여성','OT'=>'기타','PA'=>'결제','NO'=>'거래 내역이 없습니다!',
    'HO'=>'시간','RD'=>'차감','CB'=>'현재 잔액','1PHR'=>'1ᕫ/시간','SO'=>'연대','NB'=>'최종 잔액','NM'=>'이름','ACC'=>'계좌','ST'=>'시작',
    'M_1'=>'1월','M_2'=>'2월','M_3'=>'3月','M_4'=>'4月','M_5'=>'5月','M_6'=>'6月','M_7'=>'7月','M_8'=>'8月','M_9'=>'9月','M_10'=>'10月','M_11'=>'11月','M_12'=>'12月',
    'HC_0'=>'흰색','HC_1'=>'진저 레드','HC_2'=>'밤색','HC_3'=>'금발','HC_4'=>'밝은 밤색','HC_5'=>'구리색','HC_6'=>'연금발','HC_7'=>'체스트넛 브라운','HC_8'=>'은색','HC_9'=>'중간 금발','HC_10'=>'연갈색','HC_11'=>'티타늄','HC_12'=>'어두운 금발','HC_13'=>'중간 갈색','HC_14'=>'회색','HC_15'=>'황금빛 금발','HC_16'=>'진갈색','HC_17'=>'검정색','HC_18'=>'스트로베리 금발','HC_19'=>'머리카락 없음',
    'EC_0'=>'호박색','EC_1'=>'파란색','EC_2'=>'갈색','EC_3'=>'회색','EC_4'=>'초록색','EC_5'=>'헤이즐','EC_6'=>'빨간색','EC_7'=>'청회색','EC_8'=>'청록색','EC_9'=>'녹회색','EC_10'=>'녹갈색','EC_11'=>'의안'
),

// kg - Kyrgyz
'kg' => array(
    'TITLE'=>'Кенномика Акша','OVERVIEW'=>'Транзакцияларга сереп','FROM'=>'Баштап','TO'=>'Дейин','ALL'=>'Бардык транзакцияларды басып чыгаруу','HIST'=>'Толук жеке тарыхты басып чыгаруу','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;Б Е Т','BD'=>'Туулган күнү','GN'=>'Жынысы','HT'=>'Бою','HR'=>'Чачы','LE'=>'Сол көзү','RE'=>'Оң көзү','SF'=>'Өзгөчөлүктөрү','ML'=>'Эркек','FM'=>'Аял','OT'=>'Башка','PA'=>'Төлөм','NO'=>'Транзакциялар табылган жок!',
    'HO'=>'Сааттар','RD'=>'Азайтуу','CB'=>'Учурдагы баланс','1PHR'=>'1ᕫ/Саат','SO'=>'Тилектештик','NB'=>'Жаңы баланс','NM'=>'Аты','ACC'=>'Эсеп','ST'=>'Башталышы',
    'M_1'=>'Янв','M_2'=>'Фев','M_3'=>'Мар','M_4'=>'Апр','M_5'=>'Май','M_6'=>'Июн','M_7'=>'Июл','M_8'=>'Авг','M_9'=>'Сен','M_10'=>'Окт','M_11'=>'Ноя','M_12'=>'Дек',
    'HC_0'=>'Ак','HC_1'=>'Саргыч кызыл','HC_2'=>'Каштан','HC_3'=>'Ак сары','HC_4'=>'Ачык каштан','HC_5'=>'Жез','HC_6'=>'Ачык ак сары','HC_7'=>'Каштан күрөң','HC_8'=>'Күмүш','HC_9'=>'Орто ак сары','HC_10'=>'Ачык күрөң','HC_11'=>'Титан','HC_12'=>'Коюу ак сары','HC_13'=>'Орто күрөң','HC_14'=>'Боз','HC_15'=>'Алтын ак сары','HC_16'=>'Коюу күрөң','HC_17'=>'Кара','HC_18'=>'Кулпунай ак сары','HC_19'=>'Чачы жок',
    'EC_0'=>'Янтарь','EC_1'=>'Көк','EC_2'=>'Күрөң','EC_3'=>'Боз','EC_4'=>'Жашыл','EC_5'=>'Орман жаңгагы','EC_6'=>'Кызыл','EC_7'=>'Көк-боз','EC_8'=>'Көк-жашыл','EC_9'=>'Жашыл-боз','EC_10'=>'Жашыл-күрөң','EC_11'=>'Протез'
),

// la - Lao
'la' => array(
    'TITLE'=>'ຮ່ວມເສດຖາ ເງິນ','OVERVIEW'=>'ພາບລວມການເຮັດທຸລະກຳ','FROM'=>'ຈາກ','TO'=>'ເຖິງ','ALL'=>'ພິມທຸລະກຳທັງໝົດ','HIST'=>'ພິມປະຫວັດສ່ວນຕົວທັງໝົດ','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ໜ້ າ','BD'=>'ວັນເກີດ','GN'=>'ເພດ','HT'=>'ຄວາມສູງ','HR'=>'ສີຜົມ','LE'=>'ຕາຊ້າຍ','RE'=>'ຕາຂວາ','SF'=>'ຄຸນລັກສະນະພິເສດ','ML'=>'ຊາຍ','FM'=>'ຍິງ','OT'=>'ອື່ນໆ','PA'=>'ການຊຳລະ','NO'=>'ບໍ່ພົບທຸລະກຳ!',
    'HO'=>'ຊົ່ວໂມງ','RD'=>'ການຫຼຸດຜ່ອນ','CB'=>'ຍອດເງິນຄົງເຫຼືອ','1PHR'=>'1ᕫ/ຊົ່ວໂມງ','SO'=>'ຄວາມສາມັກຄີ','NB'=>'ຍອດເງິນໃໝ່','NM'=>'ຊື່','ACC'=>'ບັນຊີ','ST'=>'ເລີ່ມຕົ້ນ',
    'M_1'=>'ມັງກອນ','M_2'=>'ກຸມພາ','M_3'=>'ມີນາ','M_4'=>'ເມສາ','M_5'=>'ພຶດສະພາ','M_6'=>'ມິຖຸນາ','M_7'=>'ກໍລະກົດ','M_8'=>'ສິງຫາ','M_9'=>'ກັນຍາ','M_10'=>'ຕຸລາ','M_11'=>'ພະຈິກ','M_12'=>'ທັນວາ',
    'HC_0'=>'ຂາວ','HC_1'=>'ແດງຂີງ','HC_2'=>'ນ້ຳຕານແດງ','HC_3'=>'ບລອນ','HC_4'=>'ນ້ຳຕານອ່ອນ','HC_5'=>'ທອງແດງ','HC_6'=>'ບລອນອ່ອນ','HC_7'=>'ນ້ຳຕານເຂັ້ມ','HC_8'=>'ເງິນ','HC_9'=>'ບລອນກາງ','HC_10'=>'ນ້ຳຕານ','HC_11'=>'ຕີຕານ','HC_12'=>'ບລອນເຂັ້ມ','HC_13'=>'ນ້ຳຕານກາງ','HC_14'=>'ສີເທົາ','HC_15'=>'ບລອນທອງ','HC_16'=>'ນ້ຳຕານດຳ','HC_17'=>'ດຳ','HC_18'=>'ບລອນສະຕໍເບີຣີ','HC_19'=>'ບໍ່ມີຜົມ',
    'EC_0'=>'ອຳພັນ','EC_1'=>'ຟ້າ','EC_2'=>'ນ້ຳຕານ','EC_3'=>'ເທົາ','EC_4'=>'ຂຽວ','EC_5'=>'ສີນ້ຳຕານອ່ອນ','EC_6'=>'ແດງ','EC_7'=>'ຟ້າເທົາ','EC_8'=>'ຟ້າຂຽວ','EC_9'=>'ຂຽວເທົາ','EC_10'=>'ຂຽວນ້ຳຕານ','EC_11'=>'ຕາທຽມ'
),

// lv - Latvian
'lv' => array(
    'TITLE'=>'Bagātnomika Nauda','OVERVIEW'=>'Darījumu pārskats','FROM'=>'No','TO'=>'Līdz','ALL'=>'Drukāt visus darījumus','HIST'=>'Drukāt pilnu vēsturi','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;L A P P U S E','BD'=>'Dzimšanas diena','GN'=>'Dzimums','HT'=>'Augums','HR'=>'Mati','LE'=>'Kreisā acs','RE'=>'Labā acs','SF'=>'Īpašas pazīmes','ML'=>'Vīrietis','FM'=>'Sieviete','OT'=>'Cits','PA'=>'Maksājums','NO'=>'Darījumi nav atrasti!',
    'HO'=>'Stundas','RD'=>'Samazinājums','CB'=>'Pašreizējā bilance','1PHR'=>'1ᕫ/Stunda','SO'=>'Solidaritāte','NB'=>'Jaunā bilance','NM'=>'Vārds','ACC'=>'Konts','ST'=>'Sākums',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Mai','M_6'=>'Jūn','M_7'=>'Jūl','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Balta','HC_1'=>'Ruda','HC_2'=>'Sarkanbrūna','HC_3'=>'Blonda','HC_4'=>'Gaiši kastaņbrūna','HC_5'=>'Vara','HC_6'=>'Gaiši blonda','HC_7'=>'Kastaņbrūna','HC_8'=>'Sudraba','HC_9'=>'Vidēji blonda','HC_10'=>'Gaiši brūna','HC_11'=>'Titāna','HC_12'=>'Tumši blonda','HC_13'=>'Vidēji brūna','HC_14'=>'Pelēka','HC_15'=>'Zeltblonda','HC_16'=>'Tumši brūna','HC_17'=>'Melna','HC_18'=>'Zemeņu blonda','HC_19'=>'Bez matiem',
    'EC_0'=>'Dzintara','EC_1'=>'Zila','EC_2'=>'Brūna','EC_3'=>'Pelēka','EC_4'=>'Zaļa','EC_5'=>'Lazdu','EC_6'=>'Sarkana','EC_7'=>'Zilganpelēka','EC_8'=>'Zilganzaļa','EC_9'=>'Zaļganpelēka','EC_10'=>'Zaļganbrūna','EC_11'=>'Protēze'
),

// lt - Lithuanian
'lt' => array(
    'TITLE'=>'Gausonomika Pinigai','OVERVIEW'=>'Sandorių apžvalga','FROM'=>'Nuo','TO'=>'Iki','ALL'=>'Spausdinti visus sandorius','HIST'=>'Spausdinti visą istoriją','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P U S L A P I S','BD'=>'Gimtadienis','GN'=>'Lytis','HT'=>'Ūgis','HR'=>'Plaukai','LE'=>'Kairė akis','RE'=>'Dešinė akis','SF'=>'Ypatingos savybės','ML'=>'Vyras','FM'=>'Moteris','OT'=>'Kita','PA'=>'Mokėjimas','NO'=>'Sandorių nerasta!',
    'HO'=>'Valandos','RD'=>'Sumažinimas','CB'=>'Dabartinis balansas','1PHR'=>'1ᕫ/Valanda','SO'=>'Solidarumas','NB'=>'Naujas balansas','NM'=>'Vardas','ACC'=>'Sąskaita','ST'=>'Pradžia',
    'M_1'=>'Sau','M_2'=>'Vas','M_3'=>'Kov','M_4'=>'Bal','M_5'=>'Geg','M_6'=>'Bir','M_7'=>'Lie','M_8'=>'Rgp','M_9'=>'Rgs','M_10'=>'Spl','M_11'=>'Lap','M_12'=>'Gru',
    'HC_0'=>'Balta','HC_1'=>'Ruda (Ginger)','HC_2'=>'Kaštoninė','HC_3'=>'Šviesi (Blond)','HC_4'=>'Šviesiai kaštoninė','HC_5'=>'Varinė','HC_6'=>'Labai šviesi','HC_7'=>'Tamsiai kaštoninė','HC_8'=>'Sidabrinė','HC_9'=>'Vidutiniškai šviesi','HC_10'=>'Šviesiai ruda','HC_11'=>'Titano','HC_12'=>'Tamsiai šviesi','HC_13'=>'Vidutiniškai ruda','HC_14'=>'Žila','HC_15'=>'Auksinė','HC_16'=>'Tamsiai ruda','HC_17'=>'Juoda','HC_18'=>'Rausvai šviesi','HC_19'=>'Be plaukų',
    'EC_0'=>'Gintarinė','EC_1'=>'Mėlyna','EC_2'=>'Ruda','EC_3'=>'Pilka','EC_4'=>'Žalia','EC_5'=>'Riešutinė','EC_6'=>'Raudona','EC_7'=>'Melsvai pilka','EC_8'=>'Melsvai žalia','EC_9'=>'Žalsvai pilka','EC_10'=>'Žalsvai ruda','EC_11'=>'Protezas'
),

// hu - Hungarian
'hu' => array(
    'TITLE'=>'Bőséggazda Pénz','OVERVIEW'=>'Tranzakciók áttekintése','FROM'=>'Ettől','TO'=>'Eddig','ALL'=>'Összes tranzakció nyomtatása','HIST'=>'Teljes személyes előzmény nyomtatása','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;O L D A L','BD'=>'Születésnap','GN'=>'Nem','HT'=>'Magasság','HR'=>'Haj','LE'=>'Bal szem','RE'=>'Jobb szem','SF'=>'Különleges jellemzők','ML'=>'Férfi','FM'=>'Nő','OT'=>'Egyéb','PA'=>'Fizetés','NO'=>'Nem található tranzakció!',
    'HO'=>'Óra','RD'=>'Csökkentés','CB'=>'Jelenlegi egyenleg','1PHR'=>'1ᕫ/Óra','SO'=>'Szolidaritás','NB'=>'Új egyenleg','NM'=>'Név','ACC'=>'Számla','ST'=>'Kezdet',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Már','M_4'=>'Ápr','M_5'=>'Máj','M_6'=>'Jún','M_7'=>'Júl','M_8'=>'Aug','M_9'=>'Szep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Fehér','HC_1'=>'Vöröses szőke','HC_2'=>'Gesztenyebarna','HC_3'=>'Szőke','HC_4'=>'Világos gesztenye','HC_5'=>'Rézvörös','HC_6'=>'Világosszőke','HC_7'=>'Gesztenyebarna','HC_8'=>'Ezüst','HC_9'=>'Középszőke','HC_10'=>'Világosbarna','HC_11'=>'Titán','HC_12'=>'Sötétszőke','HC_13'=>'Középbarna','HC_14'=>'Ősz','HC_15'=>'Aranyszőke','HC_16'=>'Sötétbarna','HC_17'=>'Fekete','HC_18'=>'Eper-szőke','HC_19'=>'Haj nélkül',
    'EC_0'=>'Borostyán','EC_1'=>'Kék','EC_2'=>'Barna','EC_3'=>'Szürke','EC_4'=>'Zöld','EC_5'=>'Mogyoróbarna','EC_6'=>'Vörös','EC_7'=>'Kékes-szürke','EC_8'=>'Kékes-zöld','EC_9'=>'Zöldes-szürke','EC_10'=>'Zöldes-barna','EC_11'=>'Protézis'
),

// mg - Malagasy
'mg' => array(
    'TITLE'=>'Hafarekarena Vola','OVERVIEW'=>'Topimaso momba ny fifanakalozana','FROM'=>'Nanomboka','TO'=>'Hatramin’ny','ALL'=>'Avoahy ny fifanakalozana rehetra','HIST'=>'Avoahy ny tantara manontolo','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P E J Y','BD'=>'Andro nahaterahana','GN'=>'Lahy na vavy','HT'=>'Halava','HR'=>'Volo','LE'=>'Maso havia','RE'=>'Maso havanana','SF'=>'Sombiny manokana','ML'=>'Lahy','FM'=>'Vavy','OT'=>'Hafa','PA'=>'Fandoavam-bola','NO'=>'Tsy misy fifanakalozana hita!',
    'HO'=>'Ora','RD'=>'Fihenam-bidy','CB'=>'Mbola eo am-pelatanana','1PHR'=>'1ᕫ/Ora','SO'=>'Firaisankina','NB'=>'Mbola eo am-pelatanana vaovao','NM'=>'Anarana','ACC'=>'Kaonty','ST'=>'Fanombohana',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'May','M_6'=>'Jon','M_7'=>'Jol','M_8'=>'Aog','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Des',
    'HC_0'=>'Fotsy','HC_1'=>'Mena sakamalao','HC_2'=>'Mena matetika','HC_3'=>'Volo volamena','HC_4'=>'Mena mazava','HC_5'=>'Varahina','HC_6'=>'Mavo mazava','HC_7'=>'Mena antitra','HC_8'=>'Volafotsy','HC_9'=>'Mavo antonony','HC_10'=>'Mena mazava','HC_11'=>'Titanina','HC_12'=>'Mavo antitra','HC_13'=>'Mena antonony','HC_14'=>'Fotsy volo','HC_15'=>'Volo volamena mamirapiratra','HC_16'=>'Mena antitra be','HC_17'=>'Mainty','HC_18'=>'Volo mavo manopy mena','HC_19'=>'Tsy misy volo',
    'EC_0'=>'Ambra','EC_1'=>'Manga','EC_2'=>'Mena matetika','EC_3'=>'Fotsy volo','EC_4'=>'Maitso','EC_5'=>'Hazel','EC_6'=>'Mena','EC_7'=>'Manga matroka','EC_8'=>'Manga maitso','EC_9'=>'Maitso matroka','EC_10'=>'Maitso mena','EC_11'=>'Solon-maso'
),

// ma - Marathi
'ma' => array(
    'TITLE'=>'विपुलशास्त्र पैसा','OVERVIEW'=>'व्यवहाराचे विहंगावलोकन','FROM'=>'पासून','TO'=>'पर्यंत','ALL'=>'सर्व व्यवहार प्रिंट करा','HIST'=>'पूर्ण वैयक्तिक इतिहास प्रिंट करा','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;पृ ष्ठ','BD'=>'वाढदिवस','GN'=>'लिंग','HT'=>'उंची','HR'=>'केस','LE'=>'डावा डोळा','RE'=>'उजवा डोळा','SF'=>'विशेष वैशिष्ट्ये','ML'=>'पुरुष','FM'=>'स्त्री','OT'=>'इतर','PA'=>'पेमेंट','NO'=>'कोणताही व्यवहार आढळला नाही!',
    'HO'=>'तास','RD'=>'कपात','CB'=>'सध्याची शिल्लक','1PHR'=>'१ᕫ/तास','SO'=>'एकता','NB'=>'नवीन शिल्लक','NM'=>'नाव','ACC'=>'खाते','ST'=>'प्रारंभ',
    'M_1'=>'जाने','M_2'=>'फेब्रु','M_3'=>'मार्च','M_4'=>'एप्रिल','M_5'=>'मे','M_6'=>'जून','M_7'=>'जुलै','M_8'=>'ऑगस्ट','M_9'=>'सप्टें','M_10'=>'ऑक्टो','M_11'=>'नोव्हें','M_12'=>'डिसें',
    'HC_0'=>'पांढरा','HC_1'=>'आले लाल','HC_2'=>'गडद लाल','HC_3'=>'सोनेरी','HC_4'=>'फिकट अक्रोड','HC_5'=>'तांबेरी','HC_6'=>'फिकट सोनेरी','HC_7'=>'अक्रोड तपकिरी','HC_8'=>'सोनेरी चंदेरी','HC_9'=>'मध्यम सोनेरी','HC_10'=>'फिकट तपकिरी','HC_11'=>'टायटन','HC_12'=>'गडद सोनेरी','HC_13'=>'मध्यम तपकिरी','HC_14'=>'राखाडी','HC_15'=>'गोल्ड ब्लॉन्ड','HC_16'=>'गडद तपकिरी','HC_17'=>'काळा','HC_18'=>'स्ट्रॉबेरी ब्लॉन्ड','HC_19'=>'केस नाहीत',
    'EC_0'=>'एम्बर','EC_1'=>'निळा','EC_2'=>'तपकिरी','EC_3'=>'राखाडी','EC_4'=>'हिरवा','EC_5'=>'हेझेल','EC_6'=>'लाल','EC_7'=>'निळा राखाडी','EC_8'=>'निळा हिरवा','EC_9'=>'हिरवा राखाडी','EC_10'=>'हिरवा तपकिरी','EC_11'=>'कृत्रिम डोळा'
),

// ml - Malay
'ml' => array(
    'TITLE'=>'Limpahnomi Wang','OVERVIEW'=>'Ringkasan Transaksi','FROM'=>'Dari','TO'=>'Hingga','ALL'=>'Cetak semua transaksi','HIST'=>'Cetak sejarah peribadi penuh','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;M U K A&nbsp;&nbsp;S U R A T','BD'=>'Hari Lahir','GN'=>'Jantina','HT'=>'Tinggi','HR'=>'Rambut','LE'=>'Mata Kiri','RE'=>'Mata Kanan','SF'=>'Ciri-ciri Khas','ML'=>'Lelaki','FM'=>'Perempuan','OT'=>'Lain-lain','PA'=>'Pembayaran','NO'=>'Tiada Transaksi Ditemui!',
    'HO'=>'Jam','RD'=>'Pengurangan','CB'=>'Baki semasa','1PHR'=>'1ᕫ/Jam','SO'=>'Solidariti','NB'=>'Baki Baru','NM'=>'Nama','ACC'=>'Akaun','ST'=>'Mula',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mac','M_4'=>'Apr','M_5'=>'Mei','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Ogos','M_9'=>'Sept','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dis',
    'HC_0'=>'Putih','HC_1'=>'Merah Halia','HC_2'=>'Auburn','HC_3'=>'Perang','HC_4'=>'Berangan Cerah','HC_5'=>'Tembaga','HC_6'=>'Perang Cerah','HC_7'=>'Berangan Coklat','HC_8'=>'Perak','HC_9'=>'Perang Sederhana','HC_10'=>'Coklat Cerah','HC_11'=>'Titan','HC_12'=>'Perang Gelap','HC_13'=>'Coklat Sederhana','HC_14'=>'Kelabu','HC_15'=>'Perang Emas','HC_16'=>'Coklat Gelap','HC_17'=>'Hitam','HC_18'=>'Perang Strawberi','HC_19'=>'Tiada Rambut',
    'EC_0'=>'Amber','EC_1'=>'Biru','EC_2'=>'Coklat','EC_3'=>'Kelabu','EC_4'=>'Hijau','EC_5'=>'Hazel','EC_6'=>'Merah','EC_7'=>'Biru Kelabu','EC_8'=>'Biru Hijau','EC_9'=>'Hijau Kelabu','EC_10'=>'Hijau Coklat','EC_11'=>'Prostesis'
),

// mo - Mongolian
'mo' => array(
    'TITLE'=>'Элбэгзасаг Мөнгө','OVERVIEW'=>'Гүйлгээний тойм','FROM'=>'Эхлэх','TO'=>'Хүртэл','ALL'=>'Бүх гүйлгээг хэвлэх','HIST'=>'Хувийн түүхийг бүрэн хэвлэх','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;Х У У Д А С','BD'=>'Төрсөн өдөр','GN'=>'Хүйс','HT'=>'Өндөр','HR'=>'Үсний өнгө','LE'=>'Зүүн нүд','RE'=>'Баруун нүд','SF'=>'Онцлог шинж чанар','ML'=>'Эрэгтэй','FM'=>'Эмэгтэй','OT'=>'Бусад','PA'=>'Төлбөр','NO'=>'Гүйлгээ олдсонгүй!',
    'HO'=>'Цаг','RD'=>'Бууруулалт','CB'=>'Одоогийн үлдэгдэл','1PHR'=>'1ᕫ/Цаг','SO'=>'Эв нэгдэл','NB'=>'Шинэ үлдэгдэл','NM'=>'Нэр','ACC'=>'Данс','ST'=>'Эхлэл',
    'M_1'=>'1-р сар','M_2'=>'2-р сар','M_3'=>'3-р сар','M_4'=>'4-р сар','M_5'=>'5-р сар','M_6'=>'6-р сар','M_7'=>'7-р сар','M_8'=>'8-р сар','M_9'=>'9-р сар','M_10'=>'10-р сар','M_11'=>'11-р сар','M_12'=>'12-р сар',
    'HC_0'=>'Цагаан','HC_1'=>'Улаан шар','HC_2'=>'Хүрэн улаан','HC_3'=>'Шар','HC_4'=>'Цайвар хүрэн','HC_5'=>'Зэс','HC_6'=>'Цайвар шар','HC_7'=>'Туулайн бөөрний хүрэн','HC_8'=>'Мөнгөлөг','HC_9'=>'Дундаж шар','HC_10'=>'Цайвар бор','HC_11'=>'Титан','HC_12'=>'Гүн шар','HC_13'=>'Дундаж бор','HC_14'=>'Саарал','HC_15'=>'Алтан шар','HC_16'=>'Гүн бор','HC_17'=>'Хар','HC_18'=>'Гүзээлзгэний шар','HC_19'=>'Үсгүй',
    'EC_0'=>'Хув','EC_1'=>'Цэнхэр','EC_2'=>'Бор','EC_3'=>'Саарал','EC_4'=>'Ногоон','EC_5'=>'Самар','EC_6'=>'Улаан','EC_7'=>'Хөх саарал','EC_8'=>'Хөх ногоон','EC_9'=>'Ногоон саарал','EC_10'=>'Ногоон бор','EC_11'=>'Хиймэл нүд'
),

// bu - Burmese
'bu' => array(
    'TITLE'=>'ကယ်လ်စီးပွားရေး ငွေ','OVERVIEW'=>'ငွေလွှဲမှုအနှစ်ချုပ်','FROM'=>'မှ','TO'=>'အထိ','ALL'=>'ငွေလွှဲမှုအားလုံးထုတ်ရန်','HIST'=>'ကိုယ်ရေးမှတ်တမ်းအပြည့်အစုံထုတ်ရန်','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;စာ မျက် နှာ','BD'=>'မွေးနေ့','GN'=>'ကျား/မ','HT'=>'အရပ်အမြင့်','HR'=>'ဆံပင်','LE'=>'ဘယ်ဘက်မျက်စိ','RE'=>'ညာဘက်မျက်စိ','SF'=>'ထူးခြားချက်များ','ML'=>'ကျား','FM'=>'မ','OT'=>'အခြား','PA'=>'ပေးချေမှု','NO'=>'ငွေလွှဲမှုမရှိပါ!',
    'HO'=>'နာရီ','RD'=>'လျှော့ချမှု','CB'=>'လက်ကျန်ငွေ','1PHR'=>'၁ᕫ/နာရီ','SO'=>'စည်းလုံးညီညွတ်မှု','NB'=>'လက်ကျန်ငွေအသစ်','NM'=>'အမည်','ACC'=>'အကောင့်','ST'=>'စတင်ခြင်း',
    'M_1'=>'ဇန်','M_2'=>'ဖေ','M_3'=>'မတ်','M_4'=>'ဧ','M_5'=>'မေ','M_6'=>'ဇွန်','M_7'=>'ဇူ','M_8'=>'သြ','M_9'=>'စက်','M_10'=>'အောက်','M_11'=>'နို','M_12'=>'ဒီ',
    'HC_0'=>'အဖြူ','HC_1'=>'ဂျင်းနီရောင်','HC_2'=>'နီညိုရောင်','HC_3'=>'ရွှေရောင်','HC_4'=>'သစ်အယ်ဖျော့ရောင်','HC_5'=>'ကြေးနီရောင်','HC_6'=>'ရွှေဖျော့ရောင်','HC_7'=>'သစ်အယ်ညိုရောင်','HC_8'=>'ငွေရောင်','HC_9'=>'ရွှေအလတ်ရောင်','HC_10'=>'အညိုဖျော့','HC_11'=>'တိုက်တေနီယမ်','HC_12'=>'ရွှေရောင်ရင့်','HC_13'=>'အညိုအလတ်','HC_14'=>'မီးခိုးရောင်','HC_15'=>'ရွှေဝါရောင်','HC_16'=>'အညိုရင့်','HC_17'=>'အမည်း','HC_18'=>'စတော်ဘယ်ရီရွှေရောင်','HC_19'=>'ဆံပင်မရှိ',
    'EC_0'=>'ပယင်းရောင်','EC_1'=>'အပြာ','EC_2'=>'အညို','EC_3'=>'မီးခိုးရောင်','EC_4'=>'အစိမ်း','EC_5'=>'Hazel','EC_6'=>'အနီ','EC_7'=>'ပြာလဲ့လဲ့','EC_8'=>'ပြာစိမ်း','EC_9'=>'စိမ်းပြာရောင်','EC_10'=>'စိမ်းညိုရောင်','EC_11'=>'မျက်စိအတု'
),

// ne - Dutch
'ne' => array(
    'TITLE'=>'Abundomie Geld','OVERVIEW'=>'Transactieoverzicht','FROM'=>'Van','TO'=>'Tot','ALL'=>'Alle transacties afdrukken','HIST'=>'Volledige geschiedenis afdrukken','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P A G I N A','BD'=>'Geboortedag','GN'=>'Geslacht','HT'=>'Lengte','HR'=>'Haar','LE'=>'Linkeroog','RE'=>'Rechteroog','SF'=>'Bijzondere kenmerken','ML'=>'Man','FM'=>'Vrouw','OT'=>'Overig','PA'=>'Betaling','NO'=>'Geen transacties gevonden!',
    'HO'=>'Uren','RD'=>'Afname','CB'=>'Huidig saldo','1PHR'=>'1ᕫ/Uur','SO'=>'Solidariteit','NB'=>'Nieuw saldo','NM'=>'Naam','ACC'=>'Rekening','ST'=>'Start',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mrt','M_4'=>'Apr','M_5'=>'Mei','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Wit','HC_1'=>'Gemberrood','HC_2'=>'Kastanjerood','HC_3'=>'Blond','HC_4'=>'Licht kastanje','HC_5'=>'Koper','HC_6'=>'Lichtblond','HC_7'=>'Kastanjebruin','HC_8'=>'Zilver','HC_9'=>'Middenblond','HC_10'=>'Lichtbruin','HC_11'=>'Titan','HC_12'=>'Donkerblond','HC_13'=>'Middenbruin','HC_14'=>'Grijs','HC_15'=>'Goudblond','HC_16'=>'Donkerbruin','HC_17'=>'Zwart','HC_18'=>'Aardbeiblond','HC_19'=>'Kaal',
    'EC_0'=>'Amber','EC_1'=>'Blauw','EC_2'=>'Bruin','EC_3'=>'Grijs','EC_4'=>'Groen','EC_5'=>'Hazelaar','EC_6'=>'Rood','EC_7'=>'Blauwgrijs','EC_8'=>'Blauwgroen','EC_9'=>'Groengrijs','EC_10'=>'Groenbruin','EC_11'=>'Prothese'
),

// np - Nepali
'np' => array(
    'TITLE'=>'प्रचुरशास्त्र पैसा','OVERVIEW'=>'कारोबार सारांश','FROM'=>'बाट','TO'=>'सम्म','ALL'=>'सबै कारोबार प्रिन्ट गर्नुहोस्','HIST'=>'पूर्ण व्यक्तिगत इतिहास प्रिन्ट गर्नुहोस्','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;पृ ष्ठ','BD'=>'जन्मदिन','GN'=>'लिङ्ग','HT'=>'उचाइ','HR'=>'कपाल','LE'=>'बायाँ आँखा','RE'=>'दायाँ आँखा','SF'=>'विशेष विशेषताहरू','ML'=>'पुरुष','FM'=>'महिला','OT'=>'अन्य','PA'=>'भुक्तानी','NO'=>'कुनै कारोबार फेला परेन!',
    'HO'=>'घण्टा','RD'=>'कटौती','CB'=>'हालको मौज्दात','1PHR'=>'१ᕫ/घण्टा','SO'=>'एकता','NB'=>'नयाँ मौज्दात','NM'=>'नाम','ACC'=>'खाता','ST'=>'सुरु',
    'M_1'=>'जन','M_2'=>'फेब','M_3'=>'मार्च','M_4'=>'अप्रिल','M_5'=>'मे','M_6'=>'जुन','M_7'=>'जुलाई','M_8'=>'अगस्ट','M_9'=>'सेप्टे','M_10'=>'अक्टो','M_11'=>'नोभे','M_12'=>'डिसे',
    'HC_0'=>'सेतो','HC_1'=>'अदुवा रातो','HC_2'=>'गाढा रातो','HC_3'=>'खैरो','HC_4'=>'हल्का ओखर','HC_5'=>'तामा','HC_6'=>'हल्का खैरो','HC_7'=>'ओखर खैरो','HC_8'=>'चाँदी','HC_9'=>'मध्यम खैरो','HC_10'=>'हल्का खैरो','HC_11'=>'टाइटन','HC_12'=>'गाढा खैरो','HC_13'=>'मध्यम खैरो','HC_14'=>'फुस्रो','HC_15'=>'सुनौलो खैरो','HC_16'=>'गाढा खैरो','HC_17'=>'कालो','HC_18'=>'स्ट्रबेरी खैरो','HC_19'=>'कपाल नभएको',
    'EC_0'=>'एम्बर','EC_1'=>'निलो','EC_2'=>'खैरो','EC_3'=>'फुस्रो','EC_4'=>'हरियो','EC_5'=>'हेजल','EC_6'=>'रातो','EC_7'=>'निलो फुस्रो','EC_8'=>'निलो हरियो','EC_9'=>'हरियो फुस्रो','EC_10'=>'हरियो खैरो','EC_11'=>'कृत्रिम'
),

// no - Norwegian
'no' => array(
    'TITLE'=>'Overflomi Penger','OVERVIEW'=>'Transaksjonsoversikt','FROM'=>'Fra','TO'=>'Til','ALL'=>'Skriv ut alle transaksjoner','HIST'=>'Skriv ut fullstendig historikk','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S I D E','BD'=>'Bursdag','GN'=>'Kjønn','HT'=>'Høyde','HR'=>'Hår','LE'=>'Venstre øye','RE'=>'Høyre øye','SF'=>'Spesielle kjennetegn','ML'=>'Mann','FM'=>'Kvinne','OT'=>'Annet','PA'=>'Betaling','NO'=>'Ingen transaksjoner funnet!',
    'HO'=>'Timer','RD'=>'Reduksjon','CB'=>'Nåværende saldo','1PHR'=>'1ᕫ/Time','SO'=>'Solidaritet','NB'=>'Ny saldo','NM'=>'Navn','ACC'=>'Konto','ST'=>'Start',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Mai','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Des',
    'HC_0'=>'Hvit','HC_1'=>'Ingefærrød','HC_2'=>'Rødbrun','HC_3'=>'Blond','HC_4'=>'Lys kastanje','HC_5'=>'Kobber','HC_6'=>'Lys blond','HC_7'=>'Kastanjebrun','HC_8'=>'Sølv','HC_9'=>'Mellomblond','HC_10'=>'Lys brun','HC_11'=>'Titan','HC_12'=>'Mørk blond','HC_13'=>'Mellombrun','HC_14'=>'Grå','HC_15'=>'Gullblond','HC_16'=>'Mørkebrun','HC_17'=>'Svart','HC_18'=>'Jordbærblond','HC_19'=>'Uten hår',
    'EC_0'=>'Rav','EC_1'=>'Blå','EC_2'=>'Brun','EC_3'=>'Grå','EC_4'=>'Grønn','EC_5'=>'Hassel','EC_6'=>'Rød','EC_7'=>'Blågrå','EC_8'=>'Blågrønn','EC_9'=>'Grågrønn','EC_10'=>'Grønnbrun','EC_11'=>'Protese'
),

// or - Oromo
'or' => array(
    'TITLE'=>'Badhaadagdee Maallaqa','OVERVIEW'=>'Ibsa Galii fi Baasii','FROM'=>'Irraa','TO'=>'Hanga','ALL'=>'Maallaqa hunda maxxansi','HIST'=>'Seenaa dhuunfaa guutuu maxxansi','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;F U L A','BD'=>'Guyyaa dhalootaa','GN'=>'Saala','HT'=>'Dheerinna','HR'=>'Rifeensa','LE'=>'Ija bitaa','RE'=>'Ija mirga','SF'=>'Amala addaa','ML'=>'Dhiira','FM'=>'Dubartii','OT'=>'Kan biraa','PA'=>'Kaffaltii','NO'=>'Maallaqni argame hin jiru!',
    'HO'=>'Sa\'aatii','RD'=>'Hir’ina','CB'=>'Hafee jiru','1PHR'=>'1ᕫ/Sa\'aatii','SO'=>'Tokkummaa','NB'=>'Hafee haaraa','NM'=>'Maqaa','ACC'=>'Kovntii','ST'=>'Eegalumsa',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'May','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Oct','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Adii','HC_1'=>'Diimaa jinji_ilaa','HC_2'=>'Diimaa gurraacha','HC_3'=>'Keelloo','HC_4'=>'Buna ifaa','HC_5'=>'Sibiila','HC_6'=>'Keelloo ifaa','HC_7'=>'Buna gadi fageenya','HC_8'=>'Meeta','HC_9'=>'Keelloo giddu-galeessaa','HC_10'=>'Buna ifaa','HC_11'=>'Tiitaanii','HC_12'=>'Keelloo gadi fageenya','HC_13'=>'Buna giddu-galeessaa','HC_14'=>'Daaree','HC_15'=>'Keelloo warqee','HC_16'=>'Buna gurraacha','HC_17'=>'Gurraacha','HC_18'=>'Keelloo istirooberii','HC_19'=>'Rifeensa hin qabu',
    'EC_0'=>'Anbar','EC_1'=>'Cuquliisa','EC_2'=>'Buna','EC_3'=>'Daaree','EC_4'=>'Magariisa','EC_5'=>'Heezil','EC_6'=>'Diimaa','EC_7'=>'Cuquliisa-daaree','EC_8'=>'Cuquliisa-magariisa','EC_9'=>'Magariisa-daaree','EC_10'=>'Magariisa-buna','EC_11'=>'Ija nam-tolchee'
),

// pa - Pashto
'pa' => array(
    'TITLE'=>'پریمانیساد پیسې','OVERVIEW'=>'د راکړې ورکړې بیاکتنه','FROM'=>'له','TO'=>'تر','ALL'=>'ټولې معاملې چاپ کړئ','HIST'=>'بشپړ شخصي تاریخ چاپ کړئ','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;پ ا ڼ ه','BD'=>'د زیږون نیټه','GN'=>'جنسیت','HT'=>'قد','HR'=>'ویښتان','LE'=>'کیڼه سترګه','RE'=>'ښۍ سترګه','SF'=>'ځانګړتیاوې','ML'=>'ن نارینه','FM'=>'ښځینه','OT'=>'بل','PA'=>'تادیه','NO'=>'کومه معامله ونه موندل شوه!',
    'HO'=>'ساعتونه','RD'=>'کمی','CB'=>'اوسنی بیلانس','1PHR'=>'۱ᕫ/ساعت','SO'=>'پیوستون','NB'=>'نوی بیلانس','NM'=>'نوم','ACC'=>'اکاونټ','ST'=>'پیل',
    'M_1'=>'جنوري','M_2'=>'فبروري','M_3'=>'مارچ','M_4'=>'اپریل','M_5'=>'می','M_6'=>'جون','M_7'=>'جولای','M_8'=>'اګست','M_9'=>'سپتمبر','M_10'=>'اکتوبر','M_11'=>'نومبر','M_12'=>'دسمبر',
    'HC_0'=>'سپین','HC_1'=>'زنجبیل سور','HC_2'=>'نصواري سور','HC_3'=>'بور','HC_4'=>'روښانه نسواري','HC_5'=>'مس','HC_6'=>'روښانه بور','HC_7'=>'تاریک نسواري','HC_8'=>'سپین زر','HC_9'=>'منځنی بور','HC_10'=>'روښانه نصواري','HC_11'=>'تیتان','HC_12'=>'تاریک بور','HC_13'=>'منځنی نصواري','HC_14'=>'خړ','HC_15'=>'طلایی بور','HC_16'=>'تور نصواري','HC_17'=>'تور','HC_18'=>'سټرابیري بور','HC_19'=>'ویښتان نلري',
    'EC_0'=>'عنبري','EC_1'=>'شین','EC_2'=>'نصواري','EC_3'=>'خړ','EC_4'=>'زرغون','EC_5'=>'هیزل','EC_6'=>'سور','EC_7'=>'شین خړ','EC_8'=>'شین زرغون','EC_9'=>'زرغون خړ','EC_10'=>'زرغون نصواري','EC_11'=>'مصنوعي'
),

// pe - Persian
'pe' => array(
    'TITLE'=>'فراوانید پول','OVERVIEW'=>'مرور تراکنش‌ها','FROM'=>'از','TO'=>'تا','ALL'=>'چاپ تمام تراکنش‌ها','HIST'=>'چاپ کامل تاریخچه شخصی','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ص ف ح ه','BD'=>'تاریخ تولد','GN'=>'جنسیت','HT'=>'قد','HR'=>'مو','LE'=>'چشم چپ','RE'=>'چشم راست','SF'=>'ویژگی‌های خاص','ML'=>'مرد','FM'=>'زن','OT'=>'سایر','PA'=>'پرداخت','NO'=>'تراکنشی یافت نشد!',
    'HO'=>'ساعت','RD'=>'کاهش','CB'=>'تراز فعلی','1PHR'=>'۱ᕫ/ساعت','SO'=>'همبستگی','NB'=>'تراز جدید','NM'=>'نام','ACC'=>'حساب','ST'=>'شروع',
    'M_1'=>'ژانویه','M_2'=>'فوریه','M_3'=>'مارس','M_4'=>'آوریل','M_5'=>'مه','M_6'=>'ژوئن','M_7'=>'ژوئیه','M_8'=>'اوت','M_9'=>'سپتامبر','M_10'=>'اکتبر','M_11'=>'نوامبر','M_12'=>'دسامبر',
    'HC_0'=>'سفید','HC_1'=>'قرمز زنجبیلی','HC_2'=>'خرمایی مایل به قرمز','HC_3'=>'بلوند','HC_4'=>'بلوطی روشن','HC_5'=>'مسی','HC_6'=>'بلوند روشن','HC_7'=>'قهوه‌ای بلوطی','HC_8'=>'نقره‌ای','HC_9'=>'بلوند متوسط','HC_10'=>'قهوه‌ای روشن','HC_11'=>'تیتان','HC_12'=>'بلوند تیره','HC_13'=>'قهوه‌ای متوسط','HC_14'=>'خاکستری','HC_15'=>'بلوند طلایی','HC_16'=>'قهوه‌ای تیره','HC_17'=>'مشکی','HC_18'=>'بلوند توت‌فرنگی','HC_19'=>'بدون مو',
    'EC_0'=>'کهربایی','EC_1'=>'آبی','EC_2'=>'قهوه‌ای','EC_3'=>'خاکستری','EC_4'=>'سبز','EC_5'=>'عسلی','EC_6'=>'قرمز','EC_7'=>'آبی خاکستری','EC_8'=>'آبی سبز','EC_9'=>'سبز خاکستری','EC_10'=>'سبز قهوه‌ای','EC_11'=>'پروتز'
),

// po - Polish
'po' => array(
    'TITLE'=>'Obfitonomia Pieniądze','OVERVIEW'=>'Przegląd transakcji','FROM'=>'Od','TO'=>'Do','ALL'=>'Drukuj wszystkie transakcje','HIST'=>'Drukuj pełną historię osobistą','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S T R O N A','BD'=>'Data urodzenia','GN'=>'Płeć','HT'=>'Wzrost','HR'=>'Włosy','LE'=>'Lewe oko','RE'=>'Prawe oko','SF'=>'Cechy szczególne','ML'=>'Mężczyzna','FM'=>'Kobieta','OT'=>'Inne','PA'=>'Płatność','NO'=>'Nie znaleziono transakcji!',
    'HO'=>'Godziny','RD'=>'Redukcja','CB'=>'Aktualne saldo','1PHR'=>'1ᕫ/Godzina','SO'=>'Solidarność','NB'=>'Nowe saldo','NM'=>'Imię','ACC'=>'Konto','ST'=>'Start',
    'M_1'=>'Sty','M_2'=>'Lut','M_3'=>'Mar','M_4'=>'Kwi','M_5'=>'Maj','M_6'=>'Cze','M_7'=>'Lip','M_8'=>'Sie','M_9'=>'Wrz','M_10'=>'Paź','M_11'=>'Lis','M_12'=>'Gru',
    'HC_0'=>'Białe','HC_1'=>'Rude','HC_2'=>'Kasztanowe','HC_3'=>'Blond','HC_4'=>'Jasny kasztan','HC_5'=>'Miedziane','HC_6'=>'Jasny blond','HC_7'=>'Kasztanowy brąz','HC_8'=>'Srebrne','HC_9'=>'Średni blond','HC_10'=>'Jasny brąz','HC_11'=>'Tytan','HC_12'=>'Ciemny blond','HC_13'=>'Średni brąz','HC_14'=>'Siwe','HC_15'=>'Złoty blond','HC_16'=>'Ciemny brąz','HC_17'=>'Czarne','HC_18'=>'Truskawkowy blond','HC_19'=>'Brak włosów',
    'EC_0'=>'Bursztynowe','EC_1'=>'Niebieskie','EC_2'=>'Brązowe','EC_3'=>'Szare','EC_4'=>'Zielone','EC_5'=>'Piwne','EC_6'=>'Czerwone','EC_7'=>'Niebiesko-szare','EC_8'=>'Niebiesko-zielone','EC_9'=>'Zielono-szare','EC_10'=>'Zielono-brązowe','EC_11'=>'Proteza'
),

// pt - Portuguese
'pt' => array(
    'TITLE'=>'Abundomia Dinheiro','OVERVIEW'=>'Visão geral das transações','FROM'=>'De','TO'=>'Até','ALL'=>'Imprimir todas as transações','HIST'=>'Imprimir histórico pessoal completo','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P Á G I N A','BD'=>'Aniversário','GN'=>'Género','HT'=>'Altura','HR'=>'Cabelo','LE'=>'Olho esquerdo','RE'=>'Olho direito','SF'=>'Características especiais','ML'=>'Masculino','FM'=>'Feminino','OT'=>'Outro','PA'=>'Pagamento','NO'=>'Nenhuma transação encontrada!',
    'HO'=>'Horas','RD'=>'Redução','CB'=>'Saldo atual','1PHR'=>'1ᕫ/Hora','SO'=>'Solidariedade','NB'=>'Novo saldo','NM'=>'Nome','ACC'=>'Conta','ST'=>'Início',
    'M_1'=>'Jan','M_2'=>'Fev','M_3'=>'Mar','M_4'=>'Abr','M_5'=>'Mai','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Ago','M_9'=>'Set','M_10'=>'Out','M_11'=>'Nov','M_12'=>'Dez',
    'HC_0'=>'Branco','HC_1'=>'Ruivo','HC_2'=>'Castanho avermelhado','HC_3'=>'Loiro','HC_4'=>'Castanho claro','HC_5'=>'Acobreado','HC_6'=>'Loiro claro','HC_7'=>'Castanho escuro','HC_8'=>'Prateado','HC_9'=>'Loiro médio','HC_10'=>'Castanho claro','HC_11'=>'Titânio','HC_12'=>'Loiro escuro','HC_13'=>'Castanho médio','HC_14'=>'Grisalho','HC_15'=>'Loiro dourado','HC_16'=>'Castanho escuro','HC_17'=>'Preto','HC_18'=>'Loiro morango','HC_19'=>'Sem cabelo',
    'EC_0'=>'Âmbar','EC_1'=>'Azul','EC_2'=>'Castanho','EC_3'=>'Cinzento','EC_4'=>'Verde','EC_5'=>'Avelã','EC_6'=>'Vermelho','EC_7'=>'Azul acinzentado','EC_8'=>'Azul esverdeado','EC_9'=>'Verde acinzentado','EC_10'=>'Verde castanho','EC_11'=>'Prótese'
),

// ro - Romanian
'ro' => array(
    'TITLE'=>'Abundonomia Bani','OVERVIEW'=>'Prezentare tranzacții','FROM'=>'De la','TO'=>'Până la','ALL'=>'Imprimă toate tranzacțiile','HIST'=>'Imprimă istoricul personal complet','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;P A G I N Ă','BD'=>'Zi de naștere','GN'=>'Gen','HT'=>'Înălțime','HR'=>'Păr','LE'=>'Ochiul stâng','RE'=>'Ochiul drept','SF'=>'Caracteristici speciale','ML'=>'Masculin','FM'=>'Feminin','OT'=>'Altul','PA'=>'Plată','NO'=>'Nu s-au găsit tranzacții!',
    'HO'=>'Ore','RD'=>'Reducere','CB'=>'Sold curent','1PHR'=>'1ᕫ/Oră','SO'=>'Solidaritate','NB'=>'Sold nou','NM'=>'Nume','ACC'=>'Cont','ST'=>'Start',
    'M_1'=>'Ian','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Mai','M_6'=>'Iun','M_7'=>'Iul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Oct','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Alb','HC_1'=>'Roșcat','HC_2'=>'Castaniu roșiatic','HC_3'=>'Blond','HC_4'=>'Castaniu deschis','HC_5'=>'Cupru','HC_6'=>'Blond deschis','HC_7'=>'Maro castaniu','HC_8'=>'Argintiu','HC_9'=>'Blond mediu','HC_10'=>'Maro deschis','HC_11'=>'Titan','HC_12'=>'Blond închis','HC_13'=>'Maro mediu','HC_14'=>'Gri','HC_15'=>'Blond auriu','HC_16'=>'Maro închis','HC_17'=>'Negru','HC_18'=>'Blond căpșună','HC_19'=>'Fără păr',
    'EC_0'=>'Chihlimbar','EC_1'=>'Albastru','EC_2'=>'Căprui','EC_3'=>'Gri','EC_4'=>'Verde','EC_5'=>'Alună','EC_6'=>'Roșu','EC_7'=>'Albastru-gri','EC_8'=>'Albastru-verde','EC_9'=>'Verde-gri','EC_10'=>'Verde-căprui','EC_11'=>'Proteză'
),

// ru - Russian
'ru' => array(
    'TITLE'=>'Изобиломикс Деньги','OVERVIEW'=>'Обзор транзакций','FROM'=>'От','TO'=>'До','ALL'=>'Печать всех транзакций','HIST'=>'Печать полной личной истории','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;С Т Р А Н И Ц А','BD'=>'День рождения','GN'=>'Пол','HT'=>'Рост','HR'=>'Волосы','LE'=>'Левый глаз','RE'=>'Правый глаз','SF'=>'Особенности','ML'=>'Мужской','FM'=>'Женский','OT'=>'Другое','PA'=>'Платеж','NO'=>'Транзакции не найдены!',
    'HO'=>'Часы','RD'=>'Скидка','CB'=>'Текущий баланс','1PHR'=>'1ᕫ/Час','SO'=>'Солидарность','NB'=>'Новый баланс','NM'=>'Имя','ACC'=>'Счет','ST'=>'Начало',
    'M_1'=>'Янв','M_2'=>'Фев','M_3'=>'Мар','M_4'=>'Апр','M_5'=>'Май','M_6'=>'Июн','M_7'=>'Июл','M_8'=>'Авг','M_9'=>'Сен','M_10'=>'Окт','M_11'=>'Ноя','M_12'=>'Дек',
    'HC_0'=>'Белый','HC_1'=>'Рыжий','HC_2'=>'Каштановый','HC_3'=>'Блондин','HC_4'=>'Светло-каштановый','HC_5'=>'Медный','HC_6'=>'Светлый блондин','HC_7'=>'Каштаново-коричневый','HC_8'=>'Серебристый','HC_9'=>'Средний блондин','HC_10'=>'Светло-коричневый','HC_11'=>'Титан','HC_12'=>'Темный блондин','HC_13'=>'Средне-коричневый','HC_14'=>'Седой','HC_15'=>'Золотистый блондин','HC_16'=>'Темно-коричневый','HC_17'=>'Черный','HC_18'=>'Клубничный блондин','HC_19'=>'Без волос',
    'EC_0'=>'Янтарный','EC_1'=>'Голубой','EC_2'=>'Карий','EC_3'=>'Серый','EC_4'=>'Зеленый','EC_5'=>'Ореховый','EC_6'=>'Красный','EC_7'=>'Серо-голубой','EC_8'=>'Сине-зеленый','EC_9'=>'Серо-зеленый','EC_10'=>'Зелено-карий','EC_11'=>'Протез'
),

// zi - Tswana
'zi' => array(
    'TITLE'=>'Letlotlonomi Madi','OVERVIEW'=>'Tshobokanyo ya ditirisano','FROM'=>'Go tswa','TO'=>'Go ya','ALL'=>'Printa ditirisano tsotlhe','HIST'=>'Printa hisitori yotlhe ya motho','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;T S E B E','BD'=>'Letsatsi la botsalo','GN'=>'Bong','HT'=>'Boleele','HR'=>'Moriri','LE'=>'Leitlho la molema','RE'=>'Leitlho la moja','SF'=>'Diponagalo tse di kgethegileng','ML'=>'Monna','FM'=>'Mosadi','OT'=>'Tse dingwe','PA'=>'Tuelo','NO'=>'Ga go a bonwa ditirisano!',
    'HO'=>'Diura','RD'=>'Phokotso','CB'=>'Madi a a setseng','1PHR'=>'1ᕫ/Ura','SO'=>'Boikanyego','NB'=>'Madi a masha a a setseng','NM'=>'Leina','ACC'=>'Akhaonto','ST'=>'Tshimologo',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mop','M_4'=>'Mor','M_5'=>'Mot','M_6'=>'See','M_7'=>'Phu','M_8'=>'Pha','M_9'=>'Lwe','M_10'=>'Phal','M_11'=>'Ngw','M_12'=>'Sed',
    'HC_0'=>'Mosweu','HC_1'=>'Bofubedu jwa jinja','HC_2'=>'Borokwa jo bo khunou','HC_3'=>'Moriri o o mosehla','HC_4'=>'Borokwa jo bo phatshwa','HC_5'=>'Kopore','HC_6'=>'Mosehla o o phatshwa','HC_7'=>'Borokwa jwa tšhesenate','HC_8'=>'Selefera','HC_9'=>'Mosehla wa gare','HC_10'=>'Borokwa jo bo seng tsenelelang','HC_11'=>'Thaethene','HC_12'=>'Mosehla o o tsenelelang','HC_13'=>'Borokwa wa gare','HC_14'=>'Putswa','HC_15'=>'Mosehla wa gauta','HC_16'=>'Borokwa o o tsenelelang','HC_17'=>'Montsho','HC_18'=>'Mosehla wa morara','HC_19'=>'Ga gona moriri',
    'EC_0'=>'Emba','EC_1'=>'Botala jwa loapi','EC_2'=>'Borokwa','EC_3'=>'Putswa','EC_4'=>'Botala jwa tlhaga','EC_5'=>'Borokwa jwa moretlwa','EC_6'=>'Khunou','EC_7'=>'Botala-putswa','EC_8'=>'Botala-tlhaga','EC_9'=>'Tlhaga-putswa','EC_10'=>'Tlhaga-borokwa','EC_11'=>'Leitlho la maitirelo'
),

// al - Albanian
'al' => array(
    'TITLE'=>'Bollëkonomi Para','OVERVIEW'=>'Përmbledhja e transaksioneve','FROM'=>'Nga','TO'=>'Deri','ALL'=>'Printo të gjitha transaksionet','HIST'=>'Printo historinë e plotë','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;F A Q E','BD'=>'Ditëlindja','GN'=>'Gjinia','HT'=>'Lartësia','HR'=>'Flokët','LE'=>'Syri i majtë','RE'=>'Syri i djathtë','SF'=>'Karakteristika të veçanta','ML'=>'Mashkull','FM'=>'Femër','OT'=>'Tjetër','PA'=>'Pagesa','NO'=>'Nuk u gjet asnjë transaksion!',
    'HO'=>'Orë','RD'=>'Zbritje','CB'=>'Balanca aktuale','1PHR'=>'1ᕫ/Orë','SO'=>'Solidariteti','NB'=>'Balanca e re','NM'=>'Emri','ACC'=>'Llogaria','ST'=>'Fillimi',
    'M_1'=>'Jan','M_2'=>'Shk','M_3'=>'Mar','M_4'=>'Prill','M_5'=>'Maj','M_6'=>'Qer','M_7'=>'Kor','M_8'=>'Gush','M_9'=>'Sht','M_10'=>'Tet','M_11'=>'Nën','M_12'=>'Dhj',
    'HC_0'=>'Bardhë','HC_1'=>'Kuqe si xhenxhefil','HC_2'=>'Gështenjë e kuqërremtë','HC_3'=>'Bionde','HC_4'=>'Gështenjë e hapur','HC_5'=>'Bakër','HC_6'=>'Bionde e hapur','HC_7'=>'Gështenjë e errët','HC_8'=>'Argjend','HC_9'=>'Bionde mesatare','HC_10'=>'Kafe e hapur','HC_11'=>'Titan','HC_12'=>'Bionde e errët','HC_13'=>'Kafe mesatare','HC_14'=>'Gri','HC_15'=>'Bionde e artë','HC_16'=>'Kafe e errët','HC_17'=>'Zezë','HC_18'=>'Bionde luleshtrydhe','HC_19'=>'Pa flokë',
    'EC_0'=>'Amber','EC_1'=>'Kaltër','EC_2'=>'Kafe','EC_3'=>'Gri','EC_4'=>'Gjelbër','EC_5'=>'Lajthi','EC_6'=>'Kuqe','EC_7'=>'Kaltër në gri','EC_8'=>'Kaltër në gjelbër','EC_9'=>'Gjelbër në gri','EC_10'=>'Gjelbër në kafe','EC_11'=>'Protezë'
),

// sl - Slovenian
'sl' => array(
    'TITLE'=>'Izonomija Denar','OVERVIEW'=>'Pregled transakcij','FROM'=>'Od','TO'=>'Do','ALL'=>'Natisni vse transakcije','HIST'=>'Natisni celotno zgodovino','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S T R A N','BD'=>'Rojstni dan','GN'=>'Spol','HT'=>'Višina','HR'=>'Lasje','LE'=>'Levo oko','RE'=>'Desno oko','SF'=>'Posebne lastnosti','ML'=>'Moški','FM'=>'Ženska','OT'=>'Drugo','PA'=>'Plačilo','NO'=>'Ni najdenih transakcij!',
    'HO'=>'Ure','RD'=>'Zmanjšanje','CB'=>'Trenutno stanje','1PHR'=>'1ᕫ/Ura','SO'=>'Solidarnost','NB'=>'Novo stanje','NM'=>'Ime','ACC'=>'Račun','ST'=>'Začetek',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Maj','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Avg','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Bela','HC_1'=>'Ingverjevo rdeča','HC_2'=>'Kostanjevo rdeča','HC_3'=>'Platinasto blond','HC_4'=>'Svetlo kostanjeva','HC_5'=>'Bakrena','HC_6'=>'Svetlo blond','HC_7'=>'Kostanjevo rjava','HC_8'=>'Srebrna','HC_9'=>'Srednje blond','HC_10'=>'Svetlo rjava','HC_11'=>'Titan','HC_12'=>'Temno blond','HC_13'=>'Srednje rjava','HC_14'=>'Siva','HC_15'=>'Zlato blond','HC_16'=>'Temno rjava','HC_17'=>'Črna','HC_18'=>'Jagodno blond','HC_19'=>'Brez las',
    'EC_0'=>'Jantarjeva','EC_1'=>'Modra','EC_2'=>'Rjava','EC_3'=>'Siva','EC_4'=>'Zelena','EC_5'=>'Lešnikova','EC_6'=>'Rdeča','EC_7'=>'Modro-siva','EC_8'=>'Modro-zelena','EC_9'=>'Zeleno-siva','EC_10'=>'Zeleno-rjava','EC_11'=>'Proteza'
),

// sk - Slovak
'sk' => array(
    'TITLE'=>'Hojnomika Peniaze','OVERVIEW'=>'Prehľad transakcií','FROM'=>'Od','TO'=>'Do','ALL'=>'Vytlačiť všetky transakcie','HIST'=>'Vytlačiť celú históriu','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S T R A N A','BD'=>'Narodeniny','GN'=>'Pohlavie','HT'=>'Výška','HR'=>'Vlasy','LE'=>'Ľavé oko','RE'=>'Pravé oko','SF'=>'Zvláštne znaky','ML'=>'Muž','FM'=>'Žena','OT'=>'Iné','PA'=>'Platba','NO'=>'Nenašli sa žiadne transakcie!',
    'HO'=>'Hodiny','RD'=>'Zníženie','CB'=>'Aktuálny zostatok','1PHR'=>'1ᕫ/Hodina','SO'=>'Solidarita','NB'=>'Nový zostatok','NM'=>'Meno','ACC'=>'Účet','ST'=>'Začiatok',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Máj','M_6'=>'Jún','M_7'=>'Júl','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Biela','HC_1'=>'Ryšavá','HC_2'=>'Gaštanová','HC_3'=>'Blond','HC_4'=>'Svetlá gaštanová','HC_5'=>'Medená','HC_6'=>'Svetlá blond','HC_7'=>'Gaštanovo hnedá','HC_8'=>'Strieborná','HC_9'=>'Stredná blond','HC_10'=>'Svetlohnedá','HC_11'=>'Titán','HC_12'=>'Tmavá blond','HC_13'=>'Stredne hnedá','HC_14'=>'Sivá','HC_15'=>'Zlatá blond','HC_16'=>'Tmavohnedá','HC_17'=>'Čierna','HC_18'=>'Jahodová blond','HC_19'=>'Bez vlasov',
    'EC_0'=>'Jantárová','EC_1'=>'Modrá','EC_2'=>'Hnedá','EC_3'=>'Sivá','EC_4'=>'Zelená','EC_5'=>'Oriešková','EC_6'=>'Červená','EC_7'=>'Modrosivá','EC_8'=>'Modrozelená','EC_9'=>'Zelenosivá','EC_10'=>'Zelenohnedá','EC_11'=>'Protéza'
),

// so - Somali
'so' => array(
    'TITLE'=>'Barwaaqalaha Lacag','OVERVIEW'=>'Guudmarka Macaamilka','FROM'=>'Ka','TO'=>'Ilaa','ALL'=>'Daabac dhammaan macaamilada','HIST'=>'Daabac taariikhda shakhsiga ah','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;B O O G','BD'=>'Dhalashada','GN'=>'Jinsiga','HT'=>'Dhererka','HR'=>'Timaha','LE'=>'Isha bidix','RE'=>'Isha midig','SF'=>'Astaamaha gaarka ah','ML'=>'Lab','FM'=>'Dheddig','OT'=>'Kale','PA'=>'Bixin','NO'=>'Ma jiro macaamil la helay!',
    'HO'=>'Saacadaha','RD'=>'Dhimista','CB'=>'Haraaga hadda','1PHR'=>'1ᕫ/Saacaddii','SO'=>'Midnimada','NB'=>'Haraaga cusub','NM'=>'Magaca','ACC'=>'Akoonka','ST'=>'Bilaabid',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Maar','M_4'=>'Abe','M_5'=>'May','M_6'=>'Jun','M_7'=>'Luul','M_8'=>'Oog','M_9'=>'Seb','M_10'=>'Okt','M_11'=>'Nof','M_12'=>'Dis',
    'HC_0'=>'Caddaan','HC_1'=>'Booraan cas','HC_2'=>'Madoow xiga','HC_3'=>'Cawl','HC_4'=>'Madoow xiga khafiif','HC_5'=>'Naxaas','HC_6'=>'Cawl khafiif','HC_7'=>'Buna xiga','HC_8'=>'Qalin','HC_9'=>'Cawl dhexdhexaad','HC_10'=>'Buna khafiif','HC_11'=>'Titaanim','HC_12'=>'Cawl madoow','HC_13'=>'Buna dhexdhexaad','HC_14'=>'Ood-cad','HC_15'=>'Cawl dahabi','HC_16'=>'Buna madoow','HC_17'=>'Madoow','HC_18'=>'Cawl strawberries','HC_19'=>'Timo la’aan',
    'EC_0'=>'Amber','EC_1'=>'Buluug','EC_2'=>'Buna','EC_3'=>'Ood-cad','EC_4'=>'Cagaar','EC_5'=>'Hazel','EC_6'=>'Cas','EC_7'=>'Buluug-oodcad','EC_8'=>'Buluug-cagaar','EC_9'=>'Cagaar-oodcad','EC_10'=>'Cagaar-buna','EC_11'=>'Il-macmal'
),

// fi - Finnish
'fi' => array(
    'TITLE'=>'Runstalous Raha','OVERVIEW'=>'Tapahtumien yleiskatsaus','FROM'=>'Alkaen','TO'=>'Asti','ALL'=>'Tulosta kaikki tapahtumat','HIST'=>'Tulosta täysi henkilöhistoria','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S I V U','BD'=>'Syntymäpäivä','GN'=>'Sukupuoli','HT'=>'Pituus','HR'=>'Hiukset','LE'=>'Vasen silmä','RE'=>'Oikea silmä','SF'=>'Erityistuntomerkit','ML'=>'Mies','FM'=>'Nainen','OT'=>'Muu','PA'=>'Maksu','NO'=>'Tapahtumia ei löytynyt!',
    'HO'=>'Tunnit','RD'=>'Vähennys','CB'=>'Nykyinen saldo','1PHR'=>'1ᕫ/Tunti','SO'=>'Solidaarisuus','NB'=>'Uusi saldo','NM'=>'Nimi','ACC'=>'Tili','ST'=>'Alku',
    'M_1'=>'Tammi','M_2'=>'Helmi','M_3'=>'Maalis','M_4'=>'Huhti','M_5'=>'Touko','M_6'=>'Kesä','M_7'=>'Heinä','M_8'=>'Elo','M_9'=>'Syys','M_10'=>'Loka','M_11'=>'Marras','M_12'=>'Joulu',
    'HC_0'=>'Valkoinen','HC_1'=>'Inkiväärinpunainen','HC_2'=>'Kastanjanruskea','HC_3'=>'Blondi','HC_4'=>'Vaaleanruskea','HC_5'=>'Kupari','HC_6'=>'Kirkas blondi','HC_7'=>'Kastanjanruskea','HC_8'=>'Hopea','HC_9'=>'Keskivaalea','HC_10'=>'Vaaleanruskea','HC_11'=>'Titaani','HC_12'=>'Tummanvaalea','HC_13'=>'Keskiruskea','HC_14'=>'Harmaa','HC_15'=>'Kultablondi','HC_16'=>'Tummanruskea','HC_17'=>'Musta','HC_18'=>'Mansikkablondi','HC_19'=>'Ei hiuksia',
    'EC_0'=>'Meripihka','EC_1'=>'Sininen','EC_2'=>'Ruskea','EC_3'=>'Harmaa','EC_4'=>'Vihreä','EC_5'=>'Pähkinänruskea','EC_6'=>'Punainen','EC_7'=>'Siniharmaa','EC_8'=>'Sinivihreä','EC_9'=>'Vihreänharmaa','EC_10'=>'Vihreänruskea','EC_11'=>'Proteesi'
),

// sw - Swedish
'sw' => array(
    'TITLE'=>'Overflomi Pengar','OVERVIEW'=>'Transaktionsöversikt','FROM'=>'Från','TO'=>'Till','ALL'=>'Skriv ut alla transaktioner','HIST'=>'Skriv ut fullständig personhistorik','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S I D A','BD'=>'Födelsedag','GN'=>'Kön','HT'=>'Längd','HR'=>'Hår','LE'=>'Vänster öga','RE'=>'Höger öga','SF'=>'Särskilda kännetecken','ML'=>'Man','FM'=>'Kvinna','OT'=>'Annat','PA'=>'Betalning','NO'=>'Inga transaktioner hittades!',
    'HO'=>'Timmar','RD'=>'Reduktion','CB'=>'Nuvarande saldo','1PHR'=>'1ᕫ/Timme','SO'=>'Solidaritet','NB'=>'Nytt saldo','NM'=>'Namn','ACC'=>'Konto','ST'=>'Start',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'Maj','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Okt','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Vit','HC_1'=>'Ingefärsröd','HC_2'=>'Rödbrun','HC_3'=>'Blond','HC_4'=>'Ljus kastanj','HC_5'=>'Koppar','HC_6'=>'Ljusblond','HC_7'=>'Kastanjebrun','HC_8'=>'Silver','HC_9'=>'Mellanblond','HC_10'=>'Ljusbrun','HC_11'=>'Titan','HC_12'=>'Mörkblond','HC_13'=>'Mellanbrun','HC_14'=>'Grå','HC_15'=>'Guldblond','HC_16'=>'Mörkbrun','HC_17'=>'Svart','HC_18'=>'Jordgubbsblond','HC_19'=>'Inget hår',
    'EC_0'=>'Bärnsten','EC_1'=>'Blå','EC_2'=>'Brun','EC_3'=>'Grå','EC_4'=>'Grön','EC_5'=>'Hassel','EC_6'=>'Röd','EC_7'=>'Blågrå','EC_8'=>'Blågrön','EC_9'=>'Grågrön','EC_10'=>'Grönbrun','EC_11'=>'Protes'
),

// ta - Tamil
'ta' => array(
    'TITLE'=>'வளதரம் பணம்','OVERVIEW'=>'பரிவர்த்தனை மேலோட்டம்','FROM'=>'இருந்து','TO'=>'வரை','ALL'=>'அனைத்து பரிவர்த்தனைகளையும் அச்சிடுக','HIST'=>'முழு தனிப்பட்ட வரலாற்றை அச்சிடுக','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ப க் க ம்','BD'=>'பிறந்த நாள்','GN'=>'பாலினம்','HT'=>'உயரம்','HR'=>'முடி','LE'=>'இடது கண்','RE'=>'வலது கண்','SF'=>'சிறப்பு அம்சங்கள்','ML'=>'ஆண்','FM'=>'பெண்','OT'=>'மற்றவை','PA'=>'கட்டணம்','NO'=>'பரிவர்த்தனைகள் எதுவும் இல்லை!',
    'HO'=>'மணிநேரம்','RD'=>'குறைப்பு','CB'=>'தற்போதைய இருப்பு','1PHR'=>'1ᕫ/மணிநேரம்','SO'=>'ஒற்றுமை','NB'=>'புதிய இருப்பு','NM'=>'பெயர்','ACC'=>'கணக்கு','ST'=>'தொடக்கம்',
    'M_1'=>'ஜன','M_2'=>'பிப்','M_3'=>'மார்','M_4'=>'ஏப்','M_5'=>'மே','M_6'=>'ஜூன்','M_7'=>'ஜூலை','M_8'=>'ஆக','M_9'=>'செப்','M_10'=>'அக்','M_11'=>'நவ','M_12'=>'டிச',
    'HC_0'=>'வெள்ளை','HC_1'=>'இஞ்சி சிவப்பு','HC_2'=>'அபர்ன்','HC_3'=>'பொன்னிறம்','HC_4'=>'வெளிர் செஸ்ட்நட்','HC_5'=>'செம்பு','HC_6'=>'வெளிர் பொன்னிறம்','HC_7'=>'செஸ்ட்நட் பிரவுன்','HC_8'=>'வெள்ளி','HC_9'=>'நடுத்தர பொன்னிறம்','HC_10'=>'வெளிர் பழுப்பு','HC_11'=>'டைட்டன்','HC_12'=>'கரும் பொன்னிறம்','HC_13'=>'நடுத்தர பழுப்பு','HC_14'=>'சாம்பல்','HC_15'=>'தங்க பொன்னிறம்','HC_16'=>'கரும் பழுப்பு','HC_17'=>'கருப்பு','HC_18'=>'ஸ்ட்ராபெரி பொன்னிறம்','HC_19'=>'முடி இல்லை',
    'EC_0'=>'ஆம்பர்','EC_1'=>'நீலம்','EC_2'=>'பழுப்பு','EC_3'=>'சாம்பல்','EC_4'=>'பச்சை','EC_5'=>'ஹேசல்','EC_6'=>'சிவப்பு','EC_7'=>'நீல சாம்பல்','EC_8'=>'நீல பச்சை','EC_9'=>'பச்சை சாம்பல்','EC_10'=>'பச்சை பழுப்பு','EC_11'=>'செயற்கை கண்'
),

// th - Thai
'th' => array(
    'TITLE'=>'มั่งเศรษฐา เงิน','OVERVIEW'=>'ภาพรวมการทำธุรกรรม','FROM'=>'จาก','TO'=>'ถึง','ALL'=>'พิมพ์ธุรกรรมทั้งหมด','HIST'=>'พิมพ์ประวัติส่วนตัวทั้งหมด','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ห น้ า','BD'=>'วันเกิด','GN'=>'เพศ','HT'=>'ส่วนสูง','HR'=>'เส้นผม','LE'=>'ตาซ้าย','RE'=>'ตาขวา','SF'=>'ลักษณะพิเศษ','ML'=>'ชาย','FM'=>'หญิง','OT'=>'อื่นๆ','PA'=>'การชำระเงิน','NO'=>'ไม่พบรายการธุรกรรม!',
    'HO'=>'ชั่วโมง','RD'=>'การลดทอน','CB'=>'ยอดคงเหลือปัจจุบัน','1PHR'=>'1ᕫ/ชั่วโมง','SO'=>'ความเป็นน้ำหนึ่งใจเดียวกัน','NB'=>'ยอดคงเหลือใหม่','NM'=>'ชื่อ','ACC'=>'บัญชี','ST'=>'เริ่มต้น',
    'M_1'=>'ม.ค.','M_2'=>'ก.พ.','M_3'=>'มี.ค.','M_4'=>'เม.ย.','M_5'=>'พ.ค.','M_6'=>'มิ.ย.','M_7'=>'ก.ค.','M_8'=>'ส.ค.','M_9'=>'ก.ย.','M_10'=>'ต.ค.','M_11'=>'พ.ย.','M_12'=>'ธ.ค.',
    'HC_0'=>'ขาว','HC_1'=>'แดงขิง','HC_2'=>'น้ำตาลแดง','HC_3'=>'บลอนด์','HC_4'=>'น้ำตาลอ่อน','HC_5'=>'ทองแดง','HC_6'=>'บลอนด์อ่อน','HC_7'=>'น้ำตาลเข้ม','HC_8'=>'เงิน','HC_9'=>'บลอนด์กลาง','HC_10'=>'น้ำตาล','HC_11'=>'ไทเทเนียม','HC_12'=>'บลอนด์เข้ม','HC_13'=>'น้ำตาลกลาง','HC_14'=>'เทา','HC_15'=>'บลอนด์ทอง','HC_16'=>'น้ำตาลดำ','HC_17'=>'ดำ','HC_18'=>'บลอนด์สตรอเบอร์รี่','HC_19'=>'ไม่มีผม',
    'EC_0'=>'อำพัน','EC_1'=>'น้ำเงิน','EC_2'=>'น้ำตาล','EC_3'=>'เทา','EC_4'=>'เขียว','EC_5'=>'เฮเซล','EC_6'=>'แดง','EC_7'=>'เทาน้ำเงิน','EC_8'=>'เขียวน้ำเงิน','EC_9'=>'เทาเขียว','EC_10'=>'น้ำตาลเขียว','EC_11'=>'ตาปลอม'
),

// vi - Vietnamese
'vi' => array(
    'TITLE'=>'Đới-kinhtế Tiền','OVERVIEW'=>'Tổng quan giao dịch','FROM'=>'Từ','TO'=>'Đến','ALL'=>'In tất cả giao dịch','HIST'=>'In toàn bộ lịch sử cá nhân','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;T R A N G','BD'=>'Ngày sinh','GN'=>'Giới tính','HT'=>'Chiều cao','HR'=>'Tóc','LE'=>'Mắt trái','RE'=>'Mắt phải','SF'=>'Đặc điểm đặc biệt','ML'=>'Nam','FM'=>'Nữ','OT'=>'Khác','PA'=>'Thanh toán','NO'=>'Không tìm thấy giao dịch!',
    'HO'=>'Giờ','RD'=>'Khấu trừ','CB'=>'Số dư hiện tại','1PHR'=>'1ᕫ/Giờ','SO'=>'Đoàn kết','NB'=>'Số dư mới','NM'=>'Tên','ACC'=>'Tài khoản','ST'=>'Bắt đầu',
    'M_1'=>'Th1','M_2'=>'Th2','M_3'=>'Th3','M_4'=>'Th4','M_5'=>'Th5','M_6'=>'Th6','M_7'=>'Th7','M_8'=>'Th8','M_9'=>'Th9','M_10'=>'Th10','M_11'=>'Th11','M_12'=>'Th12',
    'HC_0'=>'Trắng','HC_1'=>'Đỏ gừng','HC_2'=>'Nâu đỏ','HC_3'=>'Vàng hoe','HC_4'=>'Hạt dẻ sáng','HC_5'=>'Đồng','HC_6'=>'Vàng hoe sáng','HC_7'=>'Nâu hạt dẻ','HC_8'=>'Bạc','HC_9'=>'Vàng hoe vừa','HC_10'=>'Nâu sáng','HC_11'=>'Titan','HC_12'=>'Vàng hoe đậm','HC_13'=>'Nâu vừa','HC_14'=>'Xám','HC_15'=>'Vàng ánh kim','HC_16'=>'Nâu đậm','HC_17'=>'Đen','HC_18'=>'Vàng dâu tây','HC_19'=>'Không có tóc',
    'EC_0'=>'Hổ phách','EC_1'=>'Xanh dương','EC_2'=>'Nâu','EC_3'=>'Xám','EC_4'=>'Xanh lá','EC_5'=>'Hạt dẻ','EC_6'=>'Đỏ','EC_7'=>'Xanh xám','EC_8'=>'Xanh lục','EC_9'=>'Xanh xám lục','EC_10'=>'Nâu lục','EC_11'=>'Mắt giả'
),

// tu - Turkish
'tu' => array(
    'TITLE'=>'Bolnomi Para','OVERVIEW'=>'İşlem Özeti','FROM'=>'Başlangıç','TO'=>'Bitiş','ALL'=>'Tüm işlemleri yazdır','HIST'=>'Tüm kişisel geçmişi yazdır','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;S A Y F A','BD'=>'Doğum Günü','GN'=>'Cinsiyet','HT'=>'Boy','HR'=>'Saç','LE'=>'Sol Göz','RE'=>'Sağ Göz','SF'=>'Özel Özellikler','ML'=>'Erkek','FM'=>'Kadın','OT'=>'Diğer','PA'=>'Ödeme','NO'=>'İşlem Bulunamadı!',
    'HO'=>'Saat','RD'=>'İndirim','CB'=>'Güncel bakiye','1PHR'=>'1ᕫ/Saat','SO'=>'Dayanışma','NB'=>'Yeni Bakiye','NM'=>'İsim','ACC'=>'Hesap','ST'=>'Başlangıç',
    'M_1'=>'Oca','M_2'=>'Şub','M_3'=>'Mar','M_4'=>'Nis','M_5'=>'May','M_6'=>'Haz','M_7'=>'Tem','M_8'=>'Ağu','M_9'=>'Eyl','M_10'=>'Eki','M_11'=>'Kas','M_12'=>'Ara',
    'HC_0'=>'Beyaz','HC_1'=>'Zencefil Rengi','HC_2'=>'Kızıl Kahve','HC_3'=>'Sarışın','HC_4'=>'Açık Kestane','HC_5'=>'Bakır','HC_6'=>'Açık Sarı','HC_7'=>'Kestane Kahverengi','HC_8'=>'Gümüş','HC_9'=>'Orta Sarı','HC_10'=>'Açık Kahverengi','HC_11'=>'Titan','HC_12'=>'Koyu Sarı','HC_13'=>'Orta Kahverengi','HC_14'=>'Gri','HC_15'=>'Altın Sarısı','HC_16'=>'Koyu Kahverengi','HC_17'=>'Siyah','HC_18'=>'Çilek Sarısı','HC_19'=>'Saçsız',
    'EC_0'=>'Kehribar','EC_1'=>'Mavi','EC_2'=>'Kahverengi','EC_3'=>'Gri','EC_4'=>'Yeşil','EC_5'=>'Ela','EC_6'=>'Kırmızı','EC_7'=>'Mavi Gri','EC_8'=>'Mavi Yeşil','EC_9'=>'Yeşil Gri','EC_10'=>'Yeşil Kahve','EC_11'=>'Protez'
),

// ur - Urdu
'ur' => array(
    'TITLE'=>'فرامعیشت رقم','OVERVIEW'=>'لین دین کا جائزہ','FROM'=>'سے','TO'=>'تک','ALL'=>'تمام لین دین پرنٹ کریں','HIST'=>'مکمل ذاتی تاریخ پرنٹ کریں','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;ص ف ح ہ','BD'=>'تاریخ پیدائش','GN'=>'جنس','HT'=>'قد','HR'=>'بال','LE'=>'بائیں آنکھ','RE'=>'دائیں آنکھ','SF'=>'خصوصیات','ML'=>'مرد','FM'=>'عورت','OT'=>'دیگر','PA'=>'ادائیگی','NO'=>'کوئی لین دین نہیں ملا!',
    'HO'=>'گھنٹے','RD'=>'کٹوتی','CB'=>'موجودہ بیلنس','1PHR'=>'۱ᕫ/گھنٹہ','SO'=>'یکجہتی','NB'=>'نیا بیلنس','NM'=>'نام','ACC'=>'اکاؤنٹ','ST'=>'آغاز',
    'M_1'=>'جنوری','M_2'=>'فروری','M_3'=>'مارچ','M_4'=>'اپریل','M_5'=>'مئی','M_6'=>'جون','M_7'=>'جولائی','M_8'=>'اگست','M_9'=>'ستمبر','M_10'=>'اکتوبر','M_11'=>'نومبر','M_12'=>'دسمبر',
    'HC_0'=>'سفید','HC_1'=>'ادرک سرخ','HC_2'=>'شاہ بلوطی سرخ','HC_3'=>'سنہرا','HC_4'=>'ہلکا شاہ بلوطی','HC_5'=>'تانبا','HC_6'=>'ہلکا سنہرا','HC_7'=>'شاہ بلوطی بھورا','HC_8'=>'چاندی','HC_9'=>'درمیانہ سنہرا','HC_10'=>'ہلکا بھورا','HC_11'=>'ٹائٹن','HC_12'=>'گہرا سنہرا','HC_13'=>'درمیانہ بھورا','HC_14'=>'سرمئی','HC_15'=>'سنہری مائل سنہرا','HC_16'=>'گہرا بھورا','HC_17'=>'کالا','HC_18'=>'سٹرابری سنہرا','HC_19'=>'بال نہیں',
    'EC_0'=>'عنبر','EC_1'=>'نیلا','EC_2'=>'بھورا','EC_3'=>'سرمئی','EC_4'=>'ہرا','EC_5'=>'ہیزل','EC_6'=>'سرخ','EC_7'=>'نیلا سرمئی','EC_8'=>'نیلا ہرا','EC_9'=>'ہرا سرمئی','EC_10'=>'ہرا بھورا','EC_11'=>'مصنوعی آنکھ'
),

// yo - Yoruba
'yo' => array(
    'TITLE'=>'Ọpọlowo Owo','OVERVIEW'=>'Akopọ Iṣowo','FROM'=>'Lati','TO'=>'Sii','ALL'=>'Tẹ gbogbo iṣowo jade','HIST'=>'Tẹ itan ara ẹni ni kikun jade','PAGE'=>'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;O J Ú&nbsp;&nbsp;E W É','BD'=>'Ọjọ ibi','GN'=>'Akọ tabi abo','HT'=>'Giga','HR'=>'Irun','LE'=>'Oju osi','RE'=>'Oju ọtun','SF'=>'Awọn ẹya pataki','ML'=>'Ọkunrin','FM'=>'Obinrin','OT'=>'Miran','PA'=>'Isanwo','NO'=>'A ko ri iṣowo kankan!',
    'HO'=>'Awọn wakati','RD'=>'Idinku','CB'=>'Iwontunwonsi lọwọlọwọ','1PHR'=>'1ᕫ/Wakati','SO'=>'Isokan','NB'=>'Iwontunwonsi Tuntun','NM'=>'Orukọ','ACC'=>'Akọọlẹ','ST'=>'Ibẹrẹ',
    'M_1'=>'Jan','M_2'=>'Feb','M_3'=>'Mar','M_4'=>'Apr','M_5'=>'May','M_6'=>'Jun','M_7'=>'Jul','M_8'=>'Aug','M_9'=>'Sep','M_10'=>'Oct','M_11'=>'Nov','M_12'=>'Dec',
    'HC_0'=>'Funfun','HC_1'=>'Pupa atalẹ','HC_2'=>'Brown pupa','HC_3'=>'Irun kọkan','HC_4'=>'Brown fẹẹrẹ','HC_5'=>'Ejò','HC_6'=>'Irun kọkan fẹẹrẹ','HC_7'=>'Brown dudu fẹẹrẹ','HC_8'=>'Fadaka','HC_9'=>'Irun kọkan alabọde','HC_10'=>'Brown fẹẹrẹ fẹẹrẹ','HC_11'=>'Titanium','HC_12'=>'Irun kọkan dudu','HC_13'=>'Brown alabọde','HC_14'=>'Eeru','HC_15'=>'Irun kọkan wura','HC_16'=>'Brown dudu','HC_17'=>'Dudu','HC_18'=>'Irun kọkan strawberry','HC_19'=>'Ko si irun',
    'EC_0'=>'Amber','EC_1'=>'Blue','EC_2'=>'Brown','EC_3'=>'Eeru','EC_4'=>'Green','EC_5'=>'Hazel','EC_6'=>'Pupa','EC_7'=>'Blue-eeru','EC_8'=>'Blue-green','EC_9'=>'Green-eeru','EC_10'=>'Green-brown','EC_11'=>'Oju atọwọda'
),

    );

    public static function get($key, $lang = 'en') {
        $set = isset(self::$data[$lang]) ? self::$data[$lang] : self::$data['en'];
        return isset($set[$key]) ? $set[$key] : (isset(self::$data['en'][$key]) ? self::$data['en'][$key] : $key);
    }
}

// 2. Data Collection (Ensure $L is defined from your DB query)
$viewId = (int)$_POST['user_id'];
// ... your existing DB query to get $L ...


// 8. PDF CLASS
class MYPDF extends TCPDF {
    public $userData;
    public $accNum;
    public $dateRange;
    public $imageChanged = false; // Make sure this is declared at the top of the class


    // --- Helper to determine the best font for a specific string ---
    private function getSmartFont($text, $baseFont = 'roboto') {
        // 1. Japanese (Hiragana/Katakana)
        if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text)) return 'cid0jp';
        // 2. Korean (Hangul)
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) return 'cid0kr';
        // 3. Chinese / Cantonese
        if (preg_match('/[\x{4E00}-\x{9FAF}]/u', $text)) return 'cid0cs';
        // 4. Lao (Using the installed Phetsarath font)
        if (preg_match('/[\x{0E80}-\x{0EFF}]/u', $text)) return 'phetsarath'; 
        // 5. Myanmar
        if (preg_match('/[\x{1000}-\x{109F}]/u', $text)) return 'padauk'; 
        // 6. Khmer
        if (preg_match('/[\x{1780}-\x{17FF}]/u', $text)) return 'hanuman';
        // 7. Global Unicode fallback
        if (preg_match('/[^\x00-\x7F]/u', $text)) return 'freeserif';
        
        return $baseFont;
    }




    public function Header() {
        global $L;

        // 1. Lines and Logo
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(0.8);
        $this->Line(15, 8, 195, 8);
        $this->SetLineWidth(0.2);
        $logo = '../img/1CoinH_140x140.png';
        if (file_exists($logo)) { $this->Image($logo, 15, 10, 20, 20, 'PNG'); }

        // 2. Prepare Strings
        $t1 = PDFTrans::get('TITLE', $L);
        $t2 = PDFTrans::get('OVERVIEW', $L);
        // Added (UTC+0) to the title string
        $title = $t1 . " " . $t2 . " (UTC+0)";
        $range = $this->dateRange;

        // 3. FONT DETECTION (Updated for Tamil)
        $hFont = 'roboto'; 
        if ($L == 'ch' || $L == 'ca') { $hFont = 'cid0cs'; }
        elseif ($L == 'ja') { $hFont = 'cid0jp'; }
        elseif ($L == 'ko') { $hFont = 'cid0kr'; }
        elseif ($L == 'bu') { $hFont = 'padauk'; }
        elseif ($L == 'kh') { $hFont = 'hanuman'; }
        elseif ($L == 'la') { $hFont = 'phetsarath'; }
        // Added 'ta' (Tamil) to the freeserif list
        elseif (in_array($L, ['ta','be','ah','ar','am','he','hi','np','pa','pe','th','ur'])) { 
            $hFont = 'freeserif'; 
        }

        // --- ROW 1: TITLE ---
        $this->SetY(10);
        $this->SetFont($hFont, 'B', 14, '', true); 
        $this->Cell(0, 7, $title, 0, 1, 'C');

        // --- ROW 2: DATE RANGE ---
        $this->SetFont($hFont, '', 10, '', true);
        $this->Cell(0, 5, $range, 0, 1, 'C');

        // --- ROW 3: USER INFO ---
        $st = strtotime($this->userData['start']);
        $monthName = PDFTrans::get('M_'.date("n", $st), $L); // Localized Tamil Month
        $hFullDate = date("d", $st).' '.$monthName.' '.date("Y H:i", $st);
        
        $this->SetY(23);
        $this->SetFont('roboto', '', 11, '', true);
        $this->Cell(45, 7, $this->accNum, 0, 0, 'R');
        
        // Name (Using smart font)
        $this->SetFont($hFont, 'B', 14.5, '', true);
        $this->Cell(80.5, 7, $this->userData['usersName'], 0, 0, 'C');
        
        // Join Date (Red) - Force hFont to render Tamil month names
        $this->SetTextColor(255, 0, 0); 
        $this->SetFont($hFont, '', 11, '', true); 
        $this->Cell(54.5, 7, $hFullDate, 0, 1, 'L');
        $this->SetTextColor(0, 0, 0); 

        // 4. IMAGE & BOTTOM LINE
        if (!empty($this->userData['image'])) {
            $imgStr = (strpos($this->userData['image'], ',') !== false) ? substr($this->userData['image'], strpos($this->userData['image'], ',') + 1) : $this->userData['image'];
            $this->StartTransform();
            $this->Circle(185, 20, 10, 0, 360, 'CNZ'); 
            $this->Image('@'.base64_decode($imgStr), 175, 10, 20, 20);
            $this->StopTransform();
        }
        $this->SetLineWidth(($this->getPage() > 1) ? 0.8 : 0.2);
        $this->Line(15, 32, 195, 32);
        $this->SetLineWidth(0.2);
    }


    public function Footer() {
        global $L;
        // 1. Top Footer Thick Line
        $this->SetLineWidth(0.8);
        $this->Line(15, 282, 195, 282);
        $this->SetLineWidth(0.2);

        $footerY = 283.5; 
        
        // 2. Prepare Data
        $mNum = date("n");
        $monthName = PDFTrans::get("M_$mNum", $L);
        $footerDate = date("d") . ' ' . $monthName . ' ' . date("Y H:i");
        
        // Clean the Page Label: remove &nbsp; and split into the "|" and the "Text"
        $rawPageLabel = PDFTrans::get('PAGE', $L);
        $cleanPageLabel = str_replace('&nbsp;', ' ', $rawPageLabel); // "   |   頁 碼"
        
        // 3. RENDER LEFT: Date (Smart Font)
        $dateFont = $this->getSmartFont($footerDate, 'roboto');
        $this->SetFont($dateFont, 'B', 11);
        $this->SetXY(15, $footerY);
        $this->Cell(60, 10, $footerDate, 0, 0, 'L');

        // 4. RENDER CENTER: Page Numbering (Precision Control)
        $centerX = 95; 
        
        // A. The Number (Always Roboto)
        $this->SetFont('roboto', 'B', 11);
        $this->SetXY($centerX - 10, $footerY); 
        $this->Cell(10, 10, $this->getAliasNumPage(), 0, 0, 'R');

        // B. The Translation (Smart Font Fix for Japanese)
        // Manual check for Japanese range (Hiragana/Katakana/Kanji)
        $pageFont = 'roboto';
        if ($L == 'ja' || preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]/u', $cleanPageLabel)) {
            $pageFont = 'cid0jp';
        } else {
            $pageFont = $this->getSmartFont($cleanPageLabel, 'roboto');
        }

        $this->SetFont($pageFont, 'B', 11);
        $this->SetXY($centerX, $footerY);
        $this->Cell(40, 10, $cleanPageLabel, 0, 0, 'L');

        // 5. RENDER RIGHT: URL (Native Cell with Link - Unchanged)
        $this->SetFont('roboto', 'B', 11);
        $this->SetXY(130, $footerY);
        $this->Cell(65, 10, 'www.abundomy.com', 0, 0, 'R', false, 'https://abundomy.com');
    }
}



//====================   B-SECTION OF THE HEADER ON PAGE 1      ========================================
function getProfileRow($pdf, $data, $prev, $L) {
    // 1. Color mapping for changes
    $c = ['bd'=>'#000','gn'=>'#000','ht'=>'#000','hr'=>'#000','le'=>'#000','re'=>'#000','sf'=>'#000'];
    
    if ($prev) {
        if (isset($prev['birthday']) && $data['birthday'] != $prev['birthday']) $c['bd'] = '#ff0000';
        if (isset($prev['gender']) && $data['gender'] != $prev['gender']) $c['gn'] = '#ff0000';
        if (isset($prev['height']) && $data['height'] != $prev['height']) $c['ht'] = '#ff0000';
        if (isset($prev['hair']) && $data['hair'] != $prev['hair']) $c['hr'] = '#ff0000';
        if (isset($prev['leftEye']) && $data['leftEye'] != $prev['leftEye']) $c['le'] = '#ff0000';
        if (isset($prev['rightEye']) && $data['rightEye'] != $prev['rightEye']) $c['re'] = '#ff0000';
        if (isset($prev['specialFeatures']) && $data['specialFeatures'] != $prev['specialFeatures']) $c['sf'] = '#ff0000';
    }

    // 2. Data Preparation
    $genderLabel = ($data['gender'] == 0) ? PDFTrans::get('ML', $L) : (($data['gender'] == 1) ? PDFTrans::get('FM', $L) : PDFTrans::get('OT', $L));
    
    // 3. Smart Font Detection
    $sampleLabel = PDFTrans::get('BD', $L);
    $labelFont = 'roboto'; 

    if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sampleLabel)) { 
        $labelFont = 'cid0jp'; // Japanese (Hiragana/Katakana)
    } elseif (preg_match('/[\x{AC00}-\x{D7AF}]/u', $sampleLabel)) { 
        $labelFont = 'cid0kr'; // Korean
    } elseif (preg_match('/[\x{4E00}-\x{9FAF}]/u', $sampleLabel)) { 
        $labelFont = 'cid0cs'; // Chinese/Cantonese
    } elseif (preg_match('/[\x{1000}-\x{109F}]/u', $sampleLabel)) { 
        $labelFont = 'padauk'; // Myanmar (Burmese)
    } elseif (preg_match('/[\x{1780}-\x{17FF}]/u', $sampleLabel)) { 
        $labelFont = 'hanuman'; // Khmer
    } elseif (preg_match('/[\x{0E80}-\x{0EFF}]/u', $sampleLabel)) { 
        $labelFont = 'phetsarath'; // Lao
    } elseif (preg_match('/[^\x00-\x7F]/u', $sampleLabel)) { 
        $labelFont = 'freeserif'; // General Unicode fallback
    }

    // --- FONT AND HEIGHT RATIO ---
    $pdf->SetFont($labelFont, '', 8.25);
    $pdf->setCellHeightRatio(1.6); 

    $sLab = 'font-size:8.25pt;'; 
    $sVal = 'font-size:10pt;';

    $hasFeatures = !empty(trim($data['specialFeatures'] ?? ''));
    $wasCleared = (!$hasFeatures && !empty($prev['specialFeatures']));

    // 4. Construct HTML (Removed all thin line border styles)
    $html = '<table border="0" cellpadding="0" style="line-height:1.6;">
        <tr>
            <td width="33.3%" align="left">
                <span style="'.$sLab.'">'.PDFTrans::get('BD',$L).':</span> <span style="'.$sVal.'">'.autoFont($data['birthday'], $c['bd']).'</span>
            </td>
            <td width="33.3%" align="left">
                <span style="'.$sLab.'">'.PDFTrans::get('GN',$L).':</span> <span style="'.$sVal.'">'.autoFont($genderLabel, $c['gn']).'</span>
            </td>
            <td width="33.4%" align="left">
                <span style="'.$sLab.'">'.PDFTrans::get('HT',$L).':</span> <span style="'.$sVal.'">'.autoFont($data['height'], $c['ht']).'</span>
            </td>
        </tr>
        <tr>
            <td width="33.3%" align="left">
                <span style="'.$sLab.'">'.PDFTrans::get('HR',$L).':</span> <span style="'.$sVal.'">'.autoFont(PDFTrans::get('HC_'.$data['hair'], $L), $c['hr']).'</span>
            </td>
            <td width="33.3%" align="left">
                <span style="'.$sLab.'">'.PDFTrans::get('LE',$L).':</span> <span style="'.$sVal.'">'.autoFont(PDFTrans::get('EC_'.$data['leftEye'], $L), $c['le']).'</span>
            </td>
            <td width="33.4%" align="left">
                <span style="'.$sLab.'">'.PDFTrans::get('RE',$L).':</span> <span style="'.$sVal.'">'.autoFont(PDFTrans::get('EC_'.$data['rightEye'], $L), $c['re']).'</span>
            </td>
        </tr>';

    if ($hasFeatures) {
        $html .= '<tr><td width="100%" align="left" style="padding-top:2px;">
            <span style="'.$sLab.'">'.PDFTrans::get('SF',$L).':</span> <span style="'.$sVal.'">'.autoFont($data['specialFeatures'], $c['sf']).'</span>
        </td></tr>';
    } elseif ($wasCleared) {
        $html .= '<tr><td width="100%" align="left" style="padding-top:2px;">
            <span style="'.$sLab.'">'.PDFTrans::get('SF',$L).':</span> <span style="'.$sVal.'; color:#ff0000;">( ! )</span>
        </td></tr>';
    }

    $html .= '</table>';
    return $html;
}


// Execution Start
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// --- THE TRUE HELVETICA KILLER ---
// 1. Determine which font the Header/Footer should use as a base
$baseHFont = 'roboto';
$testStr = PDFTrans::get('TITLE', $L);

if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $testStr)) { 
    $baseHFont = 'cid0jp'; // Japanese (Hiragana/Katakana)
} elseif (preg_match('/[\x{AC00}-\x{D7AF}]/u', $testStr)) { 
    $baseHFont = 'cid0kr'; // Korean
} elseif (preg_match('/[\x{4E00}-\x{9FAF}]/u', $testStr)) { 
    $baseHFont = 'cid0cs'; // Chinese/Cantonese
} elseif (preg_match('/[\x{1000}-\x{109F}]/u', $testStr)) { 
    $baseHFont = 'padauk'; // Myanmar
} elseif (preg_match('/[\x{1780}-\x{17FF}]/u', $testStr)) { 
    $baseHFont = 'hanuman'; // Khmer
} elseif (preg_match('/[\x{0E80}-\x{0EFF}]/u', $testStr)) { 
    $baseHFont = 'phetsarath'; // Lao
} elseif (preg_match('/[^\x00-\x7F]/u', $testStr)) { 
    $baseHFont = 'freeserif'; // General Unicode
}

// 2. Overwrite the default TCPDF Header/Footer font configurations
$pdf->setHeaderFont(array($baseHFont, 'B', 14));
$pdf->setFooterFont(array($baseHFont, '', 11));

// 3. Set standard global font
$pdf->SetFont($baseHFont, '', 10);
$pdf->setFontSubsetting(true); 


$pdf->userData = $curr;
$pdf->accNum = $formattedAcc;
$pdf->loc = $L; 


// Build Date Range
if ($downloadAll) {
    $pdf->dateRange = PDFTrans::get('ALL', $L);
} else {
    $pdf->dateRange = PDFTrans::get('FROM', $L) . " " . $dateFrom . "   " . PDFTrans::get('TO', $L) . " " . $dateTo;
}

// 1. Fetch History
$stmtO = $conn->prepare("SELECT * FROM users_old WHERE uid_old = ? ORDER BY usersOldId DESC");
$stmtO->bind_param("i", $viewId);
$stmtO->execute();
$history = $stmtO->get_result()->fetch_all(MYSQLI_ASSOC);

// 2. DEEP SCAN (Must happen before AddPage)
$prevMapped = null;
if (!empty($history)) {
    $fieldsToMap = [
        'birthday'=>'birthday_old',
        'gender'=>'gender_old',
        'height'=>'height_old',
        'hair'=>'hair_old',
        'leftEye'=>'leftEye_old',
        'rightEye'=>'rightEye_old',
        'specialFeatures'=>'specialFeatures_old',
        'image'=>'image_old'
    ];
    foreach ($fieldsToMap as $currKey => $oldKey) {
        foreach ($history as $hRow) {
            if (!is_null($hRow[$oldKey])) {
                $prevMapped[$currKey] = $hRow[$oldKey];
                break;
            }
        }
    }
}


// 3. Set the imageChanged flag FOR the Header
$pdf->imageChanged = false;
if (isset($prevMapped['image']) && $curr['image'] !== $prevMapped['image']) {
    $pdf->imageChanged = true;
}

// 4. NOW add the page (triggers Header)
$pdf->SetMargins(15, 32, 15);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(0);
$pdf->SetAutoPageBreak(TRUE, 25);
$pdf->AddPage();

// 5. Print Section B
$pdf->SetY(32.5); 
$htmlCur = getProfileRow($pdf, $curr, $prevMapped, $L);

$pdf->writeHTML($htmlCur, true, false, false, false, '');

// 6. Seal Section B
$currentY = $pdf->GetY() - 4; 
$pdf->SetLineWidth(0.8);
$pdf->Line(15, $currentY, 195, $currentY);
$pdf->SetLineWidth(0.2);


// =============================== 5. HISTORY SECTION (users_old) =====================================
if ($printHistory && !empty($history)) {
    $hArray = array_values($history);

    // Determine best font for labels in this language (Check one common label)
    $sampleLabelH = PDFTrans::get('NM', $L);
    $labelFontH = 'roboto'; 

    if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sampleLabelH)) { 
        $labelFontH = 'cid0jp';    // Japanese (Hiragana/Katakana)
    } elseif (preg_match('/[\x{AC00}-\x{D7AF}]/u', $sampleLabelH)) { 
        $labelFontH = 'cid0kr';    // Korean
    } elseif (preg_match('/[\x{4E00}-\x{9FAF}]/u', $sampleLabelH)) { 
        $labelFontH = 'cid0cs';    // Chinese/Cantonese
    } elseif (preg_match('/[\x{1000}-\x{109F}]/u', $sampleLabelH)) { 
        $labelFontH = 'padauk';    // Myanmar
    } elseif (preg_match('/[\x{1780}-\x{17FF}]/u', $sampleLabelH)) { 
        $labelFontH = 'hanuman';   // Khmer
    } elseif (preg_match('/[\x{0E80}-\x{0EFF}]/u', $sampleLabelH)) { 
        $labelFontH = 'phetsarath'; // Lao
    } elseif (preg_match('/[^\x00-\x7F]/u', $sampleLabelH)) { 
        $labelFontH = 'freeserif'; // General Unicode fallback
    }

    foreach ($hArray as $key => $row) {

        $fields = ['usersName_old','birthday_old','gender_old','height_old','hair_old','leftEye_old','rightEye_old','specialFeatures_old', 'image_old'];
        $nextOldestRow = $hArray[$key + 1] ?? null;
        $c = [];

        // 1. RED MARKING LOGIC
        $c['st'] = '#ff0000'; 
        foreach ($fields as $f) {
            $short = ($f == 'usersName_old') ? 'nm' : (($f == 'specialFeatures_old') ? 'sf' : (($f == 'image_old') ? 'img' : substr($f, 0, 2)));
            if (!$nextOldestRow) {
                $c[$short] = '#ff0000';
                $c['acc'] = '#ff0000';
            } else {
                $c['acc'] = '#000000';
                if (is_null($row[$f])) {
                    $c[$short] = '#000000';
                } else {
                    $realPrevValue = null;
                    for ($i = $key + 1; $i < count($hArray); $i++) {
                        if (!is_null($hArray[$i][$f])) {
                            $realPrevValue = $hArray[$i][$f];
                            break;
                        }
                    }
                    $c[$short] = ($row[$f] !== $realPrevValue) ? '#ff0000' : '#000000';
                }
            }
        }

        // 2. DISPLAY DATA LOGIC
        $display = [];
        foreach ($fields as $f) {
            $val = $row[$f];
            if (is_null($val)) {
                for ($i = $key + 1; $i < count($hArray); $i++) {
                    if (!is_null($hArray[$i][$f])) { $val = $hArray[$i][$f]; break; }
                }
            }
            $display[$f] = $val;
        }

        // 3. DATA PREP
        $sLab = 'font-size:8.25pt; font-family:'.$labelFontH.';'; 
        $sVal = 'font-size:10pt;';
        $stH  = strtotime($row['start_old']);
        $hDate = date("d", $stH).' '.PDFTrans::get('M_'.date("n",$stH), $L).' '.date("Y H:i",$stH);
        $genL  = ($display['gender_old']==0 ? PDFTrans::get('ML',$L) : ($display['gender_old']==1 ? PDFTrans::get('FM',$L) : PDFTrans::get('OT',$L)));
        
        $hasSF = !empty(trim($display['specialFeatures_old'] ?? ''));
        $wasSF_Cleared = ($c['sf'] == '#ff0000' && !$hasSF);
        $row2Border = 'border-bottom: 0.1pt solid black;';
        $row3Border = ($hasSF || $wasSF_Cleared) ? 'border-bottom: 0.1pt solid black;' : '';

        // 4. HTML CONSTRUCTION
        $htmlH = '<table border="0" cellpadding="2" style="line-height:1.2;">
            <tr>
                <td width="34%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('NM',$L).':</span> <span style="'.$sVal.'">'.autoFont($display['usersName_old'], $c['nm']).'</span></td>
                <td width="26%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('ACC',$L).':</span> <span style="'.$sVal.'">'.autoFont($formattedAcc, $c['acc']).'</span></td>
                <td width="27%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('ST',$L).':</span> <span style="'.$sVal.'">'.autoFont($hDate, $c['st']).'</span></td>
            </tr>
            <tr>
                <td width="34%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('BD',$L).':</span> <span style="'.$sVal.'">'.autoFont($display['birthday_old'], $c['bi']).'</span></td>
                <td width="26%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('GN',$L).':</span> <span style="'.$sVal.'">'.autoFont($genL, $c['ge']).'</span></td>
                <td width="27%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('HT',$L).':</span> <span style="'.$sVal.'">'.autoFont($display['height_old'], $c['he']).'</span></td>
            </tr>
            <tr>
                <td width="34%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('HR',$L).':</span> <span style="'.$sVal.'">'.autoFont(PDFTrans::get('HC_'.$display['hair_old'], $L), $c['ha']).'</span></td>
                <td width="26%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('LE',$L).':</span> <span style="'.$sVal.'">'.autoFont(PDFTrans::get('EC_'.$display['leftEye_old'], $L), $c['le']).'</span></td>
                <td width="27%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('RE',$L).':</span> <span style="'.$sVal.'">'.autoFont(PDFTrans::get('EC_'.$display['rightEye_old'], $L), $c['ri']).'</span></td>
            </tr>';
        
        if ($hasSF) {
            $htmlH .= '<tr><td width="100%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('SF',$L).':</span> <span style="'.$sVal.'">'.autoFont($display['specialFeatures_old'], $c['sf']).'</span></td></tr>';
        } elseif ($wasSF_Cleared) {
            $htmlH .= '<tr><td width="100%" align="left"><span style="'.$sLab.'">'.PDFTrans::get('SF',$L).':</span> <span style="'.$sVal.'; color:#ff0000;">( ! )</span></td></tr>';
        }
        $htmlH .= '</table>';

        // 5. RENDER
        if ($pdf->GetY() > 220) { $pdf->AddPage(); $pdf->SetY(42.5); } // Corrected to 42.5
        $startY = $pdf->GetY();
        $pdf->writeHTMLCell(156, 0, 15, $startY, $htmlH, 0, 1, false, true, 'L', true);
        
        if (!empty($display['image_old'])) {
            $imgRaw = $display['image_old'];
            if (strpos($imgRaw, ',') !== false) $imgRaw = substr($imgRaw, strpos($imgRaw, ',') + 1);
            if ($c['img'] == '#ff0000') {
                $pdf->SetDrawColor(255, 0, 0); $pdf->SetLineWidth(0.8);
                $pdf->Circle(185, $startY + 11, 10.5);
                $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.2);
            }
            $pdf->StartTransform();
            $pdf->Circle(185, $startY + 11, 10, 0, 360, 'CNZ');
            $pdf->Image('@'.base64_decode($imgRaw), 175, $startY + 1, 20, 20);
            $pdf->StopTransform();
        }

        $endY = $pdf->GetY();
        if (($endY - $startY) < 24) { $endY = $startY + 24; }
        $pdf->SetLineWidth(0.1);
        $pdf->Line(15, $endY, 195, $endY);
        $pdf->SetLineWidth(0.2);
        $pdf->SetY($endY);
    }

    // --- 6. PAGE BREAK LOGIC FOR TRANSACTIONS ---
    if ($printHistory && count($history) > 1) {
        $pdf->AddPage();
        $pdf->SetY(42.5); // Corrected to 42.5
    } elseif ($pdf->GetY() > 190) {
        $pdf->AddPage();
        $pdf->SetY(42.5); // Corrected to 42.5
    }
}


// --- 6. TRANSACTIONS SECTION ---

// 1. Setup Icons & Label Formatting
$unitPath = '../img/U_130x172.png';
$unitIcon = '<sub><img src="' . $unitPath . '" width="5.5" height="9" /></sub>';
$unitIconSmall = '<sub><img src="' . $unitPath . '" width="5" height="7.5" /></sub>';

// RTL Check - Only apply control characters if language is Hebrew, Arabic, etc.
$isRTL = in_array($L, ['he', 'ar', 'pe', 'ur']);
$lre   = $isRTL ? "\xE2\x80\xAA" : ""; 
$pdf_c = $isRTL ? "\xE2\x80\xAC" : ""; 

// 2. Smart Font Detection for Labels
$sampleLabel = PDFTrans::get('HO', $L);
$labelFont = 'roboto'; 

if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $sampleLabel)) { 
    $labelFont = 'cid0jp'; // Japanese (Hiragana/Katakana)
} elseif (preg_match('/[\x{AC00}-\x{D7AF}]/u', $sampleLabel)) { 
    $labelFont = 'cid0kr'; // Korean (Hangul)
} elseif (preg_match('/[\x{4E00}-\x{9FAF}]/u', $sampleLabel)) { 
    $labelFont = 'cid0cs'; // Chinese/Cantonese
} elseif (preg_match('/[\x{1000}-\x{109F}]/u', $sampleLabel)) { 
    $labelFont = 'padauk'; // Myanmar
} elseif (preg_match('/[\x{1780}-\x{17FF}]/u', $sampleLabel)) { 
    $labelFont = 'hanuman'; // Khmer
} elseif (preg_match('/[\x{0E80}-\x{0EFF}]/u', $sampleLabel)) { 
    $labelFont = 'phetsarath'; // Lao
} elseif (preg_match('/[^\x00-\x7F]/u', $sampleLabel)) { 
    $labelFont = 'freeserif'; // General Unicode fallback
}



// 3. Header Line - Dynamic Balance
// --- HEADER LINE: Section 6 ---
$pdf->Ln(5);
$pdf->SetFont($labelFont, 'B', 13.5);

// 1. Name and Balance
$headerName = str_replace('style="', 'style="font-weight:bold;', autoFont($curr['usersName']));
$formattedBalNum = '<span style="color:#0055aa; font-weight:bold;">+' . $lre . number_format($m_availablecoins, 2, '.', ' ') . '</span>';

// 2. THE FIX: 
// - If RTL: Add two spaces before the icon to create the 1mm right-margin gap.
// - Drop: Use <sub> to force the large icon down.
$iconGap = $isRTL ? '&nbsp;&nbsp;' : '&nbsp;'; 
$balIcon = $iconGap . '<sub style="line-height:0.5;"><img src="' . $unitPath . '" width="7.7" height="11.5" /></sub>';

$headerText = '<b>' . $headerName . '</b> &nbsp; &nbsp; &nbsp; ' . PDFTrans::get('CB', $L) . ': &nbsp;' . $formattedBalNum . $balIcon;

if ($isRTL) { $pdf->setRTL(true); }
$pdf->writeHTML($headerText, true, false, true, false, $isRTL ? 'R' : 'L');
if ($isRTL) { $pdf->setRTL(false); }
$pdf->Ln(4);



// 2. Fetch Transactions
$sqlT = "SELECT * FROM transactions WHERE (giver = ? OR receiver = ?) ";
if (!$downloadAll) { $sqlT .= " AND DATE(time_stamp) BETWEEN ? AND ? "; }
$sqlT .= " ORDER BY time_stamp DESC";
$stmtD = $conn->prepare($sqlT);
if ($downloadAll) { $stmtD->bind_param("ii", $viewId, $viewId); } 
else { $stmtD->bind_param("iiss", $viewId, $viewId, $dateFrom, $dateTo); }
$stmtD->execute();
$resD = $stmtD->get_result();

if ($resD->num_rows === 0) {
    $pdf->Ln(20); 
    $pdf->SetTextColor(255, 0, 0);
    
    // 1. Get the translation
    $noMsgRaw = PDFTrans::get('NO', $L);
    
    // 2. Only use uppercase for Latin/Cyrillic scripts to avoid breaking Unicode
    $noMsg = in_array($L, ['en','de','fr','it','es','pt']) ? mb_strtoupper($noMsgRaw, 'UTF-8') : $noMsgRaw;
    
    // 3. Wrap in autoFont to guarantee the correct font file is used
    $htmlNo = '<div style="text-align:center; font-size:22pt; font-weight:bold;">' . autoFont($noMsg, '#ff0000') . '</div>';
    
    $pdf->writeHTML($htmlNo, true, false, true, false, 'C');
    $pdf->SetTextColor(0, 0, 0); // Reset
} else {

    $pdf->setCellHeightRatio(1.0);
    $transactionsData = $resD->fetch_all(MYSQLI_ASSOC);
    $oldestInSelectionTS = null;

    // Bridge Calculation
    $newestTr = $transactionsData[0];
    $newestTS = $newestTr['time_stamp'];
    $printTS = date("Y-m-d H:i:s");
    $d1 = new DateTime($newestTS); $d2 = new DateTime($printTS); $diff = $d1->diff($d2);
    $bridgeHours = ($diff->days * 24) + $diff->h + ($diff->i / 60) + ($diff->s / 3600);
    $decayRate = 0.99995;
    $bridgeIncome = ($bridgeHours > 0) ? ($decayRate * (1 - pow($decayRate, $bridgeHours))) / (1 - $decayRate) : 0;
    
    $stateNewest = getPreviousTransactionState($conn, $viewId, $newestTS);
    $nIsG = ($newestTr['giver'] == $viewId);
    $newestBalanceVal = (float)$stateNewest['availableBalance'] + ($nIsG ? -(float)$newestTr['amount'] : (float)$newestTr['amount']);
    
    $sqlPost = "SELECT amount, giver FROM transactions WHERE (giver = ? OR receiver = ?) AND time_stamp > ?";
    $stPost = $conn->prepare($sqlPost); $stPost->bind_param("iis", $viewId, $viewId, $newestTS); $stPost->execute();
    $resPost = $stPost->get_result(); $postPaymentsSum = 0;
    while($pRow = $resPost->fetch_assoc()) { $postPaymentsSum += ($pRow['giver'] == $viewId) ? -(float)$pRow['amount'] : (float)$pRow['amount']; }
    
    $delta = $m_availablecoins - $newestBalanceVal;
    $bridgeSolidarity = $delta - $postPaymentsSum;

    // --- 1. ENSURE DATE IS DEFINED ---
    $printTS = date("Y-m-d H:i:s");
    $printFormatted = date("d", strtotime($printTS)) . ' ' . PDFTrans::get('M_'.date("n", strtotime($printTS)), $L) . ' ' . date("Y H:i:s", strtotime($printTS));

    // --- 2. BRIDGE RENDERING ---
    $sLab = 'font-size:8pt; font-family:'.$labelFont.';'; 
    $nbsp = '&nbsp;&nbsp;'; 
    $pushDown = '<div style="font-size:1.5pt;">&nbsp;</div>';

    $bridgeHtml = '
    <table border="0" cellpadding="1" style="line-height:0.95;">
        <tr>
            <td width="7%"></td>
            <!-- Check Column 2 Row 1: Fixed variables -->
            <td width="34%" align="left" style="font-size:9pt;">'.$pushDown.$formattedAcc.'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;'.$printFormatted.'</td>
            <td width="15%" align="right" style="font-size:10pt;">'.$lre.(int)$bridgeHours.$pdf_c.'</td>
            <td width="11%" align="left" style="'.$sLab.'">'.$pushDown.$nbsp.PDFTrans::get('HO', $L).'</td>
            <td width="17%" align="right" style="font-size:10pt;">'.fmt($bridgeSolidarity, $unitIcon).'</td>
            <td width="16%" align="left" style="'.$sLab.'">'.$pushDown.$nbsp.PDFTrans::get('SO', $L).'</td>
        </tr>
        <tr>
            <td width="7%"></td>
            <td width="34%"></td>
            <td width="15%" align="right" style="font-size:10pt;">'.fmt($bridgeIncome, $unitIcon).'</td>
            <td width="11%" align="left" style="'.$sLab.'">'.$nbsp.$onePhrFormatted.'</td>
            <td width="17%" align="right" style="font-size:10pt;">'.fmt($postPaymentsSum, $unitIcon).'</td>
            <td width="16%" align="left" style="'.$sLab.'">'.$nbsp.PDFTrans::get('PA', $L).'</td>
        </tr>
    </table>';



    if ($pdf->GetY() > 245) { $pdf->AddPage(); $pdf->SetY(42.5); }
    $pdf->writeHTML($bridgeHtml, true, false, false, false, '');
    // Adjust the Y-offset after printing to pull the next row even closer
    $pdf->SetY($pdf->GetY() - 2.5, true); 

    // 3. Loop selection
    foreach ($transactionsData as $tr) {
        $pdf->SetFont($labelFont, 'B', 12.5); 
        $oldestInSelectionTS = $tr['time_stamp'];
        $state = getPreviousTransactionState($conn, $viewId, $tr['time_stamp']);
        $isGiver = ($tr['giver'] == $viewId);
        $vPay = $isGiver ? -(float)$tr['amount'] : (float)$tr['amount'];
        $vBal = (float)$state['availableBalance'] + $vPay;
        $partnerId = $isGiver ? $tr['receiver'] : $tr['giver'];
        $stmtP = $conn->prepare("SELECT usersName, image FROM users WHERE usersId = ?");
        $stmtP->bind_param("i", $partnerId); $stmtP->execute();
        $pData = $stmtP->get_result()->fetch_assoc();
        $ts = strtotime($tr['time_stamp']);
        $dateFormatted = date("d", $ts) . ' ' . PDFTrans::get('M_'.date("n", $ts), $L) . ' ' . date("Y H:i:s", $ts);

        // 1. Manually determine the bold font file name for the User Name
        $nameRaw = $pData['usersName'] ?? 'Unknown';
        $nf = 'roboto'; 
        if (preg_match('/[\x{4E00}-\x{9FAF}]/u', $nameRaw)) { $nf = 'cid0cs'; }
        elseif (preg_match('/[\x{1000}-\x{109F}]/u', $nameRaw)) { $nf = 'padauk'; }
        elseif (preg_match('/[\x{1780}-\x{17FF}]/u', $nameRaw)) { $nf = 'hanuman'; }
        elseif (preg_match('/[\x{0E80}-\x{0EFF}]/u', $nameRaw)) { $nf = 'phetsarath'; }
        elseif (preg_match('/[^\x00-\x7F]/u', $nameRaw)) { $nf = 'freeserif'; }

        // Use the literal bold file (e.g. robotob) - CID fonts stay the same
        $fBold = (in_array($nf, ['cid0cs','cid0jp','cid0kr'])) ? $nf : $nf.'b';

        // 2. Build the row
        $htmlRow = '
        <table border="0" cellpadding="1" style="line-height:1.1;">
            <tr>
                <td width="7%" rowspan="3"></td>
                <td width="34%" align="left" style="font-size:9pt;">'.$pushDown.$formattedAcc.'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;'.$dateFormatted.'</td>
                <td width="15%" align="right" style="font-size:10pt;">'.$lre.(int)$state['hours'].$pdf_c.'</td>
                <td width="11%" align="left" style="font-size:8pt; font-family:'.$labelFont.';">'.$pushDown.$nbsp.PDFTrans::get('HO', $L).'</td>
                <td width="17%" align="right" style="font-size:10pt;">'.fmt((float)$state['income'] + (float)$state['reductionAmount'], $unitIcon).'</td>
                <td width="16%" align="left" style="font-size:8pt; font-family:'.$labelFont.';">'.$pushDown.$nbsp.PDFTrans::get('SO', $L).'</td>
            </tr>
            <tr>
                <!-- Row 2: Name (Standard weight, 12.5pt) -->
                <td width="34%" align="left" style="font-size:12.5pt; line-height:0.85;">'.autoFont($pData['usersName'] ?? 'Unknown').'</td>
                <td width="15%" align="right" style="font-size:10pt;">'.fmt((float)$state['reductionAmount'], $unitIcon).'</td>
                <td width="11%" align="left" style="font-size:8pt; font-family:'.$labelFont.';">'.$pushDown.$nbsp.PDFTrans::get('RD', $L).'</td>
                <td width="17%" align="right" style="font-size:10pt;">'.fmt($vPay, $unitIcon).'</td>
                <td width="16%" align="left" style="font-size:8pt; font-family:'.$labelFont.';">'.$pushDown.$nbsp.PDFTrans::get('PA', $L).'</td>
            </tr>
            <tr>
                <!-- Column 2, Row 3: Text reduced to 10pt, line-height adjusted for the larger emoji -->
                <td width="34%" align="left" style="font-size:10pt; color:#444; line-height:1.2;">'.autoFont($tr['description']).'</td>
                <td width="15%" align="right" style="font-size:10pt;">'.fmt((float)$state['income'], $unitIcon).'</td>
                <td width="11%" align="left" style="font-size:8pt; font-family:'.$labelFont.';">'.$pushDown.$nbsp.$onePhrFormatted.'</td>
                <td width="17%" align="right" style="font-size:10pt;"><b>'.fmt($vBal, $unitIcon, true).'</b></td>
                <td width="16%" align="left" style="font-size:8pt; font-family:'.$labelFont.';">'.$pushDown.$nbsp.PDFTrans::get('NB', $L).'</td>
            </tr>
        </table>';




        if ($pdf->GetY() > 245) { $pdf->AddPage(); $pdf->SetY(42.5); }
        $startY = $pdf->GetY() - 2.0; 
        $pdf->SetY($startY, true);
        $pdf->SetLineWidth(0.1); $pdf->Line(15, $startY, 195, $startY);
        $pdf->writeHTML($htmlRow, true, false, false, false, '');

        if (!empty($pData['image'])) {
            $pRaw = (strpos($pData['image'], ',') !== false) ? substr($pData['image'], strpos($pData['image'], ',') + 1) : $pData['image'];
            $pdf->StartTransform();
            $pdf->Circle(21.3, $startY + 8.0, 5, 0, 360, 'CNZ');
            $pdf->Image('@'.base64_decode($pRaw), 16.3, $startY + 3.0, 10, 10);
            $pdf->StopTransform();
        }
        $pdf->SetY($pdf->GetY() - 3.0); 
    }

    // --- Starting Balance ---
    $sqlP = "SELECT * FROM transactions WHERE (giver = ? OR receiver = ?) AND time_stamp < ? ORDER BY time_stamp DESC LIMIT 1";
    $stP = $conn->prepare($sqlP);
    $stP->bind_param("iis", $viewId, $viewId, $oldestInSelectionTS);
    $stP->execute();
    $prevRow = $stP->get_result()->fetch_assoc();

    if ($prevRow) {
        $prevState = getPreviousTransactionState($conn, $viewId, $prevRow['time_stamp']);
        $pIsG = ($prevRow['giver'] == $viewId);
        $old_balance = (float)$prevState['availableBalance'] + ($pIsG ? -(float)$prevRow['amount'] : (float)$prevRow['amount']);
        $start_timestamp = $prevRow['time_stamp'];
    } else {
        $old_balance = 1000.00;
        $start_timestamp = $trueJoinedDate; 
    }

    // --- 4. STARTING BALANCE ---
    $stTS = strtotime($start_timestamp);
    $startFormatted = date("d", $stTS) . ' ' . PDFTrans::get('M_'.date("n", $stTS), $L) . ' ' . date("Y H:i:s", $stTS);
    
    // 1. Icon Nudge: Kept at -0.5mm but increased dimensions to 6.1x9.0
    $startIcon = '&nbsp;&nbsp;<sub style="line-height:1.5;"><img src="' . $unitPath . '" width="6.1" height="9.0" style="vertical-align:-0.5mm;" /></sub>';
    
    $formattedOldBal = '<span style="color:#0055aa;">+' . $lre . number_format($old_balance, 2, '.', ' ') . '</span>' . $startIcon;

    $startRowHtml = '
    <table border="0" cellpadding="1" style="line-height:1.3;">
        <tr>
            <td width="7%"></td>
            <td width="34%" align="left" style="font-size:9pt;"><div style="font-size:3pt;">&nbsp;</div>'.$formattedAcc.'&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;'.$startFormatted.'</td>
            <td width="15%"></td>
            <td width="11%"></td>
            <td width="17%" align="right" style="font-size:10pt; padding-top:1mm;"><b>'.$formattedOldBal.'</b></td>
            
            <!-- Col 6: Label lift -->
            <td width="16%" align="left" style="font-size:8pt; font-family:'.$labelFont.';"><div style="font-size:1pt;">&nbsp;</div>'.$nbsp.PDFTrans::get('ST', $L).'</td>
        </tr>
    </table>';

    $startY = $pdf->GetY() - 2.0; 
    $pdf->SetY($startY, true);
    $pdf->SetLineWidth(0.1); 
    $pdf->Line(15, $startY, 195, $startY);
    $pdf->writeHTML($startRowHtml, true, false, false, false, '');

}

// --- FIX FOR IPHONE "-1 BYTE" ERROR ---

// 1. Clear any accidental whitespace or PHP warnings in the buffer
if (ob_get_length()) ob_end_clean();

// 2. Generate the PDF as a string to calculate length
$pdfData = $pdf->Output('', 'S');
$pdfLen  = strlen($pdfData);
$filename = str_pad($viewId, 10, "0", STR_PAD_LEFT) . '.pdf';

// 3. Set explicit headers so mobile browsers don't get confused
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $pdfLen);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// 4. Output the data and stop execution
echo $pdfData;
exit();