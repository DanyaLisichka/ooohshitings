<?php
// Подключение к БД. Если у тимлида уже есть свой Database.php, используй его,
// а этот файл можно удалить (и поправить require в index.php).

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

function getPdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? ''
    );

    return new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}