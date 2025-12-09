<?php

declare(strict_types=1);

namespace Windcave\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Windcave\Service\Struct\WindcaveDropInSession;
use Windcave\Service\WindcaveSessionRequestPayload;
use Windcave\Service\WindcaveResult;

class WindcaveApiService
{
    private const LIVE_SESSION_ENDPOINT = 'https://sec.windcave.com/api/v1/sessions';
    private const TEST_SESSION_ENDPOINT = 'https://uat.windcave.com/api/v1/sessions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Creates an HPP session and returns session metadata (links/hpp URI + session id).
     */
    public function createHostedPayment(WindcaveSessionRequestPayload $payload): WindcaveDropInSession
    {
        $endpoint = $payload->testMode ? self::TEST_SESSION_ENDPOINT : self::LIVE_SESSION_ENDPOINT;

        $response = $this->httpClient->request('POST', $endpoint, [
            'json' => $payload->asArray(),
            'auth_basic' => [$payload->username, $payload->apiKey],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200 && $status !== 202) {
            throw new \RuntimeException('Unexpected status from Windcave sessions: ' . $status);
        }

        $data = $response->toArray(false);

        if (!isset($data['id']) || !isset($data['links'])) {
            $this->logger->error('Windcave createHostedPayment missing id/links', ['response' => $data]);
            throw new \RuntimeException('Windcave response missing session data');
        }

        $hppUrl = null;
        foreach ($data['links'] as $link) {
            if (($link['rel'] ?? '') === 'hpp') {
                $hppUrl = (string) ($link['href'] ?? null);
                break;
            }
        }

        return new WindcaveDropInSession(
            id: (string) $data['id'],
            links: $data['links'],
            hppUrl: $hppUrl
        );
    }

    /**
     * Creates a Drop-In REST session and returns its metadata (links + fallback HPP link).
     */
    public function createDropInSession(WindcaveSessionRequestPayload $payload): WindcaveDropInSession
    {
        $endpoint = $payload->testMode ? self::TEST_SESSION_ENDPOINT : self::LIVE_SESSION_ENDPOINT;
        $requestPayload = $payload->asArray();

        $this->logger->info('Windcave createDropInSession request', [
            'endpoint' => $endpoint,
            'payload' => $requestPayload,
        ]);

        $response = $this->httpClient->request('POST', $endpoint, [
            'json' => $requestPayload,
            'auth_basic' => [$payload->username, $payload->apiKey],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200 && $status !== 202) {
            $responseBody = $response->getContent(false);
            $this->logger->error('Windcave createDropInSession failed', [
                'status' => $status,
                'endpoint' => $endpoint,
                'testMode' => $payload->testMode,
                'response' => $responseBody,
                'payload' => $requestPayload,
            ]);
            throw new \RuntimeException('Unexpected status from Windcave sessions: ' . $status . ' - ' . $responseBody);
        }

        $data = $response->toArray(false);

        if (!isset($data['id']) || !isset($data['links'])) {
            $this->logger->error('Windcave createDropInSession missing id/links', ['response' => $data]);
            throw new \RuntimeException('Windcave response missing session data');
        }

        $hppUrl = null;
        foreach ($data['links'] as $link) {
            if (($link['rel'] ?? '') === 'hpp') {
                $hppUrl = (string) ($link['href'] ?? null);
                break;
            }
        }

        return new WindcaveDropInSession(
            id: (string) $data['id'],
            links: $data['links'],
            hppUrl: $hppUrl
        );
    }

    public function fetchDropInResult(string $sessionId, WindcaveSessionRequestPayload $payload): WindcaveResult
    {
        $endpoint = $payload->testMode ? self::TEST_SESSION_ENDPOINT : self::LIVE_SESSION_ENDPOINT;
        $response = $this->httpClient->request('GET', sprintf('%s/%s', $endpoint, $sessionId), [
            'auth_basic' => [$payload->username, $payload->apiKey],
        ]);
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new \RuntimeException('Failed to load Windcave session result: HTTP ' . $status);
        }

        $data = $response->toArray(false);

        $this->logger->debug('Windcave session result', ['sessionId' => $sessionId, 'data' => $data]);

        $state = \strtolower((string) ($data['state'] ?? ''));
        $cardId = $this->extractCardId($data);
        $transactionId = $this->extractTransactionId($data);
        $amount = $this->extractAmount($data);
        $currency = $this->extractCurrency($data);
        $cardType = $this->extractCardType($data);
        $cardLast4 = $this->extractCardLast4($data);
        $cardExpiry = $this->extractCardExpiry($data);

        return new WindcaveResult(
            success: \in_array($state, ['approved', 'complete', 'completed'], true),
            message: (string) ($data['state'] ?? ''),
            cardId: $cardId,
            transactionId: $transactionId,
            amount: $amount,
            currency: $currency,
            cardType: $cardType,
            cardLast4: $cardLast4,
            cardExpiry: $cardExpiry
        );
    }

    private function extractCardId(array $data): ?string
    {
        if (isset($data['transactions'][0]['card']['id'])) {
            return (string) $data['transactions'][0]['card']['id'];
        }

        if (isset($data['card']['id'])) {
            return (string) $data['card']['id'];
        }

        return null;
    }

    private function extractTransactionId(array $data): ?string
    {
        // The transaction ID is in the transactions array
        if (isset($data['transactions'][0]['id'])) {
            return (string) $data['transactions'][0]['id'];
        }

        // Also check links for transaction reference
        if (isset($data['links'])) {
            foreach ($data['links'] as $link) {
                if (($link['rel'] ?? '') === 'transaction' && isset($link['href'])) {
                    // Extract ID from URL like https://sec.windcave.com/api/v1/transactions/0000000c01159507
                    $parts = explode('/', $link['href']);
                    return end($parts);
                }
            }
        }

        return null;
    }

    private function extractAmount(array $data): ?string
    {
        if (isset($data['transactions'][0]['amount'])) {
            return (string) $data['transactions'][0]['amount'];
        }

        if (isset($data['amount'])) {
            return (string) $data['amount'];
        }

        return null;
    }

    private function extractCurrency(array $data): ?string
    {
        if (isset($data['transactions'][0]['currency'])) {
            return (string) $data['transactions'][0]['currency'];
        }

        if (isset($data['currency'])) {
            return (string) $data['currency'];
        }

        return null;
    }

    private function extractCardType(array $data): ?string
    {
        // Windcave returns card type/scheme in different locations
        if (isset($data['transactions'][0]['card']['type'])) {
            return (string) $data['transactions'][0]['card']['type'];
        }

        if (isset($data['transactions'][0]['card']['cardHolderName'])) {
            // Sometimes it's under cardScheme instead
        }

        if (isset($data['transactions'][0]['cardScheme'])) {
            return (string) $data['transactions'][0]['cardScheme'];
        }

        if (isset($data['card']['type'])) {
            return (string) $data['card']['type'];
        }

        return null;
    }

    private function extractCardLast4(array $data): ?string
    {
        // Windcave returns masked card number like "411111........1111"
        if (isset($data['transactions'][0]['card']['cardNumber'])) {
            $masked = (string) $data['transactions'][0]['card']['cardNumber'];
            // Extract last 4 digits
            $cleaned = preg_replace('/[^0-9]/', '', $masked);
            if ($cleaned && strlen($cleaned) >= 4) {
                return substr($cleaned, -4);
            }
            return $masked;
        }

        if (isset($data['card']['cardNumber'])) {
            $masked = (string) $data['card']['cardNumber'];
            $cleaned = preg_replace('/[^0-9]/', '', $masked);
            if ($cleaned && strlen($cleaned) >= 4) {
                return substr($cleaned, -4);
            }
            return $masked;
        }

        return null;
    }

    private function extractCardExpiry(array $data): ?string
    {
        if (isset($data['transactions'][0]['card']['dateExpiryMonth']) && isset($data['transactions'][0]['card']['dateExpiryYear'])) {
            $month = str_pad((string) $data['transactions'][0]['card']['dateExpiryMonth'], 2, '0', STR_PAD_LEFT);
            $year = substr((string) $data['transactions'][0]['card']['dateExpiryYear'], -2);
            return $month . '/' . $year;
        }

        if (isset($data['card']['dateExpiryMonth']) && isset($data['card']['dateExpiryYear'])) {
            $month = str_pad((string) $data['card']['dateExpiryMonth'], 2, '0', STR_PAD_LEFT);
            $year = substr((string) $data['card']['dateExpiryYear'], -2);
            return $month . '/' . $year;
        }

        return null;
    }

    /**
     * Test API credentials by making a minimal request to Windcave.
     * Uses the sessions endpoint with minimal data to validate credentials.
     */
    public function testCredentials(string $username, string $apiKey, bool $testMode): array
    {
        $endpoint = $testMode ? self::TEST_SESSION_ENDPOINT : self::LIVE_SESSION_ENDPOINT;

        $startTime = microtime(true);

        try {
            // Make a minimal session request with invalid/minimal data
            // Windcave will return 401 for bad credentials or 400 for valid credentials with bad data
            $response = $this->httpClient->request('POST', $endpoint, [
                'json' => [
                    'type' => 'purchase',
                    'amount' => '0.01',
                    'currency' => 'NZD',
                    'merchantReference' => 'credential-test-' . time(),
                    'methods' => ['card'],
                ],
                'auth_basic' => [$username, $apiKey],
            ]);

            $responseTime = round((microtime(true) - $startTime) * 1000);
            $status = $response->getStatusCode();

            // 200/202 means credentials are valid and request succeeded
            if ($status === 200 || $status === 202) {
                return [
                    'success' => true,
                    'message' => 'Credentials are valid',
                    'response_time_ms' => $responseTime,
                    'environment' => $testMode ? 'UAT (Test)' : 'Production',
                ];
            }

            // Parse error response
            $data = $response->toArray(false);

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Unknown error',
                'response_time_ms' => $responseTime,
                'status_code' => $status,
            ];

        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000);
            $response = $e->getResponse();
            $status = $response->getStatusCode();

            // 401 = invalid credentials
            if ($status === 401) {
                return [
                    'success' => false,
                    'message' => 'Invalid credentials. Please check your REST Username and API Key.',
                    'response_time_ms' => $responseTime,
                    'status_code' => $status,
                ];
            }

            // 400 = valid credentials but bad request (which is fine for testing)
            if ($status === 400) {
                return [
                    'success' => true,
                    'message' => 'Credentials are valid (request rejected for other reasons, which is expected for test)',
                    'response_time_ms' => $responseTime,
                ];
            }

            throw $e;
        }
    }
}
