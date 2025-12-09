<?php

declare(strict_types=1);

namespace Windcave\Payment\Handler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Windcave\Service\WindcaveApiService;
use Windcave\Service\WindcaveConfig;
use Windcave\Service\WindcavePayloadFactory;
use Windcave\Service\WindcaveSessionRequestPayload;
use Windcave\Service\WindcaveTokenService;

class WindcaveDropInPaymentHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcavePayloadFactory $payloadFactory,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly UrlGeneratorInterface $router,
        private readonly WindcaveTokenService $tokenService,
        private readonly WindcaveConfig $config,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // We do not support recurring or refund through this handler
        return false;
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $returnUrl = $transaction->getReturnUrl();

        // Check if payment was already completed via inline Drop-In on checkout page
        $paymentCompleted = $request->request->get('windcavePaymentCompleted') === '1';
        $inlineSessionId = $request->request->get('windcaveSessionId');

        if ($paymentCompleted && $inlineSessionId) {
            $this->logger->info('Windcave: Payment completed via inline Drop-In', [
                'sessionId' => $inlineSessionId,
                'orderTransactionId' => $orderTransactionId,
            ]);

            // Verify the session and process payment
            return $this->handleInlinePaymentCompleted($inlineSessionId, $orderTransactionId, $returnUrl ?? '', $context);
        }

        // Fetch order and transaction from repository
        [$orderTransaction, $order] = $this->fetchOrderTransaction($orderTransactionId, $context);

        $payload = $this->payloadFactory->fromOrderAndTransaction($order, $orderTransaction, $context, $returnUrl ?? '');

        try {
            $session = $this->apiService->createDropInSession($payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Windcave createHostedPayment failed (drop-in)', [
                'error' => $exception->getMessage(),
            ]);

            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Unable to initiate Windcave payment: ' . $exception->getMessage()
            );
        }

        // Get sales channel ID from order
        $salesChannelId = $order->getSalesChannelId();

        // Store session data for drop-in page rendering
        // Note: We do NOT store API credentials - they're fetched from config during finalize
        $this->orderTransactionRepository->upsert(
            [
                [
                    'id' => $orderTransactionId,
                    'customFields' => [
                        'windcaveDropInSession' => $session->asArray(),
                        'windcaveSessionId' => $session->getId(),
                        'windcaveReturnUrl' => $returnUrl,
                        'windcaveDropInTestMode' => $payload->testMode,
                        'windcaveDropInScriptBase' => $payload->testMode ? 'https://uat.windcave.com' : 'https://sec.windcave.com',
                        'windcaveAppleMerchantId' => $this->payloadFactory->getConfig()->getAppleMerchantId($salesChannelId),
                        'windcaveGoogleMerchantId' => $this->payloadFactory->getConfig()->getGoogleMerchantId($salesChannelId),
                    ],
                ],
            ],
            $context
        );

        $dropInRoute = $this->router->generate(
            'frontend.windcave.dropin.start',
            [
                'orderTransactionId' => $orderTransactionId,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new RedirectResponse($dropInRoute);
    }

    /**
     * Handle payment that was completed via the inline Drop-In on checkout page.
     * Verifies the session result and marks transaction as paid.
     */
    private function handleInlinePaymentCompleted(
        string $sessionId,
        string $orderTransactionId,
        string $returnUrl,
        Context $context
    ): ?RedirectResponse {
        [$orderTransaction, $order] = $this->fetchOrderTransaction($orderTransactionId, $context);
        $salesChannelId = $order->getSalesChannelId();

        $testMode = $this->config->isTestMode($salesChannelId);
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);

        try {
            // Create a minimal payload for session verification
            $verifyPayload = new WindcaveSessionRequestPayload(
                username: $username,
                apiKey: $apiKey,
                amount: 0.00,
                currency: $order->getCurrency()?->getIsoCode() ?? 'NZD',
                merchantReference: '',
                language: 'en',
                approvedUrl: '',
                declinedUrl: '',
                cancelledUrl: '',
                notificationUrl: '',
                testMode: $testMode
            );

            $result = $this->apiService->fetchDropInResult($sessionId, $verifyPayload);

            if (!$result->isSuccessful()) {
                $this->logger->warning('Windcave inline payment verification failed', [
                    'sessionId' => $sessionId,
                    'message' => $result->getMessage(),
                ]);

                throw PaymentException::asyncProcessInterrupted(
                    $orderTransactionId,
                    'Windcave payment verification failed: ' . $result->getMessage()
                );
            }

            // Store session and transaction data including card details
            $customFields = [
                'windcaveSessionId' => $sessionId,
                'windcaveDropInTestMode' => $testMode,
            ];

            if ($result->getTransactionId()) {
                $customFields['windcaveTransactionId'] = $result->getTransactionId();
            }
            if ($result->getAmount()) {
                $customFields['windcaveAmount'] = $result->getAmount();
            }
            if ($result->getCurrency()) {
                $customFields['windcaveCurrency'] = $result->getCurrency();
            }
            if ($result->getCardType()) {
                $customFields['windcaveCardType'] = $result->getCardType();
            }
            if ($result->getCardLast4()) {
                $customFields['windcaveCardLast4'] = $result->getCardLast4();
            }
            if ($result->getCardExpiry()) {
                $customFields['windcaveCardExpiry'] = $result->getCardExpiry();
            }

            $this->orderTransactionRepository->update([
                [
                    'id' => $orderTransactionId,
                    'customFields' => $customFields,
                ],
            ], $context);

            // Handle card tokenization
            $cardId = $result->getCardId();
            $customerId = $order->getOrderCustomer()?->getCustomerId();
            if ($cardId && $customerId) {
                $this->tokenService->storeForCustomer($customerId, $cardId, $context);
            } elseif ($cardId) {
                $this->tokenService->storeOnTransaction($orderTransactionId, $cardId, $context);
            }

            // Mark the transaction as paid
            $this->transactionStateHandler->paid($orderTransactionId, $context);

            $this->logger->info('Windcave inline payment verified and marked as paid', [
                'sessionId' => $sessionId,
                'orderTransactionId' => $orderTransactionId,
            ]);

            // Return null to indicate synchronous payment (no redirect needed)
            // Shopware will redirect to the return URL automatically
            return null;

        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Windcave inline payment verification error', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Failed to verify Windcave payment: ' . $e->getMessage()
            );
        }
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $orderTransactionId = $transaction->getOrderTransactionId();

        // Fetch order and transaction from repository
        [$orderTransaction, $order] = $this->fetchOrderTransaction($orderTransactionId, $context);

        // Get sessionId from query parameters
        $windcaveResult = $request->query->get('result') ?? $request->query->get('sessionId');

        $customFields = $orderTransaction->getCustomFields() ?? [];
        $testMode = (bool) ($customFields['windcaveDropInTestMode'] ?? false);

        // Get credentials from config (NOT from stored customFields for security)
        $salesChannelId = $order->getSalesChannelId();
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);

        if (!$windcaveResult) {
            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransactionId,
                'Missing Windcave result token on return'
            );
        }

        try {
            $dropInPayload = $this->payloadFactory->dropInPayloadFromOrderAndTransaction($order, $orderTransaction, $context, (string) ($customFields['windcaveReturnUrl'] ?? ''));
            $dropInPayload = new WindcaveSessionRequestPayload(
                username: $username,
                apiKey: $apiKey,
                amount: $dropInPayload->amount,
                currency: $dropInPayload->currency,
                merchantReference: $dropInPayload->merchantReference,
                language: $dropInPayload->language,
                approvedUrl: $dropInPayload->approvedUrl,
                declinedUrl: $dropInPayload->declinedUrl,
                cancelledUrl: $dropInPayload->cancelledUrl,
                notificationUrl: $dropInPayload->notificationUrl,
                testMode: $testMode
            );

            $result = $this->apiService->fetchDropInResult(
                (string) $windcaveResult,
                $dropInPayload
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Windcave fetchResult failed', [
                'error' => $exception->getMessage(),
            ]);

            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransactionId,
                'Failed to verify Windcave payment: ' . $exception->getMessage()
            );
        }

        if (!$result->isSuccessful()) {
            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransactionId,
                'Windcave reported payment failed: ' . $result->getMessage()
            );
        }

        // Store transaction data for refunds and card details for display
        $transactionData = [
            'id' => $orderTransactionId,
            'customFields' => [],
        ];

        if ($result->getTransactionId()) {
            $transactionData['customFields']['windcaveTransactionId'] = $result->getTransactionId();
        }
        if ($result->getAmount()) {
            $transactionData['customFields']['windcaveAmount'] = $result->getAmount();
        }
        if ($result->getCurrency()) {
            $transactionData['customFields']['windcaveCurrency'] = $result->getCurrency();
        }
        if ($result->getCardType()) {
            $transactionData['customFields']['windcaveCardType'] = $result->getCardType();
        }
        if ($result->getCardLast4()) {
            $transactionData['customFields']['windcaveCardLast4'] = $result->getCardLast4();
        }
        if ($result->getCardExpiry()) {
            $transactionData['customFields']['windcaveCardExpiry'] = $result->getCardExpiry();
        }

        if (!empty($transactionData['customFields'])) {
            $this->orderTransactionRepository->update([$transactionData], $context);
        }

        // Handle card tokenization
        $cardId = $result->getCardId();
        $customerId = $order->getOrderCustomer()?->getCustomerId();
        if ($cardId && $customerId) {
            $this->tokenService->storeForCustomer($customerId, $cardId, $context);
        } elseif ($cardId) {
            $this->tokenService->storeOnTransaction($orderTransactionId, $cardId, $context);
        }

        // Mark the transaction as paid
        $this->transactionStateHandler->paid($orderTransactionId, $context);
    }

    /**
     * @return array{0: OrderTransactionEntity, 1: OrderEntity}
     */
    private function fetchOrderTransaction(string $transactionId, Context $context): array
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.orderCustomer.customer');
        $criteria->addAssociation('order.language.translationCode');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        \assert($transaction instanceof OrderTransactionEntity);

        $order = $transaction->getOrder();
        \assert($order instanceof OrderEntity);

        return [$transaction, $order];
    }
}
