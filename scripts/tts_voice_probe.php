<?php
/**
 * TTS voice probe — what does OUR key actually offer, and how does each voice
 * really behave?
 *
 *   php scripts/tts_voice_probe.php                 # en-IN flight, synthesized
 *   php scripts/tts_voice_probe.php --list          # enumerate only, no spend
 *   php scripts/tts_voice_probe.php --lang=en-IN,en-US
 *   php scripts/tts_voice_probe.php --max=2         # candidates per family
 *   php scripts/tts_voice_probe.php --all-genders   # not just FEMALE
 *
 * Written 19 Aug 2026, after the client's verdict on Aisha's voice: "just the
 * accent is different, no emotions, nothing... it's like the agent is reading
 * a paragraph, not talking." We had only ever auditioned four Neural2 voices,
 * because that is what we happened to know about.
 *
 * This is the same move as scripts/gemini_model_probe.php: stop reasoning from
 * blog posts and ask the live endpoint. It answers four questions that the
 * documentation alone cannot settle for THIS key:
 *
 *   1. Which voices exist for our languages (Chirp3-HD? Studio? Neural2?).
 *   2. Which of them our key is actually allowed to synthesize.
 *   3. Which accept SSML — TtsService::supportsSsml() currently assumes the
 *      Chirp families do not, and an assumption in code is a bug waiting.
 *   4. Which accept the pitch knob, for the same reason.
 *
 * Every candidate is spoken the SAME warm line, so the flight can be judged by
 * ear the way the last one was. MP3s land in the normal TTS cache with normal
 * hash names, so they are listenable at /buddy/tts/<file> while logged in.
 *
 * Cost: ~120 characters per synthesis. A full default flight is well under
 * 5,000 characters — cents, even at Studio rates. --list spends nothing.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Mirrors TtsService::apiKey() — dedicated key wins, Vertex key is the fallback.
$key = ($_ENV['GOOGLE_TTS_API_KEY'] ?? '') ?: ($_ENV['VERTEX_API_KEY'] ?? '');
if ($key === '') {
    fwrite(STDERR, "No GOOGLE_TTS_API_KEY or VERTEX_API_KEY in .env\n");
    exit(1);
}

// ── args ────────────────────────────────────────────────────────────────────
$argvv       = array_slice($argv, 1);
$listOnly    = in_array('--list', $argvv, true);
$allGenders  = in_array('--all-genders', $argvv, true);
$langs       = ['en-IN'];
$perFamily   = 3;
foreach ($argvv as $a) {
    if (str_starts_with($a, '--lang=')) {
        $langs = array_values(array_filter(array_map('trim', explode(',', substr($a, 7)))));
    }
    if (str_starts_with($a, '--max=')) {
        $perFamily = max(1, min(8, (int) substr($a, 6)));
    }
}

/**
 * The audition line. Deliberately the NEW greeting shape (short, contracted,
 * one question) rather than the old four-paragraph block — a voice must be
 * judged on what it will actually be asked to say.
 */
$LINE = "Hey TJ! Fresh page today, so let's get one on the board. What kind of trips do you like selling most?";

$cacheDir = dirname(__DIR__) . '/storage/buddy/tts';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// ── 1. enumerate ────────────────────────────────────────────────────────────
function httpGet(string $url, string $key): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . $key],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, (string) $raw];
}

echo "=== 1. VOICES OUR KEY CAN SEE ===\n\n";

[$code, $raw] = httpGet('https://texttospeech.googleapis.com/v1/voices', $key);
if ($code !== 200) {
    $dec = json_decode($raw, true);
    fwrite(STDERR, "voices list failed HTTP {$code}: "
        . ($dec['error']['message'] ?? substr($raw, 0, 300)) . "\n");
    exit(1);
}

$all = json_decode($raw, true)['voices'] ?? [];
echo count($all) . " voices visible in total.\n\n";

/** family name from a voice id, e.g. en-IN-Chirp3-HD-Achernar → Chirp3-HD */
function familyOf(string $name): string
{
    $p = explode('-', $name);
    $rest = array_slice($p, 2);                      // drop the en-IN prefix
    if ($rest === []) {
        return 'Unknown';
    }
    if (strcasecmp($rest[0], 'Chirp3') === 0 && isset($rest[1])) {
        return 'Chirp3-' . $rest[1];                 // Chirp3-HD
    }
    return $rest[0];                                 // Neural2 | Studio | Wavenet | Standard | Chirp
}

