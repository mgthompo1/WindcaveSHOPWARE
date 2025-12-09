<?php

declare(strict_types=1);

namespace Windcave\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Windcave\Service\WindcaveSessionService;

/**
 * Storefront controller for Windcave Drop-in payment page.
 *
 * This controller renders the server-side Twig template for the Drop-in.
 * For headless/PWA integration, use the Store API endpoints instead.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WindcaveDropInController extends StorefrontController
{
    public function __construct(
        private readonly WindcaveSessionService $sessionService
    ) {
    }

    #[Route(path: '/windcave/dropin/{orderTransactionId}', name: 'frontend.windcave.dropin.start', methods: ['GET'])]
    public function start(string $orderTransactionId, SalesChannelContext $context): Response
    {
        try {
            $sessionData = $this->sessionService->getDropInSessionData(
                $orderTransactionId,
                $context->getSalesChannelId(),
                $context->getContext()
            );
        } catch (\Throwable $e) {
            throw $this->createNotFoundException('Order transaction not found or no session available');
        }

        return $this->renderStorefront('@Windcave/storefront/page/windcave/dropin.html.twig', [
            'windcaveLinks' => $sessionData['links'],
            'windcaveHppUrl' => $sessionData['hppUrl'],
            'windcaveReturnUrl' => $sessionData['returnUrl'],
            'windcaveSessionId' => $sessionData['sessionId'],
            'windcaveScriptBase' => $sessionData['scriptBase'],
            'windcaveAppleMerchantId' => $sessionData['appleMerchantId'],
            'windcaveGoogleMerchantId' => $sessionData['googleMerchantId'],
            'windcaveCurrency' => $sessionData['currency'],
            'windcaveMerchantName' => $context->getSalesChannel()->getName(),
            'windcaveCountry' => $sessionData['country'],
            'windcaveIsTest' => $sessionData['isTest'],
            // Styling options
            'windcaveDarkModeConfig' => $sessionData['darkModeConfig'],
            'windcaveHideSelectPaymentMethodTitle' => $sessionData['hideSelectPaymentMethodTitle'],
            'windcaveStyles' => $sessionData['styles'],
            'windcaveCustomCss' => $sessionData['customCss'],
        ]);
    }
}
