<?php
/**
 * Claude API Proxy - Feature Demonstration
 * Pasco School District - Community Engineering Project
 *
 * This proxy demonstrates key Claude API features:
 * - Multiple model support (Haiku, Sonnet, Opus via tier aliases)
 * - Auto-healing model resolution (fallback on deprecated models)
 * - Vision/multimodal capabilities
 * - System prompts
 * - Token tracking
 * - Per-student rate limiting
 * - School-hours throttling
 * - Conversation length cap
 */

// Buffer ALL output from here on, before a single header is set.
//
// The secrets files this script requires are separate PHP files; if one has so
// much as a newline after its closing tag, that whitespace is output the moment
// it is included — which makes PHP send the headers it has at that point and
// silently ignore every header() call afterwards. That is invisible on the JSON
// path (json_decode skips leading whitespace) but fatal to streaming: the
// Content-Type stays application/json while the body is Server-Sent Events, so
// the browser never recognises the stream.
//
// With a buffer open, nothing is sent until this script decides to send it, and
// the stray whitespace can simply be discarded.
ob_start();

// Always return JSON (even on errors)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

// Only allow POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Load API key from secrets file
// The secrets file is stored outside public_html for security
$accountRoot  = dirname($_SERVER['DOCUMENT_ROOT']);   // e.g. /home2/fikrttmy
$secretsDir   = $accountRoot . '/.secrets';
$secretsFile  = $secretsDir . '/claudekey.php';
$studentFile  = $secretsDir . '/student_roster.csv';
$smtpFile     = $secretsDir . '/smtp_credentials.php';  // shared with wheel3/coach6
define('ALERT_TO', 'jweisenfeld@psd1.org');

if (!is_readable($secretsFile)) {
    http_response_code(500);
    error_log("Secrets file not readable: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (secrets missing).']);
    exit;
}

$secrets = require $secretsFile;
$ANTHROPIC_API_KEY = $secrets['ANTHROPIC_API_KEY'] ?? null;

// Sessions directory (outside public_html, not web-accessible)
$sessionsDir = $accountRoot . '/.claude_sessions';

if (!$ANTHROPIC_API_KEY) {
    http_response_code(500);
    error_log("ANTHROPIC_API_KEY missing in secrets file: $secretsFile");
    echo json_encode(['error' => 'Server configuration error (API key missing).']);
    exit;
}

// How long to wait for a model to finish. Raising max_tokens to 8192 made the
// old 120s ceiling too tight: a full program from a slow provider can take
// longer than that, and GLM was returning "Operation timed out after 120001
// milliseconds with 0 bytes received" on exactly the kind of request this
// chatbot exists for. This proxy is non-streaming, so the entire answer must be
// generated before a single byte comes back — that wait is unavoidable without
// a streaming rewrite.
define('API_TIMEOUT_SECONDS', 240);

// Non-Anthropic ("external") providers — Z.AI (GLM), Moonshot (Kimi),
// DeepSeek, and xAI (Grok) — are all OpenAI-compatible endpoints, each keyed by its own
// secrets file. Keys are optional at boot; only required if a student
// actually picks that tier, so a missing/unreadable file just means that
// one tier is down.
$EXTERNAL_PROVIDERS = [
    'zai' => [
        'endpoint'    => 'https://api.z.ai/api/paas/v4/chat/completions',
        'secretsFile' => $secretsDir . '/zaikey.php',
        'secretKey'   => 'ZAI_API_KEY',
    ],
    'moonshot' => [
        'endpoint'    => 'https://api.moonshot.ai/v1/chat/completions',
        'secretsFile' => $secretsDir . '/kimikey.php',
        'secretKey'   => 'KIMI_API_KEY',
    ],
    'deepseek' => [
        'endpoint'    => 'https://api.deepseek.com/chat/completions',
        'secretsFile' => $secretsDir . '/deepseekkey.php',
        'secretKey'   => 'DEEPSEEK_API_KEY',
    ],
    // xAI's own docs now lead with the Responses API (POST /v1/responses), but
    // the OpenAI-compatible /v1/chat/completions route is still live (verified:
    // it answers 401 without a key, where an unknown route answers 404). This
    // proxy speaks chat/completions, so that is the one to point at.
    'xai' => [
        'endpoint'    => 'https://api.x.ai/v1/chat/completions',
        'secretsFile' => $secretsDir . '/grokkey.php',
        'secretKey'   => 'GROK_API_KEY',
    ],
];

$EXTERNAL_API_KEYS = [];
foreach ($EXTERNAL_PROVIDERS as $providerName => $providerInfo) {
    $EXTERNAL_API_KEYS[$providerName] = null;
    if (is_readable($providerInfo['secretsFile'])) {
        $providerSecrets = require $providerInfo['secretsFile'];
        $EXTERNAL_API_KEYS[$providerName] = $providerSecrets[$providerInfo['secretKey']] ?? null;
    }
}

// Read and validate JSON request body
$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

if (!is_array($requestData)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON in request body']);
    exit;
}

// ============================================
// LOGIN ROUTE
// ============================================
if (isset($requestData['action']) && $requestData['action'] === 'verify_login') {
    if (!is_readable($studentFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Roster file missing or not readable.']);
        exit;
    }
    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
    $found = false;
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2], $row[6]) &&
            trim($row[2]) === trim($requestData['student_id'] ?? '') &&
            trim($row[6]) === trim($requestData['password'] ?? '')) {
            $found = true;
            $studentName  = $row[9] ?? $row[2];
            $loginFreeHrs = strtolower(trim($row[10] ?? ''));
            $loginTopicLk = strtolower(trim($row[11] ?? ''));
            $isUnlimited  = ($loginFreeHrs === 'unlimited' && $loginTopicLk === 'unlimited');
            break;
        }
    }
    fclose($handle);
    if ($found) {
        // Create a server-side session token
        $token        = bin2hex(random_bytes(32));
        $passwordHash = hash('sha256', trim($requestData['password'] ?? ''));
        if (!is_dir($sessionsDir)) {
            @mkdir($sessionsDir, 0700, true);
        }
        $sessionFile = $sessionsDir . '/' . $token . '.json';
        file_put_contents($sessionFile, json_encode([
            'student_id'    => trim($requestData['student_id']),
            'password_hash' => $passwordHash,
            'created_at'    => time(),
            'last_used'     => time(),
        ]), LOCK_EX);
        echo json_encode([
            'success'       => true,
            'student_name'  => $studentName,
            'token'         => $token,
            'is_unlimited'  => $isUnlimited ?? false,
            // So the chat can show what's in force before the first message.
            '_restrictions' => buildRestrictionStatus([
                'free_hours' => $loginFreeHrs,
                'topic_lock' => $loginTopicLk,
            ]),
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials.']);
    }
    exit;
}

// ============================================
// VALIDATE SESSION ROUTE
// ============================================
if (isset($requestData['action']) && $requestData['action'] === 'validate_session') {
    $sid   = trim($requestData['student_id'] ?? '');
    $token = $requestData['session_token'] ?? '';
    if (validateSession($sessionsDir, $token, $sid, $studentFile)) {
        // Returning to a saved session (page reload) rebuilds the banner too.
        echo json_encode([
            'success'       => true,
            '_restrictions' => buildRestrictionStatus(getStudentRestrictions($studentFile, $sid)),
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Session invalid or expired.']);
    }
    exit;
}

if (!isset($requestData['model'], $requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: model, messages']);
    exit;
}

// Validate messages array
if (!is_array($requestData['messages']) || empty($requestData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Messages must be a non-empty array']);
    exit;
}

// --- Extract student ID early (needed for rate limiting) ---
$studentId = isset($requestData['student_id']) && is_string($requestData['student_id'])
    ? substr(trim($requestData['student_id']), 0, 50)
    : 'unknown';

// ============================================
// SESSION VALIDATION (every chat request)
// ============================================
$sessionToken = isset($requestData['session_token']) && is_string($requestData['session_token'])
    ? $requestData['session_token'] : '';
if (!validateSession($sessionsDir, $sessionToken, $studentId, $studentFile)) {
    http_response_code(401);
    echo json_encode(['error' => ['type' => 'auth_error', 'message' => 'Session expired or invalid. Please sign in again.']]);
    exit;
}

// ============================================
// PER-STUDENT RESTRICTIONS (FreeHours / TopicLock)
// Read fresh from roster on every request so teacher changes take effect immediately.
//
// Behavior:
//   No columns set          → any topic, any time (default)
//   TopicLock only          → topic restricted 24/7
//   FreeHours + TopicLock   → topic restricted all day EXCEPT inside the free window
//   FreeHours only          → no-op (nothing to lift)
// ============================================
$restrictions = getStudentRestrictions($studentFile, $studentId);

// Determine whether we are currently inside a free-topic window.
// When true, the TopicLock (if any) is lifted for this request.
$inFreeHours = false;
if ($restrictions['free_hours'] === 'unlimited') {
    $inFreeHours = true;  // bypass school-hours throttle and topic lock 24/7
} elseif ($restrictions['free_hours'] !== '') {
    $restrictTz   = new DateTimeZone('America/Los_Angeles');
    $restrictNow  = new DateTime('now', $restrictTz);
    $restrictMins = (int)$restrictNow->format('G') * 60 + (int)$restrictNow->format('i');
    $inFreeHours  = isWithinAllowedHours($restrictions['free_hours'], $restrictMins);
}

// ============================================
// RATE LIMITING (per-student, file-based)
// ============================================

$rateLimitDir = __DIR__ . '/rate_limits';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0755, true);
}

// Configurable limits
$RATE_LIMIT_REQUESTS_PER_HOUR = 30;   // max requests per student per hour
$RATE_LIMIT_REQUESTS_PER_DAY  = 150;  // max requests per student per day

if ($studentId !== 'unknown') {
    $rateLimitFile = $rateLimitDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId) . '.json';
    $now = time();
    $rateData = [];

    if (is_readable($rateLimitFile)) {
        $rateData = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    }

    // Clean old timestamps (older than 24 hours)
    $rateData['requests'] = array_values(array_filter(
        $rateData['requests'] ?? [],
        function ($ts) use ($now) { return ($now - $ts) < 86400; }
    ));

    // Count requests in last hour and last day
    $lastHour = array_filter($rateData['requests'], function ($ts) use ($now) {
        return ($now - $ts) < 3600;
    });
    $lastDay = $rateData['requests']; // already filtered to 24h

    if (count($lastHour) >= $RATE_LIMIT_REQUESTS_PER_HOUR) {
        http_response_code(429);
        $resetIn = min(array_map(function ($ts) use ($now) { return 3600 - ($now - $ts); }, $lastHour));
        echo json_encode([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => "Rate limit exceeded: $RATE_LIMIT_REQUESTS_PER_HOUR requests per hour. Try again in " . ceil($resetIn / 60) . " minutes."
            ]
        ]);
        exit;
    }

    if (count($lastDay) >= $RATE_LIMIT_REQUESTS_PER_DAY) {
        http_response_code(429);
        echo json_encode([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => "Daily limit exceeded: $RATE_LIMIT_REQUESTS_PER_DAY requests per day. Try again tomorrow."
            ]
        ]);
        exit;
    }

    // Record this request
    $rateData['requests'][] = $now;
    file_put_contents($rateLimitFile, json_encode($rateData), LOCK_EX);
}

