<?php

declare(strict_types=1);

namespace Windcave\Controller;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WindcaveDropInController extends StorefrontController
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    #[Route(path: '/windcave/dropin/{orderTransactionId}', name: 'frontend.windcave.dropin.start', methods: ['GET'])]
    public function start(string $orderTransactionId, SalesChannelContext $context): Response
    {
        $criteria = (new Criteria([$orderTransactionId]))->addAssociation('order');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context->getContext())->first();
        if (!$transaction) {
            throw $this->createNotFoundException('Order transaction not found');
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $session = $customFields['windcaveDropInSession'] ?? null;
        $returnUrl = $customFields['windcaveReturnUrl'] ?? null;

        $links = $session['links'] ?? [];
        $hppUrl = $session['hppUrl'] ?? null;
        $scriptBase = $customFields['windcaveDropInScriptBase'] ?? 'https://sec.windcave.com';
        $appleMerchantId = $customFields['windcaveAppleMerchantId'] ?? '';
        $googleMerchantId = $customFields['windcaveGoogleMerchantId'] ?? '';
        $isTest = (bool) ($customFields['windcaveDropInTestMode'] ?? false);
        $currency = $transaction->getOrder()?->getCurrency()?->getIsoCode() ?? 'NZD';
        $merchantName = $context->getSalesChannel()->getName();
        $country = $transaction->getOrder()?->getBillingAddress()?->getCountry()?->getIso() ?? 'NZ';

        return $this->renderStorefront('@Windcave/storefront/page/windcave/dropin.html.twig', [
            'windcaveLinks' => $links,
            'windcaveHppUrl' => $hppUrl,
            'windcaveReturnUrl' => $returnUrl,
            'windcaveSessionId' => $session['id'] ?? null,
            'windcaveScriptBase' => $scriptBase,
            'windcaveAppleMerchantId' => $appleMerchantId,
            'windcaveGoogleMerchantId' => $googleMerchantId,
            'windcaveCurrency' => $currency,
            'windcaveMerchantName' => $merchantName,
            'windcaveCountry' => $country,
            'windcaveIsTest' => $isTest,
        ]);
    }
}
