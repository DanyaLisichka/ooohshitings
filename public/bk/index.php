<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\Database;
use App\Parser\ParserFactory;

try {
    // Загружаем .env
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    echo '<h2>.env: OK</h2>';

    // Проверяем БД
    $db = Database::getConnection();

    echo '<h2>БД: OK</h2>';

    // Создаём тестовый парсер
    $parser = ParserFactory::create(
        'App\\Parser\\SberParser'
    );

    // Получаем данные
    $rates = $parser->parse();

    echo '<h2>Парсер: OK</h2>';

    echo '<pre>';
    print_r($rates);
    echo '</pre>';

} catch (Throwable $e) {

    echo '<h2>Ошибка:</h2>';

    echo '<pre>';
    echo htmlspecialchars($e->getMessage());
    echo "\n\n";
    echo htmlspecialchars($e->getFile());
    echo ':';
    echo $e->getLine();
    echo "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
}