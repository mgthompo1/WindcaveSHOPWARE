<?php

declare(strict_types=1);

namespace Windcave\Payment\Handler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Windcave\Service\WindcaveApiService;
use Windcave\Service\WindcaveConfig;
use Windcave\Service\WindcavePayloadFactory;

class WindcavePaymentHandler implements AsynchronousPaymentHandlerInterface
{
    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcavePayloadFactory $payloadFactory,
        private readonly RequestStack $requestStack,
        private readonly WindcaveConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $returnUrl = $transaction->getReturnUrl();

        $payload = $this->payloadFactory->fromTransaction($transaction, $salesChannelContext, $returnUrl);

        try {
            $session = $this->apiService->createHostedPayment($payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Windcave createHostedPayment failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Unable to initiate Windcave payment: ' . $exception->getMessage()
            );
        }

        $hpp = $session->getHppUrl();
        if (!$hpp) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Windcave response missing HPP link'
            );
        }

        return new RedirectResponse($hpp);
    }

    public function finalize(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $windcaveResult = $request?->query->get('sessionId') ?? $request?->query->get('result');
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $testMode = $this->config->isTestMode($salesChannelId);

        if (!$windcaveResult) {
            throw new AsyncPaymentFinalizeException(
                $transaction->getOrderTransaction()->getId(),
                'Missing Windcave result token on return'
            );
        }

        try {
            $sessionPayload = $this->payloadFactory->fromTransaction($transaction, $salesChannelContext, (string) $transaction->getReturnUrl());

            $result = $this->apiService->fetchDropInResult(
                (string) $windcaveResult,
                $sessionPayload
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Windcave fetchResult failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new AsyncPaymentFinalizeException(
                $transaction->getOrderTransaction()->getId(),
                'Failed to verify Windcave payment: ' . $exception->getMessage()
            );
        }

        if (!$result->isSuccessful()) {
            throw new AsyncPaymentFinalizeException(
                $transaction->getOrderTransaction()->getId(),
                'Windcave reported payment failed: ' . $result->getMessage()
            );
        }
    }
}
