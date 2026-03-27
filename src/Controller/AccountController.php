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

        $formattedAmount = null;
        $stripeNextBillingAt = null;
        $stripeCurrentPeriodEndAt = null;
        $stripeCancelAtPeriodEnd = null;

        // La base reste la source principale.
        // Stripe ne sert qu'à enrichir l'affichage des infos de facturation.
        if (
            $user->getPremiumSource() === 'stripe'
            && $user->getStripeSubscriptionId()
        ) {
            try {
                $subscription = $stripeService->getAccountSubscriptionSummary($user);

                if ($subscription) {
                    if (isset($subscription['amount'], $subscription['currency'])) {
                        $formattedAmount = $stripeService->formatAmount(
                            $subscription['amount'],
                            $subscription['currency']
                        );
                    }

                    $stripeNextBillingAt = $subscription['next_billing_at'] ?? null;
                    $stripeCurrentPeriodEndAt = $subscription['current_period_end_at'] ?? null;
                    $stripeCancelAtPeriodEnd = $subscription['cancel_at_period_end'] ?? null;
                }
            } catch (\Throwable $e) {
                // On ignore silencieusement :
                // la page account doit continuer à fonctionner à partir de la DB.
            }
        }

        return $this->render('account/index.html.twig', [
            'vapid_public_key' => $vapidPublicKey,
            'user_entity' => $user,
            'formatted_amount' => $formattedAmount,
            'stripe_next_billing_at' => $stripeNextBillingAt,
            'stripe_current_period_end_at' => $stripeCurrentPeriodEndAt,
            'stripe_cancel_at_period_end' => $stripeCancelAtPeriodEnd,
        ]);
    }
}