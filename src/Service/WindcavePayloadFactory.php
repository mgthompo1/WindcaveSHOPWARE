<?php

declare(strict_types=1);

namespace Windcave\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class WindcavePayloadFactory
{
    public function __construct(
        private readonly WindcaveConfig $config,
        private readonly UrlGeneratorInterface $router,
        private readonly WindcaveTokenService $tokenService
    ) {
    }

    /**
     * Create payload from Order and OrderTransaction entities (Shopware 6.6+)
     */
    public function fromOrderAndTransaction(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        string $returnUrl
    ): WindcaveSessionRequestPayload {
        $currency = $order->getCurrency()?->getIsoCode() ?? 'NZD';
        $total = $orderTransaction->getAmount()->getTotalPrice();
        $salesChannelId = $order->getSalesChannelId();
        $language = $order->getLanguage()?->getTranslationCode()?->getCode() ?? 'en';
        $orderCustomer = $order->getOrderCustomer();
        $billing = $order->getBillingAddress();
        $shipping = $order->getDeliveries()?->first()?->getShippingOrderAddress();
        $customerEmail = $orderCustomer?->getEmail();

        // Note: We don't include returnUrl in callback URLs to keep them short
        // The returnUrl is stored in order transaction custom fields and retrieved during callback handling
        $approvedUrl = $this->router->generate(
            'frontend.windcave.success',
            ['orderId' => $order->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $declinedUrl = $this->router->generate(
            'frontend.windcave.fail',
            ['orderId' => $order->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $cancelledUrl = $this->router->generate(
            'frontend.windcave.fail',
            ['orderId' => $order->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // FPRN notification URL - Windcave will send payment result here
        $notificationUrl = $this->router->generate(
            'frontend.windcave.notification',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $restUser = $this->config->getRestUsername($salesChannelId);
        $restKey = $this->config->getRestApiKey($salesChannelId);
        $testMode = $this->config->isTestMode($salesChannelId);
        $storeCard = $this->config->isStoreCardEnabled($salesChannelId);
        $customerId = $orderCustomer?->getCustomerId();
        $savedToken = $this->getStoredToken($customerId, $context);
        $storedCardIndicator = $savedToken
            ? $this->config->getStoredCardIndicatorRecurring($salesChannelId)
            : ($storeCard ? $this->config->getStoredCardIndicatorInitial($salesChannelId) : null);

        return new WindcaveSessionRequestPayload(
            username: $restUser,
            apiKey: $restKey,
            amount: $total,
            currency: $currency,
            merchantReference: $order->getOrderNumber() ?? $order->getId(),
            language: $language,
            approvedUrl: $approvedUrl,
            declinedUrl: $declinedUrl,
            cancelledUrl: $cancelledUrl,
            notificationUrl: $notificationUrl,
            testMode: $testMode,
            customerEmail: $customerEmail ?: null,
            customerPhone: $billing?->getPhoneNumber(),
            customerHomePhone: null,
            billingAddress: $this->mapAddress($billing),
            shippingAddress: $this->mapAddress($shipping),
            threeDS: $this->buildThreeDS($customerEmail, $billing),
            storeCard: $storeCard && !$savedToken,
            storedCardIndicator: $storedCardIndicator,
            cardId: $savedToken
        );
    }

    /**
     * Create a minimal payload for drop-in finalization (session query).
     * This is used when we need to query the session result without all the address data.
     */
    public function dropInPayloadFromOrderAndTransaction(
        OrderEntity $order,
        OrderTransactionEntity $orderTransaction,
        Context $context,
        string $returnUrl
    ): WindcaveSessionRequestPayload {
        $currency = $order->getCurrency()?->getIsoCode() ?? 'NZD';
        $total = $orderTransaction->getAmount()->getTotalPrice();
        $salesChannelId = $order->getSalesChannelId();

        $restUser = $this->config->getRestUsername($salesChannelId);
        $restKey = $this->config->getRestApiKey($salesChannelId);
        $testMode = $this->config->isTestMode($salesChannelId);

        $notificationUrl = $this->router->generate(
            'frontend.windcave.notification',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new WindcaveSessionRequestPayload(
            username: $restUser,
            apiKey: $restKey,
            amount: (string) $total,
            currency: $currency,
            merchantReference: $order->getOrderNumber() ?? $order->getId(),
            language: 'en',
            approvedUrl: $returnUrl,
            declinedUrl: $returnUrl,
            cancelledUrl: $returnUrl,
            notificationUrl: $notificationUrl,
            testMode: $testMode
        );
    }

    public function getConfig(): WindcaveConfig
    {
        return $this->config;
    }

    private function mapAddress(?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity $address): ?array
    {
        if (!$address) {
            return null;
        }

        $name = \trim(($address->getFirstName() ?? '') . ' ' . ($address->getLastName() ?? ''));

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

    private function buildThreeDS(?string $email, ?\Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity $billing): ?array
    {
        if (!$email && !$billing) {
            return null;
        }

        return [
            'challengeIndicator' => 'challengepreferred',
            'merchantRiskIndicator' => [
                'deliveryEmailAddress' => $email,
                'shippingIndicator' => 'digital',
            ],
        ];
    }

    private function getStoredToken(?string $customerId, Context $context): ?string
    {
        if (!$customerId) {
            return null;
        }

        return $this->tokenService->getStoredToken($customerId, $context);
    }
}