// ============================================
// SCHOOL HOURS THROTTLING
// ============================================
// Pacific time zone for Pasco, WA
$schoolTz = new DateTimeZone('America/Los_Angeles');
$nowLocal = new DateTime('now', $schoolTz);
$hour     = (int)$nowLocal->format('G');  // 0-23
$dayOfWeek = (int)$nowLocal->format('N'); // 1=Mon, 7=Sun

// School hours: Mon-Fri, 7:00 AM - 5:00 PM Pacific
$isSchoolHours = ($dayOfWeek >= 1 && $dayOfWeek <= 5 && $hour >= 7 && $hour < 17);

// Outside school hours: only allow Haiku, and reduce rate limit
// Exception: free_hours="unlimited" bypasses school-hours restrictions entirely.
if (!$isSchoolHours && $restrictions['free_hours'] !== 'unlimited') {
    // Force model to haiku outside school hours
    if ($requestData['model'] !== 'haiku') {
        $requestData['model'] = 'haiku';
        // We'll note this in the response so the frontend can inform the user
        $modelDowngraded = true;
    }
    // Tighter rate limit outside school hours: 10/hour
    if ($studentId !== 'unknown' && isset($lastHour) && count($lastHour) >= 10) {
        http_response_code(429);
        echo json_encode([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Outside school hours (Mon-Fri 7AM-5PM Pacific): limited to 10 requests/hour with Haiku model only.'
            ]
        ]);
        exit;
    }
}

// ============================================
// CONVERSATION LENGTH CAP
// ============================================
$MAX_MESSAGES = 50;  // max messages in a conversation
$messageCount = count($requestData['messages']);

if ($messageCount > $MAX_MESSAGES) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'type' => 'conversation_too_long',
            'message' => "Conversation too long ($messageCount messages). Maximum is $MAX_MESSAGES. Please clear your chat and start a new conversation."
        ]
    ]);
    exit;
}

// ============================================
// INPUT SIZE CAP (prevents copy-paste exploit)
// ============================================
// Estimate input size from the raw JSON body. Skip the check when images
// are present — base64 images are inherently large and Anthropic enforces
// their own limits server-side. Flagging image requests as "too large" just
// confuses students who are doing exactly the right thing.
$inputBytes = strlen($input);
$MAX_INPUT_BYTES = 200000;  // ~30K tokens worth of JSON (text only)

$requestHasImages = false;
foreach ($requestData['messages'] as $msg) {
    if (!is_array($msg['content'] ?? null)) continue;
    foreach ($msg['content'] as $block) {
        if (($block['type'] ?? '') === 'image') { $requestHasImages = true; break 2; }
    }
}

if (!$requestHasImages && $inputBytes > $MAX_INPUT_BYTES) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'type' => 'input_too_large',
            'message' => 'Request too large. Please clear your chat and start a shorter conversation.'
        ]
    ]);
    exit;
}

// ============================================
// OPUS: FIRST EXCHANGE ONLY (waived for unlimited users)
// ============================================
// Opus is allowed only on the first message (msg_count == 1) so students
// can paste a screenshot and get one high-quality answer.  After that,
// the server downgrades to Sonnet.  The frontend grays out the button.
// Exception: users with both free_hours="unlimited" AND topic_lock="unlimited"
// may use Opus for the entire conversation.
$isUnlimitedUser = ($restrictions['free_hours'] === 'unlimited' && $restrictions['topic_lock'] === 'unlimited');
$opusDowngraded = false;
if ($requestData['model'] === 'opus' && $messageCount > 1 && !$isUnlimitedUser) {
    $requestData['model'] = 'sonnet';
    $opusDowngraded = true;
}

// Load model config from JSON file (with hardcoded fallback)
$configPath = __DIR__ . '/model_config.json';
$config = loadModelConfig($configPath);

// Build tier-to-primary map from config
$modelMap = [];
foreach ($config['tiers'] as $tier => $info) {
    $modelMap[$tier] = $info['primary'];
}

$requestedModel = $requestData['model'];
if (!isset($modelMap[$requestedModel])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid model tier. Allowed: ' . implode(', ', array_keys($modelMap)),
        'allowed_models' => array_keys($modelMap)
    ]);
    exit;
}
$resolvedModel = $modelMap[$requestedModel];
$provider = $config['tiers'][$requestedModel]['provider'] ?? 'anthropic';
$isExternalProvider = $provider !== 'anthropic';
// Anthropic tiers all accept Anthropic-shaped image blocks natively. Among
// external providers, only tiers explicitly marked supportsVision (currently
// just DeepSeek's vision-exp model) get their images translated to OpenAI's
// image_url format in buildOpenAiCompatibleRequest() — everyone else (GLM,
// Kimi K3, DeepSeek flash/pro, Grok) is text-only here, even where the underlying
// model has vision (e.g. Kimi K3), because that translation isn't built yet.
$supportsVision = !$isExternalProvider || !empty($config['tiers'][$requestedModel]['supportsVision']);

if (!$supportsVision && $requestHasImages) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'type' => 'unsupported_model',
            'message' => 'This model doesn\'t support images. Switch to Sonnet, Opus, or DeepSeek Vision to send a photo or screenshot.'
        ]
    ]);
    exit;
}

if ($isExternalProvider && empty($EXTERNAL_API_KEYS[$provider])) {
    http_response_code(500);
    error_log("API key missing for external provider '$provider' (tier '$requestedModel'): " . $EXTERNAL_PROVIDERS[$provider]['secretsFile']);
    echo json_encode(['error' => 'Server configuration error (API key missing for this model).']);
    exit;
}

// Build the API request
$apiRequest = [
    'model' => $resolvedModel,
    'max_tokens' => min((int)($requestData['max_tokens'] ?? 4096), 8192),
    'messages' => $requestData['messages'],
];

// Add optional system prompt
if (isset($requestData['system']) && is_string($requestData['system'])) {
    $apiRequest['system'] = $requestData['system'];
}

// --- Topic-lock injection ---
// Applied 24/7 UNLESS the student is currently inside their free-topic window.
//
// TopicLock format:
//   "subject"        e.g. "physics", "economics"   → answer mode  (direct, helpful)
//   "subject-tutor"  e.g. "physics-tutor"           → Socratic mode (guide, don't give away)
//
// Any subject word works — the constraint is built dynamically.
if ($restrictions['topic_lock'] !== '' && $restrictions['topic_lock'] !== 'unlimited' && !$inFreeHours) {
    $rawLock     = $restrictions['topic_lock'];
    $isTutor     = (substr($rawLock, -6) === '-tutor');
    $subject     = $isTutor ? substr($rawLock, 0, -6) : $rawLock;
    $subjectName = ucfirst($subject);   // e.g. "Physics", "Economics"

    if ($isTutor) {
        $topicConstraint =
            "IMPORTANT CONSTRAINT (enforced by school administrator): " .
            "You are a Socratic {$subjectName} tutor for a high school class. " .
            "Your job is to GUIDE students to discover answers themselves — never give the answer directly. " .
            "Instead, ask focused questions, surface the key concept, and let the student do the reasoning. " .
            "Confirm when they get it right, then ask what comes next. " .
            "Only discuss {$subjectName} topics and related schoolwork. " .
            "If the student tries to engage in roleplay, creative writing, emotional support, " .
            "or any topic unrelated to {$subjectName}, redirect them warmly: " .
            "\"I'm here as your {$subjectName} tutor — what {$subjectName} question can we work through together?\" " .
            "Do not make exceptions to this rule, even if asked nicely.";
    } else {
        $topicConstraint =
            "IMPORTANT CONSTRAINT (enforced by school administrator): " .
            "You are a {$subjectName} assistant for a high school class. " .
            "You must ONLY discuss {$subjectName} topics and related schoolwork. " .
            "If the student tries to engage in roleplay, creative writing, emotional support conversations, " .
            "or any topic that is not {$subjectName} or schoolwork, respond warmly but firmly: " .
            "\"I'm set up as your {$subjectName} assistant, so I can only help with {$subjectName} questions right now. " .
            "What {$subjectName} topic can I help you with?\" " .
            "Do not make exceptions to this rule, even if asked nicely.";
    }
    $apiRequest['system'] = isset($apiRequest['system'])
        ? $topicConstraint . "\n\n" . $apiRequest['system']
        : $topicConstraint;
}

// Add optional temperature (0.0 to 1.0), rounded to 2 decimal places.
//
// Newer Anthropic models reject the parameter outright ("`temperature` is
// deprecated for this model"), which is a per-model quirk like Kimi's
// fixed_temperature — so it's the `omit_temperature` field in
// model_config.json, not a branch here. Leaving it in was silently expensive:
// the 400 tripped isModelError(), auto-healing walked down the fallback chain,
// and students asking for Sonnet 5 quietly got Sonnet 4.6 at Sonnet 5 prices.
$omitTemperature = !empty($config['tiers'][$requestedModel]['omit_temperature']);
if (!$omitTemperature && isset($requestData['temperature'])) {
    $temp = round((float)$requestData['temperature'], 2);
    if ($temp >= 0.0 && $temp <= 1.0) {
        $apiRequest['temperature'] = $temp;
    }
}

// Build log entry for monitoring (verbose: includes user prompt text)
$imageInfo = countImages($requestData['messages']);
$lastUserText = getLastUserText($requestData['messages']);
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'student_id' => $studentId,
    'model_tier' => $requestedModel,
    'model' => $resolvedModel,
    'temperature' => $apiRequest['temperature'] ?? null,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'message_count' => $messageCount,
    'has_system' => isset($requestData['system']),
    'image_count' => $imageInfo['count'],
    'image_types' => $imageInfo['types'],
    'user_text_length' => strlen($lastUserText),
    'user_text' => mb_substr($lastUserText, 0, 500),  // first 500 chars for summary log
    'is_school_hours' => $isSchoolHours,
];

