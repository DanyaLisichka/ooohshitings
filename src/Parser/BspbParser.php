<?php

declare(strict_types=1);

namespace App\Parser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Парсер курсов валют Банка «Санкт-Петербург».
 * Использует JSON endpoint /api/currency-service/office-rates.
 *
 * Важно: API возвращает курсы по офисам. Сейчас используется офис id=9119
 * («ДО "Пионерский"» из полученного ответа), чтобы не смешивать курсы
 * разных офисов в одной таблице. При необходимости OFFICE_ID можно поменять.
 */
class BspbParser implements ParserInterface
{
    private const URL = 'https://www.bspb.ru/api/currency-service/office-rates';
    private const OFFICE_ID = 9119;
    private const CURRENCIES = ['USD', 'EUR', 'CNY'];

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                    . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                'Referer' => 'https://www.bspb.ru/finance',
            ],
        ]);
    }

    /**
     * @return array<int, array{currency: string, buy: float, sell: float}>
     */
    public function parse(): array
    {
        // Небольшая пауза между запросами согласно требованиям проекта.
        usleep(random_int(500000, 1500000));

        try {
            $response = $this->client->get(self::URL);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'BSPB: ошибка HTTP-запроса: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($statusCode !== 200) {
            throw new RuntimeException(
                "BSPB: API вернул HTTP {$statusCode}. Ответ: " . mb_substr($body, 0, 500)
            );
        }

        if (trim($body) === '') {
            throw new RuntimeException('BSPB: API вернул пустой ответ');
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(
                'BSPB: ответ API не является корректным JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('BSPB: в JSON отсутствует массив items');
        }

        $office = null;

        foreach ($data['items'] as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === self::OFFICE_ID) {
                $office = $item;
                break;
            }
        }

        if ($office === null) {
            throw new RuntimeException(
                'BSPB: офис id=' . self::OFFICE_ID . ' отсутствует в ответе API'
            );
        }

        if (!isset($office['rates']) || !is_array($office['rates'])) {
            throw new RuntimeException(
                'BSPB: у офиса id=' . self::OFFICE_ID . ' отсутствует массив rates'
            );
        }

        $result = [];

        foreach ($office['rates'] as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $currency = strtoupper((string) ($rate['currencyCode'] ?? ''));

            if (!in_array($currency, self::CURRENCIES, true)) {
                continue;
            }

            // Агрегатор сравнивает курсы валют к рублю.
            $currencyCodeSecond = strtoupper((string) ($rate['currencyCodeSecond'] ?? 'RUB'));
            if ($currencyCodeSecond !== 'RUB') {
                continue;
            }

            if (!isset($rate['buyRate'], $rate['sellRate'])) {
                continue;
            }

            if (!is_numeric($rate['buyRate']) || !is_numeric($rate['sellRate'])) {
                continue;
            }

            $buy = (float) $rate['buyRate'];
            $sell = (float) $rate['sellRate'];

            if ($buy <= 0 || $sell <= 0) {
                continue;
            }

            // Не добавляем дубликаты одной валюты.
            if (isset($result[$currency])) {
                continue;
            }

            $result[$currency] = [
                'currency' => $currency,
                'buy' => $buy,
                'sell' => $sell,
            ];
        }

        if ($result === []) {
            throw new RuntimeException(
                'BSPB: в rates офиса id=' . self::OFFICE_ID
                . ' не найдены корректные курсы USD, EUR или CNY'
            );
        }

        // Стабильный порядок валют в выводе.
        $orderedResult = [];
        foreach (self::CURRENCIES as $currency) {
            if (isset($result[$currency])) {
                $orderedResult[] = $result[$currency];
            }
        }

        return $orderedResult;
    }
}
