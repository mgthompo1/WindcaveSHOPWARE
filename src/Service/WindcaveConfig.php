<?php

declare(strict_types=1);

namespace Windcave\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class WindcaveConfig
{
    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function getRestUsername(?string $salesChannelId): string
    {
        return (string) $this->systemConfig->get('WindcavePayment.config.restUsername', $salesChannelId);
    }

    public function getRestApiKey(?string $salesChannelId): string
    {
        return (string) $this->systemConfig->get('WindcavePayment.config.restApiKey', $salesChannelId);
    }

    public function getAppleMerchantId(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get('WindcavePayment.config.appleMerchantId', $salesChannelId) ?? '');
    }

    public function getGoogleMerchantId(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get('WindcavePayment.config.googleMerchantId', $salesChannelId) ?? '');
    }

    public function isStoreCardEnabled(?string $salesChannelId): bool
    {
        return (bool) ($this->systemConfig->get('WindcavePayment.config.storeCard', $salesChannelId) ?? false);
    }

    public function getStoredCardIndicatorInitial(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get('WindcavePayment.config.storedCardIndicatorInitial', $salesChannelId) ?? 'credentialonfileinitial');
    }

    public function getStoredCardIndicatorRecurring(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get('WindcavePayment.config.storedCardIndicatorRecurring', $salesChannelId) ?? 'credentialonfile');
    }

    public function isTestMode(?string $salesChannelId): bool
    {
        return (bool) $this->systemConfig->get('WindcavePayment.config.testMode', $salesChannelId);
    }
}
