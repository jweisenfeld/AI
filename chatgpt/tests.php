<?php
/**
 * Lightweight PHP unit tests for the chat API helper functions.
 *
 * Run with:
 *   php tests.php
 */

declare(strict_types=1);

require_once __DIR__ . '/chat_api_utils.php';

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . " (expected: {$expected}, got: {$actual})");
    }
}

function makeTempSecrets(string $baseDir): string
{
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }
    $path = $baseDir . '/chatgptkey.php';
    file_put_contents($path, "<?php return ['OPENAI_API_KEY' => 'test'];");
    return $path;
}

$tests = [];

$tests['prefers environment override'] = function (): void {
    $override = '/tmp/nonexistent/override.php';
    $resolved = resolveSecretsPath(__DIR__, $override);
    assertSame($override, $resolved, 'Should return override path');
};

$tests['finds secrets one level up'] = function (): void {
    $tempRoot = sys_get_temp_dir() . '/chatgpt_test_' . uniqid();
    $secretsDir = $tempRoot . '/.secrets';
    $expected = makeTempSecrets($secretsDir);

    $resolved = resolveSecretsPath($tempRoot . '/public_html/chatgpt', null);
    assertSame($expected, $resolved, 'Should locate .secrets one level up');
};

$tests['finds secrets two levels up'] = function (): void {
    $tempRoot = sys_get_temp_dir() . '/chatgpt_test_' . uniqid();
    $secretsDir = $tempRoot . '/.secrets';
    $expected = makeTempSecrets($secretsDir);

    $resolved = resolveSecretsPath($tempRoot . '/public_html/chatgpt/nested', null);
    assertSame($expected, $resolved, 'Should locate .secrets two levels up');
};

$passed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
        echo "✅ {$name}\n";
    } catch (Throwable $error) {
        echo "❌ {$name}: {$error->getMessage()}\n";
    }
}

echo "\n{$passed}/" . count($tests) . " tests passed.\n";
