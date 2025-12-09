<?php

declare(strict_types=1);

namespace Windcave\Controller\Api;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Windcave\Service\WindcaveApiService;
use Windcave\Service\WindcaveConfig;

/**
 * Admin API Controller for Windcave plugin management.
 *
 * Provides endpoints for:
 * - API credential testing
 * - Webhook status monitoring
 * - Transaction history retrieval
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class WindcaveAdminApiController extends AbstractController
{
    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcaveConfig $config,
        private readonly EntityRepository $transactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Test API credentials by making a test request to Windcave.
     */
    #[Route(
        path: '/api/windcave/test-credentials',
        name: 'api.windcave.test_credentials',
        methods: ['POST']
    )]
    public function testCredentials(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->request->get('salesChannelId');

        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);
        $testMode = $this->config->isTestMode($salesChannelId);

        if (!$username || !$apiKey) {
            return new JsonResponse([
                'success' => false,
                'message' => 'API credentials are not configured. Please enter REST Username and API Key.',
                'details' => [
                    'username_set' => !empty($username),
                    'api_key_set' => !empty($apiKey),
                ]
            ], 400);
        }

        try {
            // Attempt to create a minimal session to test credentials
            $testResult = $this->apiService->testCredentials($username, $apiKey, $testMode);

            if ($testResult['success']) {
                $this->logger->info('Windcave API credentials test successful', [
                    'salesChannelId' => $salesChannelId,
                    'testMode' => $testMode,
                ]);

                return new JsonResponse([
                    'success' => true,
                    'message' => 'API credentials are valid!',
                    'details' => [
                        'environment' => $testMode ? 'Test (UAT)' : 'Production',
                        'username' => $username,
                        'response_time_ms' => $testResult['response_time_ms'] ?? null,
                    ]
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'message' => $testResult['message'] ?? 'Credentials validation failed',
                'details' => $testResult
            ], 401);

        } catch (\Throwable $e) {
            $this->logger->error('Windcave API credentials test failed', [
                'error' => $e->getMessage(),
                'salesChannelId' => $salesChannelId,
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Failed to connect to Windcave API: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e),
                ]
            ], 500);
        }
    }

    /**
     * Get webhook configuration status and URL.
     */
    #[Route(
        path: '/api/windcave/webhook-status',
        name: 'api.windcave.webhook_status',
        methods: ['GET']
    )]
    public function getWebhookStatus(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');

        // Generate the webhook URL
        $webhookUrl = $this->generateUrl(
            'frontend.windcave.notification',
            [],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new JsonResponse([
            'webhook_url' => $webhookUrl,
            'instructions' => [
                'step1' => 'Log in to your Windcave merchant portal',
                'step2' => 'Navigate to FPRN (Fail Proof Result Notification) settings',
                'step3' => 'Enter the webhook URL shown above',
                'step4' => 'Enable FPRN notifications',
                'step5' => 'Save your settings',
            ],
            'supported_events' => [
                'payment.approved',
                'payment.declined',
                'payment.cancelled',
                'refund.completed',
            ],
            'test_mode' => $this->config->isTestMode($salesChannelId),
            'note' => 'Windcave uses FPRN (server-to-server notification) rather than webhook subscriptions. Configure FPRN in your Windcave portal.'
        ]);
    }

    /**
     * Test webhook endpoint connectivity.
     */
    #[Route(
        path: '/api/windcave/test-webhook',
        name: 'api.windcave.test_webhook',
        methods: ['POST']
    )]
    public function testWebhook(Request $request, Context $context): JsonResponse
    {
        // Get the webhook URL
        $webhookUrl = $this->generateUrl(
            'frontend.windcave.notification',
            [],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        try {
            // Make a test request to our own webhook endpoint
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            $startTime = microtime(true);

            $response = $client->request('GET', $webhookUrl, [
                'query' => ['test' => '1'],
                'http_errors' => false,
            ]);

            $responseTime = round((microtime(true) - $startTime) * 1000);
            $statusCode = $response->getStatusCode();

            // Our webhook endpoint returns 200 for valid requests
            $success = $statusCode >= 200 && $statusCode < 300;

            return new JsonResponse([
                'success' => $success,
                'webhook_url' => $webhookUrl,
                'status_code' => $statusCode,
                'response_time_ms' => $responseTime,
                'message' => $success
                    ? 'Webhook endpoint is reachable and responding'
                    : 'Webhook endpoint returned unexpected status code',
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Webhook connectivity test failed', [
                'error' => $e->getMessage(),
                'webhook_url' => $webhookUrl,
            ]);

            return new JsonResponse([
                'success' => false,
                'webhook_url' => $webhookUrl,
                'message' => 'Failed to reach webhook endpoint: ' . $e->getMessage(),
                'error_type' => get_class($e),
            ], 500);
        }
    }

    /**
     * Get transaction history for an order.
     */
    #[Route(
        path: '/api/windcave/transactions/{orderTransactionId}',
        name: 'api.windcave.transactions',
        methods: ['GET']
    )]
    public function getTransactions(string $orderTransactionId, Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        $transactions = $this->transactionRepository->search($criteria, $context);

        $result = [];
        foreach ($transactions as $transaction) {
            $result[] = [
                'id' => $transaction->getId(),
                'windcaveTransactionId' => $transaction->getWindcaveTransactionId(),
                'sessionId' => $transaction->getSessionId(),
                'transactionType' => $transaction->getTransactionType(),
                'status' => $transaction->getStatus(),
                'responseCode' => $transaction->getResponseCode(),
                'responseText' => $transaction->getResponseText(),
                'amount' => $transaction->getAmount(),
                'currency' => $transaction->getCurrency(),
                'cardScheme' => $transaction->getCardScheme(),
                'cardNumberMasked' => $transaction->getCardNumberMasked(),
                'authCode' => $transaction->getAuthCode(),
                'paymentMode' => $transaction->getPaymentMode(),
                'testMode' => $transaction->getTestMode(),
                'createdAt' => $transaction->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse([
            'total' => $transactions->getTotal(),
            'transactions' => $result,
        ]);
    }

    /**
     * Get Windcave plugin configuration summary for admin dashboard.
     */
    #[Route(
        path: '/api/windcave/config-summary',
        name: 'api.windcave.config_summary',
        methods: ['GET']
    )]
    public function getConfigSummary(Request $request, Context $context): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');

        $username = $this->config->getRestUsername($salesChannelId);

        return new JsonResponse([
            'configured' => !empty($username),
            'test_mode' => $this->config->isTestMode($salesChannelId),
            'payment_mode' => $this->config->getPaymentMode($salesChannelId),
            'apple_pay_enabled' => !empty($this->config->getAppleMerchantId($salesChannelId)),
            'google_pay_enabled' => !empty($this->config->getGoogleMerchantId($salesChannelId)),
            'card_storage_enabled' => $this->config->isStoreCardEnabled($salesChannelId),
            'webhook_url' => $this->generateUrl(
                'frontend.windcave.notification',
                [],
                \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ]);
    }
}
