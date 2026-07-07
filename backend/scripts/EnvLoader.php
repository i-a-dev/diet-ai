<?php

declare(strict_types=1);

/**
 * プロジェクトルートの .env を読み込み、putenv / $_ENV に設定する。
 */
function load_project_env(?string $envPath = null): void
{
    $path = $envPath ?? dirname(__DIR__, 2) . '/.env';

    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $delimiterPos = strpos($line, '=');

        if ($delimiterPos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $delimiterPos));
        $value = trim(substr($line, $delimiterPos + 1));

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($name === '') {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
