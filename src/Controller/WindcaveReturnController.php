<?php

declare(strict_types=1);

namespace Windcave\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WindcaveReturnController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    #[Route(path: '/windcave/return', name: 'frontend.windcave.return', methods: ['GET', 'POST'])]
    public function return(Request $request): RedirectResponse
    {
        $returnUrl = $this->resolveReturnUrl($request);
        $result = $request->query->get('result') ?? $request->query->get('sessionId');

        if ($returnUrl && $result) {
            $separator = \str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl = $returnUrl . $separator . 'sessionId=' . urlencode((string) $result);
        }

        return new RedirectResponse($returnUrl ?: '/');
    }

    #[Route(path: '/windcave/return/success', name: 'frontend.windcave.success', methods: ['GET'])]
    public function success(Request $request): RedirectResponse
    {
        $returnUrl = $this->resolveReturnUrl($request);
        $result = $request->query->get('result') ?? $request->query->get('sessionId');

        if ($returnUrl && $result) {
            $separator = \str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl = $returnUrl . $separator . 'sessionId=' . urlencode((string) $result);
        }

        return new RedirectResponse($returnUrl ?: '/checkout/finish');
    }

    #[Route(path: '/windcave/return/fail', name: 'frontend.windcave.fail', methods: ['GET'])]
    public function fail(Request $request): RedirectResponse
    {
        $returnUrl = $this->resolveReturnUrl($request);
        $result = $request->query->get('result') ?? $request->query->get('sessionId');

        if ($returnUrl && $result) {
            $separator = \str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl = $returnUrl . $separator . 'sessionId=' . urlencode((string) $result);
        }

        return new RedirectResponse($returnUrl ?: '/checkout/confirm');
    }

    /**
     * Resolve the return URL from either query parameter or order transaction custom fields.
     */
    private function resolveReturnUrl(Request $request): string
    {
        // First try to get from query parameter (legacy support)
        $returnUrl = $request->query->get('returnUrl');
        if ($returnUrl) {
            return (string) $returnUrl;
        }

        // Otherwise look up from order transaction custom fields
        $orderId = $request->query->get('orderId');
        if (!$orderId) {
            return '';
        }

        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->setLimit(1);

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        if (!$transaction) {
            return '';
        }

        $customFields = $transaction->getCustomFields() ?? [];
        return (string) ($customFields['windcaveReturnUrl'] ?? '');
    }
}
