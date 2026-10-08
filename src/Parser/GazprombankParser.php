<?php

namespace App\Parser;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер курсов валют сайта Газпромбанка.
 * Реализует ParserInterface.
 */
class GazprombankParser implements ParserInterface
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 15,
            'headers' => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
            ],
        ]);
    }

    /**
     * Парсит курсы валют с сайта Газпромбанка.
     *
     * @return array [['currency' => 'USD', 'buy' => 90.50, 'sell' => 93.20], ...]
     * @throws \Exception
     */
    public function parse(): array
    {
        $url = 'https://www.gpb.ru/retail/cards-and-services/currency-exchange/';
        $rates = [];

        try {
            $response = $this->client->get($url);
            $html = (string) $response->getBody();

            $crawler = new Crawler($html);

            // --- Вариант 1: таблица курсов ---
            $crawler->filter('table tr')
                ->each(function (Crawler $row) use (&$rates) {
                    $cells = $row->filter('td')->each(fn(Crawler $c) => trim($c->text()));

                    if (count($cells) < 3) {
                        return;
                    }

                    $currency = $this->extractCurrencyCode($cells[0] ?? '');
                    $buy      = $this->parseDecimal($cells[1] ?? '');
                    $sell     = $this->parseDecimal($cells[2] ?? '');

                    if ($currency && $buy > 0 && $sell > 0) {
                        $rates[] = [
                            'currency' => $currency,
                            'buy'      => $buy,
                            'sell'     => $sell,
                        ];
                    }
                });

            // --- Вариант 2: карточки/блоки (если таблица не сработала) ---
            if (empty($rates)) {
                $crawler->filter('.currency-item, .rate-card, .exchange-rate-item, .rates-item')
                    ->each(function (Crawler $item) use (&$rates) {
                        $currency = $this->extractCurrencyCode(
                            $item->filter('.currency-code, .currency-name, .title, .name')->text('')
                        );
                        $buy  = $this->parseDecimal($item->filter('.buy, .purchase, .rate-buy')->text(''));
                        $sell = $this->parseDecimal($item->filter('.sell, .sale, .rate-sell')->text(''));

                        if ($currency && $buy > 0 && $sell > 0) {
                            $rates[] = [
                                'currency' => $currency,
                                'buy'      => $buy,
                                'sell'     => $sell,
                            ];
                        }
                    });
            }

            // --- Вариант 3: fallback по кодам валют в DOM ---
            if (empty($rates)) {
                $rates = $this->fallbackParse($crawler);
            }

            if (empty($rates)) {
                throw new \Exception('Не удалось найти курсы валют на странице Газпромбанка. Возможно, изменилась вёрстка.');
            }

            return $rates;

        } catch (GuzzleException $e) {
            throw new \Exception('Ошибка HTTP при запросе к Газпромбанку: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception('Ошибка парсинга Газпромбанка: ' . $e->getMessage());
        }
    }

    /**
     * Извлекает 3-буквенный код валюты из строки.
     */
    private function extractCurrencyCode(string $text): ?string
    {
        $text = strtoupper(trim($text));

        if (preg_match('/\b(USD|EUR|CNY|GBP|JPY|CHF)\b/', $text, $m)) {
            return $m[1];
        }

        $map = [
            'ДОЛЛАР' => 'USD', 'USA' => 'USD', 'США' => 'USD',
            'ЕВРО'   => 'EUR', 'EURO'  => 'EUR',
            'ЮАНЬ'   => 'CNY', 'КИТАЙ' => 'CNY', 'CHN' => 'CNY',
            'ФУНТ'   => 'GBP',
            'ЙЕНА'   => 'JPY',
            'ФРАНК'  => 'CHF',
        ];

        foreach ($map as $key => $code) {
            if (str_contains($text, $key)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Парсит десятичное число из строки ("90,50" → 90.50).
     */
    private function parseDecimal(string $text): float
    {
        $text = trim($text);
        $text = str_replace(' ', '', $text);
        $text = str_replace(',', '.', $text);
        $text = preg_replace('/[^\d.]/', '', $text);

        $val = (float) $text;
        return ($val > 0 && $val < 10000) ? $val : 0.0;
    }

    /**
     * Запасной парсер: ищет числа рядом с кодами валют в DOM.
     */
    private function fallbackParse(Crawler $crawler): array
    {
        $rates = [];
        $currencies = ['USD', 'EUR', 'CNY'];

        foreach ($currencies as $cur) {
            $node = $crawler->filterXPath(
                "//*[contains(translate(text(), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'), '$cur')]"
            );

            if ($node->count() === 0) {
                continue;
            }

            $parentText = $node->parents()->first()->text('');
            $numbers = [];
            preg_match_all('/(\d+[.,]\d+)/', $parentText, $matches);

            if (count($matches[1]) >= 2) {
                $buy  = $this->parseDecimal($matches[1][0]);
                $sell = $this->parseDecimal($matches[1][1]);
                if ($buy > 0 && $sell > 0) {
                    $rates[] = ['currency' => $cur, 'buy' => $buy, 'sell' => $sell];
                }
            }
        }

        return $rates;
    }
}
