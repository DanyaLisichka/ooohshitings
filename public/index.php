<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\Database;

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    echo '<h2>Загрузка .env: OK</h2>';

    $db = Database::getConnection();

    echo '<h2>Подключение к БД: OK</h2>';

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