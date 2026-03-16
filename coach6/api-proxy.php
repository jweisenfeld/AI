<?php
/**
 * coach6 — PowerPoint Grader API Proxy
 * Handles: login, file download/upload, Gemini 2.5 Pro grading, Gmail SMTP email
 */
set_time_limit(300);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

$accountRoot = dirname($_SERVER['DOCUMENT_ROOT']);
$secretsFile = $accountRoot . '/.secrets/amentum_geminikey.php';
$studentFile = $accountRoot . '/.secrets/student_roster.csv';
$smtpFile    = $accountRoot . '/.secrets/smtp_credentials.php';

define('GEMINI_MODEL',   'gemini-2.5-pro');
define('RUBRIC_VERSION', 'v1.0');
define('TEACHER_EMAIL',  'jweisenfeld@psd1.org');
define('SUBMISSIONS_DIR', __DIR__ . '/student_submissions');
define('BAD_LINK_VIDEO', 'http://orionhs.us/anyonewiththelink');

// Accepted MIME types for uploaded/downloaded files
define('PPTX_MIME', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
define('PPT_MIME',  'application/vnd.ms-powerpoint');

header('Content-Type: application/json; charset=utf-8');

function send_error($msg, $details = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['error' => $msg, 'details' => $details]);
    exit;
}

// ── Ensure submissions directory exists ──────────────────────────────────────
if (!is_dir(SUBMISSIONS_DIR)) {
    mkdir(SUBMISSIONS_DIR, 0755, true);
}

// Block direct browser access to submissions
$htaccess = SUBMISSIONS_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Require all denied\n");
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

// =============================================================================
// HELPER: Download a SharePoint/OneDrive "Anyone with the link" share
// Uses the official OneDrive Shares API (works from server IPs; no auth needed
// for anonymous shares). Falls back to direct &download=1 if the API returns
// an error.  Returns ['bytes'=>..., 'method'=>...] or calls send_error().
// =============================================================================
function download_sharepoint(string $shareUrl): array {
    // ── Method 1: OneDrive Shares API ────────────────────────────────────────
    // Encode the share URL as base64url with "u!" prefix (Microsoft's scheme).
    $shareId     = 'u!' . rtrim(strtr(base64_encode($shareUrl), '+/', '-_'), '=');
    $apiUrl      = "https://api.onedrive.com/v1.0/shares/{$shareId}/root/content";

    $bytes = curl_get_bytes($apiUrl);
    if ($bytes !== null && strlen($bytes) > 4 && substr($bytes, 0, 2) === 'PK') {
        return ['bytes' => $bytes, 'method' => 'shares_api'];
    }

    // ── Method 2: Direct &download=1 (fallback) ──────────────────────────────
    $sep   = strpos($shareUrl, '?') !== false ? '&' : '?';
    $dlUrl = $shareUrl . $sep . 'download=1';

    $bytes = curl_get_bytes($dlUrl);
    if ($bytes !== null && strlen($bytes) > 4 && substr($bytes, 0, 2) === 'PK') {
        return ['bytes' => $bytes, 'method' => 'download1'];
    }

    // ── Both failed — detect reason ──────────────────────────────────────────
    // If we got HTML back it's a login wall; otherwise it's a bad/missing link.
    if ($bytes !== null && (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<html') !== false)) {
        send_error('restricted_link', [
            'message'    => 'This link requires sign-in. Change sharing to "Anyone with the link" and try again.',
            'video_help' => BAD_LINK_VIDEO,
        ]);
    }
    send_error(
        'Could not download your presentation. Check that the link is correct and sharing is set to "Anyone with the link".',
        ['video_help' => BAD_LINK_VIDEO]
    );
}

/**
 * cURL helper — returns raw body string or null on network/HTTP error.
 * Follows redirects, uses a real browser UA.
 */
function curl_get_bytes(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch);
    curl_close($ch);

    if ($curlErr || $httpCode >= 400) return null;
    return $body ?: null;
}

