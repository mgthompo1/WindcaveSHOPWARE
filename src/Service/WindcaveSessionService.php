<?php

declare(strict_types=1);

namespace Windcave\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Windcave\Service\Struct\WindcaveDropInSession;

/**
 * Service layer for Windcave session operations.
 *
 * Extracts business logic from controllers to support both:
 * - Storefront (traditional server-rendered)
 * - Store API (headless/PWA)
 */
class WindcaveSessionService
{
    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcaveConfig $config,
        private readonly WindcaveTokenService $tokenService,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get drop-in session data for rendering the payment form.
     * Used by both Storefront controller and Store API.
     */
    public function getDropInSessionData(string $orderTransactionId, string $salesChannelId, Context $context): array
    {
        $criteria = (new Criteria([$orderTransactionId]))
            ->addAssociation('order.currency')
            ->addAssociation('order.billingAddress.country');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$transaction) {
            throw new \RuntimeException('Order transaction not found');
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $session = $customFields['windcaveDropInSession'] ?? null;

        if (!$session) {
            throw new \RuntimeException('No Windcave session found for this transaction');
        }

        return [
            'sessionId' => $session['id'] ?? null,
            'links' => $session['links'] ?? [],
            'hppUrl' => $session['hppUrl'] ?? null,
            'returnUrl' => $customFields['windcaveReturnUrl'] ?? null,
            'scriptBase' => $customFields['windcaveDropInScriptBase'] ?? 'https://sec.windcave.com',
            'appleMerchantId' => $customFields['windcaveAppleMerchantId'] ?? '',
            'googleMerchantId' => $customFields['windcaveGoogleMerchantId'] ?? '',
            'isTest' => (bool) ($customFields['windcaveDropInTestMode'] ?? false),
            'currency' => $transaction->getOrder()?->getCurrency()?->getIsoCode() ?? 'NZD',
            'country' => $transaction->getOrder()?->getBillingAddress()?->getCountry()?->getIso() ?? 'NZ',
            // Styling configuration
            'darkModeConfig' => $this->config->getDarkModeConfig($salesChannelId),
            'hideSelectPaymentMethodTitle' => $this->config->getHideSelectPaymentMethodTitle($salesChannelId),
            'styles' => $this->config->getDropInStyles($salesChannelId),
            'customCss' => $this->config->getCustomCss($salesChannelId),
        ];
    }

    /**
     * Verify a Windcave session and return the result.
     * Used by finalize handlers and notification webhook.
     *
     * @param string $sessionId The Windcave session ID to verify
     * @param string $salesChannelId The sales channel for config lookup
     * @param bool|null $testMode Override test mode (null = use config)
     */
    public function verifySession(string $sessionId, string $salesChannelId, ?bool $testMode = null): WindcaveResult
    {
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);
        $isTestMode = $testMode ?? $this->config->isTestMode($salesChannelId);

        if (!$username || !$apiKey) {
            throw new \RuntimeException('Windcave API credentials not configured');
        }

        $payload = new WindcaveSessionRequestPayload(
            username: $username,
            apiKey: $apiKey,
            amount: '0.00',
            currency: 'NZD',
            merchantReference: '',
            language: 'en',
            approvedUrl: '',
            declinedUrl: '',
            cancelledUrl: '',
            notificationUrl: '',
            testMode: $isTestMode
        );

