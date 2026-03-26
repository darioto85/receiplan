<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Stripe\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PremiumController extends AbstractController
{
    private string $priceMonthly;
    private string $priceYearly;

    public function __construct(
        string $priceMonthly,
        string $priceYearly
    ) {
        $this->priceMonthly = trim($priceMonthly);
        $this->priceYearly = trim($priceYearly);
    }

    #[Route('/premium', name: 'premium_index')]
    public function index(): Response
    {
        return $this->render('premium/index.html.twig');
    }

    #[Route('/premium/checkout/{plan}', name: 'premium_checkout')]
    public function checkout(string $plan, StripeService $stripeService): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $priceId = match ($plan) {
            'monthly' => $this->priceMonthly,
            'yearly' => $this->priceYearly,
            default => throw $this->createNotFoundException(),
        };

        $client = $stripeService->getClient();

        $session = $client->checkout->sessions->create([
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $user->getId(),
            'customer_email' => $user->getEmail(),
            'success_url' => $this->generateUrl(
                'premium_success',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->generateUrl(
                'premium_cancel',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ]);

        return new RedirectResponse($session->url, Response::HTTP_SEE_OTHER);
    }

    #[Route('/premium/success', name: 'premium_success')]
    public function success(): Response
    {
        return $this->render('premium/success.html.twig');
    }

    #[Route('/premium/cancel', name: 'premium_cancel')]
    public function cancel(): Response
    {
        return $this->render('premium/cancel.html.twig');
    }
}