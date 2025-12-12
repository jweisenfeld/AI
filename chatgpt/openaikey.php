<?php
/**
 * OpenAI API secrets
 *
 * IMPORTANT:
 * - Keep this file OUTSIDE public_html (recommended: /home2/<account>/.secrets/openaikey.php)
 * - Do NOT commit this file to Git
 */

return [
    // Required
    'OPENAI_API_KEY' => 'YOUR_OPENAI_API_KEY_HERE',

    // Optional: only needed if you use a legacy user key across multiple orgs/projects
    // 'OPENAI_ORG'     => 'org_...',
    // 'OPENAI_PROJECT' => 'proj_...'
];

// (No closing PHP tag is recommended)
