<?php

namespace App\Controller;

use App\Service\Stripe\StripeWebhookEventHandler;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $stripeWebhookSecret
    ) {
    }

    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        StripeWebhookEventHandler $stripeWebhookEventHandler
    ): Response {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature');

        if (!$signature) {
            return new Response('Missing Stripe signature', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $this->stripeWebhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        } catch (SignatureVerificationException $e) {
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        if ($stripeWebhookEventHandler->isAlreadyProcessed($event->id)) {
            return new Response('Event already processed', Response::HTTP_OK);
        }

        $stripeWebhookEventHandler->handle($event);

        return new Response('Webhook handled', Response::HTTP_OK);
    }
}