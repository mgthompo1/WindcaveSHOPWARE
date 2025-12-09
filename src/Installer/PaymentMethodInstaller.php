<?php

declare(strict_types=1);

namespace Windcave\Installer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Windcave\Payment\Handler\WindcavePaymentHandler;
use Windcave\Payment\Handler\WindcaveDropInPaymentHandler;

class PaymentMethodInstaller
{
    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
        private readonly PluginIdProvider $pluginIdProvider
    ) {
    }

    public function install(Context $context): void
    {
        $this->upsertPaymentMethod($context, true);
    }

    public function activate(Context $context): void
    {
        $this->upsertPaymentMethod($context, true);
    }

    private function upsertPaymentMethod(Context $context, bool $active): void
    {
        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(
            \Windcave\WindcavePayment::class,
            $context
        );

        $existing = $this->paymentMethodRepository->search(
            (new Criteria())->addFilter(
                new EqualsFilter('handlerIdentifier', WindcavePaymentHandler::class)
            ),
            $context
        )->first();
        $paymentMethodId = $existing?->getId() ?? Uuid::randomHex();

        $dropInExisting = $this->paymentMethodRepository->search(
            (new Criteria())->addFilter(
                new EqualsFilter('handlerIdentifier', WindcaveDropInPaymentHandler::class)
            ),
            $context
        )->first();
        $dropInId = $dropInExisting?->getId() ?? Uuid::randomHex();

        $this->paymentMethodRepository->upsert(
            [
                [
                    'id' => $paymentMethodId,
                    'handlerIdentifier' => WindcavePaymentHandler::class,
                    'name' => 'Windcave Hosted Payment',
                    'description' => 'Redirect customers to Windcave Hosted Payment Page (HPP)',
                    'pluginId' => $pluginId,
                    'active' => $active,
                    'afterOrderEnabled' => false,
                    'technicalName' => 'windcave_hpp',
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
                    'handlerIdentifier' => WindcaveDropInPaymentHandler::class,
                    'name' => 'Windcave Drop-in',
                    'description' => 'Inline Windcave payment using drop-in container',
                    'pluginId' => $pluginId,
                    'active' => $active,
                    'afterOrderEnabled' => false,
                    'technicalName' => 'windcave_dropin',
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
            ],
            $context
        );
    }
}
