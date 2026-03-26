<?php

namespace App\Service\Premium;

use App\Entity\User;

class PremiumAccessChecker
{
    public function hasActiveTrial(User $user): bool
    {
        $trialEndsAt = $user->getTrialEndsAt();

        return $trialEndsAt !== null && $trialEndsAt > new \DateTimeImmutable();
    }

    public function hasActiveManualPremium(User $user): bool
    {
        if ($user->isManualPremiumIsLifetime()) {
            return true;
        }

        $manualPremiumEndsAt = $user->getManualPremiumEndsAt();

        return $manualPremiumEndsAt !== null
            && $manualPremiumEndsAt > new \DateTimeImmutable();
    }

    public function hasActiveStripePremium(User $user): bool
    {
        return \in_array($user->getSubscriptionStatus(), ['active', 'trialing'], true);
    }

    public function canUsePremium(User $user): bool
    {
        return $this->hasActiveTrial($user)
            || $this->hasActiveManualPremium($user)
            || $this->hasActiveStripePremium($user);
    }

    public function getPremiumSource(User $user): ?string
    {
        if ($this->hasActiveTrial($user)) {
            return 'trial';
        }

        if ($this->hasActiveManualPremium($user)) {
            return 'manual';
        }

        if ($this->hasActiveStripePremium($user)) {
            return 'stripe';
        }

        return null;
    }

    public function getPremiumLabel(User $user): ?string
    {
        return match ($this->getPremiumSource($user)) {
            'trial' => 'Essai gratuit',
            'manual' => 'Accès offert',
            'stripe' => 'Abonnement actif',
            default => null,
        };
    }

    public function isTrialExpired(User $user): bool
    {
        $trialEndsAt = $user->getTrialEndsAt();

        return $trialEndsAt !== null && $trialEndsAt <= new \DateTimeImmutable();
    }

    public function shouldRedirectToPremium(User $user): bool
    {
        return !$this->canUsePremium($user);
    }
}