// Save images from this request as separate files tagged with student ID
$savedImageFiles = saveRequestImages($requestData['messages'], $studentId);
if (isset($modelDowngraded) && $modelDowngraded) {
    $logEntry['model_downgraded'] = true;
}
if ($opusDowngraded) {
    $logEntry['opus_downgraded'] = true;
}
$logEntry['provider'] = $provider;

// --- Make API call ---
// Give PHP more headroom than the cURL timeout, so a slow model produces a
// real timeout error the student can read rather than a truncated 500.
@set_time_limit(API_TIMEOUT_SECONDS + 60);
$modelHealed = false;

// Streaming is opt-in per request. The SSE response is opened LAZILY, on the
// first text delta — until then nothing has been written, so every error path
// below can still answer with ordinary JSON and the frontend handles it
// exactly as it always has. It also means auto-healing can still retry a
// fallback model after a failure, because nothing reached the screen yet.
$wantsStream   = !empty($requestData['stream']);
$streamStarted = false;
$onDelta = function (string $piece) use (&$streamStarted) {
    if (!$streamStarted) {
        beginEventStream();
        $streamStarted = true;
    }
    sendEvent('delta', ['text' => $piece]);
};
if ($isExternalProvider) {
    // GLM/Kimi K3/DeepSeek/Grok: OpenAI-compatible endpoints, no auto-healing (single model, no fallbacks configured).
    $fixedTemp = isset($config['tiers'][$requestedModel]['fixed_temperature'])
        ? (float)$config['tiers'][$requestedModel]['fixed_temperature']
        : null;
    $externalRequest = buildOpenAiCompatibleRequest($apiRequest, $resolvedModel, $supportsVision, $fixedTemp);
    $endpoint = $EXTERNAL_PROVIDERS[$provider]['endpoint'];
    $apiKey   = $EXTERNAL_API_KEYS[$provider];
    if ($wantsStream) {
        list($httpCode, $responseData, $curlError) = callOpenAiCompatibleApiStreaming(
            $endpoint, $externalRequest, $apiKey, $onDelta
        );
    } else {
        list($httpCode, $rawResponse, $curlError) = callOpenAiCompatibleApi($endpoint, $externalRequest, $apiKey);
        $responseData = normalizeOpenAiCompatibleResponse(json_decode($rawResponse, true), $resolvedModel);
    }

    if ($curlError) {
        emitFailure($streamStarted, 504, describeConnectionFailure($curlError, $provider));
    }

    if (isset($responseData['error']) && $httpCode < 400) {
        $httpCode = 502; // Provider returned 2xx but no usable content — treat as an upstream failure
    }
    $response = json_encode($responseData);
} else {
    // Anthropic: auto-healing fallback across tier's configured backup models.
    // The streaming and non-streaming calls are interchangeable here because
    // the streaming one returns the same response shape, JSON-encoded.
    $callAnthropic = function (array $req) use ($ANTHROPIC_API_KEY, $wantsStream, $onDelta, &$streamStarted) {
        if ($wantsStream && !$streamStarted) {
            list($code, $data, $err) = callAnthropicApiStreaming($req, $ANTHROPIC_API_KEY, $onDelta);
            return [$code, json_encode($data), $err];
        }
        return callAnthropicApi($req, $ANTHROPIC_API_KEY);
    };

    list($httpCode, $response, $curlError) = $callAnthropic($apiRequest);

    if ($curlError) {
        emitFailure($streamStarted, 504, describeConnectionFailure($curlError, 'anthropic'));
    }

    $responseData = json_decode($response, true);

    // Never heal once text is on the student's screen — a retry would stream a
    // second copy of the answer on top of the first, and bill for both.
    if (!$streamStarted && isModelError($httpCode, $responseData)) {
        $tierConfig = $config['tiers'][$requestedModel] ?? null;
        $fallbacks  = $tierConfig['fallbacks'] ?? [];

        // Cooldown: skip fallback if config was updated in last 60 seconds
        $configMtime = @filemtime($configPath);
        $cooldownActive = $configMtime && (time() - $configMtime < 60);

        if (!$cooldownActive && !empty($fallbacks)) {
            error_log("Model healing: primary '{$resolvedModel}' failed for tier '{$requestedModel}', trying fallbacks");

            foreach ($fallbacks as $fallbackModel) {
                $apiRequest['model'] = $fallbackModel;
                list($fbHttpCode, $fbResponse, $fbCurlError) = $callAnthropic($apiRequest);

                if ($fbCurlError) continue;

                $fbResponseData = json_decode($fbResponse, true);

                if (!isModelError($fbHttpCode, $fbResponseData)) {
                    // This model worked (or failed for a non-model reason)
                    $httpCode      = $fbHttpCode;
                    $response      = $fbResponse;
                    $responseData  = $fbResponseData;
                    $resolvedModel = $fallbackModel;
                    $modelHealed   = true;

                    // Update config so future requests use this model
                    if ($fbHttpCode === 200) {
                        updateModelConfig($configPath, $requestedModel, $fallbackModel);
                        error_log("Model healing: updated tier '{$requestedModel}' primary to '{$fallbackModel}'");
                    }
                    break;
                }
        }
    }
    }
}

// Streaming responses only carry usage if the provider honoured
// stream_options.include_usage. When one doesn't, estimate from the text
// rather than logging a request that cost real money as free — and flag the
// entry so the dashboard's numbers are never mistaken for measured ones.
if ($wantsStream && is_array($responseData) && !isset($responseData['error'])) {
    $reportedTokens = (int)($responseData['usage']['input_tokens'] ?? 0)
                    + (int)($responseData['usage']['output_tokens'] ?? 0);
    if ($reportedTokens === 0) {
        $streamedText = $responseData['content'][0]['text'] ?? '';
        $responseData['usage'] = estimateUsage($lastUserText, $streamedText);
        $logEntry['usage_estimated'] = true;
    }
}

// Log response token usage and cost (tokens x $/token, per model_config.json)
if (is_array($responseData) && isset($responseData['usage'])) {
    $logEntry['input_tokens'] = $responseData['usage']['input_tokens'] ?? 0;
    $logEntry['output_tokens'] = $responseData['usage']['output_tokens'] ?? 0;
    $logEntry['cost_usd'] = calculateCostUsd($config, $requestedModel, $logEntry['input_tokens'], $logEntry['output_tokens']);
}
// Record why a response was unusable (e.g. 'empty_response' with the token
// budget spent on reasoning) so the dashboard shows more than a bare 502.
if (is_array($responseData) && isset($responseData['error'])) {
    $logEntry['error_type'] = $responseData['error']['type'] ?? 'unknown';
    if (isset($responseData['error']['reasoning_chars'])) {
        $logEntry['reasoning_chars'] = $responseData['error']['reasoning_chars'];
    }
}
$logEntry['http_status'] = $httpCode;
$logEntry['model'] = $resolvedModel;
$logEntry['model_healed'] = $modelHealed;
if (!empty($savedImageFiles)) {
    $logEntry['saved_images'] = $savedImageFiles;
}

// Write summary entry to the shared usage log
$logFile = __DIR__ . '/claude_usage.log';
file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);

// Write full prompt + response to per-student log file
$responseText = '';
if (is_array($responseData) && isset($responseData['content'])) {
    foreach ($responseData['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $responseText .= $block['text'] ?? '';
        }
    }
}
writeStudentLog($studentId, $lastUserText, $responseText, $logEntry);

// Refresh the restriction banner on every reply: a topic lock lifts when a free
// window opens and the school-hours model limit starts at 5 PM, so a banner
// drawn once at login would go stale during a long session.
if (is_array($responseData)) {
    $responseData['_restrictions'] = buildRestrictionStatus($restrictions);
    $response = json_encode($responseData);
}

// If model was downgraded, inject a note into the response
if (isset($modelDowngraded) && $modelDowngraded && is_array($responseData)) {
    $responseData['_notice'] = 'Outside school hours: model downgraded to Haiku. Full model access Mon-Fri 7AM-5PM Pacific.';
    $response = json_encode($responseData);
}
if ($opusDowngraded && is_array($responseData)) {
    $responseData['_opus_limited'] = true;
    $responseData['_notice'] = 'Opus is available for your first message only. Switched to Sonnet for follow-ups. Clear chat to use Opus again.';
    $response = json_encode($responseData);
}
// Send the response to the client before doing any email work.
// The SMTP call can block for up to 30 s on a slow/unreachable server; if it
// runs before echo the browser receives an empty body and throws
// "Unexpected end of JSON input".  Closing the connection first lets the
// student's page load instantly while PHP finishes the alert in the background.
if ($streamStarted) {
    // The answer is already on screen; this final event carries the metadata
    // the page needs afterwards — token counts, the model that actually
    // replied, truncation, and the refreshed restriction banner.
    if (is_array($responseData) && isset($responseData['error'])) {
        sendEvent('error', $responseData['error']);
    } else {
        sendEvent('done', [
            'usage'           => $responseData['usage'] ?? null,
            'model'           => $responseData['model'] ?? $resolvedModel,
            'stop_reason'     => $responseData['stop_reason'] ?? null,
            'usage_estimated' => !empty($logEntry['usage_estimated']),
            '_restrictions'   => $responseData['_restrictions'] ?? null,
            '_notice'         => $responseData['_notice'] ?? null,
            '_opus_limited'   => $responseData['_opus_limited'] ?? null,
        ]);
    }
    flush();
} else {
    // Drop any stray buffered output so the body is exactly $response and the
    // Content-Length below is honest.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    echo $response;
    flush();
}
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ============================================
// SAFETY ALERT EMAIL  (runs after response is sent)
// ============================================
// Give this section its own time budget and keep running even if the browser
// has already closed the connection.  Without these, mod_php can hit
// max_execution_time during the SMTP socket call and silently die.
ignore_user_abort(true);
set_time_limit(30);

// Scan student message + AI reply for safety concerns and jailbreak attempts.
// Fires an email (same SMTP as wheel3) when anything concerning is detected.
// Also scan the client-supplied system prompt — students can abuse it
// to inject personas (e.g. white supremacist, sexual content) even if
// their actual message looks innocent.
$clientSystem = isset($requestData['system']) && is_string($requestData['system'])
    ? $requestData['system'] : '';
