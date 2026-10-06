<?php
 
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
 
class SberParser implements ParserInterface
{
    private const API_URL = 'https://www.sberbank.ru/proxy/services/rates/public/v2/graph';
 
    // ERNP-1 - наличные курсы, как на графике "Динамика курсов наличной валюты".
    private const RATE_TYPE = 'ERNP-1';
 
    // Валюты из справочника currencies.
    private const CURRENCIES = ['USD', 'EUR', 'CNY'];
 
    // Глубина выборки в днях. Хватает, чтобы поймать последнее изменение курса.
    private const DAYS_BACK = 30;
 
    private Client $client;
    private int $regionId;
 
    public function __construct(?Client $client = null, int $regionId = 38)
    {
        $this->regionId = $regionId;
        $this->client = $client ?? new Client([
            'timeout'         => 10,
            'connect_timeout' => 5,
            'headers'         => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                   . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                'Accept'          => 'application/json',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ],
        ]);
    }
 
    public function parse(): array
    {
        $result = [];
 
        foreach (self::CURRENCIES as $i => $code) {
            if ($i > 0) {
                usleep(random_int(500000, 1500000)); // пауза 0.5-1.5 сек между запросами
            }
 
            $result[] = $this->fetchCurrency($code);
        }
 
        return $result;
    }
 
    private function fetchCurrency(string $code): array
    {
        $dateEnd = (int) (microtime(true) * 1000);
        $dateBeg = $dateEnd - self::DAYS_BACK * 86400 * 1000;
 
        try {
            $response = $this->client->get(self::API_URL, [
                'query' => [
                    'rateType' => self::RATE_TYPE,
                    'isoCode'  => $code,
                    'id'       => $this->regionId,
                    'dateBeg'  => $dateBeg,
                    'dateEnd'  => $dateEnd,
                    'segType'  => 'TRADITIONAL',
                ],
            ]);
 
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            throw new RuntimeException("Sber {$code}: ошибка запроса: " . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new RuntimeException("Sber {$code}: ответ не JSON: " . $e->getMessage(), 0, $e);
        }
 
        return $this->extractLatest($data, $code);
    }
 
    /**
     * Структура ответа:
     * historyRates -> {метка} -> ERNP-1 -> {валюта} -> {время изменения} -> rangeList[] -> rateBuy, rateSell
     * Берём запись с максимальным временем и диапазон суммы, который начинается с нуля.
     */
    private function extractLatest(array $data, string $code): array
    {
        $latestTime = null;
        $latestEntry = null;
 
        foreach ($data['historyRates'] ?? [] as $block) {
            foreach ($block[self::RATE_TYPE][$code] ?? [] as $time => $entry) {
                $time = (int) $time;
                if ($latestTime === null || $time > $latestTime) {
                    $latestTime = $time;
                    $latestEntry = $entry;
                }
            }
        }
 
        if ($latestEntry === null) {
            throw new RuntimeException("Sber {$code}: курсы не найдены в ответе");
        }
 
        $range = null;
        foreach ($latestEntry['rangeList'] ?? [] as $item) {
            if ($range === null || ($item['rangeAmountBottom'] ?? 0) < ($range['rangeAmountBottom'] ?? 0)) {
                $range = $item;
            }
        }
 
        if ($range === null || !isset($range['rateBuy'], $range['rateSell'])) {
            throw new RuntimeException("Sber {$code}: в записи нет rateBuy или rateSell");
        }
 
        return [
            'currency' => $code,
            'buy'      => (float) $range['rateBuy'],
            'sell'     => (float) $range['rateSell'],
        ];
    }
}
