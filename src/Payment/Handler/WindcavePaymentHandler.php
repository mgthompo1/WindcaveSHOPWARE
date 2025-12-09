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
use Windcave\Service\WindcaveApiService;
use Windcave\Service\WindcaveConfig;
use Windcave\Service\WindcavePayloadFactory;
use Windcave\Service\WindcaveTokenService;

class WindcavePaymentHandler extends AbstractPaymentHandler
{
    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcavePayloadFactory $payloadFactory,
        private readonly WindcaveConfig $config,
        private readonly WindcaveTokenService $tokenService,
        private readonly EntityRepository $orderTransactionRepository,
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

        // Fetch order and transaction from repository
        [$orderTransaction, $order] = $this->fetchOrderTransaction($orderTransactionId, $context);

        $payload = $this->payloadFactory->fromOrderAndTransaction($order, $orderTransaction, $context, $returnUrl ?? '');

        try {
            $session = $this->apiService->createHostedPayment($payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Windcave createHostedPayment failed', [
                'error' => $exception->getMessage(),
            ]);

            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Unable to initiate Windcave payment: ' . $exception->getMessage()
            );
        }

        $hpp = $session->getHppUrl();
        if (!$hpp) {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Windcave response missing HPP link'
            );
        }

        // Store session ID for FPRN notification matching
        $this->orderTransactionRepository->update([
            [
                'id' => $orderTransactionId,
                'customFields' => [
                    'windcaveSessionId' => $session->getId(),
                ],
            ],
        ], $context);

        return new RedirectResponse($hpp);
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $returnUrl = $transaction->getReturnUrl();

        // Fetch order and transaction from repository
        [$orderTransaction, $order] = $this->fetchOrderTransaction($orderTransactionId, $context);

        // Get sessionId from query parameters
        $windcaveResult = $request->query->get('sessionId') ?? $request->query->get('result');

        if (!$windcaveResult) {
            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransactionId,
                'Missing Windcave result token on return'
            );
        }

        try {
            $sessionPayload = $this->payloadFactory->fromOrderAndTransaction($order, $orderTransaction, $context, $returnUrl ?? '');

            $result = $this->apiService->fetchDropInResult(
                (string) $windcaveResult,
                $sessionPayload
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

        // Store transaction data for refunds
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
