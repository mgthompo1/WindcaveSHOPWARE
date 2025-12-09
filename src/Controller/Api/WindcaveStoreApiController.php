<?php

declare(strict_types=1);

namespace Windcave\Controller\Api;

use OpenApi\Annotations as OA;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Windcave\Service\WindcaveConfig;
use Windcave\Service\WindcaveSessionService;

/**
 * Store API controller for Windcave payments.
 *
 * Provides headless/PWA support for Windcave payment integration.
 * All endpoints are accessible via /store-api/windcave/* routes.
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
class WindcaveStoreApiController
{
    public function __construct(
        private readonly WindcaveSessionService $sessionService,
        private readonly WindcaveConfig $config
    ) {
    }

    /**
     * Get drop-in session data for a transaction.
     *
     * Returns all data needed to render the Windcave Drop-in payment form
     * on a headless frontend (PWA, mobile app, etc.).
     *
     * @OA\Post(
     *     path="/store-api/windcave/dropin-session/{orderTransactionId}",
     *     summary="Get Windcave Drop-in session data",
     *     operationId="getWindcaveDropinSession",
     *     tags={"Store API", "Windcave"},
     *     @OA\Parameter(
     *         name="orderTransactionId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Drop-in session data",
     *         @OA\JsonContent(
     *             @OA\Property(property="sessionId", type="string"),
     *             @OA\Property(property="links", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="hppUrl", type="string", nullable=true),
     *             @OA\Property(property="returnUrl", type="string"),
     *             @OA\Property(property="scriptBase", type="string"),
     *             @OA\Property(property="isTest", type="boolean"),
     *             @OA\Property(property="currency", type="string"),
     *             @OA\Property(property="country", type="string"),
     *             @OA\Property(property="appleMerchantId", type="string"),
     *             @OA\Property(property="googleMerchantId", type="string"),
     *             @OA\Property(property="darkModeConfig", type="string"),
     *             @OA\Property(property="hideSelectPaymentMethodTitle", type="boolean"),
     *             @OA\Property(property="styles", type="object")
     *         )
     *     )
     * )
     */
    #[Route(
        path: '/store-api/windcave/dropin-session/{orderTransactionId}',
        name: 'store-api.windcave.dropin-session',
        methods: ['GET', 'POST']
    )]
    public function getDropinSession(
        string $orderTransactionId,
        SalesChannelContext $context
    ): JsonResponse {
        try {
            $sessionData = $this->sessionService->getDropInSessionData(
                $orderTransactionId,
                $context->getSalesChannelId(),
                $context->getContext()
            );

            // Add merchant name from sales channel
            $sessionData['merchantName'] = $context->getSalesChannel()->getName();

            return new JsonResponse($sessionData);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Verify a Windcave session result.
     *
     * Called after payment completion to verify the session state.
     * Headless frontends should call this endpoint to confirm payment success
     * before completing the checkout flow.
     *
     * @OA\Post(
     *     path="/store-api/windcave/verify-session",
     *     summary="Verify Windcave payment session",
     *     operationId="verifyWindcaveSession",
     *     tags={"Store API", "Windcave"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="sessionId", type="string", description="Windcave session ID"),
     *             @OA\Property(property="orderTransactionId", type="string", description="Shopware order transaction ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Session verification result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="transactionId", type="string", nullable=true)
     *         )
     *     )
     * )
     */
    #[Route(
        path: '/store-api/windcave/verify-session',
        name: 'store-api.windcave.verify-session',
        methods: ['POST']
    )]
    public function verifySession(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse {
        $sessionId = $request->request->get('sessionId') ?? $request->query->get('sessionId');
        $orderTransactionId = $request->request->get('orderTransactionId');

        if (!$sessionId) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing sessionId parameter',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->sessionService->verifySession(
                $sessionId,
                $context->getSalesChannelId()
            );

            // If we have a transaction ID, process and store the result
            if ($orderTransactionId) {
                $this->sessionService->processSessionResult(
                    $orderTransactionId,
                    $sessionId,
                    $context->getSalesChannelId(),
                    $context->getCustomer()?->getId(),
                    $context->getContext()
                );
            }

            return new JsonResponse([
                'success' => $result->isSuccessful(),
                'message' => $result->getMessage(),
                'transactionId' => $result->getTransactionId(),
                'cardId' => $result->getCardId(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle FPRN notification (also accessible via Store API for testing).
     *
     * Primary notification endpoint is at /windcave/notification (storefront scope).
     * This endpoint allows Store API access for testing and special integrations.
     *
     * @OA\Post(
     *     path="/store-api/windcave/notification",
     *     summary="Handle Windcave FPRN notification",
     *     operationId="handleWindcaveNotification",
     *     tags={"Store API", "Windcave"},
     *     @OA\Parameter(
     *         name="sessionId",
     *         in="query",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Notification processed"
     *     )
     * )
     */
    #[Route(
        path: '/store-api/windcave/notification',
        name: 'store-api.windcave.notification',
        methods: ['GET', 'POST']
    )]
    public function notification(
        Request $request,
        SalesChannelContext $context
    ): JsonResponse {
        $sessionId = $request->query->get('sessionId') ?? $request->query->get('transactionId');

        if (!$sessionId) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing sessionId',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->sessionService->handleNotification($sessionId, $context->getContext());

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get Windcave configuration for the storefront.
     *
     * Returns public configuration needed by headless frontends
     * (merchant IDs, styling, test mode status).
     * Sensitive data (API keys) is NOT exposed.
     *
     * @OA\Get(
     *     path="/store-api/windcave/config",
     *     summary="Get Windcave public configuration",
     *     operationId="getWindcaveConfig",
     *     tags={"Store API", "Windcave"},
     *     @OA\Response(
     *         response="200",
     *         description="Public configuration",
     *         @OA\JsonContent(
     *             @OA\Property(property="isTestMode", type="boolean"),
     *             @OA\Property(property="appleMerchantId", type="string"),
     *             @OA\Property(property="googleMerchantId", type="string"),
     *             @OA\Property(property="scriptBase", type="string"),
     *             @OA\Property(property="darkModeConfig", type="string"),
     *             @OA\Property(property="styles", type="object")
     *         )
     *     )
     * )
     */
    #[Route(
        path: '/store-api/windcave/config',
        name: 'store-api.windcave.config',
        methods: ['GET']
    )]
    public function getConfig(SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannelId();
        $isTestMode = $this->config->isTestMode($salesChannelId);

        return new JsonResponse([
            'isTestMode' => $isTestMode,
            'appleMerchantId' => $this->config->getAppleMerchantId($salesChannelId),
            'googleMerchantId' => $this->config->getGoogleMerchantId($salesChannelId),
            'scriptBase' => $isTestMode ? 'https://uat.windcave.com' : 'https://sec.windcave.com',
            'darkModeConfig' => $this->config->getDarkModeConfig($salesChannelId),
            'hideSelectPaymentMethodTitle' => $this->config->getHideSelectPaymentMethodTitle($salesChannelId),
            'styles' => $this->config->getDropInStyles($salesChannelId),
            'customCss' => $this->config->getCustomCss($salesChannelId),
            'storeCardEnabled' => $this->config->isStoreCardEnabled($salesChannelId),
        ]);
    }
}
