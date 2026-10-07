<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database\Database;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

try {
    $db = Database::getConnection();

    echo "Подключение к базе данных успешно.";
} catch (Throwable $e) {
    echo "Ошибка: " . $e->getMessage();
}