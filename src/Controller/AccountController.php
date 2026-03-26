<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Stripe\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/account', name: 'account_index', methods: ['GET'])]
    public function index(
        StripeService $stripeService,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')] string $vapidPublicKey,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $subscription = $stripeService->getAccountSubscriptionSummary($user);

        return $this->render('account/index.html.twig', [
            'vapid_public_key' => $vapidPublicKey,
            'subscription' => $subscription,
            'formatted_amount' => $subscription
                ? $stripeService->formatAmount($subscription['amount'], $subscription['currency'])
                : null,
        ]);
    }
}