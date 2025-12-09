<?php

declare(strict_types=1);

namespace Windcave\Payment\Handler;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Windcave\Service\WindcaveApiService;
use Windcave\Service\WindcaveConfig;
use Windcave\Service\WindcavePayloadFactory;
use Windcave\Service\WindcaveTokenService;

class WindcaveDropInPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcavePayloadFactory $payloadFactory,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $router,
        private readonly WindcaveTokenService $tokenService,
        private readonly WindcaveConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $returnUrl = $transaction->getReturnUrl();
        $payload = $this->payloadFactory->fromTransaction($transaction, $salesChannelContext, $returnUrl);

        try {
            $session = $this->apiService->createDropInSession($payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Windcave createHostedPayment failed (drop-in)', [
                'error' => $exception->getMessage(),
            ]);

            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Unable to initiate Windcave payment: ' . $exception->getMessage()
            );
        }

        // Store session data for drop-in page rendering
        // Note: We do NOT store API credentials - they're fetched from config during finalize
        $this->orderTransactionRepository->upsert(
            [
                [
                    'id' => $transaction->getOrderTransaction()->getId(),
                    'customFields' => [
                        'windcaveDropInSession' => $session->asArray(),
                        'windcaveSessionId' => $session->getId(),
                        'windcaveReturnUrl' => $returnUrl,
                        'windcaveDropInTestMode' => $payload->testMode,
                        'windcaveDropInScriptBase' => $payload->testMode ? 'https://uat.windcave.com' : 'https://sec.windcave.com',
                        'windcaveAppleMerchantId' => $this->payloadFactory->getConfig()->getAppleMerchantId($salesChannelContext->getSalesChannelId()),
                        'windcaveGoogleMerchantId' => $this->payloadFactory->getConfig()->getGoogleMerchantId($salesChannelContext->getSalesChannelId()),
                    ],
                ],
            ],
            $salesChannelContext->getContext()
        );

        $dropInRoute = $this->router->generate(
            'frontend.windcave.dropin.start',
            [
                'orderTransactionId' => $transaction->getOrderTransaction()->getId(),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new RedirectResponse($dropInRoute);
    }

    public function finalize(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        // Support both storefront (RequestStack) and headless (RequestDataBag) flows
        $windcaveResult = $dataBag->get('sessionId') ?? $dataBag->get('result');

        // Fall back to request query parameters for storefront flow
        if (!$windcaveResult) {
            $request = $this->requestStack->getCurrentRequest();
            $windcaveResult = $request?->query->get('result') ?? $request?->query->get('sessionId');
        }

        $customFields = $transaction->getOrderTransaction()->getCustomFields() ?? [];
        $testMode = (bool) ($customFields['windcaveDropInTestMode'] ?? false);

        // Get credentials from config (NOT from stored customFields for security)
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);

        if (!$windcaveResult) {
            throw new AsyncPaymentFinalizeException(
                $transaction->getOrderTransaction()->getId(),
                'Missing Windcave result token on return'
            );
        }

        try {
            $dropInPayload = $this->payloadFactory->dropInPayload($transaction, $salesChannelContext, (string) ($customFields['windcaveReturnUrl'] ?? ''));
            $dropInPayload = new \Windcave\Service\WindcaveSessionRequestPayload(
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

        // Store transaction data for refunds
        $transactionData = [
            'id' => $transaction->getOrderTransaction()->getId(),
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
            $this->orderTransactionRepository->update([$transactionData], $salesChannelContext->getContext());
        }

        // Handle card tokenization
        $cardId = $result->getCardId();
        $customerId = $salesChannelContext->getCustomer()?->getId();
        if ($cardId && $customerId) {
            $this->tokenService->storeForCustomer($customerId, $cardId, $salesChannelContext->getContext());
        } elseif ($cardId) {
            $this->tokenService->storeOnTransaction($transaction->getOrderTransaction()->getId(), $cardId, $salesChannelContext->getContext());
        }
    }
}
