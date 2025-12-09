<?php

declare(strict_types=1);

namespace Windcave\Service;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Windcave\Service\WindcaveSessionRequestPayload;
use Windcave\Service\WindcaveTokenService;

class WindcavePayloadFactory
{
    public function __construct(
        private readonly WindcaveConfig $config,
        private readonly UrlGeneratorInterface $router,
        private readonly WindcaveTokenService $tokenService
    ) {
    }

    public function fromTransaction(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $context,
        string $returnUrl
    ): WindcaveSessionRequestPayload {
        $order = $transaction->getOrder();
        $currency = $order->getCurrency()?->getIsoCode() ?? $context->getCurrency()->getIsoCode();
        $total = $transaction->getOrderTransaction()->getAmount()->getTotalPrice();
        $salesChannelId = $context->getSalesChannelId();
        $language = $order->getLanguage()?->getTranslationCode()?->getCode() ?? 'en';
        $orderCustomer = $order->getOrderCustomer();
        $billing = $order->getBillingAddress();
        $shipping = $order->getDeliveries()?->first()?->getShippingOrderAddress();
        $customerEmail = $orderCustomer?->getEmail();

        $approvedUrl = $this->router->generate(
            'frontend.windcave.success',
            [
                'orderId' => $order->getId(),
                'returnUrl' => $returnUrl,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $declinedUrl = $this->router->generate(
            'frontend.windcave.fail',
            [
                'orderId' => $order->getId(),
                'returnUrl' => $returnUrl,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $cancelledUrl = $this->router->generate(
            'frontend.windcave.fail',
            [
                'orderId' => $order->getId(),
                'returnUrl' => $returnUrl,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $restUser = $this->config->getRestUsername($salesChannelId);
        $restKey = $this->config->getRestApiKey($salesChannelId);
        $testMode = $this->config->isTestMode($salesChannelId);
        $storeCard = $this->config->isStoreCardEnabled($salesChannelId);
        $customerId = $context->getCustomer()?->getId();
        $savedToken = $this->getStoredToken($customerId, $context->getContext());
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
            notificationUrl: $returnUrl,
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

    private function getStoredToken(?string $customerId, \Shopware\Core\Framework\Context $context): ?string
    {
        if (!$customerId) {
            return null;
        }

        return $this->tokenService->getStoredToken($customerId, $context);
    }
}
