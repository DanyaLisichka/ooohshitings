<?php

declare(strict_types=1);

namespace App\Parser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Парсер курсов валют Ак Барс Банка.
 *
 * Источник: https://www.akbars.ru/rates?cityFiasRef=...&currencycode=XXX
 * Тип курса: наличные, курс банка для города Казань.
 */
class AkBarsBankParser implements ParserInterface
{
    private const BASE_URI = 'https://www.akbars.ru/';
    private const RATES_PATH = 'rates';

    /**
     * ФИАС-код города. 93b3d5f7-4c89-44df-ac42-96f05e9c8b9 — Казань.
     */
    private const CITY_FIAS_REF = '93b3d5f7-4c89-44df-ac42-96f05e9c8b9';

    /**
     * Стартовая валюта: по ней API возвращает полный список currencyCodes.
     */
    private const SEED_CURRENCY = 'USD';

    private const TIMEOUT = 15;
    private const REQUEST_DELAY_US = 500_000;

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::BASE_URI,
            'timeout'  => self::TIMEOUT,
            'headers'  => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer'         => 'https://www.akbars.ru/offices/currency/',
            ],
        ]);
    }

    /**
     * @return array<int, array{currency:string, buy:float|int, sell:float|int}>
     */
    public function parse(): array
    {
        $seed = $this->fetch(self::SEED_CURRENCY);
        if ($seed === null) {
            return [];
        }

        $rates = [];
        $codes = $this->extractCurrencyCodes($seed);
        if ($codes === []) {
            $codes = [self::SEED_CURRENCY];
        }

        $rate = $this->extractRate(self::SEED_CURRENCY, $seed);
        if ($rate !== null) {
            $rates[] = $rate;
        }

        foreach ($codes as $code) {
            if ($code === self::SEED_CURRENCY) {
                continue;
            }

            usleep(self::REQUEST_DELAY_US);

            $data = $this->fetch($code);
            if ($data === null) {
                continue;
            }

            $rate = $this->extractRate($code, $data);
            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        return $rates;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetch(string $currencyCode): ?array
    {
        try {
            $response = $this->client->get(self::RATES_PATH, [
                'query' => [
                    'cityFiasRef'  => self::CITY_FIAS_REF,
                    'currencycode' => $currencyCode,
                ],
            ]);
        } catch (GuzzleException $e) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $json = json_decode((string) $response->getBody(), true);
        if (!is_array($json)) {
            return null;
        }

        return $json;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    private function extractCurrencyCodes(array $data): array
    {
        $codes = $data['currencyCodes'] ?? [];
        if (!is_array($codes)) {
            return [];
        }

        $result = [];
        foreach ($codes as $code) {
            $code = mb_strtoupper(trim((string) $code));
            if ($code !== '') {
                $result[] = $code;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * ⚠️ В API Ак Барса buyPrice > sellPrice (86.2 против 85.9) —
     * поля названы «от лица клиента». Поэтому инверсия:
     *   sellPrice → buy (банк покупает)
     *   buyPrice  → sell (банк продаёт)
     * ЕСЛИ ТИМЛИД СКАЖЕТ ИНАЧЕ — поменяйте местами.
     *
     * @param array<string,mixed> $data
     * @return array{currency:string, buy:float|int, sell:float|int}|null
     */
    private function extractRate(string $currencyCode, array $data): ?array
    {
        $branch = $data['branches'][0] ?? null;
        if (!is_array($branch)) {
            return null;
        }

        $bankBuy  = $this->toFloat($branch['sellPrice'] ?? null);
        $bankSell = $this->toFloat($branch['buyPrice']  ?? null);

        if ($bankBuy === null && $bankSell === null) {
            return null;
        }

        return [
            'currency' => $currencyCode,
            'buy'      => $bankBuy ?? 0,
            'sell'     => $bankSell ?? 0,
        ];
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string) $value);

        return is_numeric($value) ? (float) $value : null;
    }
}
