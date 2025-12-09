<?php

declare(strict_types=1);

namespace Windcave;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Windcave\Installer\PaymentMethodInstaller;

class WindcavePayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->getPaymentMethodInstaller()->install($installContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->getPaymentMethodInstaller()->activate($activateContext->getContext());
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
        $this->getPaymentMethodInstaller()->deactivate($deactivateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Optionally clean up payment methods completely
        // For now, we just deactivate them to preserve order history
        $this->getPaymentMethodInstaller()->deactivate($uninstallContext->getContext());
    }

    private function getPaymentMethodInstaller(): PaymentMethodInstaller
    {
        /** @var PaymentMethodInstaller $installer */
        $installer = $this->container->get(PaymentMethodInstaller::class);

        return $installer;
    }
}
