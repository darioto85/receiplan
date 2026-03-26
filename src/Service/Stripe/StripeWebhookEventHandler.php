<?php

namespace App\Service\Stripe;

use App\Entity\User;
use App\Entity\UserTransaction;
use App\Repository\UserRepository;
use App\Repository\UserTransactionRepository;
use App\Service\Stripe\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Event;

class StripeWebhookEventHandler
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserTransactionRepository $userTransactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StripeService $stripeService,
    ) {
    }

    public function isAlreadyProcessed(string $eventId): bool
    {
        return $this->userTransactionRepository->isStripeEventAlreadyProcessed($eventId);
    }

    public function handle(Event $event): void
    {
        $object = $event->data->object;
        $eventType = $event->type;

        $user = match ($eventType) {
            'checkout.session.completed' => $this->findUserFromCheckoutSession($object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->findUserFromSubscription($object),
            'invoice.paid',
            'invoice.payment_failed' => $this->findUserFromInvoice($object),
            default => null,
        };

        $transaction = (new UserTransaction())
            ->setUser($user ?? $this->resolveFallbackUser())
            ->setType($eventType)
            ->setStatus($this->extractStatus($object))
            ->setStripeEventId($event->id)
            ->setPayload($object->toArray());

        $this->hydrateStripeIds($transaction, $object);

        if (property_exists($object, 'amount_paid') && $object->amount_paid !== null) {
            $transaction->setAmount((int) $object->amount_paid);
        } elseif (property_exists($object, 'amount_total') && $object->amount_total !== null) {
            $transaction->setAmount((int) $object->amount_total);
        }

        if (property_exists($object, 'currency') && $object->currency !== null) {
            $transaction->setCurrency((string) $object->currency);
        }

        if ($user instanceof User) {
            $this->applyBusinessRules($eventType, $object, $user);
        }

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();
    }

    private function applyBusinessRules(string $eventType, object $object, User $user): void
    {
        switch ($eventType) {
            case 'checkout.session.completed':
                $customerId = $object->customer ?? null;
                $subscriptionId = $object->subscription ?? null;

                if ($customerId) {
                    $user->setStripeCustomerId((string) $customerId);
                }

                if ($subscriptionId) {
                    $user->setStripeSubscriptionId((string) $subscriptionId);
                    $this->hydrateUserFromStripeSubscription($user, (string) $subscriptionId);
                }

                $this->activatePremium($user);
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                if (isset($object->customer) && $object->customer) {
                    $user->setStripeCustomerId((string) $object->customer);
                }

                if (isset($object->id) && $object->id) {
                    $user->setStripeSubscriptionId((string) $object->id);
                }

                if (isset($object->status) && $object->status) {
                    $user->setSubscriptionStatus((string) $object->status);
                }

                if (isset($object->items->data[0]->price->id) && $object->items->data[0]->price->id) {
                    $user->setStripePriceId((string) $object->items->data[0]->price->id);
                    $user->setBillingPeriod($this->resolveBillingPeriodFromPrice($object));
                }

                if (\in_array($user->getSubscriptionStatus(), ['active', 'trialing'], true)) {
                    $this->activatePremium($user);
                } else {
                    $this->deactivatePremium($user);
                }
                break;

            case 'customer.subscription.deleted':
                if (isset($object->customer) && $object->customer) {
                    $user->setStripeCustomerId((string) $object->customer);
                }

                if (isset($object->id) && $object->id) {
                    $user->setStripeSubscriptionId((string) $object->id);
                }

                if (isset($object->status) && $object->status) {
                    $user->setSubscriptionStatus((string) $object->status);
                }

                if (isset($object->items->data[0]->price->id) && $object->items->data[0]->price->id) {
                    $user->setStripePriceId((string) $object->items->data[0]->price->id);
                    $user->setBillingPeriod($this->resolveBillingPeriodFromPrice($object));
                }

                $this->deactivatePremium($user);
                break;

            case 'invoice.paid':
                if (isset($object->customer) && $object->customer) {
                    $user->setStripeCustomerId((string) $object->customer);
                }

                if (isset($object->subscription) && $object->subscription) {
                    $user->setStripeSubscriptionId((string) $object->subscription);
                    $this->hydrateUserFromStripeSubscription($user, (string) $object->subscription);
                }

                $user->setSubscriptionStatus('active');
                $this->activatePremium($user);
                break;

            case 'invoice.payment_failed':
                if (isset($object->customer) && $object->customer) {
                    $user->setStripeCustomerId((string) $object->customer);
                }

                if (isset($object->subscription) && $object->subscription) {
                    $user->setStripeSubscriptionId((string) $object->subscription);
                    $this->hydrateUserFromStripeSubscription($user, (string) $object->subscription);
                }

                $user->setSubscriptionStatus('past_due');
                $this->deactivatePremium($user);
                break;
        }
    }

    private function hydrateUserFromStripeSubscription(User $user, string $subscriptionId): void
    {
        $client = $this->stripeService->getClient();
        $subscription = $client->subscriptions->retrieve($subscriptionId, []);

        if (isset($subscription->customer) && $subscription->customer) {
            $user->setStripeCustomerId((string) $subscription->customer);
        }

        if (isset($subscription->id) && $subscription->id) {
            $user->setStripeSubscriptionId((string) $subscription->id);
        }

        if (isset($subscription->status) && $subscription->status) {
            $user->setSubscriptionStatus((string) $subscription->status);
        }

        if (isset($subscription->items->data[0]->price->id) && $subscription->items->data[0]->price->id) {
            $user->setStripePriceId((string) $subscription->items->data[0]->price->id);
            $user->setBillingPeriod($this->resolveBillingPeriodFromPrice($subscription));
        }
    }

    private function activatePremium(User $user): void
    {
        if ($user->getPremiumActivatedAt() === null) {
            $user->setPremiumActivatedAt(new \DateTimeImmutable());
        }

        $user->setPremiumEndedAt(null);

        $roles = $user->getRoles();
        if (!\in_array('ROLE_PREMIUM', $roles, true)) {
            $roles[] = 'ROLE_PREMIUM';
        }

        $user->setRoles(array_values(array_unique($roles)));
    }

    private function deactivatePremium(User $user): void
    {
        $user->setPremiumEndedAt(new \DateTimeImmutable());

        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $role): bool => $role !== 'ROLE_PREMIUM'
        ));

        $user->setRoles($roles);
    }

    private function findUserFromCheckoutSession(object $object): ?User
    {
        $userId = $object->client_reference_id ?? null;

        if ($userId) {
            return $this->userRepository->find((int) $userId);
        }

        $customerId = $object->customer ?? null;
        if ($customerId) {
            return $this->userRepository->findOneBy(['stripeCustomerId' => (string) $customerId]);
        }

        return null;
    }

    private function findUserFromSubscription(object $object): ?User
    {
        $subscriptionId = $object->id ?? null;
        if ($subscriptionId) {
            $user = $this->userRepository->findOneBy(['stripeSubscriptionId' => (string) $subscriptionId]);
            if ($user instanceof User) {
                return $user;
            }
        }

        $customerId = $object->customer ?? null;
        if ($customerId) {
            $user = $this->userRepository->findOneBy(['stripeCustomerId' => (string) $customerId]);
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    private function findUserFromInvoice(object $object): ?User
    {
        $subscriptionId = $object->subscription ?? null;
        if ($subscriptionId) {
            $user = $this->userRepository->findOneBy(['stripeSubscriptionId' => (string) $subscriptionId]);
            if ($user instanceof User) {
                return $user;
            }
        }

        $customerId = $object->customer ?? null;
        if ($customerId) {
            $user = $this->userRepository->findOneBy(['stripeCustomerId' => (string) $customerId]);
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    private function extractStatus(object $object): ?string
    {
        return isset($object->status) ? (string) $object->status : null;
    }

    private function hydrateStripeIds(UserTransaction $transaction, object $object): void
    {
        if (isset($object->customer) && $object->customer) {
            $transaction->setStripeCustomerId((string) $object->customer);
        }

        if (isset($object->subscription) && $object->subscription) {
            $transaction->setStripeSubscriptionId((string) $object->subscription);
        }

        if (isset($object->id) && $object->id) {
            if (str_starts_with((string) $object->id, 'cs_')) {
                $transaction->setStripeCheckoutSessionId((string) $object->id);
            }

            if (str_starts_with((string) $object->id, 'in_')) {
                $transaction->setStripeInvoiceId((string) $object->id);
            }

            if (str_starts_with((string) $object->id, 'sub_')) {
                $transaction->setStripeSubscriptionId((string) $object->id);
            }

            if (str_starts_with((string) $object->id, 'pi_')) {
                $transaction->setStripePaymentIntentId((string) $object->id);
            }
        }

        if (isset($object->payment_intent) && $object->payment_intent) {
            $transaction->setStripePaymentIntentId((string) $object->payment_intent);
        }
    }

    private function resolveBillingPeriodFromPrice(object $subscription): ?string
    {
        $interval = $subscription->items->data[0]->price->recurring->interval ?? null;

        return match ($interval) {
            'month' => 'monthly',
            'year' => 'yearly',
            default => null,
        };
    }

    private function resolveFallbackUser(): User
    {
        $user = $this->userRepository->findOneBy([]);

        if (!$user instanceof User) {
            throw new \RuntimeException('No user found to attach the Stripe transaction.');
        }

        return $user;
    }
}