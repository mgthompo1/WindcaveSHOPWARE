<?php

declare(strict_types=1);

namespace Windcave\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\HttpException;
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
            throw new HttpException($status, 'Unexpected status from Windcave sessions: ' . $status);
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

        $response = $this->httpClient->request('POST', $endpoint, [
            'json' => $payload->asArray(),
            'auth_basic' => [$payload->username, $payload->apiKey],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200 && $status !== 202) {
            throw new HttpException($status, 'Unexpected status from Windcave sessions: ' . $status);
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
            throw new HttpException($status, 'Failed to load Windcave session result');
        }

        $data = $response->toArray(false);
        $state = \strtolower((string) ($data['state'] ?? ''));
        $cardId = $this->extractCardId($data);
        $transactionId = $this->extractTransactionId($data);
        $amount = $this->extractAmount($data);
        $currency = $this->extractCurrency($data);

        return new WindcaveResult(
            success: \in_array($state, ['approved', 'complete', 'completed'], true),
            message: (string) ($data['state'] ?? ''),
            cardId: $cardId,
            transactionId: $transactionId,
            amount: $amount,
            currency: $currency
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
}
