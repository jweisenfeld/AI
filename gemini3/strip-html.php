<?php
/**
 * strip-html.php
 *
 * Converts Pasco-Municipal-Code.html to a lean plain-text file that fits
 * within Gemini's 1M token context window.
 *
 * Strategy: strip ALL HTML — output pure text with lightweight markdown-style
 * section headers (### Title) so Gemini understands document structure without
 * any tag overhead. Tables are converted to simple text rows.
 *
 * Target: under 3.5MB / ~875k tokens (leaving headroom for conversation).
 *
 * Run via browser: https://yoursite.com/gemini3/strip-html.php?secret=amentum2025
 * Run via CLI:     php strip-html.php
 */

set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

define('CREATE_SECRET', 'amentum2025');
if (php_sapi_name() !== 'cli') {
    if (($_GET['secret'] ?? '') !== CREATE_SECRET) {
        http_response_code(403);
        die("403 Forbidden\n");
    }
}

$inputFile   = __DIR__ . '/Pasco-Municipal-Code.html';
$outputFile  = __DIR__ . '/Pasco-Municipal-Code-clean.html';  // kept as .html for mime_type compatibility

if (!file_exists($inputFile)) die("ERROR: Input file not found: $inputFile\n");

echo "Reading HTML file...\n";
$html = file_get_contents($inputFile);
echo "  Raw size: " . number_format(strlen($html)) . " bytes\n\n";

// ── Step 1: Nuke entire non-content blocks ────────────────────────────────────
echo "Step 1: Removing scripts, styles, nav, head, footer...\n";
$remove_blocks = [
    'script', 'style', 'head', 'nav', 'header', 'footer', 'aside',
    'noscript', 'iframe', 'form', 'button', 'input', 'select', 'textarea',
    'svg', 'canvas', 'map', 'object', 'embed'
];
foreach ($remove_blocks as $tag) {
    $html = preg_replace('/<' . $tag . '\b[^>]*>[\s\S]*?<\/' . $tag . '>/i', '', $html);
    $html = preg_replace('/<' . $tag . '\b[^>]*\/?>/i', '', $html);
}

// ── Step 2: Remove comments ───────────────────────────────────────────────────
echo "Step 2: Removing comments...\n";
$html = preg_replace('/<!--[\s\S]*?-->/', '', $html);

// ── Step 3: Convert headings to plain-text section markers ───────────────────
echo "Step 3: Converting headings to text markers...\n";
$html = preg_replace_callback('/<h([1-6])\b[^>]*>([\s\S]*?)<\/h\1>/i', function($m) {
    $level = (int)$m[1];
    $text  = trim(strip_tags($m[2]));
    if (empty($text)) return '';
    $prefix = str_repeat('#', $level);
    return "\n\n$prefix $text\n";
}, $html);

// ── Step 4: Convert table cells to pipe-separated text ───────────────────────
echo "Step 4: Flattening tables...\n";
$html = preg_replace('/<th\b[^>]*>([\s\S]*?)<\/th>/i', ' | $1', $html);
$html = preg_replace('/<td\b[^>]*>([\s\S]*?)<\/td>/i', ' | $1', $html);
$html = preg_replace('/<tr\b[^>]*>/i', "\n", $html);
$html = preg_replace('/<\/tr>/i', '', $html);
$html = preg_replace('/<\/?(table|thead|tbody|tfoot)\b[^>]*>/i', "\n", $html);

// ── Step 5: Convert list items to dashes ─────────────────────────────────────
echo "Step 5: Converting lists...\n";
$html = preg_replace('/<li\b[^>]*>([\s\S]*?)<\/li>/i', "\n- $1", $html);
$html = preg_replace('/<\/?(ul|ol)\b[^>]*>/i', "\n", $html);

// ── Step 6: Add line breaks at block-level elements ───────────────────────────
echo "Step 6: Adding newlines at block boundaries...\n";
$html = preg_replace('/<\/?(p|div|section|article|main|blockquote|pre|br)\b[^>]*>/i', "\n", $html);

// ── Step 7: Strip all remaining tags ─────────────────────────────────────────
echo "Step 7: Stripping remaining tags...\n";
$text = strip_tags($html);

// ── Step 8: Decode HTML entities ─────────────────────────────────────────────
echo "Step 8: Decoding HTML entities...\n";
$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// ── Step 9: Normalize whitespace ─────────────────────────────────────────────
echo "Step 9: Normalizing whitespace...\n";
$text = str_replace(["\r\n", "\r"], "\n", $text);
$text = preg_replace('/[ \t]+/', ' ', $text);           // collapse horizontal space
$text = preg_replace('/^ /m', '', $text);                // trim leading space per line
$text = preg_replace('/\n{4,}/', "\n\n\n", $text);      // max 3 blank lines
// Remove lines that are only whitespace or 1-2 meaningless chars
$lines = explode("\n", $text);
$lines = array_filter($lines, function($line) {
    $t = trim($line);
    return strlen($t) > 2 || $t === '' || $t[0] === '#' || $t[0] === '-';
});
$text = implode("\n", $lines);
$text = preg_replace('/\n{4,}/', "\n\n\n", $text);
$text = trim($text);

// ── Step 10: Save ─────────────────────────────────────────────────────────────
file_put_contents($outputFile, $text);

$outSize = strlen($text);
$inSize  = filesize($inputFile);
$pct     = round((1 - $outSize / $inSize) * 100);
// Plain text tokenizes at ~4 chars/token
$estTok  = (int)($outSize / 4);

echo "\n=== RESULTS ===\n";
echo "  Input      : " . number_format($inSize)   . " bytes (raw HTML)\n";
echo "  Output     : " . number_format($outSize)  . " bytes ($pct% reduction)\n";
echo "  Saved to   : $outputFile\n";
echo "  Est. tokens: ~" . number_format($estTok) . " (target: <1,000,000)\n\n";

if ($estTok > 1_000_000) {
    echo "WARN: Still over 1M tokens (~$estTok estimated).\n";
    echo "      Consider trimming appendices or boilerplate sections from the source HTML.\n";
} else {
    echo "SUCCESS: Estimated " . number_format($estTok) . " tokens — fits in 1M context window.\n";
    echo "Now re-run cache-create.php to upload the new version.\n";
}

// Also update mime_type hint file so cache-create knows to send as text/plain
file_put_contents(__DIR__ . '/Pasco-Municipal-Code-clean.mime', 'text/plain');
echo "\nMime type set to text/plain (saved hint file).\n";