        return $this->apiService->fetchDropInResult($sessionId, $payload);
    }

    /**
     * Process a completed payment session - update transaction state and store data.
     *
     * @return bool True if payment was successful
     */
    public function processSessionResult(
        string $orderTransactionId,
        string $sessionId,
        string $salesChannelId,
        ?string $customerId,
        Context $context
    ): bool {
        // Get existing custom fields for test mode
        $criteria = new Criteria([$orderTransactionId]);
        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        $customFields = $transaction?->getCustomFields() ?? [];
        $testMode = (bool) ($customFields['windcaveDropInTestMode'] ?? $this->config->isTestMode($salesChannelId));

        $result = $this->verifySession($sessionId, $salesChannelId, $testMode);

        // Store transaction data for refunds
        $updateData = [
            'id' => $orderTransactionId,
            'customFields' => [],
        ];

        if ($result->getTransactionId()) {
            $updateData['customFields']['windcaveTransactionId'] = $result->getTransactionId();
        }
        if ($result->getAmount()) {
            $updateData['customFields']['windcaveAmount'] = $result->getAmount();
        }
        if ($result->getCurrency()) {
            $updateData['customFields']['windcaveCurrency'] = $result->getCurrency();
        }

        if (!empty($updateData['customFields'])) {
            $this->orderTransactionRepository->update([$updateData], $context);
        }

        // Handle card tokenization
        $cardId = $result->getCardId();
        if ($cardId && $customerId) {
            $this->tokenService->storeForCustomer($customerId, $cardId, $context);
        } elseif ($cardId) {
            $this->tokenService->storeOnTransaction($orderTransactionId, $cardId, $context);
        }

        return $result->isSuccessful();
    }

    /**
     * Handle FPRN notification - find transaction by session ID and update state.
     */
    public function handleNotification(string $sessionId, Context $context): array
    {
        $this->logger->info('Processing Windcave notification', ['sessionId' => $sessionId]);

        // Find the order transaction by session ID
        $transaction = $this->findTransactionBySessionId($sessionId, $context);

        if (!$transaction) {
            $this->logger->warning('Windcave notification: Transaction not found', ['sessionId' => $sessionId]);
            return [
                'success' => false,
                'message' => 'Transaction not found',
                'alreadyProcessed' => false,
            ];
        }

        $currentState = $transaction->getStateMachineState()?->getTechnicalName();

        // If already in a final state, don't process again
        if (in_array($currentState, [
            OrderTransactionStates::STATE_PAID,
            OrderTransactionStates::STATE_CANCELLED,
            OrderTransactionStates::STATE_FAILED,
            OrderTransactionStates::STATE_REFUNDED,
        ], true)) {
            $this->logger->info('Windcave notification: Already in final state', [
                'sessionId' => $sessionId,
                'state' => $currentState,
            ]);
            return [
                'success' => true,
                'message' => 'Already processed',
                'alreadyProcessed' => true,
            ];
        }

        $salesChannelId = $transaction->getOrder()?->getSalesChannelId();
        $customFields = $transaction->getCustomFields() ?? [];
        $testMode = (bool) ($customFields['windcaveDropInTestMode'] ?? $this->config->isTestMode($salesChannelId));

        try {
            $result = $this->verifySession($sessionId, $salesChannelId, $testMode);

            // Store transaction data
            $updateData = ['id' => $transaction->getId(), 'customFields' => []];
            if ($result->getTransactionId()) {
                $updateData['customFields']['windcaveTransactionId'] = $result->getTransactionId();
            }
            if ($result->getAmount()) {
                $updateData['customFields']['windcaveAmount'] = $result->getAmount();
            }
            if ($result->getCurrency()) {
                $updateData['customFields']['windcaveCurrency'] = $result->getCurrency();
            }
            if (!empty($updateData['customFields'])) {
                $this->orderTransactionRepository->update([$updateData], $context);
            }

            if ($result->isSuccessful()) {
                $this->transactionStateHandler->paid($transaction->getId(), $context);
                $this->logger->info('Windcave notification: Marked as paid', [
                    'sessionId' => $sessionId,
                    'transactionId' => $transaction->getId(),
                ]);
                return ['success' => true, 'message' => 'Payment confirmed', 'alreadyProcessed' => false];
            } else {
                $this->transactionStateHandler->fail($transaction->getId(), $context);
                $this->logger->info('Windcave notification: Marked as failed', [
                    'sessionId' => $sessionId,
                    'transactionId' => $transaction->getId(),
                ]);
                return ['success' => false, 'message' => $result->getMessage(), 'alreadyProcessed' => false];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Windcave notification processing error', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find an order transaction by Windcave session ID.
     */
    public function findTransactionBySessionId(string $sessionId, Context $context): ?OrderTransactionEntity
    {
        // Try windcaveSessionId first
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.windcaveSessionId', $sessionId));
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order.salesChannel');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if ($transaction) {
            return $transaction;
        }

        // Try windcaveDropInSession.id
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.windcaveDropInSession.id', $sessionId));
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order.salesChannel');

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }
}
