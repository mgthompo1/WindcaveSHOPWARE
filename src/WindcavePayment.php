<?php

declare(strict_types=1);

namespace Windcave;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
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

    private function getPaymentMethodInstaller(): PaymentMethodInstaller
    {
        /** @var PaymentMethodInstaller $installer */
        $installer = $this->container->get(PaymentMethodInstaller::class);

        return $installer;
    }
}
