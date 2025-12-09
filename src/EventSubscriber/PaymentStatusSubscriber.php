<?php

declare(strict_types=1);

namespace Windcave\EventSubscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Windcave\Payment\Handler\WindcaveDropInPaymentHandler;
use Windcave\Payment\Handler\WindcavePaymentHandler;
use Windcave\Service\WindcaveRefundService;

class PaymentStatusSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly WindcaveRefundService $refundService,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onTransitionEvent',
        ];
    }

    public function onTransitionEvent(StateMachineTransitionEvent $event): void
    {
        if ($event->getEntityName() !== OrderTransactionDefinition::ENTITY_NAME) {
            return;
        }

        $orderTransactionId = $event->getEntityId();
        $toState = $event->getToPlace()->getTechnicalName();
        $fromState = $event->getFromPlace()->getTechnicalName();
        $context = $event->getContext();

        // Load the order transaction
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('paymentMethod');
        $criteria->addAssociation('order.salesChannel');

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$orderTransaction) {
            return;
        }

        // Check if this is a Windcave payment method
        $paymentMethod = $orderTransaction->getPaymentMethod();
        if (!$paymentMethod) {
            return;
        }

        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        if (
            $handlerIdentifier !== WindcavePaymentHandler::class &&
            $handlerIdentifier !== WindcaveDropInPaymentHandler::class
        ) {
            return;
        }

        $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();
        if (!$salesChannelId) {
            $this->logger->warning('PaymentStatusSubscriber: No sales channel ID found', [
                'orderTransactionId' => $orderTransactionId,
            ]);
            return;
        }

        // Handle state transitions
        $this->handleStateTransition(
            $orderTransactionId,
            $fromState,
            $toState,
            $salesChannelId,
            $orderTransaction,
            $context
        );
    }

    private function handleStateTransition(
        string $orderTransactionId,
        string $fromState,
        string $toState,
        string $salesChannelId,
        OrderTransactionEntity $orderTransaction,
        Context $context
    ): void {
        $customFields = $orderTransaction->getCustomFields() ?? [];

        // Transition: Paid -> Refunded
        if (
            $fromState === OrderTransactionStates::STATE_PAID &&
            $toState === OrderTransactionStates::STATE_REFUNDED
        ) {
            $this->processRefund($orderTransactionId, $customFields, $salesChannelId, $context);
            return;
        }

        // Transition: Partially Paid -> Refunded (partial refund already processed, full refund now)
        if (
            $fromState === OrderTransactionStates::STATE_PARTIALLY_PAID &&
            $toState === OrderTransactionStates::STATE_REFUNDED
        ) {
            $this->processRefund($orderTransactionId, $customFields, $salesChannelId, $context);
            return;
        }

        // Transition: Authorized -> Cancelled (void the auth)
        if (
            $fromState === OrderTransactionStates::STATE_AUTHORIZED &&
            $toState === OrderTransactionStates::STATE_CANCELLED
        ) {
            $this->processVoid($orderTransactionId, $salesChannelId, $context);
            return;
        }

        // Note: Authorized -> Paid (capture) is typically handled automatically by Windcave
        // for "purchase" type transactions. If you're using "auth" type, you'd need to
        // add a capture flow here using type: "complete"
    }

    private function processRefund(
        string $orderTransactionId,
        array $customFields,
        string $salesChannelId,
        Context $context
    ): void {
        $amount = $customFields['windcaveAmount'] ?? null;

        if (!$amount) {
            $this->logger->error('PaymentStatusSubscriber: Cannot refund - no amount stored', [
                'orderTransactionId' => $orderTransactionId,
            ]);
            return;
        }

        $this->logger->info('PaymentStatusSubscriber: Processing refund', [
            'orderTransactionId' => $orderTransactionId,
            'amount' => $amount,
        ]);

        $result = $this->refundService->refund(
            $orderTransactionId,
            $amount,
            $salesChannelId,
            $context
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('PaymentStatusSubscriber: Refund failed', [
                'orderTransactionId' => $orderTransactionId,
                'message' => $result->getMessage(),
            ]);
            // Note: We don't throw here to avoid blocking the state transition
            // The admin can see the error in logs and handle manually if needed
        }
    }

    private function processVoid(
        string $orderTransactionId,
        string $salesChannelId,
        Context $context
    ): void {
        $this->logger->info('PaymentStatusSubscriber: Processing void', [
            'orderTransactionId' => $orderTransactionId,
        ]);

        $result = $this->refundService->void(
            $orderTransactionId,
            $salesChannelId,
            $context
        );

        if (!$result->isSuccessful()) {
            $this->logger->error('PaymentStatusSubscriber: Void failed', [
                'orderTransactionId' => $orderTransactionId,
                'message' => $result->getMessage(),
            ]);
        }
    }
}
