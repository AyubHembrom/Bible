<?php
// song_query.php
// Put this file on your PHP server (same folder as songs.json if you use that).
// Usage examples:
//  https://yourdomain/song_query.php?q=3%20Praise%20the%20Lord
//  https://yourdomain/song_query.php?q=3
//  https://yourdomain/song_query.php?q=Praise%20the%20Lord
//  https://yourdomain/song_query.php?sno=1

header('Content-Type: application/json; charset=utf-8');
// Allow Kodular app / browser requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // preflight
    http_response_code(204);
    exit;
}

// --- Helpers ---
function normalize_str($s) {
    if ($s === null) return '';
    // remove HTML tags
    $s = strip_tags($s);
    // convert to UTF-8 safe lower-case
    $s = mb_strtolower($s, 'UTF-8');
    // remove punctuation but keep letters/numbers/space (unicode-aware)
    // replace anything not letter/number/space by space
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    // collapse whitespace
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

// Try load songs.json (if present), else use embedded default list
$songs = [];
$jsonFile = __DIR__ . '/songs.json';
if (is_readable($jsonFile)) {
    $raw = file_get_contents($jsonFile);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $songs = $decoded;
    }
}
if (empty($songs)) {
    // fallback embedded data (you can expand this or keep songs.json)
    $songs = [
        [
            "s_no" => 1,
            "song_name" => "Amazing Grace",
            "language" => "English",
            "song" => "<div><h3>Amazing Grace</h3><p>Amazing grace! How sweet the sound<br>That saved a wretch like me!</p></div>"
        ],
        [
            "s_no" => 2,
            "song_name" => "Yeshu Mera Sahara",
            "language" => "Hindi",
            "song" => "<div><h3>यीशु मेरा सहारा</h3><p>यीशु मेरा सहारा है<br>वह मेरा रखवाला है</p></div>"
        ],
        [
            "s_no" => 3,
            "song_name" => "Praise the Lord",
            "language" => "English",
            "song" => "<div><h3>Praise the Lord</h3><p>Praise the Lord, praise the Lord<br>Let the earth hear His voice</p></div>"
        ],
        [
            "s_no" => 4,
            "song_name" => "Prabhu Tera Dhanyavaad",
            "language" => "Hindi",
            "song" => "<div><h3>प्रभु तेरा धन्यवाद</h3><p>प्रभु तेरा धन्यवाद करूँ<br>तेरी स्तुति सदा मैं गाऊँ</p></div>"
        ]
    ];
}

// --- Read query parameters ---
$q_raw = null;
if (!empty($_GET['q'])) {
    $q_raw = trim($_GET['q']);
} elseif (!empty($_GET['query'])) {
    $q_raw = trim($_GET['query']);
}
$sno_param = null;
if (isset($_GET['sno'])) {
    $sno_param = $_GET['sno'];
} elseif (isset($_GET['id'])) {
    $sno_param = $_GET['id'];
}

// Combine inputs: prefer sno param if present
$combined = '';
if ($sno_param !== null && $q_raw === null) {
    $combined = (string)$sno_param;
} elseif ($sno_param !== null && $q_raw !== null) {
    $combined = $sno_param . ' ' . $q_raw;
} elseif ($q_raw !== null) {
    $combined = $q_raw;
} else {
    $combined = '';
}

// If nothing provided, return the entire list (or you may prefer to return error)
$parsed = ["raw" => $combined, "number" => null, "text" => ""];
// detect number token (integer) anywhere in combined text
if ($combined !== '') {
    // collapse spaces
    $clean = preg_replace('/\s+/u', ' ', trim($combined));
    $tokens = preg_split('/\s+/u', $clean);
    $number = null;
    $textParts = [];
    // if first token is integer
    if (preg_match('/^-?\d+$/', $tokens[0])) {
        $number = intval($tokens[0]);
        $textParts = array_slice($tokens, 1);
    } else {
        // find integer token anywhere
        $idx = null;
        foreach ($tokens as $i => $tk) {
            if (preg_match('/^-?\d+$/', $tk)) { $idx = $i; break; }
        }
        if ($idx !== null) {
            $number = intval($tokens[$idx]);
            // remove that index
            foreach ($tokens as $i => $tk) {
                if ($i === $idx) continue;
                $textParts[] = $tk;
            }
        } else {
            $textParts = $tokens;
        }
    }
    $parsed['number'] = $number === null ? null : $number;
    $parsed['text'] = trim(implode(' ', $textParts));
}

// Matching function
function find_matches($songs, $parsed) {
    $normText = normalize_str($parsed['text']);
    // 1) exact s_no + exact normalized name
    if ($parsed['number'] !== null && $normText !== '') {
        $matches = array_filter($songs, function($s) use ($parsed, $normText) {
            return (intval($s['s_no']) === intval($parsed['number'])) && (normalize_str($s['song_name']) === $normText);
        });
        if (count($matches)) return ['reason'=>'exact s_no + name', 'matches'=>array_values($matches)];
    }
    // 2) s_no match
    if ($parsed['number'] !== null) {
        $matches = array_filter($songs, function($s) use ($parsed) {
            return intval($s['s_no']) === intval($parsed['number']);
        });
        if (count($matches)) return ['reason'=>'s_no match', 'matches'=>array_values($matches)];
    }
    // 3) exact normalized name
    if ($normText !== '') {
        $matches = array_filter($songs, function($s) use ($normText) {
            return normalize_str($s['song_name']) === $normText;
        });
        if (count($matches)) return ['reason'=>'exact name', 'matches'=>array_values($matches)];
        // 4) substring in name
        $matches = array_filter($songs, function($s) use ($normText) {
            return strpos(normalize_str($s['song_name']), $normText) !== false;
        });
        if (count($matches)) return ['reason'=>'name substring', 'matches'=>array_values($matches)];
        // 5) inside lyrics/content
        $matches = array_filter($songs, function($s) use ($normText) {
            return strpos(normalize_str($s['song']), $normText) !== false;
        });
        if (count($matches)) return ['reason'=>'lyrics contains', 'matches'=>array_values($matches)];
    }
    // 6) no input -> return all
    if ($parsed['number'] === null && $normText === '') {
        return ['reason'=>'all', 'matches'=>array_values($songs)];
    }
    // none found
    return ['reason'=>'none', 'matches'=>[]];
}

$result = find_matches($songs, $parsed);

// Build response
$response = [
    'request' => [
        'original_querystring' => $_SERVER['QUERY_STRING'] ?? '',
        'used_query' => $parsed['raw'],
        'parsed' => $parsed
    ],
    'reason' => $result['reason'],
    'matches' => $result['matches']
];

// Output JSON (pretty, unicode preserved)
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;