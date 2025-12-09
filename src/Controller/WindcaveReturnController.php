<?php

declare(strict_types=1);

namespace Windcave\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WindcaveReturnController extends AbstractController
{
    #[Route(path: '/windcave/return', name: 'frontend.windcave.return', methods: ['GET', 'POST'])]
    public function return(Request $request): RedirectResponse
    {
        $returnUrl = (string) $request->query->get('returnUrl');
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
        $returnUrl = (string) $request->query->get('returnUrl');
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
        $returnUrl = (string) $request->query->get('returnUrl');
        $result = $request->query->get('result') ?? $request->query->get('sessionId');

        if ($returnUrl && $result) {
            $separator = \str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl = $returnUrl . $separator . 'sessionId=' . urlencode((string) $result);
        }

        return new RedirectResponse($returnUrl ?: '/checkout/confirm');
    }
}
