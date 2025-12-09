<?php

declare(strict_types=1);

namespace Windcave\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WindcaveRefundService
{
    private const LIVE_TRANSACTION_ENDPOINT = 'https://sec.windcave.com/api/v1/transactions';
    private const TEST_TRANSACTION_ENDPOINT = 'https://uat.windcave.com/api/v1/transactions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly WindcaveConfig $config,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process a refund for a Windcave transaction.
     *
     * @param string $orderTransactionId Shopware order transaction ID
     * @param string $amount Amount to refund (formatted as string, e.g., "12.34")
     * @param string $salesChannelId Sales channel for config lookup
     * @param Context $context Shopware context
     * @return WindcaveResult
     */
    public function refund(
        string $orderTransactionId,
        string $amount,
        string $salesChannelId,
        Context $context
    ): WindcaveResult {
        $criteria = new Criteria([$orderTransactionId]);
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction) {
            return new WindcaveResult(
                success: false,
                message: 'Order transaction not found'
            );
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $windcaveTransactionId = $customFields['windcaveTransactionId'] ?? null;

        if (!$windcaveTransactionId) {
            $this->logger->error('Windcave refund failed: no transactionId stored', [
                'orderTransactionId' => $orderTransactionId,
            ]);

            return new WindcaveResult(
                success: false,
                message: 'No Windcave transaction ID found for refund'
            );
        }

        $testMode = $this->config->isTestMode($salesChannelId);
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);

        if (!$username || !$apiKey) {
            return new WindcaveResult(
                success: false,
                message: 'Windcave API credentials not configured'
            );
        }

        $currency = $customFields['windcaveCurrency'] ?? 'NZD';

        $payload = [
            'type' => 'refund',
            'amount' => $amount,
            'currency' => $currency,
            'transactionId' => $windcaveTransactionId,
        ];

        $endpoint = $testMode ? self::TEST_TRANSACTION_ENDPOINT : self::LIVE_TRANSACTION_ENDPOINT;

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'json' => $payload,
                'auth_basic' => [$username, $apiKey],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status !== 200 && $status !== 201 && $status !== 202) {
                $this->logger->error('Windcave refund failed', [
                    'status' => $status,
                    'response' => $data,
                    'orderTransactionId' => $orderTransactionId,
                ]);

                return new WindcaveResult(
                    success: false,
                    message: $data['message'] ?? ('Refund failed with status ' . $status)
                );
            }

            $authorised = $data['authorised'] ?? false;
            $responseText = $data['responseText'] ?? ($data['reCo'] ?? 'Unknown');

            $this->logger->info('Windcave refund processed', [
                'orderTransactionId' => $orderTransactionId,
                'windcaveTransactionId' => $windcaveTransactionId,
                'authorised' => $authorised,
                'responseText' => $responseText,
                'refundTransactionId' => $data['id'] ?? null,
            ]);

            // Store refund transaction ID
            if (isset($data['id'])) {
                $this->orderTransactionRepository->update([
                    [
                        'id' => $orderTransactionId,
                        'customFields' => [
                            'windcaveRefundTransactionId' => $data['id'],
                        ],
                    ],
                ], $context);
            }

            return new WindcaveResult(
                success: $authorised === true,
                message: $responseText
            );
        } catch (\Throwable $e) {
            $this->logger->error('Windcave refund exception', [
                'error' => $e->getMessage(),
                'orderTransactionId' => $orderTransactionId,
            ]);

            return new WindcaveResult(
                success: false,
                message: 'Refund request failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Void/cancel an authorized transaction.
     *
     * @param string $orderTransactionId Shopware order transaction ID
     * @param string $salesChannelId Sales channel for config lookup
     * @param Context $context Shopware context
     * @return WindcaveResult
     */
    public function void(
        string $orderTransactionId,
        string $salesChannelId,
        Context $context
    ): WindcaveResult {
        $criteria = new Criteria([$orderTransactionId]);
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction) {
            return new WindcaveResult(
                success: false,
                message: 'Order transaction not found'
            );
        }

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $windcaveTransactionId = $customFields['windcaveTransactionId'] ?? null;
        $amount = $customFields['windcaveAmount'] ?? null;
        $currency = $customFields['windcaveCurrency'] ?? 'NZD';

        if (!$windcaveTransactionId) {
            return new WindcaveResult(
                success: false,
                message: 'No Windcave transaction ID found for void'
            );
        }

        $testMode = $this->config->isTestMode($salesChannelId);
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);

        $payload = [
            'type' => 'void',
            'transactionId' => $windcaveTransactionId,
        ];

        if ($amount) {
            $payload['amount'] = $amount;
            $payload['currency'] = $currency;
        }

        $endpoint = $testMode ? self::TEST_TRANSACTION_ENDPOINT : self::LIVE_TRANSACTION_ENDPOINT;

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'json' => $payload,
                'auth_basic' => [$username, $apiKey],
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            $authorised = $data['authorised'] ?? false;
            $responseText = $data['responseText'] ?? ($data['reCo'] ?? 'Unknown');

            $this->logger->info('Windcave void processed', [
                'orderTransactionId' => $orderTransactionId,
                'authorised' => $authorised,
                'responseText' => $responseText,
            ]);

            return new WindcaveResult(
                success: $authorised === true,
                message: $responseText
            );
        } catch (\Throwable $e) {
            $this->logger->error('Windcave void exception', [
                'error' => $e->getMessage(),
                'orderTransactionId' => $orderTransactionId,
            ]);

            return new WindcaveResult(
                success: false,
                message: 'Void request failed: ' . $e->getMessage()
            );
        }
    }
}
