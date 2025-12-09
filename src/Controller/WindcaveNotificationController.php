<?php

declare(strict_types=1);

namespace Windcave\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Windcave\Service\WindcaveSessionService;

/**
 * Controller for handling Windcave FPRN (Fail Proof Result Notification) webhooks.
 *
 * Windcave sends notifications to this endpoint when payments complete,
 * ensuring we receive the result even if the customer's browser closes
 * before returning to the callback URL.
 *
 * For headless/PWA integration, the Store API notification endpoint
 * at /store-api/windcave/notification can also be used.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WindcaveNotificationController extends AbstractController
{
    public function __construct(
        private readonly WindcaveSessionService $sessionService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle FPRN notification from Windcave.
     *
     * Windcave sends GET request with sessionId query parameter.
     * We query the session to get the result and update the order transaction.
     */
    #[Route(
        path: '/windcave/notification',
        name: 'frontend.windcave.notification',
        methods: ['GET', 'POST'],
        defaults: ['_noStore' => true]
    )]
    public function notification(Request $request): Response
    {
        $sessionId = $request->query->get('sessionId') ?? $request->query->get('transactionId');

        if (!$sessionId) {
            $this->logger->warning('Windcave notification received without sessionId');
            return new Response('Missing sessionId', Response::HTTP_BAD_REQUEST);
        }

        $context = Context::createDefaultContext();

        try {
            $result = $this->sessionService->handleNotification($sessionId, $context);

            if (!$result['success'] && !$result['alreadyProcessed']) {
                // Return 500 to trigger retry only for actual failures
                return new Response('Processing failed', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new Response('OK', Response::HTTP_OK);
        } catch (\Throwable $e) {
            $this->logger->error('Windcave notification: Error processing', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            // Return 500 so Windcave retries
            return new Response('Processing error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
