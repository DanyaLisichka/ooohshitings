<?php

declare(strict_types=1);

use App\Database\Database;
use App\Parser\ParserFactory;
use App\Service\RateService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

// Загружаем настройки из .env.
Dotenv::createImmutable($root)->safeLoad();

$bankCode = $argv[1] ?? '';

if ($bankCode === '') {
    fwrite(
        STDERR,
        "Укажите код банка. Пример: php cron/parse-bank.php sber\n"
    );
    exit(2);
}

try {
    $pdo = Database::getConnection();

    // Получаем банк и имя его парсера из БД.
    $statement = $pdo->prepare(
        'SELECT id, name, code, parser_class
         FROM banks
         WHERE code = :code
         LIMIT 1'
    );

    $statement->execute([
        'code' => $bankCode,
    ]);

    $bank = $statement->fetch();

    if (!$bank) {
        throw new RuntimeException(
            "Банк с кодом {$bankCode} не найден в таблице banks"
        );
    }

    $bankId = (int) $bank['id'];
    $rateService = new RateService($pdo);

    // Отдельно обрабатываем ошибки парсинга и сохранения.
    try {
        $parser = ParserFactory::create(
            $bank['parser_class']
        );

        $rates = $parser->parse();

        $count = $rateService->saveRates(
            $bankId,
            $rates
        );

    } catch (Throwable $e) {
        $message = get_class($e) . ': ' . $e->getMessage();

        try {
            $rateService->writeLog(
                $bankId,
                'error',
                $message
            );
        } catch (Throwable $logError) {
            fwrite(
                STDERR,
                "Не удалось записать ошибку в журнал: "
                . $logError->getMessage() . "\n"
            );
        }

        fwrite(
            STDERR,
            "Ошибка для банка {$bank['name']}: {$message}\n"
        );

        exit(1);
    }

    // Курсы сохранены — фиксируем успешный запуск.
    try {
        $rateService->writeLog($bankId, 'success');
    } catch (Throwable $e) {
        fwrite(
            STDERR,
            "Курсы сохранены, но не удалось записать success в журнал: "
            . $e->getMessage() . "\n"
        );

        exit(1);
    }

    echo "Банк: {$bank['name']}\n";
    echo "Обработано курсов: {$count}\n";
    echo "Статус: success\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "Ошибка запуска: " . $e->getMessage() . "\n"
    );

    exit(1);
}