<?php

declare(strict_types=1);

namespace Windcave\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class WindcaveTokenService
{
    public const CUSTOMER_TOKEN_FIELD = 'windcaveCardToken';

    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    public function storeForCustomer(string $customerId, string $token, Context $context): void
    {
        $this->customerRepository->update([
            [
                'id' => $customerId,
                'customFields' => [
                    self::CUSTOMER_TOKEN_FIELD => $token,
                ],
            ],
        ], $context);
    }

    public function storeOnTransaction(string $transactionId, string $token, Context $context): void
    {
        $this->orderTransactionRepository->update([
            [
                'id' => $transactionId,
                'customFields' => [
                    self::CUSTOMER_TOKEN_FIELD => $token,
                ],
            ],
        ], $context);
    }

    public function getStoredToken(?string $customerId, Context $context): ?string
    {
        if (!$customerId) {
            return null;
        }

        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();
        $custom = $customer?->getCustomFields() ?? [];

        return $custom[self::CUSTOMER_TOKEN_FIELD] ?? null;
    }
}
