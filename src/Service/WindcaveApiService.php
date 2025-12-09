<?php

declare(strict_types=1);

namespace Windcave\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Windcave\Service\Struct\WindcaveDropInSession;
use Windcave\Service\WindcaveSessionRequestPayload;

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

        return new WindcaveResult(
            success: \in_array($state, ['approved', 'complete', 'completed'], true),
            message: (string) ($data['state'] ?? '')
        );
    }
}
