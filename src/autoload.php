<?php

declare(strict_types=1);

/**
 * Standalone PSR-4 autoloader for installations without Composer.
 *
 *     require '/path/to/php-fts/src/autoload.php';
 *
 * Composer users do not need this file.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ols\\PhpFts\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
