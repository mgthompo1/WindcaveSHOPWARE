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

    // ========================================
    // Drop-in Appearance Settings
    // ========================================

    public function getDarkModeConfig(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get('WindcavePayment.config.darkModeConfig', $salesChannelId) ?? 'light');
    }

    public function getHideSelectPaymentMethodTitle(?string $salesChannelId): bool
    {
        return (bool) ($this->systemConfig->get('WindcavePayment.config.hideSelectPaymentMethodTitle', $salesChannelId) ?? false);
    }

    public function getCustomCss(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get('WindcavePayment.config.customCss', $salesChannelId) ?? '');
    }

    /**
     * Build the Windcave Drop-In styles object from config settings.
     * Returns an array that can be passed to the Drop-In options.styles.
     */
    public function getDropInStyles(?string $salesChannelId): array
    {
        $styles = [];

        // Container styles
        $containerStyles = $this->buildStyleObject([
            'background-color' => $this->getConfigValue('containerBgColor', $salesChannelId),
            'border-color' => $this->getConfigValue('containerBorderColor', $salesChannelId),
            'border-radius' => $this->getConfigValue('containerBorderRadius', $salesChannelId),
            'padding' => $this->getConfigValue('containerPadding', $salesChannelId),
            'font-family' => $this->getConfigValue('fontFamily', $salesChannelId),
            'color' => $this->getConfigValue('primaryTextColor', $salesChannelId),
        ]);
        if (!empty($containerStyles)) {
            $styles['container'] = $containerStyles;
        }

        // Submit button styles
        $submitButtonStyles = $this->buildStyleObject([
            'background-color' => $this->getConfigValue('submitButtonBgColor', $salesChannelId),
            'color' => $this->getConfigValue('submitButtonTextColor', $salesChannelId),
            'border-radius' => $this->getConfigValue('submitButtonBorderRadius', $salesChannelId),
        ]);
        if (!empty($submitButtonStyles)) {
            $styles['cardSubmitButton'] = $submitButtonStyles;
        }

        // Submit button hover styles
        $submitButtonHoverBg = $this->getConfigValue('submitButtonHoverBgColor', $salesChannelId);
        if ($submitButtonHoverBg) {
            $styles['cardSubmitButtonHover'] = ['background-color' => $submitButtonHoverBg];
        }

        // Back button styles (match submit button for consistency)
        if (!empty($submitButtonStyles)) {
            $backButtonStyles = $submitButtonStyles;
            // Back button might want different colors - for now inherit border-radius
            $backButtonStyles = $this->buildStyleObject([
                'border-radius' => $this->getConfigValue('submitButtonBorderRadius', $salesChannelId),
            ]);
            if (!empty($backButtonStyles)) {
                $styles['cardBackButton'] = $backButtonStyles;
            }
        }

        // Redirect button styles
        if (!empty($submitButtonStyles)) {
            $styles['redirectButton'] = $submitButtonStyles;
        }
        if ($submitButtonHoverBg) {
            $styles['redirectButtonHover'] = ['background-color' => $submitButtonHoverBg];
        }

        // Card input styles
        $inputStyles = $this->buildStyleObject([
            'background-color' => $this->getConfigValue('inputBgColor', $salesChannelId),
            'border-color' => $this->getConfigValue('inputBorderColor', $salesChannelId),
            'border-radius' => $this->getConfigValue('inputBorderRadius', $salesChannelId),
        ]);
        if (!empty($inputStyles)) {
            $styles['cardInput'] = $inputStyles;
            $styles['cardInputContainer'] = $inputStyles;
        }

        // Input focus state
        $inputFocusBorder = $this->getConfigValue('inputFocusBorderColor', $salesChannelId);
        if ($inputFocusBorder) {
            $styles['cardInputContainerFocused'] = ['border-color' => $inputFocusBorder];
        }

        // Input valid state
        $inputValidBorder = $this->getConfigValue('inputValidBorderColor', $salesChannelId);
        if ($inputValidBorder) {
            $styles['cardInputValid'] = ['border-color' => $inputValidBorder];
        }

        // Input invalid state
        $inputInvalidBorder = $this->getConfigValue('inputInvalidBorderColor', $salesChannelId);
        if ($inputInvalidBorder) {
            $styles['cardInputInvalid'] = ['border-color' => $inputInvalidBorder];
        }

        // Select payment text
        $primaryTextColor = $this->getConfigValue('primaryTextColor', $salesChannelId);
        if ($primaryTextColor) {
            $styles['selectPaymentText'] = ['color' => $primaryTextColor];
        }

        // Alternative payment methods text
        $secondaryTextColor = $this->getConfigValue('secondaryTextColor', $salesChannelId);
        if ($secondaryTextColor) {
            $styles['alternativePaymentMethodsText'] = ['color' => $secondaryTextColor];
            $styles['alternativePaymentMethodsTextSpan'] = ['color' => $secondaryTextColor];
        }

        // Item group (payment method container)
        $itemGroupStyles = $this->buildStyleObject([
            'background-color' => $this->getConfigValue('containerBgColor', $salesChannelId),
            'border-color' => $this->getConfigValue('containerBorderColor', $salesChannelId),
        ]);
        if (!empty($itemGroupStyles)) {
            $styles['itemGroup'] = $itemGroupStyles;
        }

        return $styles;
    }

    /**
     * Get a single config value.
     */
    private function getConfigValue(string $key, ?string $salesChannelId): ?string
    {
        $value = $this->systemConfig->get('WindcavePayment.config.' . $key, $salesChannelId);
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /**
     * Build a style object from key-value pairs, filtering out null values.
     */
    private function buildStyleObject(array $properties): array
    {
        return array_filter($properties, fn($value) => $value !== null && $value !== '');
    }
}