$byLang = [];
foreach ($all as $v) {
    foreach ($v['languageCodes'] ?? [] as $lc) {
        $byLang[$lc][] = $v;
    }
}

$candidates = [];
foreach ($langs as $lang) {
    $voices = $byLang[$lang] ?? [];
    if ($voices === []) {
        echo "{$lang}: nothing offered.\n\n";
        continue;
    }

    $fams = [];
    foreach ($voices as $v) {
        $fams[familyOf($v['name'])][] = $v;
    }
    ksort($fams);

    echo "{$lang} — " . count($voices) . " voices across " . count($fams) . " families:\n";
    foreach ($fams as $fam => $list) {
        $names = array_map(fn($v) => $v['name'] . ' (' . substr($v['ssmlGender'] ?? '?', 0, 1) . ')', $list);
        echo sprintf("  %-14s %2d  %s\n", $fam, count($list), implode(', ', array_slice($names, 0, 8))
            . (count($names) > 8 ? ', …' : ''));
    }
    echo "\n";

    // Flight order: the families most likely to fix "no emotions" go first.
    $priority = ['Chirp3-HD', 'Chirp3', 'Chirp', 'Studio', 'Neural2', 'Wavenet', 'Standard'];
    foreach ($priority as $fam) {
        if (!isset($fams[$fam])) {
            continue;
        }
        $pool = $fams[$fam];
        if (!$allGenders) {
            $female = array_values(array_filter($pool, fn($v) => ($v['ssmlGender'] ?? '') === 'FEMALE'));
            $pool = $female ?: $pool;      // Chirp3 names are gender-neutral in the API
        }
        foreach (array_slice($pool, 0, $perFamily) as $v) {
            $candidates[] = ['name' => $v['name'], 'family' => $fam, 'lang' => $lang];
        }
    }
}

// Whatever .env is running right now goes first, so the flight always contains
// the incumbent to compare against.
$current = $_ENV['TTS_VOICE'] ?? 'en-IN-Neural2-D';
$haveCurrent = false;
foreach ($candidates as $c) {
    if ($c['name'] === $current) {
        $haveCurrent = true;
        break;
    }
}
$liveNames = array_map(fn($v) => $v['name'], $all);
echo "Current TTS_VOICE = {$current} — "
    . (in_array($current, $liveNames, true) ? "still offered by the API.\n" : "NOT in the live list!\n");
if (!$haveCurrent && in_array($current, $liveNames, true)) {
    array_unshift($candidates, ['name' => $current, 'family' => familyOf($current), 'lang' => substr($current, 0, 5)]);
}

if ($listOnly) {
    echo "\n--list: stopping before synthesis. " . count($candidates) . " candidates would have been flown.\n";
    exit(0);
}

// ── 2. fly them ─────────────────────────────────────────────────────────────
/**
 * One synthesis attempt. Returns the HTTP code, Google's verbatim error, the
 * byte count and the wall-clock milliseconds — the same four facts the model
 * probe reports, for the same reason.
 */
function synth(string $key, string $voice, string $body, bool $ssml, bool $pitch, string $cacheDir): array
{
    $audioConfig = ['audioEncoding' => 'MP3', 'speakingRate' => (float) ($_ENV['TTS_RATE'] ?? 1.0)];
    if ($pitch) {
        $audioConfig['pitch'] = (float) ($_ENV['TTS_PITCH'] ?? 0);
    }

    $payload = [
        'input'       => $ssml ? ['ssml' => $body] : ['text' => $body],
        'voice'       => ['languageCode' => substr($voice, 0, 5), 'name' => $voice],
        'audioConfig' => $audioConfig,
    ];

    $t0 = microtime(true);
    $ch = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($code !== 200) {
        $dec = json_decode((string) $raw, true);
        return ['ok' => false, 'code' => $code, 'ms' => $ms,
                'error' => substr($dec['error']['message'] ?? 'no response', 0, 160), 'file' => null];
    }

    $audio = base64_decode(json_decode((string) $raw, true)['audioContent'] ?? '', true);
    if ($audio === false || strlen($audio) < 200) {
        return ['ok' => false, 'code' => $code, 'ms' => $ms, 'error' => 'empty audioContent', 'file' => null];
    }

    // Hash-shaped name so TtsService::safeFile() will serve it at /buddy/tts/<file>.
    $file = md5($voice . '|' . ($ssml ? 'ssml' : 'txt') . '|' . ($pitch ? 'pitch' : 'nopitch') . '|' . $body) . '.mp3';
    @file_put_contents($cacheDir . '/' . $file, $audio);

    return ['ok' => true, 'code' => 200, 'ms' => $ms, 'error' => '', 'file' => $file, 'bytes' => strlen($audio)];
}

