<?php

declare(strict_types=1);

namespace Windcave\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class WindcaveConfig
{
    private const CONFIG_PREFIX = 'WindcaveSHOPWARE.config.';

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function getRestUsername(?string $salesChannelId): string
    {
        return (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'restUsername', $salesChannelId);
    }

    public function getRestApiKey(?string $salesChannelId): string
    {
        return (string) $this->systemConfig->get(self::CONFIG_PREFIX . 'restApiKey', $salesChannelId);
    }

    public function getAppleMerchantId(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'appleMerchantId', $salesChannelId) ?? '');
    }

    public function getGoogleMerchantId(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'googleMerchantId', $salesChannelId) ?? '');
    }

    public function isStoreCardEnabled(?string $salesChannelId): bool
    {
        return (bool) ($this->systemConfig->get(self::CONFIG_PREFIX . 'storeCard', $salesChannelId) ?? false);
    }

    public function getStoredCardIndicatorInitial(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'storedCardIndicatorInitial', $salesChannelId) ?? 'credentialonfileinitial');
    }

    public function getStoredCardIndicatorRecurring(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'storedCardIndicatorRecurring', $salesChannelId) ?? 'credentialonfile');
    }

    public function isTestMode(?string $salesChannelId): bool
    {
        $value = $this->systemConfig->get(self::CONFIG_PREFIX . 'testMode', $salesChannelId);
        // Handle string "false" which casts to true with (bool)
        if ($value === 'false' || $value === '0' || $value === '') {
            return false;
        }
        return (bool) $value;
    }

    /**
     * Get the payment mode: 'dropin', 'hostedfields', or 'hpp'
     */
    public function getPaymentMode(?string $salesChannelId): string
    {
        $mode = $this->systemConfig->get(self::CONFIG_PREFIX . 'paymentMode', $salesChannelId);
        if (!in_array($mode, ['dropin', 'hostedfields', 'hpp'], true)) {
            return 'dropin';
        }
        return $mode;
    }

    // ========================================
    // Drop-in Appearance Settings
    // ========================================

    public function getDarkModeConfig(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'darkModeConfig', $salesChannelId) ?? 'light');
    }

    public function getHideSelectPaymentMethodTitle(?string $salesChannelId): bool
    {
        return (bool) ($this->systemConfig->get(self::CONFIG_PREFIX . 'hideSelectPaymentMethodTitle', $salesChannelId) ?? false);
    }

    public function getCustomCss(?string $salesChannelId): string
    {
        return (string) ($this->systemConfig->get(self::CONFIG_PREFIX . 'customCss', $salesChannelId) ?? '');
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
        $value = $this->systemConfig->get(self::CONFIG_PREFIX . $key, $salesChannelId);
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
