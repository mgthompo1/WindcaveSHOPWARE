<?php

declare(strict_types=1);

namespace Windcave\Core\Content\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(WindcaveTransactionEntity $entity)
 * @method void set(string $key, WindcaveTransactionEntity $entity)
 * @method WindcaveTransactionEntity[] getIterator()
 * @method WindcaveTransactionEntity[] getElements()
 * @method WindcaveTransactionEntity|null get(string $key)
 * @method WindcaveTransactionEntity|null first()
 * @method WindcaveTransactionEntity|null last()
 */
class WindcaveTransactionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return WindcaveTransactionEntity::class;
    }

    /**
     * Get transactions by order transaction ID
     */
    public function filterByOrderTransactionId(string $orderTransactionId): self
    {
        return $this->filter(
            fn (WindcaveTransactionEntity $entity) => $entity->getOrderTransactionId() === $orderTransactionId
        );
    }

    /**
     * Get transactions by type (purchase, refund, void, etc.)
     */
    public function filterByType(string $type): self
    {
        return $this->filter(
            fn (WindcaveTransactionEntity $entity) => $entity->getTransactionType() === $type
        );
    }

    /**
     * Get successful transactions
     */
    public function filterSuccessful(): self
    {
        return $this->filter(
            fn (WindcaveTransactionEntity $entity) => $entity->getStatus() === 'approved'
        );
    }
}
