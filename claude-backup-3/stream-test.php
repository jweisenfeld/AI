<?php
/**
 * TEMPORARY diagnostic — does this host let Server-Sent Events through?
 *
 * Before rewriting api-proxy.php to stream, we need to know whether BlueHost
 * actually delivers chunks as they're written, or buffers the whole response
 * and hands it over at the end. If it buffers, streaming buys nothing: the
 * student still stares at a spinner for the full generation time, and the
 * timeouts come back.
 *
 * Emits one SSE event per second for 10 seconds, each stamped with the server
 * time it was written. The client (stream-test.py) records when each one
 * ARRIVES. Even spacing means streaming works; everything landing at once
 * means the host buffered it.
 *
 * ?pad=1 sends 4KB of comment padding first. Some proxies and PHP handlers
 * won't flush until a minimum number of bytes has accumulated, so if the plain
 * run buffers but the padded run streams, padding is the workaround.
 *
 * No API keys, no cost, no student data. DELETE THIS FILE once the question
 * is answered — it's a diagnostic, not a feature.
 */

// Turn off every layer of buffering PHP controls.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
@ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
// nginx/proxy hint: do not buffer this response.
header('X-Accel-Buffering: no');

@set_time_limit(40);

$events = 10;
$start  = microtime(true);

// Optional padding for handlers that won't flush a small buffer.
if (isset($_GET['pad'])) {
    echo ': ' . str_repeat('padding ', 512) . "\n\n";
    @flush();
}

for ($i = 1; $i <= $events; $i++) {
    $elapsed = round(microtime(true) - $start, 3);
    echo "event: tick\n";
    echo 'data: ' . json_encode(['n' => $i, 'server_elapsed' => $elapsed]) . "\n\n";
    @flush();
    if ($i < $events) {
        sleep(1);
    }
}

echo "event: done\n";
echo 'data: ' . json_encode(['total' => round(microtime(true) - $start, 3)]) . "\n\n";
@flush();