// =============================================================================
// ROUTE: test_link  (diagnostic — secret-protected)
// POST {"action":"test_link","secret":"coach6test","url":"https://..."}
// =============================================================================
if (($data['action'] ?? '') === 'test_link') {
    if (($data['secret'] ?? '') !== 'coach6test') send_error('Forbidden');
    $url = trim($data['url'] ?? '');
    if (empty($url)) send_error('url required');

    // Try Shares API
    $shareId  = 'u!' . rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $apiUrl   = "https://api.onedrive.com/v1.0/shares/{$shareId}/root/content";
    $apiBytes = curl_get_bytes($apiUrl);

    // Try &download=1
    $sep      = strpos($url, '?') !== false ? '&' : '?';
    $dlUrl    = $url . $sep . 'download=1';
    $dlBytes  = curl_get_bytes($dlUrl);

    echo json_encode([
        'shares_api' => [
            'url'          => $apiUrl,
            'bytes'        => $apiBytes !== null ? strlen($apiBytes) : null,
            'starts_with'  => $apiBytes !== null ? bin2hex(substr($apiBytes, 0, 4)) : null,
            'is_zip'       => $apiBytes !== null && substr($apiBytes, 0, 2) === 'PK',
            'looks_like_html' => $apiBytes !== null && stripos($apiBytes, '<!DOCTYPE') !== false,
            'first_100'    => $apiBytes !== null ? substr($apiBytes, 0, 100) : null,
        ],
        'download1' => [
            'url'          => $dlUrl,
            'bytes'        => $dlBytes !== null ? strlen($dlBytes) : null,
            'starts_with'  => $dlBytes !== null ? bin2hex(substr($dlBytes, 0, 4)) : null,
            'is_zip'       => $dlBytes !== null && substr($dlBytes, 0, 2) === 'PK',
            'looks_like_html' => $dlBytes !== null && stripos($dlBytes, '<!DOCTYPE') !== false,
            'first_100'    => $dlBytes !== null ? substr($dlBytes, 0, 100) : null,
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

// =============================================================================
// ROUTE: test_email  (diagnostic — secret-protected)
// POST {"action":"test_email","secret":"coach6test","to":"student@..."}
// =============================================================================
if (($data['action'] ?? '') === 'test_email') {
    if (($data['secret'] ?? '') !== 'coach6test') send_error('Forbidden');
    if (!file_exists($smtpFile)) send_error('smtp_credentials.php missing from .secrets/');
    require_once($smtpFile);

    $to      = trim($data['to'] ?? $SMTP_FROM);
    $subject = 'coach6 Email Test — ' . date('Y-m-d H:i:s');
    $body    = '<html><body style="font-family:sans-serif;padding:20px;">'
             . '<h2 style="color:#1a73e8;">coach6 Email Test</h2>'
             . '<p>If you can read this, Gmail SMTP is working correctly.</p>'
             . '<p>Sent: ' . date('Y-m-d H:i:s T') . '</p>'
             . '<p>To: ' . htmlspecialchars($to) . '</p>'
             . '</body></html>';

    $sent = send_smtp_email(
        $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
        $SMTP_FROM, $SMTP_FROM_NAME,
        $to, $TEACHER_CC,
        $subject, $body
    );

    echo json_encode([
        'success'    => $sent,
        'to'         => $to,
        'cc'         => $TEACHER_CC,
        'smtp_host'  => $SMTP_HOST,
        'smtp_port'  => $SMTP_PORT,
        'smtp_user'  => $SMTP_USER,
        'log_tail'   => file_exists(__DIR__ . '/grader.log')
            ? implode('', array_slice(file(__DIR__ . '/grader.log'), -5))
            : '(no log yet)',
    ], JSON_PRETTY_PRINT);
    exit;
}

// =============================================================================
// ROUTE: verify_login
// =============================================================================
if (($data['action'] ?? '') === 'verify_login') {
    if (!file_exists($studentFile)) send_error('Roster file missing.');
    $handle = fopen($studentFile, 'r');
    fgetcsv($handle); // skip header
    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (isset($row[2], $row[6]) &&
            trim($row[2]) === trim($data['student_id']   ?? '') &&
            trim($row[6]) === trim($data['password'] ?? '')) {
            $email = isset($row[8]) ? trim($row[8]) : '';
            $name  = isset($row[9]) ? trim($row[9]) : trim($row[2]);
            fclose($handle);
            echo json_encode(['success' => true, 'student_name' => $name, 'student_email' => $email]);
            exit;
        }
    }
    fclose($handle);
    send_error('Invalid student ID or password.');
}

// =============================================================================
// ROUTE: grade_presentation
// =============================================================================
if (($data['action'] ?? '') === 'grade_presentation') {

    $studentId    = preg_replace('/[^a-z0-9_\-]/i', '_', $data['student_id']    ?? 'unknown');
    $studentName  = $data['student_name']  ?? 'Unknown Student';
    $studentEmail = $data['student_email'] ?? '';
    $shareUrl     = trim($data['share_url'] ?? '');

    // ── Step 1: Get the PPTX bytes (URL download or base64 upload) ────────────

    $pptxBytes  = null;
    $sourceType = 'unknown';

    if (!empty($shareUrl)) {
        $result     = download_sharepoint($shareUrl); // calls send_error() on failure
        $pptxBytes  = $result['bytes'];
        $sourceType = 'url:' . $result['method'];

    } elseif (!empty($data['file_data'])) {
        // Base64-encoded file upload from the browser
        $pptxBytes  = base64_decode($data['file_data'], true);
        $sourceType = 'upload';
        if ($pptxBytes === false) {
            send_error('File upload could not be decoded. Please try again.');
        }
    } else {
        send_error('Please provide a OneDrive share link or upload a file.');
    }

    // Sanity check: PPTX files start with PK (ZIP magic bytes)
    if (strlen($pptxBytes) < 4 || substr($pptxBytes, 0, 2) !== 'PK') {
        send_error(
            'The file does not appear to be a valid PowerPoint file.',
            ['hint' => 'Make sure you are sharing a .pptx file, not a folder or web page.']
        );
    }

    // ── Step 2: Save to disk ──────────────────────────────────────────────────
    $timestamp = date('Ymd_His');
    $filename  = "{$studentId}_{$timestamp}.pptx";
    $savePath  = SUBMISSIONS_DIR . '/' . $filename;
    file_put_contents($savePath, $pptxBytes);

    $logLine = date('Y-m-d H:i:s') . " | SUBMISSION | $studentName | $studentId | $filename | src:$sourceType | " . strlen($pptxBytes) . " bytes\n";
    file_put_contents(__DIR__ . '/grader.log', $logLine, FILE_APPEND);

    // ── Step 3: Upload to Gemini Files API ────────────────────────────────────
    if (!file_exists($secretsFile)) send_error('API key file missing.');
    require_once($secretsFile); // defines $GEMINI_API_KEY

    $apiKey = trim($GEMINI_API_KEY);

    // Upload file
    $uploadUrl = "https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}";

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $pptxBytes);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . PPTX_MIME,
        'X-Goog-Upload-Protocol: raw',
        'X-Goog-Upload-Header-Content-Type: ' . PPTX_MIME,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $uploadBody = curl_exec($ch);
    $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($uploadCode !== 200) {
        file_put_contents(__DIR__ . '/grader.log',
            date('Y-m-d H:i:s') . " | UPLOAD_FAIL | $studentId | HTTP:$uploadCode | " . substr($uploadBody, 0, 300) . "\n",
            FILE_APPEND);
        send_error('Failed to upload file to AI service. Please try again in a moment.');
    }

    $uploadResult = json_decode($uploadBody, true);
    $fileUri      = $uploadResult['file']['uri'] ?? null;
    if (!$fileUri) {
        send_error('File upload succeeded but no URI returned. Please try again.');
    }

    // ── Step 4: Load rubric ───────────────────────────────────────────────────
    $rubricMd = file_get_contents(__DIR__ . '/rubric-v1.md');

    // ── Step 5: Call Gemini 2.5 Pro for rubric grading ───────────────────────
    $systemPrompt = <<<SYSTEM
You are an experienced STEM teacher grading a student's Engineering Design Process PowerPoint presentation.
You will evaluate the presentation against a specific rubric and provide detailed, encouraging, constructive feedback.

IMPORTANT RULES:
1. You only grade based on what is visible in the presentation. Do not assume content that is not shown.
2. If a slide contains text starting with "TODO:", treat it as a question the student is asking. Answer it clearly in your feedback under the "todo_answers" field.
3. Be specific: quote or reference actual slide content in your feedback whenever possible.
4. Your tone should be warm and encouraging — this is a 9th-grade student. Celebrate what they did well, then coach them on what to improve.
5. You MUST return your response as valid JSON only, with no markdown fencing, no extra commentary, no preamble.

THE RUBRIC (Version: v1.0):
$rubricMd
SYSTEM;

    $gradingPrompt = <<<PROMPT
Please grade this Engineering Design Process PowerPoint presentation.

Examine EVERY slide carefully, including all text, images, diagrams, charts, and photos of engineering notebooks or physical work.

Return ONLY a valid JSON object in exactly this structure:
{
  "rubric_version": "v1.0",
  "categories": {
    "define_the_problem": {
      "score": <integer 1-4>,
      "what_was_done_well": "<specific praise referencing actual slide content>",
      "what_to_improve": "<specific, actionable coaching>"
    },
    "generate_concepts": {
      "score": <integer 1-4>,
      "what_was_done_well": "<...>",
      "what_to_improve": "<...>"
    },
    "develop_a_solution": {
      "score": <integer 1-4>,
      "what_was_done_well": "<...>",
      "what_to_improve": "<...>"
    },
    "construct_and_test": {
      "score": <integer 1-4>,
      "what_was_done_well": "<...>",
      "what_to_improve": "<...>"
    },
    "evaluate": {
      "score": <integer 1-4>,
      "what_was_done_well": "<...>",
      "what_to_improve": "<...>"
    }
  },
  "total_score": <sum of 5 category scores, integer 5-20>,
  "overall_comments": "<2-3 sentences of warm overall encouragement and top priority for improvement>",
  "todo_answers": [
    { "slide_context": "<brief description of which slide the TODO appeared on>", "todo_text": "<the TODO text>", "answer": "<your answer to the student's question>" }
  ]
}

If there are no TODO items, return an empty array for "todo_answers".
PROMPT;

    $gradingPayload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                ['file_data' => ['mime_type' => PPTX_MIME, 'file_uri' => $fileUri]],
                ['text'      => $gradingPrompt],
            ],
        ]],
        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
        'generationConfig'  => ['responseMimeType' => 'application/json'],
    ];

    $gradingUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key={$apiKey}";

    $ch = curl_init($gradingUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($gradingPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT,        240);
    $gradingBody = curl_exec($ch);
    $gradingCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($gradingCode !== 200) {
        file_put_contents(__DIR__ . '/grader.log',
            date('Y-m-d H:i:s') . " | GRADING_FAIL | $studentId | HTTP:$gradingCode | " . substr($gradingBody, 0, 300) . "\n",
            FILE_APPEND);
        send_error('AI grading failed. Please try again in a moment.', ['http_code' => $gradingCode]);
    }

    $gradingResult = json_decode($gradingBody, true);
    $gradingJsonStr = $gradingResult['candidates'][0]['content']['parts'][0]['text'] ?? null;
    // Strip markdown fences if model added them despite instructions
    $gradingJsonStr = preg_replace('/^```(?:json)?\s*/i', '', trim($gradingJsonStr ?? ''));
    $gradingJsonStr = preg_replace('/\s*```$/', '', $gradingJsonStr);
    $grading = json_decode($gradingJsonStr, true);

    if (!$grading || !isset($grading['categories'])) {
        file_put_contents(__DIR__ . '/grader.log',
            date('Y-m-d H:i:s') . " | PARSE_FAIL | $studentId | raw:" . substr($gradingJsonStr ?? '', 0, 500) . "\n",
            FILE_APPEND);
        send_error('Could not parse AI grading response. Please try again.');
    }

    // ── Step 6: Call Gemini 2.5 Pro for grammar/spelling pass ────────────────
    $grammarPrompt = <<<PROMPT
Please review this PowerPoint presentation for grammar and spelling errors only.

Examine ALL text on ALL slides. List every distinct error you find.

Return ONLY a valid JSON object:
{
  "errors": [
    { "slide_hint": "<brief description of which slide, e.g. 'Slide 3 title'>", "original_text": "<text with error>", "issue": "<description of the error>", "suggestion": "<corrected version>" }
  ],
  "error_count": <integer, total number of errors>,
  "grammar_score": <integer, starts at 10, subtract 1 per error, minimum 0>
}

If no errors are found, return an empty array for "errors" and error_count of 0 and grammar_score of 10.
PROMPT;

    $grammarPayload = [
        'contents' => [[
            'role'  => 'user',
            'parts' => [
                ['file_data' => ['mime_type' => PPTX_MIME, 'file_uri' => $fileUri]],
                ['text'      => $grammarPrompt],
            ],
        ]],
        'systemInstruction' => ['parts' => [['text' => 'You are a precise grammar and spelling checker. Return only valid JSON as instructed.']]],
        'generationConfig'  => ['responseMimeType' => 'application/json'],
    ];

    $ch = curl_init($gradingUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($grammarPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT,        180);
    $grammarBody = curl_exec($ch);
    $grammarCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $grammar = ['errors' => [], 'error_count' => 0, 'grammar_score' => 10];
    if ($grammarCode === 200) {
        $grammarResult  = json_decode($grammarBody, true);
        $grammarJsonStr = $grammarResult['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
        $grammarJsonStr = preg_replace('/^```(?:json)?\s*/i', '', trim($grammarJsonStr));
        $grammarJsonStr = preg_replace('/\s*```$/', '', $grammarJsonStr);
        $grammarParsed  = json_decode($grammarJsonStr, true);
        if ($grammarParsed && isset($grammarParsed['errors'])) {
            $grammar = $grammarParsed;
            // Re-enforce score rule server-side
            $grammar['grammar_score'] = max(0, 10 - (int)($grammar['error_count'] ?? count($grammar['errors'])));
        }
    }

    // ── Step 7: Log usage ─────────────────────────────────────────────────────
    $usageMeta    = $gradingResult['usageMetadata'] ?? [];
    $inTokens     = ($usageMeta['promptTokenCount']     ?? 0);
    $outTokens    = ($usageMeta['candidatesTokenCount'] ?? 0);
    $totalScore   = (int)($grading['total_score'] ?? 0);
    $grammarScore = (int)($grammar['grammar_score'] ?? 10);

    $logLine = date('Y-m-d H:i:s') . " | GRADED | $studentName | $studentId | Rubric:{$totalScore}/20 | Grammar:{$grammarScore}/10 | In:{$inTokens} | Out:{$outTokens} | file:$filename\n";
    file_put_contents(__DIR__ . '/grader.log', $logLine, FILE_APPEND);

    // ── Step 8: Build and send email ──────────────────────────────────────────
    $emailSent   = false;
    $emailError  = null;

    if (!empty($studentEmail) && file_exists($smtpFile)) {
        require_once($smtpFile); // defines $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $SMTP_FROM, $SMTP_FROM_NAME, $TEACHER_CC

        $emailBody = build_email_html($studentName, $grading, $grammar, $filename);
        $emailSent = send_smtp_email(
            $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
            $SMTP_FROM, $SMTP_FROM_NAME,
            $studentEmail,
            $TEACHER_CC,
            "Engineering Design Presentation Feedback — {$studentName}",
            $emailBody
        );
        if (!$emailSent) {
            $emailError = 'Email could not be sent, but your results are shown below.';
        }
    }

    // ── Step 9: Return results to browser ────────────────────────────────────
    echo json_encode([
        'success'       => true,
        'rubric_version'=> RUBRIC_VERSION,
        'student_name'  => $studentName,
        'grading'       => $grading,
        'grammar'       => $grammar,
        'email_sent'    => $emailSent,
        'email_error'   => $emailError,
        'filename'      => $filename,
    ]);
    exit;
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * Build the HTML email body from grading results.
 */
function build_email_html(string $studentName, array $grading, array $grammar, string $filename): string {
    $totalScore   = (int)($grading['total_score'] ?? 0);
    $grammarScore = (int)($grammar['grammar_score'] ?? 10);
    $rubricVer    = htmlspecialchars($grading['rubric_version'] ?? 'v1.0');
    $overallComments = htmlspecialchars($grading['overall_comments'] ?? '');

    $categoryLabels = [
        'define_the_problem'  => 'Define the Problem',
        'generate_concepts'   => 'Generate Concepts',
        'develop_a_solution'  => 'Develop a Solution',
        'construct_and_test'  => 'Construct and Test the Prototype',
        'evaluate'            => 'Evaluate',
    ];

    $categoryRows = '';
    foreach ($categoryLabels as $key => $label) {
        $cat   = $grading['categories'][$key] ?? [];
        $score = (int)($cat['score'] ?? 0);
        $good  = htmlspecialchars($cat['what_was_done_well'] ?? '');
        $improve = htmlspecialchars($cat['what_to_improve'] ?? '');
        $stars = str_repeat('★', $score) . str_repeat('☆', 4 - $score);
        $categoryRows .= "
        <tr>
          <td style='padding:12px;border-bottom:1px solid #eee;vertical-align:top;width:180px;'>
            <strong>{$label}</strong><br>
            <span style='font-size:1.2em;color:#f4a800;'>{$stars}</span>
            <span style='color:#555;'> {$score}/4</span>
          </td>
          <td style='padding:12px;border-bottom:1px solid #eee;vertical-align:top;'>
            <span style='color:#1a7340;'>✅ {$good}</span><br><br>
            <span style='color:#8b4500;'>💡 {$improve}</span>
          </td>
        </tr>";
    }

    // Grammar errors table
    $grammarRows = '';
    if (!empty($grammar['errors'])) {
        foreach ($grammar['errors'] as $err) {
            $slide      = htmlspecialchars($err['slide_hint']    ?? '');
            $original   = htmlspecialchars($err['original_text'] ?? '');
            $issue      = htmlspecialchars($err['issue']         ?? '');
            $suggestion = htmlspecialchars($err['suggestion']    ?? '');
            $grammarRows .= "
            <tr>
              <td style='padding:8px;border-bottom:1px solid #eee;color:#888;font-size:0.85em;'>{$slide}</td>
              <td style='padding:8px;border-bottom:1px solid #eee;'><span style='color:#c0392b;'>{$original}</span></td>
              <td style='padding:8px;border-bottom:1px solid #eee;'>{$issue}</td>
              <td style='padding:8px;border-bottom:1px solid #eee;color:#1a7340;'>{$suggestion}</td>
            </tr>";
        }
    } else {
        $grammarRows = "<tr><td colspan='4' style='padding:12px;color:#1a7340;'>No grammar or spelling errors found! Great work.</td></tr>";
    }

    // TODO answers
    $todoSection = '';
    if (!empty($grading['todo_answers'])) {
        $todoRows = '';
        foreach ($grading['todo_answers'] as $todo) {
            $context = htmlspecialchars($todo['slide_context'] ?? '');
            $todoTxt = htmlspecialchars($todo['todo_text']     ?? '');
            $answer  = htmlspecialchars($todo['answer']        ?? '');
            $todoRows .= "
            <tr>
              <td style='padding:10px;border-bottom:1px solid #eee;color:#888;font-size:0.85em;vertical-align:top;'>{$context}</td>
              <td style='padding:10px;border-bottom:1px solid #eee;color:#8b4500;vertical-align:top;'><em>{$todoTxt}</em></td>
              <td style='padding:10px;border-bottom:1px solid #eee;vertical-align:top;'>{$answer}</td>
            </tr>";
        }
        $todoSection = "
        <h2 style='color:#1a73e8;border-bottom:2px solid #1a73e8;padding-bottom:6px;'>Your TODO Questions — Answered</h2>
        <table style='width:100%;border-collapse:collapse;margin-bottom:30px;'>
          <thead>
            <tr style='background:#f8f9fa;'>
              <th style='padding:8px;text-align:left;'>Slide</th>
              <th style='padding:8px;text-align:left;'>Your TODO</th>
              <th style='padding:8px;text-align:left;'>Answer</th>
            </tr>
          </thead>
          <tbody>{$todoRows}</tbody>
        </table>";
    }

    $date = date('F j, Y');
    $safeStudent = htmlspecialchars($studentName);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:'Segoe UI',Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
  <div style="max-width:800px;margin:0 auto;background:white;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.12);">

    <!-- Header -->
    <div style="background:#1a73e8;color:white;padding:28px 32px;">
      <h1 style="margin:0;font-size:1.5em;">Engineering Design Presentation Feedback</h1>
      <p style="margin:6px 0 0;opacity:0.85;">For: <strong>{$safeStudent}</strong> &nbsp;·&nbsp; {$date} &nbsp;·&nbsp; Rubric {$rubricVer}</p>
    </div>

    <div style="padding:32px;">

      <!-- Score summary -->
      <div style="display:flex;gap:20px;margin-bottom:30px;flex-wrap:wrap;">
        <div style="flex:1;min-width:160px;background:#e8f5e9;border-radius:8px;padding:20px;text-align:center;">
          <div style="font-size:2.5em;font-weight:bold;color:#1a7340;">{$totalScore}</div>
          <div style="color:#555;">out of 20</div>
          <div style="font-size:0.85em;color:#888;margin-top:4px;">Engineering Design</div>
        </div>
        <div style="flex:1;min-width:160px;background:#fff3e0;border-radius:8px;padding:20px;text-align:center;">
          <div style="font-size:2.5em;font-weight:bold;color:#e65100;">{$grammarScore}</div>
          <div style="color:#555;">out of 10</div>
          <div style="font-size:0.85em;color:#888;margin-top:4px;">Grammar &amp; Spelling</div>
        </div>
      </div>

      <!-- Overall comments -->
      <div style="background:#f0f7ff;border-left:4px solid #1a73e8;padding:16px 20px;border-radius:4px;margin-bottom:30px;">
        <strong>Overall Feedback:</strong><br>{$overallComments}
      </div>

      <!-- Rubric breakdown -->
      <h2 style="color:#1a73e8;border-bottom:2px solid #1a73e8;padding-bottom:6px;">Rubric Breakdown</h2>
      <table style="width:100%;border-collapse:collapse;margin-bottom:30px;">
        <tbody>{$categoryRows}</tbody>
      </table>

      <!-- TODO answers -->
      {$todoSection}

      <!-- Grammar / Spelling -->
      <h2 style="color:#e65100;border-bottom:2px solid #e65100;padding-bottom:6px;">Grammar &amp; Spelling ({$grammarScore}/10)</h2>
      <table style="width:100%;border-collapse:collapse;margin-bottom:30px;">
        <thead>
          <tr style="background:#fff3e0;">
            <th style="padding:8px;text-align:left;">Slide</th>
            <th style="padding:8px;text-align:left;">Error Found</th>
            <th style="padding:8px;text-align:left;">Issue</th>
            <th style="padding:8px;text-align:left;">Suggestion</th>
          </tr>
        </thead>
        <tbody>{$grammarRows}</tbody>
      </table>

      <p style="color:#999;font-size:0.8em;">File saved: {$filename} &nbsp;·&nbsp; Graded by Gemini 2.5 Pro &nbsp;·&nbsp; This is AI-generated feedback and should be reviewed by your teacher.</p>
    </div>
  </div>
</body>
</html>
HTML;
}

/**
 * Send an email via SMTP using raw PHP sockets (no PHPMailer dependency).
 * Supports STARTTLS on port 587 and SSL on port 465.
 */
function send_smtp_email(
    string $host, int $port,
    string $user, string $pass,
    string $from, string $fromName,
    string $to, string $cc,
    string $subject, string $htmlBody
): bool {
    try {
        // Open socket
        if ($port === 465) {
            $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30);
        } else {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30);
        }
        if (!$socket) {
            file_put_contents(__DIR__ . '/grader.log',
                date('Y-m-d H:i:s') . " | SMTP_CONNECT_FAIL | {$errno} {$errstr}\n", FILE_APPEND);
            return false;
        }
        stream_set_timeout($socket, 30);

        $read = function() use ($socket) {
            $resp = '';
            while ($line = fgets($socket, 512)) {
                $resp .= $line;
                if (substr($line, 3, 1) === ' ') break; // end of multi-line response
            }
            return $resp;
        };
        $write = function(string $cmd) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
        };

        $read(); // 220 greeting

        $write("EHLO coach6.psd1.net");
        $ehloResp = $read();

        // STARTTLS upgrade for port 587
        if ($port === 587) {
            $write("STARTTLS");
            $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO coach6.psd1.net");
            $read();
        }

        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $authResp = $read();
        if (strpos($authResp, '235') === false) {
            file_put_contents(__DIR__ . '/grader.log',
                date('Y-m-d H:i:s') . " | SMTP_AUTH_FAIL | " . trim($authResp) . "\n", FILE_APPEND);
            fclose($socket);
            return false;
        }

        $write("MAIL FROM:<{$from}>");
        $read();
        $write("RCPT TO:<{$to}>");
        $read();
        if (!empty($cc)) {
            $write("RCPT TO:<{$cc}>");
            $read();
        }
        $write("DATA");
        $read();

        // Build RFC 2822 message
        $boundary = 'boundary_' . md5(uniqid());
        $headers  = "From: " . mime_header_encode($fromName) . " <{$from}>\r\n"
                  . "To: {$to}\r\n"
                  . (!empty($cc) ? "Cc: {$cc}\r\n" : "")
                  . "Subject: " . mime_header_encode($subject) . "\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                  . "Date: " . date('r') . "\r\n";

        $plainText = "Engineering Design Presentation Feedback for {$subject}\r\n"
                   . "Please view this email in an HTML-capable email client for full formatting.";

        $message = $headers . "\r\n"
                 . "--{$boundary}\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                 . $plainText . "\r\n"
                 . "--{$boundary}\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                 . $htmlBody . "\r\n"
                 . "--{$boundary}--\r\n"
                 . "\r\n.";

        fwrite($socket, $message . "\r\n");
        $dataResp = $read();

        $write("QUIT");
        fclose($socket);

        $success = strpos($dataResp, '250') !== false;
        file_put_contents(__DIR__ . '/grader.log',
            date('Y-m-d H:i:s') . " | EMAIL | to:{$to} | " . ($success ? 'OK' : 'FAIL:' . trim($dataResp)) . "\n", FILE_APPEND);
        return $success;

    } catch (\Throwable $e) {
        file_put_contents(__DIR__ . '/grader.log',
            date('Y-m-d H:i:s') . " | SMTP_EXCEPTION | " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

function mime_header_encode(string $str): string {
    if (preg_match('/[^\x20-\x7E]/', $str) || strpbrk($str, '"\\') !== false) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}

send_error('Unknown action.');
