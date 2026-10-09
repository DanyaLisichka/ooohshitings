<?php

declare(strict_types=1);

namespace App\Parser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class SberParser implements ParserInterface
{
    private const URL = 'https://www.sberbank.ru/proxy/services/rates/public/v2/actual';

    /**
     * Валюты, которые запрашиваем у Сбербанка.
     */
    private const CURRENCIES = [
    'USD',
    'EUR',
    'CNY',
    ];

    /**
     * Регион Сбербанка.
     */
    private const REGION_ID = '038';

    /**
     * Тип курса.
     */
    private const RATE_TYPE = 'ERNP-2';

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,

            // Не выбрасываем исключение автоматически на HTTP 4xx/5xx.
            'http_errors' => false,

            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',

                'Accept' => 'application/json, text/plain, */*',

                'Referer' => 'https://www.sberbank.ru/ru/quotes/currencies',

                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ],
        ]);
    }

    /**
     * Получение актуальных курсов валют.
     *
     * @return array<int, array{
     *     currency: string,
     *     buy: float,
     *     sell: float
     * }>
     */
    public function parse(): array {
        echo '<pre>';
        echo 'Время запроса: ' . date('Y-m-d H:i:s') . PHP_EOL;
        echo 'Курс получен от Сбера:' . PHP_EOL;
        print_r($rates);
        echo '</pre>';
    // Формируем isoCodes[] точно в том виде,
    // в котором их отправляет браузер Safari.
    $isoCodes = [];

    
    foreach (self::CURRENCIES as $currency) {
        $isoCodes[] = 'isoCodes[]=' . rawurlencode($currency);
    }

    $query = implode('&', [
        'rateType=' . rawurlencode(self::RATE_TYPE),
        implode('&', $isoCodes),
        'regionId=' . rawurlencode(self::REGION_ID),
    ]);

    $url = self::URL . '?' . $query;

    try {
        $response = $this->client->get($url);
    } catch (GuzzleException $e) {
        throw new RuntimeException(
            'Ошибка запроса к Сбербанку: ' . $e->getMessage(),
            0,
            $e
        );
    }

    $statusCode = $response->getStatusCode();
    $body = (string) $response->getBody();

    if ($statusCode !== 200) {
        throw new RuntimeException(
            "Сбербанк вернул HTTP {$statusCode}. Ответ: {$body}"
        );
    }

    if ($body === '') {
        throw new RuntimeException(
            'Сбербанк вернул пустой ответ'
        );
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Ответ Сбербанка не является корректным JSON: '
            . json_last_error_msg()
        );
    }

    $result = [];

    foreach (self::CURRENCIES as $currency) {

        if (!isset($data[$currency])) {
            continue;
        }

        $currencyData = $data[$currency];

        if (
            !isset($currencyData['rateList']) ||
            !is_array($currencyData['rateList']) ||
            empty($currencyData['rateList'])
        ) {
            continue;
        }

        $rate = $currencyData['rateList'][0];

        if (
            !isset($rate['rateBuy']) ||
            !isset($rate['rateSell'])
        ) {
            continue;
        }

        $buy = (float) $rate['rateBuy'];
        $sell = (float) $rate['rateSell'];

        if ($buy <= 0 || $sell <= 0) {
            continue;
        }

        $result[] = [
            'currency' => $currency,
            'buy' => $buy,
            'sell' => $sell,
        ];
    }

    if (empty($result)) {
        throw new RuntimeException(
            'Сбербанк не вернул ни одного корректного курса'
        );
    }

    return $result;
}
}