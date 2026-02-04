<?php
/**
 * Shared helpers for chat_api.php and its unit tests.
 */

declare(strict_types=1);

/**
 * Resolve the secrets file path using environment overrides or common hosting layouts.
 *
 * @param string $currentDir Directory of chat_api.php (usually /home2/<acct>/public_html/chatgpt).
 * @param string|null $envOverride Explicit path from CHATGPT_SECRETS_PATH.
 */
function resolveSecretsPath(string $currentDir, ?string $envOverride): string
{
    if (!empty($envOverride)) {
        return $envOverride;
    }

    $candidates = [
        $currentDir . '/.secrets/chatgptkey.php',
        dirname($currentDir) . '/.secrets/chatgptkey.php',
        dirname(dirname($currentDir)) . '/.secrets/chatgptkey.php',
        dirname(dirname(dirname($currentDir))) . '/.secrets/chatgptkey.php',
    ];

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    return $candidates[count($candidates) - 1];
}
