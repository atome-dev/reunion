<?php

/**
 * PreToolUse hook: blocks edits to secrets and lock files.
 */
$input = json_decode(stream_get_contents(STDIN), true) ?? [];
$fileName = basename($input['tool_input']['file_path'] ?? '');

$isProtected = in_array($fileName, ['.env', 'auth.json', 'composer.lock', 'package-lock.json'], true)
    || (str_starts_with($fileName, '.env.') && $fileName !== '.env.example');

if ($isProtected) {
    fwrite(STDERR, "Modification de {$fileName} bloquée par le hook protect-files : fichier sensible ou lock file. Utilise composer/npm ou demande à l'utilisateur.\n");
    exit(2);
}

exit(0);