/** Same minimal SSML TtsService::toSsml() produces, so the probe tests reality. */
function ssmlOf(string $text): string
{
    $t = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/^([^,!?.]{1,30}[,!])\s+/u', '$1<break time="260ms"/> ', (string) $t, 1);
    $t = preg_replace('/([.!?])\s+/u', '$1<break time="380ms"/> ', (string) $t);

    return '<speak>' . $t . '</speak>';
}

echo "\n=== 2. FLIGHT — the same line, " . count($candidates) . " voices ===\n";
echo "Line: \"{$LINE}\"\n\n";
printf("%-30s %-11s %-22s %-22s %s\n", 'VOICE', 'FAMILY', 'PLAIN TEXT', 'SSML', 'PITCH');
echo str_repeat('-', 110) . "\n";

$results = [];
$chars   = 0;
foreach ($candidates as $c) {
    $plain = synth($key, $c['name'], $LINE, false, false, $cacheDir);
    $chars += mb_strlen($LINE);

    $ssml = ['ok' => false, 'error' => 'skipped (plain failed)', 'code' => 0, 'ms' => 0, 'file' => null];
    $pit  = ['ok' => false, 'error' => 'skipped', 'code' => 0, 'ms' => 0, 'file' => null];
    if ($plain['ok']) {
        $ssml  = synth($key, $c['name'], ssmlOf($LINE), true, false, $cacheDir);
        $chars += mb_strlen(ssmlOf($LINE));
        $pit    = synth($key, $c['name'], $LINE, false, true, $cacheDir);
        $chars += mb_strlen($LINE);
    }

    $fmt = fn(array $r) => $r['ok']
        ? sprintf('ok %5dms %5.1fkB', $r['ms'], ($r['bytes'] ?? 0) / 1024)
        : sprintf('HTTP %-3d %s', $r['code'], substr($r['error'], 0, 30));

    printf("%-30s %-11s %-22s %-22s %s\n",
        $c['name'], $c['family'], $fmt($plain), $fmt($ssml), $pit['ok'] ? 'ok' : 'NO');

    $results[] = ['c' => $c, 'plain' => $plain, 'ssml' => $ssml, 'pitch' => $pit];
}

// ── 3. what to do with it ───────────────────────────────────────────────────
echo "\n=== 3. LISTEN (logged in, same browser session) ===\n\n";
foreach ($results as $r) {
    if (!$r['plain']['ok']) {
        continue;
    }
    echo "  " . str_pad($r['c']['name'], 30) . "  plain  /buddy/tts/" . $r['plain']['file'] . "\n";
    if ($r['ssml']['ok']) {
        echo "  " . str_pad('', 30) . "  SSML   /buddy/tts/" . $r['ssml']['file'] . "\n";
    }
}

echo "\n=== 4. WHAT THIS PROVES ===\n\n";
$ssmlOk = $ssmlNo = [];
foreach ($results as $r) {
    if (!$r['plain']['ok']) {
        continue;
    }
    if ($r['ssml']['ok']) {
        $ssmlOk[] = $r['c']['family'];
    } else {
        $ssmlNo[] = $r['c']['family'];
    }
}
$ssmlOk = array_values(array_unique($ssmlOk));
$ssmlNo = array_values(array_unique($ssmlNo));
echo "SSML accepted by: " . ($ssmlOk ? implode(', ', $ssmlOk) : '(none)') . "\n";
echo "SSML rejected by: " . ($ssmlNo ? implode(', ', $ssmlNo) : '(none)') . "\n";
echo "\nTtsService::supportsSsml() assumes ONLY the Chirp families reject it.\n";
echo "If the two lines above disagree with that, fix the assumption in the code\n";
echo "(or set TTS_SSML=on|off) rather than leaving it to be rediscovered later.\n";

echo "\nTo adopt a voice, no deploy needed — .env, then a pull is not even required:\n";
echo "  TTS_VOICE=<name from column 1>\n";
echo "  TTS_RATE=0.96\n";
echo "  TTS_PITCH=-1        # ignored for Chirp families\n";
echo "  TTS_SSML=auto       # auto | on | off\n";
echo "\nCharacters spent this run: {$chars}\n";
