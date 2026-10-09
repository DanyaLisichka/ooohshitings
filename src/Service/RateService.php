<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

class RateService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Сохраняет или обновляет курсы одного банка.
     */
    public function saveRates(int $bankId, array $rates): int
    {
        if (empty($rates)) {
            throw new RuntimeException(
                'Парсер не вернул ни одного курса'
            );
        }

        $currencyQuery = $this->pdo->prepare(
            'SELECT id
             FROM currencies
             WHERE char_code = :code
             LIMIT 1'
        );

        $rateQuery = $this->pdo->prepare(
            'INSERT INTO rates
                (bank_id, currency_id, buy, sell, updated_at)
             VALUES
                (:bank_id, :currency_id, :buy, :sell, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                buy = VALUES(buy),
                sell = VALUES(sell),
                updated_at = CURRENT_TIMESTAMP'
        );

        $saved = 0;

        $this->pdo->beginTransaction();

        try {
            foreach ($rates as $rate) {
                if (
                    !is_array($rate) ||
                    !isset(
                        $rate['currency'],
                        $rate['buy'],
                        $rate['sell']
                    )
                ) {
                    throw new RuntimeException(
                        'Парсер вернул запись некорректного формата'
                    );
                }

                $code = strtoupper(
                    trim((string) $rate['currency'])
                );

                $buy = $rate['buy'];
                $sell = $rate['sell'];

                if (
                    !preg_match('/^[A-Z]{3}$/', $code) ||
                    !is_numeric($buy) ||
                    !is_numeric($sell) ||
                    (float) $buy <= 0 ||
                    (float) $sell <= 0
                ) {
                    throw new RuntimeException(
                        "Некорректные данные курса: {$code}"
                    );
                }

                // Находим валюту по её коду.
                $currencyQuery->execute([
                    'code' => $code,
                ]);

                $currencyId = $currencyQuery->fetchColumn();

                if ($currencyId === false) {
                    throw new RuntimeException(
                        "Валюта {$code} отсутствует в таблице currencies"
                    );
                }

                // Вставляем курс или обновляем существующий.
                $rateQuery->execute([
                    'bank_id' => $bankId,
                    'currency_id' => (int) $currencyId,
                    'buy' => $buy,
                    'sell' => $sell,
                ]);

                $saved++;
            }

            $this->pdo->commit();

            return $saved;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Записывает результат запуска парсера.
     */
    public function writeLog(
        int $bankId,
        string $status,
        ?string $errorMessage = null
    ): void {
        if (!in_array($status, ['success', 'error'], true)) {
            throw new RuntimeException(
                'Неизвестный статус парсинга'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO parsing_logs
                (bank_id, status, error_message)
             VALUES
                (:bank_id, :status, :error_message)'
        );

        $statement->execute([
            'bank_id' => $bankId,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }
}