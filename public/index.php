<?php
require __DIR__ . '/../src/db.php';

// курс считается устаревшим, если ему больше 2 часов
const STALE_AFTER = 2 * 3600;

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// столбец => [поле в SQL, направление по умолчанию]
$sorts = [
    'bank' => ['b.name',       'asc'],
    'buy'  => ['r.buy',        'desc'],
    'sell' => ['r.sell',       'asc'],
    'time' => ['r.updated_at', 'desc'],
];

$sort = $_GET['sort'] ?? 'buy';
if (!isset($sorts[$sort])) {
    $sort = 'buy';
}
$dir = $_GET['dir'] ?? $sorts[$sort][1];
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = $sorts[$sort][1];
}

$currencies = [];
$rows = [];
$cur = 'USD';
$error = null;
$pdo = null;

try {
    $pdo = getPdo();

    $currencies = $pdo->query('SELECT char_code, name FROM currencies ORDER BY id')
                      ->fetchAll(PDO::FETCH_KEY_PAIR);

    $cur = $_GET['cur'] ?? 'USD';
    if (!isset($currencies[$cur])) {
        $cur = array_key_first($currencies) ?? 'USD';
    }

    // порядок берём из белого списка, в запрос из $_GET ничего не попадает
    $sql = "SELECT b.name, b.url, r.buy, r.sell, r.updated_at,
                   (SELECT l.status FROM parsing_logs l
                     WHERE l.bank_id = b.id ORDER BY l.id DESC LIMIT 1) AS last_status
            FROM rates r
            JOIN banks b ON b.id = r.bank_id
            JOIN currencies c ON c.id = r.currency_id
            WHERE c.char_code = ?
            ORDER BY {$sorts[$sort][0]} " . strtoupper($dir);

    $st = $pdo->prepare($sql);
    $st->execute([$cur]);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Не удалось получить данные. Попробуйте зайти позже.';
}
$pdo = null; // закрываем соединение (требование Beget)

// помечаем устаревшие и ищем лучшие курсы среди свежих
$now = time();
$bestBuy = null;
$bestSell = null;
$lastUpdate = null;

foreach ($rows as &$r) {
    $ts = strtotime($r['updated_at']);
    $r['stale'] = ($now - $ts > STALE_AFTER) || $r['last_status'] === 'error';

    if ($lastUpdate === null || $ts > $lastUpdate) {
        $lastUpdate = $ts;
    }
    if (!$r['stale']) {
        if ($bestBuy === null || $r['buy'] > $bestBuy)   $bestBuy = $r['buy'];
        if ($bestSell === null || $r['sell'] < $bestSell) $bestSell = $r['sell'];
    }
}
unset($r);

// ссылка на эту же страницу с другими параметрами
function link_to(array $over): string
{
    global $cur, $sort, $dir;
    return '?' . http_build_query(array_merge(['cur' => $cur, 'sort' => $sort, 'dir' => $dir], $over));
}

// ссылка в заголовке столбца: повторный клик меняет направление
function sort_link(string $col, string $title): string
{
    global $sort, $dir, $sorts;
    $newDir = $sorts[$col][1];
    $mark = '';
    if ($sort === $col) {
        $newDir = $dir === 'asc' ? 'desc' : 'asc';
        $mark = $dir === 'asc' ? ' ↑' : ' ↓';
    }
    return '<a href="' . h(link_to(['sort' => $col, 'dir' => $newDir])) . '">' . h($title) . $mark . '</a>';
}

function fmt($n): string
{
    return number_format((float)$n, 2, ',', ' ');
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