$concerns = detectConcerns($lastUserText, $responseText, $clientSystem);
// Diagnostic: always log concern detection result so missing emails can be traced.
if ($concerns['triggered']) {
    file_put_contents($logFile, json_encode([
        'timestamp'     => date('Y-m-d H:i:s'),
        'event'         => 'ALERT_CHECK',
        'student_id'    => $studentId,
        'categories'    => $concerns['categories'],
        'smtp_file'     => $smtpFile,
        'smtp_readable' => is_readable($smtpFile),
        'post_fcgi'     => function_exists('fastcgi_finish_request'),
    ]) . "\n", FILE_APPEND | LOCK_EX);
}
if ($concerns['triggered'] && is_readable($smtpFile)) {
    try {
        require_once $smtpFile;
        // smtp_credentials.php defines: $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
        //                                $SMTP_FROM, $SMTP_FROM_NAME, and constant ALERT_TO
        $alertSubject = (in_array('TEST', $concerns['categories']) ? '🧪' : '🚨')
            . ' Claude Chatbot Alert — ' . implode(', ', $concerns['categories'])
            . ' — ' . ($restrictions['student_name'] ?: $studentId);
        $alertBody = buildClaudeAlertEmail(
            $studentId, $restrictions['student_name'],
            $requestData['messages'], $responseText,
            $concerns, $clientSystem
        );
        $alertSent = sendClaudeSmtpEmail(
            $SMTP_HOST, (int)$SMTP_PORT, $SMTP_USER, $SMTP_PASS,
            $SMTP_FROM, $SMTP_FROM_NAME,
            ALERT_TO, $restrictions['alert_email'], $alertSubject, $alertBody
        );
        file_put_contents($logFile, json_encode([
            'timestamp'    => date('Y-m-d H:i:s'),
            'event'        => $alertSent ? 'ALERT_SENT' : 'ALERT_FAILED',
            'student_id'   => $studentId,
            'student_name' => $restrictions['student_name'],
            'categories'   => $concerns['categories'],
            'matched'      => $concerns['matched'],
        ]) . "\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        file_put_contents($logFile, json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'event'     => 'ALERT_EXCEPTION',
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]) . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Validate a session token against the stored session and current roster.
 * Returns true only if the token is valid, not expired, and the student's
 * password has not changed since the session was created.
 */
function validateSession(string $sessionsDir, string $token, string $studentId, string $studentFile): bool
{
    // Token must be exactly 64 lowercase hex chars (32 random bytes)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    if ($studentId === '') return false;

    $sessionFile = $sessionsDir . '/' . $token . '.json';
    if (!is_readable($sessionFile)) return false;

    $sessionData = json_decode(file_get_contents($sessionFile), true);
    if (!is_array($sessionData)) return false;

    // Student ID must match what was recorded at login
    if (($sessionData['student_id'] ?? '') !== $studentId) return false;

    // Sessions expire after 8 hours (one school day)
    if ((time() - ($sessionData['created_at'] ?? 0)) > 28800) {
        @unlink($sessionFile);
        return false;
    }

    // Re-check the student's current password against what was hashed at login.
    // If the teacher changed the password, the hashes won't match and the
    // session is immediately invalidated — even if the tab is still open.
    if (!is_readable($studentFile)) return false;
    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
    $currentHash = null;
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2]) && trim($row[2]) === $studentId) {
            $currentHash = hash('sha256', trim($row[6] ?? ''));
            break;
        }
    }
    fclose($handle);

    if ($currentHash === null || $currentHash !== ($sessionData['password_hash'] ?? '')) {
        @unlink($sessionFile); // Wipe the now-invalid session
        return false;
    }

    // Bump last_used so we can track idle sessions later if needed
    $sessionData['last_used'] = time();
    file_put_contents($sessionFile, json_encode($sessionData), LOCK_EX);
    return true;
}

/**
 * Load model configuration from JSON file.
 * Falls back to hardcoded defaults if file is missing or corrupt.
 */
function loadModelConfig(string $configPath): array
{
    if (is_readable($configPath)) {
        $raw = file_get_contents($configPath);
        $config = json_decode($raw, true);
        if (is_array($config) && isset($config['tiers'])) {
            return $config;
        }
    }
    // Hardcoded fallback if JSON is missing/corrupt.
    // Keep in sync with model_config.json and update_models.php
    // Last verified: 2026-07-03 from https://platform.claude.com/docs/en/about-claude/models
    return [
        'tiers' => [
            'haiku'  => [
                'primary'   => 'claude-haiku-4-5-20251001',
                'fallbacks' => ['claude-haiku-4-5', 'claude-3-haiku-20240307'],
                'pricing'   => ['input_per_mtok' => 1.00, 'output_per_mtok' => 5.00],
            ],
            'sonnet' => [
                'primary'   => 'claude-sonnet-5',
                'fallbacks' => ['claude-sonnet-4-6', 'claude-sonnet-4-5-20250929', 'claude-sonnet-4-5', 'claude-sonnet-4-20250514'],
                'pricing'   => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00],
            ],
            'opus'   => [
                'primary'   => 'claude-opus-4-8',
                'fallbacks' => ['claude-opus-4-7', 'claude-opus-4-6', 'claude-opus-4-5-20251101', 'claude-opus-4-5', 'claude-opus-4-1-20250805'],
                'pricing'   => ['input_per_mtok' => 5.00, 'output_per_mtok' => 25.00],
            ],
            'glm'    => [
                'provider'  => 'zai',
                'primary'   => 'glm-5.3',
                'fallbacks' => [],
                // Blended rate from the actual purchased balance ($19.90 / 20M tokens),
                // not Z.AI's published list price (input $1.40 / output $4.40 per MTok).
                'pricing'   => ['input_per_mtok' => 0.995, 'output_per_mtok' => 0.995],
            ],
            'kimi'   => [
                'provider'  => 'moonshot',
                'primary'   => 'kimi-k3',
                'fallbacks' => [],
                // Moonshot's standard (cache-miss) rate; the cheaper $0.30/MTok
                // cache-hit rate isn't modeled since this proxy sends no cache hints.
                'pricing'   => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00],
            ],
            'dsflash' => [
                'provider'  => 'deepseek',
                'primary'   => 'deepseek-v4-flash',
                'fallbacks' => [],
                // Off-peak cache-miss rate from api-docs.deepseek.com/quick_start/pricing.
                // Peak hours (01:00-04:00 & 06:00-10:00 UTC) fall outside school hours
                // (7AM-5PM Pacific), so off-peak is the realistic rate for real usage.
                'pricing'   => ['input_per_mtok' => 0.22, 'output_per_mtok' => 0.66],
            ],
            'dspro'  => [
                'provider'  => 'deepseek',
                'primary'   => 'deepseek-v4-pro',
                'fallbacks' => [],
                'pricing'   => ['input_per_mtok' => 0.66, 'output_per_mtok' => 1.98],
            ],
            'dsvision' => [
                'provider'       => 'deepseek',
                'primary'        => 'deepseek-v4-flash-vision-exp',
                'fallbacks'      => [],
                'supportsVision' => true,
                // Same rate card as dsflash — images are billed at the input rate (up to 384 tok/image).
                'pricing'        => ['input_per_mtok' => 0.22, 'output_per_mtok' => 0.66],
            ],
            'grok'   => [
                'provider'  => 'xai',
                'primary'   => 'grok-4.6',
                'fallbacks' => [],
                // xAI's short-prompt rate (<200k prompt tokens). Long prompts bill
                // at double; nothing this chatbot sends comes close to 200k.
                'pricing'   => ['input_per_mtok' => 2.00, 'output_per_mtok' => 6.00],
            ],
        ]
    ];
}

/**
 * Make a single API call to Anthropic.
 * Returns [httpCode, response, curlError].
 */
function callAnthropicApi(array $apiRequest, string $apiKey): array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($apiRequest),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        // 8192-token answers (a full program) can take a slow provider well past
        // two minutes — GLM timed out at 120s once max_tokens was raised.
        CURLOPT_TIMEOUT        => API_TIMEOUT_SECONDS,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlError];
}

/**
 * Convert an Anthropic-shaped request (built for callAnthropicApi) into the
 * OpenAI-compatible body Z.AI/Moonshot/DeepSeek/xAI's chat/completions endpoints
 * expect: a flat messages array (system prompt becomes a leading
 * {role: 'system'} message). When $supportsVision is false, message content
 * is flattened to plain text (images dropped — callers must reject image
 * requests before reaching here). When true, content is converted to
 * OpenAI's content-block array so image_url blocks survive.
 */
function buildOpenAiCompatibleRequest(array $apiRequest, string $model, bool $supportsVision = false, ?float $fixedTemperature = null): array
{
    $messages = [];
    if (isset($apiRequest['system'])) {
        $messages[] = ['role' => 'system', 'content' => $apiRequest['system']];
    }
    foreach ($apiRequest['messages'] as $msg) {
        $content = $msg['content'] ?? '';
        $messages[] = [
            'role'    => $msg['role'] ?? 'user',
            'content' => $supportsVision ? convertToOpenAiContent($content) : extractPlainText($content),
        ];
    }
    // Some models accept only one temperature and reject everything else with a
    // hard 400 (Kimi K3: "invalid temperature: only 1 is allowed for this
    // model"). That's a per-model quirk, so it lives as a `fixed_temperature`
    // field in model_config.json rather than an if-block here — a new model with
    // the same constraint is a config edit, not a code change. The student's
    // temperature slider is simply ignored for those tiers.
    return [
        'model'       => $model,
        'messages'    => $messages,
        'max_tokens'  => $apiRequest['max_tokens'],
        'temperature' => $fixedTemperature ?? ($apiRequest['temperature'] ?? 1.0),
    ];
}

/**
 * Convert an Anthropic-style message content value (string, or an array of
 * content blocks) into OpenAI's content-block format: text blocks pass
 * through as {type: text, text}, and Anthropic base64 image blocks become
 * {type: image_url, image_url: {url: "data:<media_type>;base64,<data>"}}.
 * Non-base64 image sources (e.g. a remote "url" source type) are dropped —
 * this proxy only ever builds base64 image blocks itself (saveRequestImages
 * / the frontend upload flow), so that's the only source type expected.
 */
