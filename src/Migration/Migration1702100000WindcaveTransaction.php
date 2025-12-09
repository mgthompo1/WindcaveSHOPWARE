<?php

declare(strict_types=1);

namespace Windcave\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates the windcave_transaction table for storing payment transaction history.
 */
class Migration1702100000WindcaveTransaction extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1702100000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `windcave_transaction` (
                `id` BINARY(16) NOT NULL,
                `order_transaction_id` BINARY(16) NOT NULL,
                `session_id` VARCHAR(255) NULL,
                `windcave_transaction_id` VARCHAR(255) NULL,
                `transaction_type` VARCHAR(50) NOT NULL,
                `status` VARCHAR(50) NOT NULL,
                `response_code` VARCHAR(10) NULL,
                `response_text` VARCHAR(255) NULL,
                `amount` DOUBLE NULL,
                `currency` VARCHAR(3) NULL,
                `card_scheme` VARCHAR(50) NULL,
                `card_number_masked` VARCHAR(20) NULL,
                `card_expiry` VARCHAR(10) NULL,
                `card_token` VARCHAR(255) NULL,
                `three_ds_status` VARCHAR(50) NULL,
                `rrn` VARCHAR(50) NULL,
                `auth_code` VARCHAR(20) NULL,
                `payment_mode` VARCHAR(20) NULL,
                `test_mode` VARCHAR(5) NULL,
                `raw_response` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.windcave_transaction.order_transaction_id` (`order_transaction_id`),
                KEY `idx.windcave_transaction.session_id` (`session_id`),
                KEY `idx.windcave_transaction.windcave_transaction_id` (`windcave_transaction_id`),
                CONSTRAINT `fk.windcave_transaction.order_transaction_id`
                    FOREIGN KEY (`order_transaction_id`)
                    REFERENCES `order_transaction` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes
    }
}
