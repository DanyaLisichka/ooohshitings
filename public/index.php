<?php

declare(strict_types=1);

use App\Database\Database;
use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

$currencyNames = [
    'USD' => 'Доллар США',
    'EUR' => 'Евро',
    'CNY' => 'Китайский юань',
];

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatRate(mixed $value): string
{
    if ($value === null || !is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, 2, ',', ' ');
}

function formatDateTime(?string $value): string
{
    if ($value === null || $value === '') {
        return 'Нет данных';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? $value : date('d.m.Y H:i', $timestamp);
}

$banks = [];
$databaseError = null;
$pageGeneratedAt = date('d.m.Y H:i:s');

try {
    // Загружаем настройки локальной или удалённой БД из .env.
    Dotenv::createImmutable($projectRoot)->safeLoad();

    $pdo = Database::getConnection();

    // Для каждого банка показываем USD, EUR и CNY, даже если часть курсов отсутствует.
    // Парсеры здесь НЕ запускаются: страница только читает сохранённые данные.
    $sql = "
        SELECT
            b.id AS bank_id,
            b.name AS bank_name,
            b.code AS bank_code,
            c.char_code AS currency_code,
            c.name AS currency_name,
            r.buy,
            r.sell,
            r.updated_at
        FROM banks b
        LEFT JOIN currencies c
            ON c.char_code IN ('USD', 'EUR', 'CNY')
        LEFT JOIN rates r
            ON r.bank_id = b.id
            AND r.currency_id = c.id
        ORDER BY b.name, FIELD(c.char_code, 'USD', 'EUR', 'CNY')
    ";

    $rows = $pdo->query($sql)->fetchAll();

    foreach ($rows as $row) {
        $bankId = (int) $row['bank_id'];

        if (!isset($banks[$bankId])) {
            $banks[$bankId] = [
                'id' => $bankId,
                'name' => (string) $row['bank_name'],
                'code' => strtoupper((string) $row['bank_code']),
                'rates' => [],
                'updated_at' => null,
                'last_log' => null,
            ];

            foreach ($currencyNames as $code => $fallbackName) {
                $banks[$bankId]['rates'][$code] = [
                    'name' => $fallbackName,
                    'buy' => null,
                    'sell' => null,
                ];
            }
        }

        $currencyCode = strtoupper((string) ($row['currency_code'] ?? ''));

        if ($currencyCode !== '' && isset($banks[$bankId]['rates'][$currencyCode])) {
            $currencyName = $row['currency_name'] ?? null;
            if (is_string($currencyName) && $currencyName !== '') {
                $banks[$bankId]['rates'][$currencyCode]['name'] = $currencyName;
            }

            if ($row['buy'] !== null && $row['sell'] !== null) {
                $banks[$bankId]['rates'][$currencyCode]['buy'] = (float) $row['buy'];
                $banks[$bankId]['rates'][$currencyCode]['sell'] = (float) $row['sell'];
            }

            $updatedAt = $row['updated_at'];
            if (
                is_string($updatedAt) &&
                $updatedAt !== '' &&
                ($banks[$bankId]['updated_at'] === null || $updatedAt > $banks[$bankId]['updated_at'])
            ) {
                $banks[$bankId]['updated_at'] = $updatedAt;
            }
        }
    }

    // Получаем последний результат запуска парсера для каждого банка, если журнал уже заполнен.
    if ($banks !== []) {
        $logSql = "
            SELECT pl.bank_id, pl.status, pl.error_message, pl.created_at
            FROM parsing_logs pl
            INNER JOIN (
                SELECT bank_id, MAX(id) AS last_id
                FROM parsing_logs
                GROUP BY bank_id
            ) latest ON latest.last_id = pl.id
        ";

        foreach ($pdo->query($logSql)->fetchAll() as $log) {
            $bankId = (int) $log['bank_id'];

            if (isset($banks[$bankId])) {
                $banks[$bankId]['last_log'] = [
                    'status' => (string) $log['status'],
                    'error_message' => $log['error_message'] !== null
                        ? (string) $log['error_message']
                        : null,
                    'created_at' => (string) $log['created_at'],
                ];
            }
        }
    }

    foreach ($banks as &$bank) {
        $availableCount = 0;

        foreach ($bank['rates'] as $rate) {
            if ($rate['buy'] !== null && $rate['sell'] !== null) {
                $availableCount++;
            }
        }

        $bank['available_count'] = $availableCount;
        $bank['total_count'] = count($currencyNames);

        if (($bank['last_log']['status'] ?? null) === 'error') {
            $bank['status_text'] = 'Последний запуск завершился ошибкой';
            $bank['status_class'] = 'status-error';
        } elseif (($bank['last_log']['status'] ?? null) === 'success') {
            $bank['status_text'] = 'Последний запуск успешен: ' . $availableCount . ' из ' . count($currencyNames) . ' валют';
            $bank['status_class'] = $availableCount === count($currencyNames)
                ? 'status-live'
                : 'status-partial';
        } elseif ($availableCount === count($currencyNames)) {
            $bank['status_text'] = 'Курсы сохранены в базе';
            $bank['status_class'] = 'status-live';
        } elseif ($availableCount > 0) {
            $bank['status_text'] = 'Сохранена часть курсов';
            $bank['status_class'] = 'status-partial';
        } else {
            $bank['status_text'] = 'Нет сохранённых курсов';
            $bank['status_class'] = 'status-empty';
        }
    }
    unset($bank);

} catch (Throwable $e) {
    // Подробности отправляем в серверный лог; посетителю не показываем сведения о БД.
    error_log('Currency aggregator index.php: ' . $e->getMessage());
    $databaseError = 'Не удалось загрузить курсы из базы данных. Проверь подключение, .env и наличие таблиц banks, currencies, rates и parsing_logs.';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Агрегатор курсов валют</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #222;
        }

        .container {
            width: 90%;
            max-width: 1100px;
            margin: 0 auto;
        }

        .header {
            background: #fff;
            padding: 35px 0;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }

        .header h1 { margin: 0 0 10px; font-size: 32px; }
        .header p { margin: 0; color: #666; }

        .banks {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 25px;
            padding: 40px 0;
        }

        .bank-card {
            min-width: 0;
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .08);
        }

        .bank-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .bank-header h2 { margin: 0; font-size: 22px; }

        .bank-code {
            flex-shrink: 0;
            background: #eee;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
        }

        .status {
            display: inline-block;
            margin: 0 0 18px;
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 12px;
        }

        .status-live { background: #e7f6eb; color: #226b38; }
        .status-partial { background: #fff3d8; color: #855b00; }
        .status-error { background: #fff0e8; color: #9a401d; }
        .status-empty { background: #eee; color: #555; }

        .rates-table { width: 100%; border-collapse: collapse; }
        .rates-table th,
        .rates-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .rates-table th { font-size: 13px; color: #777; font-weight: 600; }
        .rates-table td { font-size: 15px; white-space: nowrap; }
        .rates-table tbody tr:last-child td { border-bottom: none; }
        .currency-code { font-weight: bold; }
        .currency-name { display: block; margin-top: 4px; color: #888; font-size: 11px; white-space: normal; }
        .no-rate { color: #999; font-style: italic; }

        .error-message {
            margin-top: 16px;
            padding: 10px 12px;
            border-left: 3px solid #d97745;
            background: #fff7f2;
            color: #713b25;
            font-size: 12px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .notice {
            margin: 30px 0 0;
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #e2e5e9;
            border-radius: 8px;
            color: #555;
            line-height: 1.5;
        }

        .updated { margin-top: 18px; color: #888; font-size: 12px; }

        .footer {
            background: #fff;
            border-top: 1px solid #ddd;
            padding: 20px 0;
            text-align: center;
            color: #777;
            font-size: 13px;
        }

        .footer p { margin: 5px 0; }

        @media (max-width: 760px) {
            .banks { grid-template-columns: 1fr; }
            .header h1 { font-size: 26px; }
            .bank-card { padding: 20px; }
        }

        @media (max-width: 420px) {
            .container { width: 94%; }
            .rates-table th, .rates-table td { padding: 10px 5px; font-size: 13px; }
            .bank-header h2 { font-size: 19px; }
        }
    </style>
</head>
<body>
<header class="header">
    <div class="container">
        <h1>Агрегатор курсов валют</h1>
        <p>Сохранённые курсы российских банков</p>
    </div>
</header>

<main class="container">
    <?php if ($databaseError !== null): ?>
        <div class="error-message" role="alert">
            <?= escapeHtml($databaseError) ?>
        </div>
    <?php elseif ($banks === []): ?>
        <div class="notice">
            В таблице <strong>banks</strong> пока нет банков. Добавь банки в справочник и запусти их парсеры через CLI.
        </div>
    <?php else: ?>
        <section class="banks" aria-label="Курсы валют по банкам">
            <?php foreach ($banks as $bank): ?>
                <article class="bank-card">
                    <div class="bank-header">
                        <h2><?= escapeHtml($bank['name']) ?></h2>
                        <span class="bank-code"><?= escapeHtml($bank['code']) ?></span>
                    </div>

                    <div class="status <?= escapeHtml($bank['status_class']) ?>">
                        <?= escapeHtml($bank['status_text']) ?>
                    </div>

                    <table class="rates-table">
                        <thead>
                            <tr>
                                <th>Валюта</th>
                                <th>Покупка</th>
                                <th>Продажа</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bank['rates'] as $currency => $rate): ?>
                                <tr>
                                    <td>
                                        <span class="currency-code"><?= escapeHtml($currency) ?></span>
                                        <span class="currency-name"><?= escapeHtml($rate['name']) ?></span>
                                    </td>
                                    <td class="<?= $rate['buy'] === null ? 'no-rate' : '' ?>">
                                        <?php if ($rate['buy'] === null): ?>
                                            Нет данных
                                        <?php else: ?>
                                            <?= escapeHtml(formatRate($rate['buy'])) ?> ₽
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= $rate['sell'] === null ? 'no-rate' : '' ?>">
                                        <?php if ($rate['sell'] === null): ?>
                                            Нет данных
                                        <?php else: ?>
                                            <?= escapeHtml(formatRate($rate['sell'])) ?> ₽
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (($bank['last_log']['status'] ?? null) === 'error' && !empty($bank['last_log']['error_message'])): ?>
                        <div class="error-message">
                            <strong>Последняя ошибка парсинга:</strong><br>
                            <?= escapeHtml($bank['last_log']['error_message']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="updated">
                        Последнее обновление курсов в БД: <?= escapeHtml(formatDateTime($bank['updated_at'])) ?>
                        <?php if (!empty($bank['last_log']['created_at'])): ?>
                            <br>Последний запуск парсера: <?= escapeHtml(formatDateTime($bank['last_log']['created_at'])) ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<footer class="footer">
    <div class="container">
        <p>Агрегатор курсов валют © <?= date('Y') ?></p>
        <p>Страница сформирована: <?= escapeHtml($pageGeneratedAt) ?></p>
        <p>Данные берутся из MySQL. Для обновления курсов запусти парсер или настрой Cron.</p>
    </div>
</footer>
</body>
</html>
