<?php
// OHS Memory — Server Configuration
// This file lives on the server. DO NOT commit with real keys.
// On psd1.net cPanel: upload this file, fill in your values.

define('OPENAI_API_KEY',    'sk-proj-...');
define('SUPABASE_URL',      'https://[project-ref].supabase.co');
define('SUPABASE_ANON_KEY', 'eyJ...');

// Embedding model — MUST match what ingest.py uses
define('EMBEDDING_MODEL', 'text-embedding-3-small');
