-- =====================================================
-- Агрегатор курсов валют: схема БД + тестовые данные
-- =====================================================

CREATE DATABASE IF NOT EXISTS currency_aggregator
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE currency_aggregator;

-- Порядок удаления учитывает внешние ключи
DROP TABLE IF EXISTS parsing_logs;
DROP TABLE IF EXISTS rates;
DROP TABLE IF EXISTS currencies;
DROP TABLE IF EXISTS banks;

-- -----------------------------------------------------
-- Справочник банков
-- -----------------------------------------------------
CREATE TABLE banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,      -- например, 'sber', 'vtb'
    url VARCHAR(255) NOT NULL,
    parser_class VARCHAR(100) NOT NULL     -- имя класса парсера
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Справочник валют
-- -----------------------------------------------------
CREATE TABLE currencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    char_code CHAR(3) UNIQUE NOT NULL,     -- 'USD', 'EUR', 'CNY'
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Курсы (одна строка на пару банк + валюта)
-- -----------------------------------------------------
CREATE TABLE rates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    currency_id INT NOT NULL,
    buy DECIMAL(10, 4) NOT NULL,           -- банк покупает у клиента
    sell DECIMAL(10, 4) NOT NULL,          -- банк продаёт клиенту
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,       -- обновляется и при UPDATE
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    FOREIGN KEY (currency_id) REFERENCES currencies(id),
    UNIQUE KEY unique_rate (bank_id, currency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Логи парсинга
-- -----------------------------------------------------
CREATE TABLE parsing_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    status ENUM('success', 'error') NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    INDEX idx_bank_created (bank_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ТЕСТОВЫЕ ДАННЫЕ
-- =====================================================

INSERT INTO currencies (char_code, name) VALUES
('USD', 'Доллар США'),
('EUR', 'Евро'),
('CNY', 'Китайский юань');

INSERT INTO banks (name, code, url, parser_class) VALUES
('Сбербанк',            'sber',       'https://www.sberbank.ru',      'SberParser'),
('Тинькофф',            'tinkoff',    'https://www.tbank.ru',         'TinkoffParser'),
('ВТБ',                 'vtb',        'https://www.vtb.ru',           'VtbParser'),
('Альфа-Банк',          'alfa',       'https://alfabank.ru',          'AlfaParser'),
('Газпромбанк',         'gazprom',    'https://www.gazprombank.ru',   'GazprombankParser'),
('Россельхозбанк',      'rshb',       'https://www.rshb.ru',          'RshbParser'),
('Райффайзенбанк',      'raiffeisen', 'https://www.raiffeisen.ru',    'RaiffeisenParser'),
('Открытие',            'otkritie',   'https://www.open.ru',          'OtkritieParser'),
('Промсвязьбанк',       'psb',        'https://www.psbank.ru',        'PsbParser'),
('Совкомбанк',          'sovcom',     'https://sovcombank.ru',        'SovcombankParser'),
('Банк Санкт-Петербург','bspb',       'https://www.bspb.ru',          'BspbParser'),
('Ак Барс Банк',        'akbars',     'https://www.akbars.ru',        'AkbarsParser'),
('МКБ',                 'mkb',        'https://mkb.ru',               'MkbParser'),
('Уралсиб',             'uralsib',    'https://www.uralsib.ru',       'UralsibParser'),
('БКС Банк',            'bcs',        'https://bcs-bank.com',         'BcsParser');

-- Курсы: валюта 1 = USD, 2 = EUR, 3 = CNY
INSERT INTO rates (bank_id, currency_id, buy, sell) VALUES
-- USD
(1, 1, 91.5000, 93.2000),
(2, 1, 91.9000, 93.0000),
(3, 1, 91.8000, 93.1000),
(4, 1, 91.2000, 93.5000),
(5, 1, 91.6000, 93.3000),
(6, 1, 91.0000, 93.8000),
(7, 1, 91.4000, 93.4000),
(8, 1, 91.7000, 93.2500),
(9, 1, 91.3000, 93.6000),
(10, 1, 91.8500, 93.1500),
(11, 1, 91.1000, 93.7000),
(12, 1, 91.5500, 93.3500),
(13, 1, 91.2500, 93.5500),
(14, 1, 91.4500, 93.4500),
(15, 1, 91.6500, 93.2800),
-- EUR
(1, 2, 99.1000, 101.3000),
(2, 2, 99.4000, 101.0000),
(3, 2, 99.3000, 101.1000),
(4, 2, 98.8000, 101.6000),
(5, 2, 99.2000, 101.2000),
(6, 2, 98.9000, 101.7000),
(7, 2, 99.0000, 101.4000),
(8, 2, 99.5000, 101.1500),
(9, 2, 98.7000, 101.8000),
(10, 2, 99.3500, 101.0500),
(11, 2, 98.9500, 101.5000),
(12, 2, 99.1500, 101.3500),
(13, 2, 98.8500, 101.6500),
(14, 2, 99.0500, 101.4500),
(15, 2, 99.2500, 101.2500),
-- CNY
(1, 3, 12.7000, 13.2000),
(2, 3, 12.8000, 13.1000),
(3, 3, 12.7500, 13.1500),
(4, 3, 12.5000, 13.4000),
(5, 3, 12.6500, 13.2500),
(6, 3, 12.5500, 13.3500),
(7, 3, 12.6000, 13.3000),
(8, 3, 12.7800, 13.1200),
(9, 3, 12.5200, 13.4500),
(10, 3, 12.7200, 13.1800),
(11, 3, 12.5800, 13.3800),
(12, 3, 12.6800, 13.2200),
(13, 3, 12.5300, 13.4200),
(14, 3, 12.6200, 13.2800),
(15, 3, 12.7400, 13.1600);

-- Логи: у большинства банков успех, у двух ошибка (для проверки пометки «устарело»)
INSERT INTO parsing_logs (bank_id, status, error_message) VALUES
(1,  'success', NULL),
(2,  'success', NULL),
(3,  'success', NULL),
(4,  'success', NULL),
(5,  'success', NULL),
(6,  'error',   'HTTP 503: сайт банка недоступен'),
(7,  'success', NULL),
(8,  'success', NULL),
(9,  'success', NULL),
(10, 'success', NULL),
(11, 'success', NULL),
(12, 'error',   'Не найден блок с курсами (изменилась вёрстка)'),
(13, 'success', NULL),
(14, 'success', NULL),
(15, 'success', NULL);

-- Проверка
SELECT b.name, c.char_code, r.buy, r.sell, r.updated_at
FROM rates r
JOIN banks b ON b.id = r.bank_id
JOIN currencies c ON c.id = r.currency_id
WHERE c.char_code = 'USD'
ORDER BY r.buy DESC;banks