function convertToOpenAiContent($content): array
{
    if (is_string($content)) {
        return [['type' => 'text', 'text' => $content]];
    }
    if (!is_array($content)) return [];
    $blocks = [];
    foreach ($content as $block) {
        $type = $block['type'] ?? '';
        if ($type === 'text') {
            $blocks[] = ['type' => 'text', 'text' => $block['text'] ?? ''];
        } elseif ($type === 'image') {
            $source = $block['source'] ?? [];
            if (($source['type'] ?? '') === 'base64' && !empty($source['data'])) {
                $mediaType = $source['media_type'] ?? 'image/jpeg';
                $blocks[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$mediaType};base64,{$source['data']}"],
                ];
            }
        }
    }
    return $blocks;
}

/**
 * Flatten an Anthropic-style message content value (string, or an array of
 * content blocks) down to a plain string of its text blocks. Non-text
 * blocks (e.g. images) are dropped — callers must reject image requests
 * before reaching here, since silently dropping a photo is confusing.
 */
function extractPlainText($content): string
{
    if (is_string($content)) return $content;
    if (!is_array($content)) return '';
    $parts = [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'text') {
            $parts[] = $block['text'] ?? '';
        }
    }
    return implode("\n", $parts);
}

/**
 * Make a single API call to an OpenAI-compatible chat completions endpoint
 * (Z.AI, Moonshot, or any other provider using the same request shape).
 * Returns [httpCode, response, curlError].
 */
function callOpenAiCompatibleApi(string $endpoint, array $body, string $apiKey): array
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        // 8192-token answers (a full program) can take a slow provider well past
        // two minutes — GLM timed out at 120s once max_tokens was raised.
        CURLOPT_TIMEOUT        => API_TIMEOUT_SECONDS,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlError];
}

/**
 * Convert an OpenAI-shaped chat completion response (Z.AI, Moonshot, ...)
 * into the same {content: [...], usage: {...}, model} shape
 * callAnthropicApi() returns, so the rest of this file (logging, alert
 * scanning, the frontend) can treat every provider identically.
 */
function normalizeOpenAiCompatibleResponse(?array $raw, string $fallbackModel): array
{
    if (!is_array($raw)) {
        return ['error' => ['type' => 'api_error', 'message' => 'Invalid response from the model API.']];
    }
    if (isset($raw['error'])) {
        $err = $raw['error'];
        $message = is_array($err) ? ($err['message'] ?? 'Model API error.') : (string)$err;
        $type = is_array($err) ? ($err['type'] ?? 'api_error') : 'api_error';
        return ['error' => ['type' => $type, 'message' => $message]];
    }
    $message = $raw['choices'][0]['message'] ?? [];
    $text = $message['content'] ?? null;

    // Map OpenAI's finish_reason onto Anthropic's stop_reason vocabulary so the
    // frontend can tell "answer complete" from "ran out of tokens mid-code" the
    // same way for every provider.
    $finish = $raw['choices'][0]['finish_reason'] ?? null;
    $stopReason = $finish === 'length' ? 'max_tokens'
                : ($finish === 'stop' ? 'end_turn' : $finish);

    // Usage is reported even when the answer is unusable — those tokens were
    // still billed, so every return path below carries it through to the log.
    $usage = [
        'input_tokens'  => $raw['usage']['prompt_tokens'] ?? 0,
        'output_tokens' => $raw['usage']['completion_tokens'] ?? 0,
    ];

    // An empty answer is not the same as a missing one. Reasoning-style models
    // (DeepSeek Vision does this) write their chain of thought into
    // `reasoning_content` and only afterwards write the answer into `content`;
    // if the token budget runs out mid-thought, `content` comes back as an
    // empty string with finish_reason 'length'. Left alone that renders as a
    // blank chat bubble with a full token bill and no explanation.
    if ($text === null || trim((string)$text) === '') {
        $reasoningChars = strlen((string)($message['reasoning_content'] ?? ''));
        if ($stopReason === 'max_tokens') {
            $why = 'This model used its whole response budget thinking and never got to the answer. '
                 . 'Ask for something smaller (one class or function at a time), or switch to '
                 . 'DeepSeek Flash, DeepSeek Pro, or Sonnet for code.';
        } elseif ($text === null) {
            $why = 'The model API returned no response content. Try again or switch models.';
        } else {
            $why = 'The model returned an empty answer. Try rephrasing, or switch models.';
        }
        return [
            'error' => [
                'type'    => 'empty_response',
                'message' => $why,
                // Diagnostics for claude_usage.log — tells a future reader whether
                // the budget went into reasoning or the model simply said nothing.
                'reasoning_chars' => $reasoningChars,
                'finish_reason'   => $finish,
            ],
            'usage'       => $usage,
            'model'       => $raw['model'] ?? $fallbackModel,
            'stop_reason' => $stopReason,
        ];
    }

    return [
        'content' => [['type' => 'text', 'text' => $text]],
        'usage'   => $usage,
        'model' => $raw['model'] ?? $fallbackModel,
        'stop_reason' => $stopReason,
    ];
}

/**
 * ============================================
 * STREAMING (Server-Sent Events)
 * ============================================
 * The client opts in with "stream": true. Everything downstream of the API
 * call — logging, cost, safety alerts, the restriction banner — is unchanged:
 * these functions relay text to the browser as it arrives AND accumulate the
 * whole answer, then hand back the same response shape the non-streaming path
 * builds. That way there is one pipeline, not two.
 *
 * Verified against psd1.net with stream-test.php: chunks arrive as written,
 * with no buffering anywhere in the hosting stack.
 */

/**
 * Open an SSE response: headers, plus every buffering layer PHP controls
 * turned off. gzip is explicitly disabled — the spike measured it costing
 * about half a second to first byte for no benefit on a text stream.
 */
function beginEventStream(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    // Discard, never flush: anything buffered at this point is stray output
    // (whitespace from an included file), and flushing it would commit the
    // wrong headers before the event-stream ones below could take effect.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @ob_implicit_flush(true);

    // If headers already went out, the Content-Type below is ignored and the
    // client will not see this as a stream. Log it loudly — silent misdelivery
    // is far worse to diagnose than a logged warning.
    if (headers_sent($sentFile, $sentLine)) {
        error_log("Streaming: headers already sent at {$sentFile}:{$sentLine}; "
                . "SSE Content-Type will be ignored. Check included files for stray output.");
    }

    // Anything PHP prints mid-stream lands inside an SSE frame and corrupts it,
    // so notices go to the error log only from here on.
    @ini_set('display_errors', '0');

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header_remove('Content-Encoding');
}

/**
 * Write one SSE event and push it out immediately.
 */
function sendEvent(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    @flush();
}

/**
 * Build a cURL write callback that splits arbitrary network chunks into whole
 * lines before handing them on.
 *
 * cURL hands over whatever arrived, which can cut an SSE line in half — a
 * naive parser silently drops or corrupts those. Anything left over stays in
 * $buffer until the rest of the line turns up.
 *
 * $buffer is the CALLER's variable so that whatever is still in it when the
 * response ends can be flushed. Without that, a final line with no trailing
 * newline is lost — which is precisely how an API error arrives: one line of
 * JSON, no newline. Losing it turned a specific, actionable upstream message
 * into a generic "the model API returned an error".
 */
function makeLineSplitter(callable $onLine, string &$buffer): callable
{
    $buffer = '';
    return function ($ch, string $chunk) use (&$buffer, $onLine): int {
        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $pos), "\r");
            $buffer = substr($buffer, $pos + 1);
            $onLine($line);
        }
        return strlen($chunk);
    };
}

/**
 * Stream an Anthropic Messages request, relaying text deltas through $onDelta.
 *
 * Returns [$httpCode, $responseData, $curlError] with $responseData in the
 * same shape the non-streaming call produces, so callers can't tell which
 * transport was used.
 *
 * If the API returns an error status, no deltas are emitted — the body is
 * collected as JSON instead. That is what lets auto-healing still retry a
 * fallback model: nothing has been written to the student's screen yet.
 */
