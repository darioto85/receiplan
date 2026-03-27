<?php

namespace App\Service\Stripe;

use App\Entity\User;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;

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

    public function hasStripeSubscription(User $user): bool
    {
        return $user->getStripeSubscriptionId() !== null && $user->getStripeSubscriptionId() !== '';
    }

    public function retrieveSubscription(string $subscriptionId): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = $this->client->subscriptions->retrieve($subscriptionId, []);

        return $subscription;
    }

    public function retrieveUserSubscription(User $user): ?Subscription
    {
        if (!$this->hasStripeSubscription($user)) {
            return null;
        }

        try {
            return $this->retrieveSubscription((string) $user->getStripeSubscriptionId());
        } catch (ApiErrorException) {
            return null;
        }
    }

    public function getAccountSubscriptionSummary(User $user): ?array
    {
        $subscription = $this->retrieveUserSubscription($user);

        if (!$subscription instanceof Subscription) {
            return null;
        }

        $currentPeriodEnd = $subscription->current_period_end ?? null;
        $cancelAt = $subscription->cancel_at ?? null;
        $cancelAtPeriodEnd = (bool) ($subscription->cancel_at_period_end ?? false);

        $price = $subscription->items->data[0]->price ?? null;
        $priceUnitAmount = $price?->unit_amount ?? null;
        $currency = $price?->currency ?? null;
        $interval = $price?->recurring?->interval ?? null;

        $upcomingAmount = null;
        $upcomingCurrency = null;
        $nextBillingAt = $currentPeriodEnd ? (new \DateTimeImmutable())->setTimestamp((int) $currentPeriodEnd) : null;

        try {
            $preview = $this->client->invoices->createPreview([
                'customer' => $subscription->customer,
                'subscription' => $subscription->id,
            ]);

            if (isset($preview->amount_due) && $preview->amount_due !== null) {
                $upcomingAmount = (int) $preview->amount_due;
            }

            if (isset($preview->currency) && $preview->currency !== null) {
                $upcomingCurrency = (string) $preview->currency;
            }

            if (isset($preview->period_end) && $preview->period_end) {
                $nextBillingAt = (new \DateTimeImmutable())->setTimestamp((int) $preview->period_end);
            }
        } catch (ApiErrorException) {
            // On garde les infos de l'abonnement même si la preview de facture n'est pas disponible.
        }

        return [
            'subscription_id' => (string) $subscription->id,
            'status' => isset($subscription->status) ? (string) $subscription->status : null,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'current_period_end_at' => $currentPeriodEnd
                ? (new \DateTimeImmutable())->setTimestamp((int) $currentPeriodEnd)
                : null,
            'cancel_at' => $cancelAt
                ? (new \DateTimeImmutable())->setTimestamp((int) $cancelAt)
                : null,
            'next_billing_at' => $nextBillingAt,
            'amount' => $upcomingAmount ?? ($priceUnitAmount !== null ? (int) $priceUnitAmount : null),
            'currency' => $upcomingCurrency ?? ($currency ? (string) $currency : null),
            'billing_period' => match ($interval) {
                'month' => 'monthly',
                'year' => 'yearly',
                default => null,
            },
        ];
    }

    public function scheduleCancelAtPeriodEnd(User $user): ?Subscription
    {
        if (!$this->hasStripeSubscription($user)) {
            return null;
        }

        /** @var Subscription $subscription */
        $subscription = $this->client->subscriptions->update(
            (string) $user->getStripeSubscriptionId(),
            [
                'cancel_at_period_end' => true,
            ]
        );

        return $subscription;
    }

    public function resumeSubscription(User $user): ?Subscription
    {
        if (!$this->hasStripeSubscription($user)) {
            return null;
        }

        /** @var Subscription $subscription */
        $subscription = $this->client->subscriptions->update(
            (string) $user->getStripeSubscriptionId(),
            [
                'cancel_at_period_end' => false,
            ]
        );

        return $subscription;
    }

    public function formatAmount(?int $amount, ?string $currency): ?string
    {
        if ($amount === null || !$currency) {
            return null;
        }

        $value = $amount / 100;
        $locale = 'fr_FR';
        $currency = strtoupper($currency);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($value, $currency);

            return $formatted !== false ? $formatted : null;
        }

        return number_format($value, 2, ',', ' ') . ' ' . $currency;
    }
}