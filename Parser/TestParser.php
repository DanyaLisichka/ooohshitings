<?php

namespace App\Parser;

class TestParser implements ParserInterface
{
    public function parse(): array
    {
        return [
            [
                'currency' => 'USD',
                'buy' => 90.50,
                'sell' => 93.20
            ],
            [
                'currency' => 'EUR',
                'buy' => 98.70,
                'sell' => 102.30
            ]
        ];
    }
}