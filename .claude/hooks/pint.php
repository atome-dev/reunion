<?php

/**
 * PostToolUse hook: formats the edited PHP file with Laravel Pint.
 */
$input = json_decode(stream_get_contents(STDIN), true) ?? [];
$filePath = $input['tool_input']['file_path'] ?? $input['tool_response']['filePath'] ?? '';

if (! str_ends_with($filePath, '.php') || ! is_file($filePath)) {
    exit(0);
}

$projectDir = getenv('CLAUDE_PROJECT_DIR') ?: dirname(__DIR__, 2);

passthru(sprintf(
    'cd %s && vendor/bin/pint %s --format agent',
    escapeshellarg($projectDir),
    escapeshellarg($filePath),
));

exit(0);
