<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Parser\SberParser;

$parser = new SberParser();

try {
    $rates = $parser->parse();

    echo '<pre>';
    print_r($rates);
    echo '</pre>';

} catch (Throwable $e) {
    echo '<pre>';
    echo 'ОШИБКА:' . PHP_EOL;
    echo $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString();
    echo '</pre>';
}