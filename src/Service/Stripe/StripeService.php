<?php

namespace App\Service\Stripe;

use Stripe\StripeClient;

class StripeService
{
    private StripeClient $client;

    public function __construct(string $secretKey)
    {
        $this->client = new StripeClient($secretKey);
    }

    public function getClient(): StripeClient
    {
        return $this->client;
    }
}