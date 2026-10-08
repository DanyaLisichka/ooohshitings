<?php

namespace App\Parser;

use RuntimeException;

class ParserFactory
{
    public static function create(string $parserClass): ParserInterface
    {
        if (!class_exists($parserClass)) {
            throw new RuntimeException(
                "Класс парсера не найден: {$parserClass}"
            );
        }

        $parser = new $parserClass();

        if (!$parser instanceof ParserInterface) {
            throw new RuntimeException(
                "Класс {$parserClass} не реализует ParserInterface"
            );
        }

        return $parser;
    }
}