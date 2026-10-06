-- Справочник банков
CREATE TABLE banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL, -- например, 'sber', 'vtb'
    url VARCHAR(255) NOT NULL,
    parser_class VARCHAR(100) NOT NULL -- Имя класса парсера
);

-- Справочник валют
CREATE TABLE currencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    char_code CHAR(3) UNIQUE NOT NULL, -- 'USD', 'EUR', 'CNY'
    name VARCHAR(50) NOT NULL
);

-- Сами курсы (история)
CREATE TABLE rates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    currency_id INT NOT NULL,
    buy DECIMAL(10, 4) NOT NULL, -- Курс покупки
    sell DECIMAL(10, 4) NOT NULL, -- Курс продажи
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_id) REFERENCES banks(id),
    FOREIGN KEY (currency_id) REFERENCES currencies(id),
    UNIQUE KEY unique_rate (bank_id, currency_id) -- Обновляем запись, а не дублируем
);

-- Логи парсинга (для отладки)
CREATE TABLE parsing_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    status ENUM('success', 'error') NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
