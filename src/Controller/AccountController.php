<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccountController extends AbstractController
{
    #[Route('/account', name: 'account_index', methods: ['GET'])]
    public function index(
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')] string $vapidPublicKey,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('account/index.html.twig', [
            'vapid_public_key' => $vapidPublicKey,
        ]);
    }

}