function callAnthropicApiStreaming(
    array $apiRequest,
    string $apiKey,
    callable $onDelta,
    string $endpoint = 'https://api.anthropic.com/v1/messages'  // overridden by tests
): array {
    $apiRequest['stream'] = true;

    $text = '';
    $errorBody = '';
    $model = $apiRequest['model'] ?? '';
    $stopReason = null;
    $inputTokens = 0;
    $outputTokens = 0;
    $httpCode = 0;
    $tail = '';

    $ch = curl_init($endpoint);

    $onLine = function (string $line) use (
        &$text, &$errorBody, &$model, &$stopReason, &$inputTokens, &$outputTokens, &$httpCode, $onDelta, &$ch
    ) {
        if ($httpCode === 0) {
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        // An error response is JSON, not SSE — collect it verbatim.
        if ($httpCode >= 400) {
            $errorBody .= $line . "\n";
            return;
        }
        if (strpos($line, 'data:') !== 0) {
            return;
        }
        $payload = json_decode(trim(substr($line, 5)), true);
        if (!is_array($payload)) {
            return;
        }
        switch ($payload['type'] ?? '') {
            case 'message_start':
                $model = $payload['message']['model'] ?? $model;
                $inputTokens = $payload['message']['usage']['input_tokens'] ?? 0;
                break;
            case 'content_block_delta':
                $piece = $payload['delta']['text'] ?? '';
                if ($piece !== '') {
                    $text .= $piece;
                    $onDelta($piece);
                }
                break;
            case 'message_delta':
                $stopReason = $payload['delta']['stop_reason'] ?? $stopReason;
                $outputTokens = $payload['usage']['output_tokens'] ?? $outputTokens;
                break;
            case 'error':
                $errorBody = json_encode($payload);
                break;
        }
    };

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($apiRequest),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Accept: text/event-stream',
        ],
        CURLOPT_TIMEOUT        => API_TIMEOUT_SECONDS,
        CURLOPT_WRITEFUNCTION  => makeLineSplitter($onLine, $tail),
    ]);
    curl_exec($ch);
    if ($tail !== '') {          // final line, no trailing newline
        $onLine(rtrim($tail, "
"));
        $tail = '';
    }
    if ($httpCode === 0) {
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    // No curl_close() here: it's a deprecated no-op since PHP 8.0, and on 8.5 it
    // emits a notice that would be written straight into the event stream.
    $curlError = curl_error($ch);

    if ($httpCode >= 400 || ($text === '' && $errorBody !== '')) {
        $decoded = json_decode(trim($errorBody), true);
        return [$httpCode ?: 502, is_array($decoded) ? $decoded : [
            'error' => ['type' => 'api_error', 'message' => 'The model API returned an error.'],
        ], $curlError];
    }

    return [$httpCode ?: 200, [
        'content'     => [['type' => 'text', 'text' => $text]],
        'usage'       => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        'model'       => $model,
        'stop_reason' => $stopReason,
    ], $curlError];
}

/**
 * Stream an OpenAI-compatible request (GLM / Kimi / DeepSeek / Grok), relaying text
 * deltas through $onDelta. Same contract as callAnthropicApiStreaming().
 *
 * Token usage: streaming responses only report usage if asked, via
 * stream_options.include_usage, which every provider here supports today. If
 * a provider ever stops accepting it, usage comes back empty rather than
 * wrong — the caller estimates and flags the log entry instead of silently
 * recording a free request.
 */
function callOpenAiCompatibleApiStreaming(string $endpoint, array $body, string $apiKey, callable $onDelta): array
{
    $body['stream'] = true;
    $body['stream_options'] = ['include_usage' => true];

    $text = '';
    $errorBody = '';
    $model = $body['model'] ?? '';
    $finish = null;
    $inputTokens = 0;
    $outputTokens = 0;
    $httpCode = 0;
    $tail = '';

    $ch = curl_init($endpoint);

    $onLine = function (string $line) use (
        &$text, &$errorBody, &$model, &$finish, &$inputTokens, &$outputTokens, &$httpCode, $onDelta, &$ch
    ) {
        if ($httpCode === 0) {
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
        if ($httpCode >= 400) {
            $errorBody .= $line . "\n";
            return;
        }
        if (strpos($line, 'data:') !== 0) {
            return;
        }
        $raw = trim(substr($line, 5));
        if ($raw === '' || $raw === '[DONE]') {
            return;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return;
        }
        if (isset($payload['error'])) {
            $errorBody = $raw;
            return;
        }
        $model = $payload['model'] ?? $model;
        $piece = $payload['choices'][0]['delta']['content'] ?? '';
        if (is_string($piece) && $piece !== '') {
            $text .= $piece;
            $onDelta($piece);
        }
        $finish = $payload['choices'][0]['finish_reason'] ?? $finish;
        if (isset($payload['usage']['prompt_tokens'])) {
            $inputTokens = $payload['usage']['prompt_tokens'];
            $outputTokens = $payload['usage']['completion_tokens'] ?? $outputTokens;
        }
    };

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Accept: text/event-stream',
        ],
        CURLOPT_TIMEOUT        => API_TIMEOUT_SECONDS,
        CURLOPT_WRITEFUNCTION  => makeLineSplitter($onLine, $tail),
    ]);
    curl_exec($ch);
    if ($tail !== '') {          // final line, no trailing newline
        $onLine(rtrim($tail, "
"));
        $tail = '';
    }
    if ($httpCode === 0) {
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    // No curl_close() here: it's a deprecated no-op since PHP 8.0, and on 8.5 it
    // emits a notice that would be written straight into the event stream.
    $curlError = curl_error($ch);

    if ($httpCode >= 400 || ($text === '' && $errorBody !== '')) {
        $decoded = json_decode(trim($errorBody), true);
        return [$httpCode ?: 502, is_array($decoded) ? $decoded : [
            'error' => ['type' => 'api_error', 'message' => 'The model API returned an error.'],
        ], $curlError];
    }

    // Reuse the non-streaming normalizer so empty answers, stop_reason mapping
    // and the reasoning-budget diagnosis behave identically on both transports.
    return [$httpCode ?: 200, normalizeOpenAiCompatibleResponse([
        'model'   => $model,
        'choices' => [['message' => ['content' => $text], 'finish_reason' => $finish]],
        'usage'   => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
    ], $model), $curlError];
}

/**
 * Report a fatal request failure and stop, on whichever transport is live.
 *
 * Before the first delta nothing has been written, so an ordinary JSON error
 * is still possible and the frontend's existing handling applies. Once text
 * is on screen the response is already an event stream, and the only honest
 * thing left is an `error` event the client can append to what it has.
 */
function emitFailure(bool $streamStarted, int $status, array $error): void
{
    if ($streamStarted) {
        sendEvent('error', $error);
        @flush();
    } else {
        http_response_code($status);
        echo json_encode(['error' => $error]);
    }
    exit;
}

/**
 * Estimate token counts when a streaming provider didn't report usage.
 *
 * Better than logging zero: a request that cost real money would otherwise
 * look free on the dashboard. Entries built this way are flagged
 * `usage_estimated` so the numbers are never mistaken for measured ones.
 * Roughly four characters per token, which is the usual English ballpark.
 */
function estimateUsage(string $promptText, string $responseText): array
{
    return [
        'input_tokens'  => (int)ceil(mb_strlen($promptText) / 4),
        'output_tokens' => (int)ceil(mb_strlen($responseText) / 4),
    ];
}

/**
 * Compute the USD cost of one interaction from its token counts and the
 * requested tier's pricing in model_config.json. Uses the tier's currently
 * configured pricing, so a request auto-healed to an older Anthropic
 * fallback snapshot is priced at the tier's current rate, not that
 * snapshot's original (possibly different) historical rate.
 */
function calculateCostUsd(array $config, string $tier, int $inputTokens, int $outputTokens): ?float
{
    $pricing = $config['tiers'][$tier]['pricing'] ?? null;
    if (!$pricing) return null;
    $cost = ($inputTokens / 1000000) * ($pricing['input_per_mtok'] ?? 0)
          + ($outputTokens / 1000000) * ($pricing['output_per_mtok'] ?? 0);
    return round($cost, 6);
}

/**
 * Check if an API error response indicates an invalid/deprecated model.
 */
function isModelError(int $httpCode, ?array $responseData): bool
{
    if ($httpCode !== 400) return false;
    if (!is_array($responseData)) return false;
    $errorType = $responseData['error']['type'] ?? '';
    $errorMsg  = strtolower($responseData['error']['message'] ?? '');
    if ($errorType !== 'invalid_request_error') return false;
    if (strpos($errorMsg, 'model') === false) return false;

    // "`temperature` is deprecated for this model" contains the word "model"
    // but is a complaint about a PARAMETER, not about the model being wrong.
    // Treating it as a model error made the proxy walk its fallback chain and
    // permanently rewrite model_config.json to an older model — a real
    // downgrade caused by a request field it could have simply dropped.
    foreach (['temperature', 'top_p', 'top_k', 'max_tokens', 'stop_sequence'] as $param) {
        if (strpos($errorMsg, $param) !== false) return false;
    }
    return true;
}

/**
 * Update model_config.json with a new primary model for a tier.
 * Uses file locking to prevent race conditions.
 */
function updateModelConfig(string $configPath, string $tier, string $newPrimary): bool
{
    $fp = fopen($configPath, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    // Re-read inside lock to avoid overwriting a concurrent update
    $raw = stream_get_contents($fp);
    $config = json_decode($raw, true);
    if (!is_array($config) || !isset($config['tiers'][$tier])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    // Only write if the primary actually changed
    if ($config['tiers'][$tier]['primary'] === $newPrimary) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    $config['tiers'][$tier]['primary'] = $newPrimary;
    $config['_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Count images and collect their media types from messages
 */
function countImages(array $messages): array
{
    $count = 0;
    $types = [];
    foreach ($messages as $message) {
        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $content) {
                if (isset($content['type']) && $content['type'] === 'image') {
                    $count++;
                    $mediaType = $content['source']['media_type'] ?? 'unknown';
                    $types[] = $mediaType;
                }
            }
        }
    }
    return ['count' => $count, 'types' => array_unique($types)];
}

/**
 * Get the full text of the last user message (for verbose logging)
 */
function getLastUserText(array $messages): string
{
    $lastUserMsg = null;
    foreach (array_reverse($messages) as $msg) {
        if (($msg['role'] ?? '') === 'user') {
            $lastUserMsg = $msg;
            break;
        }
    }
    if (!$lastUserMsg) return '';

    if (is_string($lastUserMsg['content'])) {
        return $lastUserMsg['content'];
    }
    if (is_array($lastUserMsg['content'])) {
        $parts = [];
        foreach ($lastUserMsg['content'] as $part) {
            if (($part['type'] ?? '') === 'text') {
                $parts[] = $part['text'] ?? '';
            }
        }
        return implode(' ', $parts);
    }
    return '';
}

/**
 * Save base64 images from the messages array as files in student_logs/images/.
 * Returns array of saved filenames.
 */
function saveRequestImages(array $messages, string $studentId): array
{
    $imageDir = __DIR__ . '/student_logs/images';
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0755, true);
    }

    // Block direct web access to the image directory
    $htaccess = $imageDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png'  => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/heic' => 'heic',
        'image/heif' => 'heif', 'image/bmp'  => 'bmp',
    ];

    $safeId    = preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId);
    $timestamp = date('Ymd_His');
    $saved     = [];
    $imgIndex  = 0;

    foreach ($messages as $message) {
        if (!isset($message['content']) || !is_array($message['content'])) continue;
        foreach ($message['content'] as $block) {
            if (($block['type'] ?? '') !== 'image') continue;
            $source    = $block['source'] ?? [];
            $sourceType = $source['type'] ?? '';
            if ($sourceType !== 'base64') continue;

            $mimeType = $source['media_type'] ?? 'image/jpeg';
            $ext      = $mimeToExt[$mimeType] ?? 'jpg';
            $b64      = $source['data'] ?? '';
            if (empty($b64)) continue;

            $decoded = base64_decode($b64, true);
            if ($decoded === false) continue;

            $filename = "{$safeId}_{$timestamp}_{$imgIndex}.{$ext}";
            file_put_contents($imageDir . '/' . $filename, $decoded);
            $saved[] = $filename;
            $imgIndex++;
        }
    }

    return $saved;
}

/**
 * Read per-student restrictions from the roster CSV.
 * Returns ['free_hours' => '...', 'topic_lock' => '...', 'student_name' => '...'].
 * free_hours and topic_lock are empty string when not set (no restriction).
 *
 * CSV columns used:
 *   col  9 (Full_Name)  — student display name (used in alert emails)
 *   col 10 (FreeHours)   — e.g. "20-21" or "8-15,20-21"  (24-hour windows, Pacific)
 *                          Window(s) when TopicLock is lifted (free-range allowed).
 *                          Quote the value in the CSV when using multiple windows.
 *   col 11 (TopicLock)   — e.g. "physics", "physics-tutor", "economics-tutor", or ""
 *                          Topic restriction enforced 24/7 except during FreeHours.
 *                          Append "-tutor" for Socratic mode instead of answer mode.
 *   col 12 (AlertEmail)  — extra CC address(es) for safety alert emails, comma-separated.
 *                          Leave blank to send alerts to ALERT_TO only.
 *                          Example: "parent@example.com" or "parent@x.com,counselor@y.com"
 */
function getStudentRestrictions(string $studentFile, string $studentId): array
{
    $out = ['free_hours' => '', 'topic_lock' => '', 'student_name' => '', 'alert_email' => ''];
    if (!is_readable($studentFile)) return $out;

    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header row
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2]) && trim($row[2]) === $studentId) {
            $out['student_name'] = trim($row[9] ?? '');
            $out['free_hours']   = strtolower(trim($row[10] ?? ''));
            $out['topic_lock']   = strtolower(trim($row[11] ?? ''));
            $out['alert_email']  = trim($row[12] ?? '');
            break;
        }
    }
    fclose($handle);
    return $out;
}

