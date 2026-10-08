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
        'App\\Parser\\TestParser'
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Курсы валют в банках</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page">

    <header class="top">
        <h1>Курсы валют</h1>
        <p class="sub">
            <?= count($rows) ?> банков
            <?php if ($lastUpdate): ?>
                · последнее обновление <?= h(date('d.m.Y H:i', $lastUpdate)) ?>
            <?php endif; ?>
        </p>
    </header>

    <?php if ($error): ?>
        <p class="msg"><?= h($error) ?></p>
    <?php else: ?>

        <nav class="cur">
            <?php foreach ($currencies as $code => $name): ?>
                <a href="<?= h(link_to(['cur' => $code])) ?>"
                   class="<?= $code === $cur ? 'on' : '' ?>"
                   title="<?= h($name) ?>"><?= h($code) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$rows): ?>
            <p class="msg">По валюте <?= h($cur) ?> пока нет данных.</p>
        <?php else: ?>
            <div class="wrap">
                <table>
                    <thead>
                    <tr>
                        <th><?= sort_link('bank', 'Банк') ?></th>
                        <th class="num"><?= sort_link('buy', 'Покупает') ?></th>
                        <th class="num"><?= sort_link('sell', 'Продаёт') ?></th>
                        <th class="num"><?= sort_link('time', 'Обновлено') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="<?= $r['stale'] ? 'old' : '' ?>">
                            <td>
                                <a class="bank" href="<?= h($r['url']) ?>" target="_blank" rel="noopener"><?= h($r['name']) ?></a>
                                <?php if ($r['stale']): ?><span class="flag">данные устарели</span><?php endif; ?>
                            </td>
                            <td class="num <?= (!$r['stale'] && $r['buy'] == $bestBuy) ? 'best' : '' ?>"><?= fmt($r['buy']) ?></td>
                            <td class="num <?= (!$r['stale'] && $r['sell'] == $bestSell) ? 'best' : '' ?>"><?= fmt($r['sell']) ?></td>
                            <td class="num time"><?= h(date('d.m H:i', strtotime($r['updated_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="note">
                Зелёным отмечены лучшие условия: самая высокая цена, по которой банк покупает у вас
                валюту, и самая низкая, по которой продаёт. Банки с устаревшими данными
                в сравнении не участвуют.
            </p>
        <?php endif; ?>

    <?php endif; ?>

</div>
</body>
</html>