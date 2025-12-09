<?php

declare(strict_types=1);

namespace Windcave\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Windcave\Service\WindcaveApiService;
use Windcave\Service\WindcaveConfig;
use Windcave\Service\WindcaveSessionRequestPayload;

/**
 * Subscribes to checkout confirm page load to inject Windcave Drop-In session data.
 *
 * This enables the inline Drop-In payment form to appear BEFORE the submit button,
 * similar to how PayPal, Stripe, and other payment providers work.
 */
class CheckoutConfirmPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'windcaveDropIn';

    public function __construct(
        private readonly WindcaveApiService $apiService,
        private readonly WindcaveConfig $config,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => ['onCheckoutConfirmPageLoaded', 10],
        ];
    }

    public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        // Check if the selected payment method is Windcave Drop-In
        $paymentMethod = $salesChannelContext->getPaymentMethod();
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

        // Only inject data for Windcave Drop-In payment method
        if (!str_contains($handlerIdentifier, 'WindcaveDropInPaymentHandler')) {
            return;
        }

        // Check payment mode - HPP mode doesn't need inline session data
        $paymentMode = $this->config->getPaymentMode($salesChannelId);
        if ($paymentMode === 'hpp') {
            // For HPP mode, we don't inject inline payment data
            // The payment handler will create session and redirect on form submit
            return;
        }

        // Validate Windcave is configured
        $username = $this->config->getRestUsername($salesChannelId);
        $apiKey = $this->config->getRestApiKey($salesChannelId);

        if (!$username || !$apiKey) {
            $this->logger->warning('Windcave Drop-In: API credentials not configured');
            return;
        }

        try {
            $cart = $event->getPage()->getCart();
            $sessionData = $this->createDropInSession($cart, $salesChannelContext);

            // Add session data to page extensions for use in template
            $event->getPage()->addExtension(self::EXTENSION_NAME, new ArrayStruct($sessionData));

            $this->logger->debug('Windcave Drop-In session created for checkout', [
                'sessionId' => $sessionData['sessionId'] ?? null,
                'hasLinks' => !empty($sessionData['links']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create Windcave Drop-In session', [
                'error' => $e->getMessage(),
            ]);
            // Don't throw - allow checkout to continue, payment will create session on redirect
        }
    }

    /**
     * Create a Windcave session based on the cart (before order creation).
     */
    private function createDropInSession(Cart $cart, SalesChannelContext $context): array
    {
        $salesChannelId = $context->getSalesChannelId();
        $currency = $context->getCurrency()->getIsoCode();
        $total = $cart->getPrice()->getTotalPrice();
        // Get language code from context - use getSalesChannel()->getLanguage() or default to 'en'
        $language = 'en';
        try {
            $salesChannelLanguage = $context->getSalesChannel()->getLanguage();
            if ($salesChannelLanguage && $salesChannelLanguage->getTranslationCode()) {
                $language = $salesChannelLanguage->getTranslationCode()->getCode() ?? 'en';
            }
        } catch (\Throwable) {
            // Fall back to 'en' if language can't be determined
        }
        $customer = $context->getCustomer();
        $countryIso = $customer?->getActiveBillingAddress()?->getCountry()?->getIso() ?? 'NZ';

        // Generate callback URLs - these will be used for redirect-based payment methods within Drop-In
        $returnUrl = $this->router->generate(
            'frontend.checkout.finish.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $notificationUrl = $this->router->generate(
            'frontend.windcave.notification',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $testMode = $this->config->isTestMode($salesChannelId);

        $payload = new WindcaveSessionRequestPayload(
            username: $this->config->getRestUsername($salesChannelId),
            apiKey: $this->config->getRestApiKey($salesChannelId),
            amount: $total,
            currency: $currency,
            merchantReference: 'cart-' . $cart->getToken(),
            language: substr($language, 0, 2),
            approvedUrl: $returnUrl,
            declinedUrl: $returnUrl,
            cancelledUrl: $returnUrl,
            notificationUrl: $notificationUrl,
            testMode: $testMode,
            customerEmail: $customer?->getEmail(),
            customerPhone: $customer?->getActiveBillingAddress()?->getPhoneNumber(),
            billingAddress: $this->mapAddress($customer?->getActiveBillingAddress()),
            shippingAddress: $this->mapAddress($customer?->getActiveShippingAddress()),
        );

        $session = $this->apiService->createDropInSession($payload);

        $scriptBase = $testMode ? 'https://uat.windcave.com' : 'https://sec.windcave.com';

        return [
            'sessionId' => $session->getId(),
            'links' => $session->getLinks(),
            'hppUrl' => $session->getHppUrl(),
            'scriptBase' => $scriptBase,
            'isTest' => $testMode,
            'currency' => $currency,
            'country' => $countryIso,
            'totalValue' => number_format($total, 2, '.', ''),
            'merchantName' => $context->getSalesChannel()->getName(),
            'appleMerchantId' => $this->config->getAppleMerchantId($salesChannelId),
            'googleMerchantId' => $this->config->getGoogleMerchantId($salesChannelId),
            'darkModeConfig' => $this->config->getDarkModeConfig($salesChannelId),
            'hideSelectPaymentMethodTitle' => $this->config->getHideSelectPaymentMethodTitle($salesChannelId),
            'styles' => $this->config->getDropInStyles($salesChannelId),
            'customCss' => $this->config->getCustomCss($salesChannelId),
            'paymentMode' => $this->config->getPaymentMode($salesChannelId),
        ];
    }

    private function mapAddress(?\Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity $address): ?array
    {
        if (!$address) {
            return null;
        }

        $name = trim(($address->getFirstName() ?? '') . ' ' . ($address->getLastName() ?? ''));

        return [
            'name' => $name,
            'address1' => $address->getStreet(),
            'address2' => $address->getAdditionalAddressLine1(),
            'address3' => $address->getAdditionalAddressLine2(),
            'city' => $address->getCity(),
            'countryCode' => $address->getCountry()?->getIso(),
            'postalCode' => $address->getZipcode(),
            'phoneNumber' => $address->getPhoneNumber(),
            'state' => $address->getCountryState()?->getShortCode(),
        ];
    }
}
