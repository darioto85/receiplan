<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\Premium\PremiumAccessChecker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PremiumExtension extends AbstractExtension
{
    public function __construct(
        private readonly PremiumAccessChecker $premiumAccessChecker
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('premium_can_use', [$this, 'canUsePremium']),
            new TwigFunction('premium_source', [$this, 'getPremiumSource']),
            new TwigFunction('premium_label', [$this, 'getPremiumLabel']),
            new TwigFunction('premium_trial_expired', [$this, 'isTrialExpired']),
        ];
    }

    public function canUsePremium(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        return $this->premiumAccessChecker->canUsePremium($user);
    }

    public function getPremiumSource(?User $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        return $this->premiumAccessChecker->getPremiumSource($user);
    }

    public function getPremiumLabel(?User $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        return $this->premiumAccessChecker->getPremiumLabel($user);
    }

    public function isTrialExpired(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        return $this->premiumAccessChecker->isTrialExpired($user);
    }
}