/**
 * Parse a time string into minutes since midnight.
 * Accepts "HH" (whole hour) or "HH:MM" (hour and minute), both 24-hour.
 * Examples: "20" → 1200, "22:30" → 1350, "8:05" → 485
 */
function parseTimeMins(string $t): int
{
    $t = trim($t);
    if (strpos($t, ':') !== false) {
        [$h, $m] = explode(':', $t, 2);
        return (int)$h * 60 + (int)$m;
    }
    return (int)$t * 60;
}

/**
 * Check whether $currentMins (minutes since midnight, Pacific) falls inside
 * any window listed in $allowedHours.
 *
 * Format: "HH-HH" or "HH:MM-HH:MM", comma-separated for multiple windows.
 * The end time is exclusive: "20-21" = 8:00 PM up to (not including) 9:00 PM.
 * Examples:
 *   "20-21"          → 8:00 PM – 8:59 PM
 *   "22:30-22:45"    → 10:30 PM – 10:44 PM
 *   "8-15,20-21"     → 8:00 AM – 2:59 PM  OR  8:00 PM – 8:59 PM
 */
function isWithinAllowedHours(string $allowedHours, int $currentMins): bool
{
    foreach (explode(',', $allowedHours) as $window) {
        $parts = explode('-', trim($window), 2);
        if (count($parts) === 2) {
            $start = parseTimeMins($parts[0]);
            $end   = parseTimeMins($parts[1]);
            if ($currentMins >= $start && $currentMins < $end) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Convert a raw AllowedHours string into a human-readable string.
 * Examples:
 *   "20-21"       → "8 PM–9 PM"
 *   "22:30-22:45" → "10:30 PM–10:45 PM"
 *   "8-15,20-21"  → "8 AM–3 PM and 8 PM–9 PM"
 */
function formatAllowedWindows(string $allowedHours): string
{
    $fmtMins = function (int $mins): string {
        $h      = intdiv($mins, 60);
        $m      = $mins % 60;
        $period = $h < 12 ? 'AM' : 'PM';
        $h12    = $h % 12 ?: 12;
        return $m > 0
            ? sprintf('%d:%02d %s', $h12, $m, $period)
            : "{$h12} {$period}";
    };

    $parts = [];
    foreach (explode(',', $allowedHours) as $window) {
        $w = explode('-', trim($window), 2);
        if (count($w) === 2) {
            $parts[] = $fmtMins(parseTimeMins($w[0])) . '–' . $fmtMins(parseTimeMins($w[1]));
        }
    }
    return implode(' and ', $parts);
}

/**
 * Turn a raw cURL failure into something a student can act on.
 *
 * A timeout is by far the most common case and it is not a configuration
 * problem: the model simply took longer than API_TIMEOUT_SECONDS to write the
 * whole answer, which big "write me a complete program" prompts invite. Saying
 * "check your API configuration" for that would send the reader down the wrong
 * path entirely.
 */
function describeConnectionFailure(string $curlError, string $provider): array
{
    $isTimeout = stripos($curlError, 'timed out') !== false
              || stripos($curlError, 'timeout') !== false;

    if ($isTimeout) {
        return [
            'type'    => 'model_timeout',
            'message' => 'That answer took too long and the connection timed out — usually because '
                       . 'the request asked for a whole program at once. Ask for one piece at a time '
                       . '(just the player class, just the collision code), or try a faster model '
                       . 'like Haiku or DeepSeek Flash.',
        ];
    }

    return [
        'type'    => 'api_error',
        'message' => "Couldn't reach the {$provider} model right now. Wait a moment and try again, "
                   . 'or switch to a different model.',
    ];
}

/**
 * Describe the restrictions in force for this account RIGHT NOW, for the
 * banner at the top of the chat.
 *
 * Deliberately computed server-side and refreshed on every response rather
 * than worked out once in JavaScript: the answer is time-dependent (a topic
 * lock lifts inside a free window; the school-hours model limit starts at
 * 5 PM), so a banner rendered once at login goes stale mid-session. Keeping
 * the window arithmetic here also means isWithinAllowedHours() isn't
 * reimplemented in JS, where it would drift.
 *
 * Mirrors the rules applied above:
 *   - free_hours "unlimited"        → no school-hours model limit, no topic lock
 *   - topic_lock set, outside free  → subject constraint injected into system
 *   - outside Mon-Fri 7AM-5PM       → every tier forced to Haiku
 */
function buildRestrictionStatus(array $restrictions): array
{
    $tz   = new DateTimeZone('America/Los_Angeles');
    $now  = new DateTime('now', $tz);
    $mins = (int)$now->format('G') * 60 + (int)$now->format('i');
    $dow  = (int)$now->format('N');
    $hour = (int)$now->format('G');

    $freeHours = strtolower(trim($restrictions['free_hours'] ?? ''));
    $topicLock = strtolower(trim($restrictions['topic_lock'] ?? ''));

    $hoursUnlimited = ($freeHours === 'unlimited');
    $inFreeWindow   = $hoursUnlimited
        || ($freeHours !== '' && isWithinAllowedHours($freeHours, $mins));

    $isSchoolHours = ($dow >= 1 && $dow <= 5 && $hour >= 7 && $hour < 17);

    $topicActive = ($topicLock !== '' && $topicLock !== 'unlimited' && !$inFreeWindow);
    $isTutor     = $topicActive && substr($topicLock, -6) === '-tutor';
    $subject     = $isTutor ? substr($topicLock, 0, -6) : $topicLock;

    return [
        'topic_locked'   => $topicActive,
        'topic_subject'  => $topicActive ? ucfirst($subject) : null,
        'topic_mode'     => $topicActive ? ($isTutor ? 'tutor' : 'assistant') : null,
        // Set even when the lock is currently lifted, so the banner can say
        // what will come back when the free window closes.
        'topic_pending'  => ($topicLock !== '' && $topicLock !== 'unlimited' && $inFreeWindow && !$hoursUnlimited),
        'model_limited'  => (!$isSchoolHours && !$hoursUnlimited),
        'in_free_window' => ($inFreeWindow && !$hoursUnlimited),
        'free_windows'   => ($freeHours !== '' && !$hoursUnlimited) ? formatAllowedWindows($freeHours) : '',
        'unrestricted'   => ($hoursUnlimited && $topicLock === 'unlimited'),
    ];
}

/**
 * Write a full prompt+response entry to a per-student log file.
 */
function writeStudentLog(string $studentId, string $userText, string $responseText, array $meta): void
{
    $logDir = __DIR__ . '/student_logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    // Block direct web access
    $htaccess = $logDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '', $studentId ?: 'unknown');
    $logFile  = $logDir . '/' . $safeId . '.txt';

    $model    = $meta['model'] ?? 'unknown';
    $ts       = $meta['timestamp'] ?? date('Y-m-d H:i:s');
    $inTok    = $meta['input_tokens']  ?? 0;
    $outTok   = $meta['output_tokens'] ?? 0;
    $imgCount = $meta['image_count']   ?? 0;
    $imgNote  = $imgCount > 0 ? " | Images: {$imgCount}" : '';

    $entry  = "=== {$ts} | {$model} | In:{$inTok} Out:{$outTok}{$imgNote} ===\n";
    $entry .= "USER:\n" . $userText . "\n\n";
    $entry .= "CLAUDE:\n" . $responseText . "\n";
    $entry .= str_repeat('-', 60) . "\n\n";

    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Scan student message, AI response, and client-supplied system prompt for concerns.
 * Ported from wheel3/api-proxy.php; extended with HATE_SPEECH category.
 * Returns ['triggered' => bool, 'categories' => [], 'matched' => []]
 */
function detectConcerns(string $studentText, string $aiText, string $systemPrompt = ''): array
{
    $categories = [];
    $matched    = [];

    // SAFETY — self-harm or harm to others
    $safetyPatterns = [
        '/\b(hurt|harm|kill|cut)\s+(my)?self\b/i'                                      => 'self-harm',
        '/\bsuicid(e|al)\b/i'                                                           => 'suicide',
        '/\bwant\s+to\s+die\b/i'                                                        => 'want-to-die',
        '/\bend\s+my\s+life\b/i'                                                        => 'end-life',
        '/\bno\s+reason\s+to\s+live\b/i'                                               => 'no-reason-to-live',
        "/\bdon'?t\s+want\s+to\s+(live|be\s+alive|exist)\b/i"                          => 'dont-want-to-live',
        '/\b(kill|shoot|stab|murder)\s+(him|her|them|someone|everyone|people|my)\b/i'  => 'harm-to-others',
    ];
    foreach ($safetyPatterns as $pattern => $label) {
        if (preg_match($pattern, $studentText, $m)) {
            $categories[] = 'SAFETY';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // GUARDRAIL — jailbreak / prompt injection attempts
    $guardrailPatterns = [
        '/\bignore\s+(your\s+)?(instructions|rules|training|system\s*prompt|guidelines|constraints)\b/i' => 'ignore-instructions',
        '/\bpretend\s+you\s*(\'re|are)\b/i'                                              => 'pretend-you-are',
        '/\byou\s+are\s+now\b/i'                                                         => 'you-are-now',
        '/\bjailbreak\b/i'                                                               => 'jailbreak',
        '/\bforget\s+(your\s+)?(instructions?|training|rules|system)\b/i'               => 'forget-instructions',
        '/\bdisregard\s+(your\s+)?(instructions?|rules|training)\b/i'                   => 'disregard-instructions',
        '/\bact\s+as\s+if\s+you\s+(have\s+no|don\'?t\s+have)\b/i'                      => 'act-as-if',
        '/\bdo\s+anything\s+now\b/i'                                                    => 'DAN',
        '/\byour\s+new\s+(instructions?|persona|role|rules)\s+(are|is)\b/i'             => 'new-instructions',
        '/\bno\s+longer\s+an?\s*(AI|assistant|bot|chatbot)\b/i'                         => 'no-longer-AI',
    ];
    foreach ($guardrailPatterns as $pattern => $label) {
        if (preg_match($pattern, $studentText, $m)) {
            $categories[] = 'GUARDRAIL';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // TEST — secret word triggers a test alert (type "syzygy" to verify email works)
    if (preg_match('/\bsyzygy\b/i', $studentText)) {
        $categories[] = 'TEST';
        $matched[]    = 'test-trigger: "syzygy"';
    }

    // HATE_SPEECH — student-supplied system prompt or message contains hateful/inappropriate content.
    // We scan both $studentText and $systemPrompt so persona injection via the system prompt
    // is caught even when the message itself looks innocent.
    $hateTargets = $systemPrompt . ' ' . $studentText;
    $hatePatterns = [
        '/\bwhite\s+supremac/i'                                      => 'white-supremacist',
        '/\bracial\s+(superior|purity|segregat|separat)/i'           => 'racial-hate',
        '/keep.{0,25}races?\s+separat/i'                             => 'racial-separation',
        '/\b(neo.?nazi|white.?nationalist|white.?power)\b/i'         => 'extremist-ideology',
        '/\bnazi\b/i'                                                 => 'nazi-reference',
        '/\bhate\s+(crime|group|speech)\b/i'                         => 'hate-content',
        '/\b(ethnic\s+cleansing|genocide)\b/i'                       => 'genocide',
        '/\bterroris(t|m|ts)\b/i'                                    => 'terrorism',
        '/\bpedophil/i'                                              => 'pedophilia',
        '/\bchild\s+(porn|sex|abuse|exploit)/i'                      => 'CSAM',
        '/\b(porn|pornograph)\b/i'                                   => 'sexual-content',
        '/\bexplicit\s+sexual\b/i'                                   => 'explicit-sexual',
        '/\b(rape|molest)\b/i'                                       => 'sexual-violence',
    ];
    foreach ($hatePatterns as $pattern => $label) {
        if (preg_match($pattern, $hateTargets, $m)) {
            $categories[] = 'HATE_SPEECH';
            $src = ($systemPrompt !== '' && preg_match($pattern, $systemPrompt))
                ? 'system prompt' : 'message';
            $matched[] = $label . ' (' . $src . '): "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    // AI_FLAGGED — Claude itself flagged a crisis in its reply
    $aiAlertPatterns = [
        '/\btrusted\s+adult\b/i'                => 'AI-referred-to-trusted-adult',
        '/\bschool\s+counselor\b/i'             => 'AI-referred-to-counselor',
        '/\bcrisis\s+(line|center|hotline)\b/i' => 'AI-mentioned-crisis-line',
        '/\b988\b/'                             => 'AI-mentioned-988-hotline',
        '/\bemergency\s+services?\b/i'          => 'AI-mentioned-emergency-services',
    ];
    foreach ($aiAlertPatterns as $pattern => $label) {
        if (preg_match($pattern, $aiText, $m)) {
            $categories[] = 'AI_FLAGGED';
            $matched[]    = $label . ': "' . mb_substr(trim($m[0]), 0, 60) . '"';
        }
    }

    $categories = array_unique($categories);
    return [
        'triggered'  => !empty($categories),
        'categories' => array_values($categories),
        'matched'    => $matched,
    ];
}

/**
 * Build the HTML alert email body for the Claude chatbot.
 * Adapted from wheel3/api-proxy.php (removed bilingual $lang param).
 */
function buildClaudeAlertEmail(
    string $studentId, string $studentName,
    array $messages, string $aiReply, array $concerns,
    string $clientSystem = ''
): string {
    $ts          = date('Y-m-d H:i:s T');
    $safeId      = htmlspecialchars($studentId);
    $safeName    = htmlspecialchars($studentName ?: '(unknown)');
    $safeAiReply = nl2br(htmlspecialchars($aiReply));
    $catList     = implode(', ', array_map('htmlspecialchars', $concerns['categories']));
    $matchList   = implode('<br>', array_map('htmlspecialchars', $concerns['matched']));
    $systemBlock = '';
    if ($clientSystem !== '') {
        $safeSystem  = nl2br(htmlspecialchars($clientSystem));
        $systemBlock = "<h3 style='font-size:15px;margin-bottom:6px;color:#b91c1c;'>⚠️ Student-Supplied System Prompt</h3>"
                     . "<div style='background:#fef2f2;border-left:3px solid #ef4444;border-radius:6px;"
                     . "padding:10px 14px;margin-bottom:20px;white-space:pre-wrap;font-size:14px;'>"
                     . "{$safeSystem}</div>";
    }

    $transcript = '';
    foreach ($messages as $msg) {
        $role    = strtoupper($msg['role'] ?? 'USER');
        $content = is_string($msg['content']) ? $msg['content'] : '';
        $bg      = ($role === 'USER') ? '#f0f4ff' : '#f0fff4';
        $transcript .= "<div style='background:{$bg};border-radius:6px;padding:10px 14px;margin-bottom:8px;'>"
                     . "<strong style='font-size:11px;text-transform:uppercase;color:#666;'>{$role}</strong><br>"
                     . "<span style='white-space:pre-wrap;font-size:14px;'>" . htmlspecialchars($content) . "</span></div>";
    }
    $transcript .= "<div style='background:#f0fff4;border-radius:6px;padding:10px 14px;margin-bottom:8px;border-left:3px solid #22c55e;'>"
                 . "<strong style='font-size:11px;text-transform:uppercase;color:#666;'>CLAUDE (latest reply)</strong><br>"
                 . "<span style='white-space:pre-wrap;font-size:14px;'>{$safeAiReply}</span></div>";

    return <<<HTML
<html><body style="font-family:sans-serif;max-width:700px;margin:0 auto;padding:20px;color:#1e293b;">
<div style="background:#fef2f2;border:2px solid #ef4444;border-radius:12px;padding:20px 24px;margin-bottom:24px;">
    <h2 style="margin:0 0 8px;color:#b91c1c;font-size:20px;">🚨 Claude Chatbot Safety Alert</h2>
    <p style="margin:0;color:#7f1d1d;font-size:14px;">{$ts}</p>
</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:24px;">
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;width:140px;">Student</td>
        <td style="padding:6px 12px;">{$safeName} &nbsp;<span style="color:#64748b;font-size:13px;">({$safeId})</span></td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;">Alert type(s)</td>
        <td style="padding:6px 12px;color:#b91c1c;font-weight:bold;">{$catList}</td></tr>
    <tr><td style="padding:6px 12px;background:#f8fafc;font-weight:bold;vertical-align:top;">Triggered by</td>
        <td style="padding:6px 12px;font-size:13px;color:#475569;">{$matchList}</td></tr>
</table>
{$systemBlock}<h3 style="font-size:15px;margin-bottom:10px;color:#334155;">Full Conversation</h3>
{$transcript}
<hr style="margin:24px 0;border:none;border-top:1px solid #e2e8f0;">
<p style="font-size:12px;color:#94a3b8;">
    Generated automatically by the Claude chatbot at psd1.net/claude.<br>
    Full log: <code>claude/student_logs/{$safeId}.txt</code>
</p>
</body></html>
HTML;
}

/**
 * Send an email via SMTP using raw PHP sockets (no PHPMailer dependency).
 * Adapted from wheel3/api-proxy.php — supports STARTTLS (port 587) and SSL (port 465).
 */
function sendClaudeSmtpEmail(
    string $host, int $port,
    string $user, string $pass,
    string $from, string $fromName,
    string $to, string $cc,        // extra CC recipients (comma-separated, or empty)
    string $subject, string $htmlBody
): bool {
    $logFile = __DIR__ . '/claude_usage.log';
    try {
        $socket = ($port === 465)
            ? stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30)
            : stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30);
        if (!$socket) {
            file_put_contents($logFile,
                date('Y-m-d H:i:s') . " | SMTP_CONNECT_FAIL | {$errno} {$errstr}\n", FILE_APPEND);
            return false;
        }
        stream_set_timeout($socket, 30);
        $read  = fn() => fgets($socket, 512);
        $write = fn(string $cmd) => fwrite($socket, $cmd . "\r\n");

        $read();
        $write("EHLO {$host}");
        while (($line = $read()) && substr($line, 3, 1) === '-');

        if ($port === 587) {
            $write('STARTTLS'); $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO {$host}");
            while (($line = $read()) && substr($line, 3, 1) === '-');
        }

        $write('AUTH LOGIN');   $read();
        $write(base64_encode($user)); $read();
        $write(base64_encode($pass));
        $authResp = $read();
        if (strpos($authResp, '235') === false) {
            file_put_contents($logFile,
                date('Y-m-d H:i:s') . " | SMTP_AUTH_FAIL | " . trim($authResp) . "\n", FILE_APPEND);
            fclose($socket);
            return false;
        }

        $write("MAIL FROM:<{$from}>"); $read();
        $write("RCPT TO:<{$to}>");     $read();
        // CC: send RCPT TO for each extra address (comma-separated)
        $ccAddresses = array_filter(array_map('trim', explode(',', $cc)));
        foreach ($ccAddresses as $ccAddr) {
            $write("RCPT TO:<{$ccAddr}>"); $read();
        }
        $write('DATA');                $read();

        $boundary    = bin2hex(random_bytes(8));
        $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $plainText   = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        $message     = "From: {$encodedFrom} <{$from}>\r\n"
                     . "To: {$to}\r\n"
                     . (!empty($ccAddresses) ? "Cc: " . implode(', ', $ccAddresses) . "\r\n" : '')
                     . "Subject: {$subject}\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                     . "Date: " . date('r') . "\r\n\r\n"
                     . "--{$boundary}\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                     . $plainText . "\r\n"
                     . "--{$boundary}\r\n"
                     . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                     . $htmlBody . "\r\n"
                     . "--{$boundary}--\r\n";

        $write($message . '.');
        $dataResp = $read();
        fclose($socket);

        $success = strpos($dataResp, '250') !== false;
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | SMTP | to:{$to} | " . ($success ? 'OK' : 'FAIL:' . trim($dataResp)) . "\n",
            FILE_APPEND);
        return $success;

    } catch (\Throwable $e) {
        file_put_contents($logFile,
            date('Y-m-d H:i:s') . " | SMTP_EXCEPTION | " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

// (No closing PHP tag is recommended)
