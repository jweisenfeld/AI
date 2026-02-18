<?php
/**
 * strip-html.php
 *
 * Produces Pasco-Municipal-Code-clean.html from the raw scraped file.
 *
 * Strategy: KEEP structural HTML (headings, paragraphs, lists, tables)
 *           because Gemini reads HTML well and structure aids comprehension.
 *           REMOVE everything that burns tokens without adding meaning:
 *             - <script>, <style>, <head>, <nav>, <header>, <footer>
 *             - All HTML attributes (class, id, style, onclick, data-*, etc.)
 *             - HTML comments
 *             - Excess blank lines
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

$inputFile  = __DIR__ . '/Pasco-Municipal-Code.html';
$outputFile = __DIR__ . '/Pasco-Municipal-Code-clean.html';

if (!file_exists($inputFile)) die("ERROR: Input file not found: $inputFile\n");

echo "Reading HTML file...\n";
$html = file_get_contents($inputFile);
echo "  Raw size: " . number_format(strlen($html)) . " bytes\n\n";

// ── Step 1: Nuke entire junk blocks ──────────────────────────────────────────
echo "Step 1: Removing scripts, styles, head, nav, header, footer...\n";
$remove_blocks = ['script', 'style', 'head', 'nav', 'header', 'footer', 'aside', 'noscript', 'iframe', 'form', 'button', 'input', 'select', 'textarea'];
foreach ($remove_blocks as $tag) {
    $html = preg_replace('/<' . $tag . '\b[^>]*>[\s\S]*?<\/' . $tag . '>/i', '', $html);
    // Also remove self-closing variants
    $html = preg_replace('/<' . $tag . '\b[^>]*\/>/i', '', $html);
}

// ── Step 2: Remove HTML comments ─────────────────────────────────────────────
echo "Step 2: Removing comments...\n";
$html = preg_replace('/<!--[\s\S]*?-->/', '', $html);

// ── Step 3: Strip ALL attributes from ALL remaining tags ─────────────────────
// Keeps tag names but removes class="...", id="...", style="...", data-*, onclick, etc.
// This is the big token saver — attributes are pure noise for an AI reading content.
echo "Step 3: Stripping all HTML attributes...\n";
$html = preg_replace('/<([a-z][a-z0-9]*)\s+[^>]*?(\/?)\s*>/i', '<$1$2>', $html);

// ── Step 4: Remove tags that add zero meaning without their attributes ────────
// <div>, <span> without class/id are structural noise; collapse them
echo "Step 4: Collapsing meaningless wrappers...\n";
$html = preg_replace('/<\/?(?:div|span|section|article|main|figure|figcaption|label)>/i', '', $html);

// ── Step 5: Collapse whitespace ───────────────────────────────────────────────
echo "Step 5: Collapsing whitespace...\n";
// Normalize line endings
$html = str_replace(["\r\n", "\r"], "\n", $html);
// Collapse runs of spaces/tabs to single space
$html = preg_replace('/[ \t]+/', ' ', $html);
// No more than 2 consecutive blank lines
$html = preg_replace('/\n{3,}/', "\n\n", $html);
$html = trim($html);

// ── Step 6: Wrap in minimal valid HTML ───────────────────────────────────────
echo "Step 6: Wrapping in minimal HTML shell...\n";
$html = "<!DOCTYPE html>\n<html>\n<body>\n" . $html . "\n</body>\n</html>";

// ── Step 7: Save ─────────────────────────────────────────────────────────────
file_put_contents($outputFile, $html);

$outSize  = strlen($html);
$inSize   = filesize($inputFile);
$pct      = round((1 - $outSize / $inSize) * 100);
$estTok   = (int)($outSize / 3.5); // HTML tokens ~3.5 chars each (denser than prose)

echo "\n=== RESULTS ===\n";
echo "  Input  : " . number_format($inSize)   . " bytes\n";
echo "  Output : " . number_format($outSize)  . " bytes  ($pct% reduction)\n";
echo "  Saved  : $outputFile\n";
echo "  Est. tokens: ~" . number_format($estTok) . "\n\n";

if ($estTok > 1_000_000) {
    echo "WARN: Still estimated over 1M tokens.\n";
    echo "      The actual Gemini tokenizer may differ — try cache-create.php anyway.\n";
    echo "      If it fails, we'll switch to the Files API approach.\n";
} else {
    echo "Looks promising! Now run cache-create.php to attempt the upload.\n";
}
