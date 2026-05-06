<?php
// demo/autoload.php

spl_autoload_register(function (string $class): void {
    // Retire le namespace pour obtenir juste le nom de la classe
    $class = str_replace('Ols\\PhpFts\\', '', $class);
    require __DIR__ . '/../src/' . $class . '.php';
});
