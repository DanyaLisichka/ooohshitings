<?php

use GuzzleHttp\Client;

class RshbParser implements ParserInterface
{
    private const URL = "https://coins.rshb.ru/exchange";

    public function parse(): array
    {
        $client = new Client([
            "timeout" => 30,
            "headers" => [
                "User-Agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                "Accept-Language" => "ru-RU,ru;q=0.9",
            ],
        ]);

        $response = $client->request("GET", self::URL);
        $html = (string) $response->getBody();
        $rates = [];

        if (preg_match('/"rows":\s*(\[.*?\])\s*,\s*"updatedAt"/s', $html, $matches)) {
            $rows = json_decode($matches[1], true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $currency = $row["code"] ?? null;
                    $buy = $row["rateSellStats"]["min"] ?? null;
                    $sell = $row["rateBuyStats"]["max"] ?? null;
                    if ($currency && $buy !== null && $sell !== null) {
                        $rates[] = [
                            "currency" => $currency,
                            "buy" => (float) $buy,
                            "sell" => (float) $sell,
                        ];
                    }
                }
            }
        }

        return $rates;
    }
}
