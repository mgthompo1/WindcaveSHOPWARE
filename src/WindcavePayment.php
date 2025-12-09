<?php

declare(strict_types=1);

namespace Windcave;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Windcave\Payment\Handler\WindcaveDropInPaymentHandler;
use Windcave\Payment\Handler\WindcavePaymentHandler;

class WindcavePayment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->upsertPaymentMethods($installContext->getContext(), true);
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->upsertPaymentMethods($activateContext->getContext(), true);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
        $this->setPaymentMethodsActive($deactivateContext->getContext(), false);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->setPaymentMethodsActive($uninstallContext->getContext(), false);
    }

    private function upsertPaymentMethods(Context $context, bool $active): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        $pluginIdProvider = $this->container->get(\Shopware\Core\Framework\Plugin\Util\PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(self::class, $context);

        // HPP Payment Method
        $existing = $paymentMethodRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', WindcavePaymentHandler::class)),
            $context
        )->first();
        $paymentMethodId = $existing?->getId() ?? Uuid::randomHex();

        // Drop-in Payment Method
        $dropInExisting = $paymentMethodRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', WindcaveDropInPaymentHandler::class)),
            $context
        )->first();
        $dropInId = $dropInExisting?->getId() ?? Uuid::randomHex();

        $paymentMethodRepository->upsert([
            [
                'id' => $paymentMethodId,
                'technicalName' => 'payment_windcave_hpp',
                'handlerIdentifier' => WindcavePaymentHandler::class,
                'name' => 'Windcave Hosted Payment',
                'description' => 'Redirect customers to Windcave Hosted Payment Page (HPP)',
                'pluginId' => $pluginId,
                'active' => $active,
                'afterOrderEnabled' => false,
                'translations' => [
                    'en-GB' => [
                        'name' => 'Windcave Hosted Payment',
                        'description' => 'Redirect customers to Windcave Hosted Payment Page (HPP)',
                    ],
                    'de-DE' => [
                        'name' => 'Windcave Bezahlseite',
                        'description' => 'Kunden werden zur Windcave Hosted Payment Page (HPP) geleitet',
                    ],
                ],
            ],
            [
                'id' => $dropInId,
                'technicalName' => 'payment_windcave_dropin',
                'handlerIdentifier' => WindcaveDropInPaymentHandler::class,
                'name' => 'Windcave Drop-in',
                'description' => 'Inline Windcave payment using drop-in container',
                'pluginId' => $pluginId,
                'active' => $active,
                'afterOrderEnabled' => false,
                'translations' => [
                    'en-GB' => [
                        'name' => 'Windcave Drop-in',
                        'description' => 'Inline Windcave payment using drop-in container',
                    ],
                    'de-DE' => [
                        'name' => 'Windcave Drop-in',
                        'description' => 'Eingebettete Windcave-Zahlung über Drop-in-Container',
                    ],
                ],
            ],
        ], $context);
    }

    private function setPaymentMethodsActive(Context $context, bool $active): void
    {
        /** @var EntityRepository $paymentMethodRepository */
        $paymentMethodRepository = $this->container->get('payment_method.repository');

        $handlers = [
            WindcavePaymentHandler::class,
            WindcaveDropInPaymentHandler::class,
        ];

        foreach ($handlers as $handlerIdentifier) {
            $existing = $paymentMethodRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier)),
                $context
            )->first();

            if ($existing) {
                $paymentMethodRepository->update([
                    [
                        'id' => $existing->getId(),
                        'active' => $active,
                    ],
                ], $context);
            }
        }
    }
}
