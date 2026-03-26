<?php

namespace App\Service\Premium;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PremiumRouteGuard
{
    public function __construct(
        private readonly PremiumAccessChecker $premiumAccessChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getDeniedResponse(?User $user, Request $request): ?Response
    {
        if (!$user instanceof User) {
            return $this->buildUnauthorizedResponse($request);
        }

        if ($this->premiumAccessChecker->canUsePremium($user)) {
            return null;
        }

        return $this->buildPremiumRequiredResponse($request);
    }

    private function buildUnauthorizedResponse(Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Non authentifié.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }

    private function buildPremiumRequiredResponse(Request $request): Response
    {
        $premiumUrl = $this->urlGenerator->generate('premium_index');

        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'premium_required',
                    'message' => 'Cette fonctionnalité nécessite un accès premium.',
                ],
                'redirect_url' => $premiumUrl,
            ], Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse($premiumUrl);
    }

    private function isJsonRequest(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json');
    }
}