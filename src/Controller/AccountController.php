<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Premium\PremiumAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/account', name: 'account_index', methods: ['GET'])]
    public function index(
        PremiumAccessChecker $premiumAccessChecker,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')] string $vapidPublicKey,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('account/index.html.twig', [
            'vapid_public_key' => $vapidPublicKey,
            'premium_label' => $premiumAccessChecker->getPremiumLabel($user),
            'premium_source' => $premiumAccessChecker->getPremiumSource($user),
            'premium_can_use' => $premiumAccessChecker->canUsePremium($user),
            'trial_expired' => $premiumAccessChecker->isTrialExpired($user),
        ]);
    }
}