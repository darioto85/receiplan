<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_google_id', fields: ['googleId'])]
#[ORM\UniqueConstraint(name: 'uniq_user_apple_id', fields: ['appleId'])]
#[ORM\UniqueConstraint(name: 'uniq_user_password_reset_token', fields: ['passwordResetToken'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Nullable to allow OAuth-only accounts.
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appleId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetRequestedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetExpiresAt = null;

    /**
     * =========================
     * TRIAL
     * =========================
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialStartedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    /**
     * =========================
     * PREMIUM MANUEL
     * =========================
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $manualPremiumStartsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $manualPremiumEndsAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $manualPremiumIsLifetime = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manualPremiumReason = null;

    #[ORM\Column(nullable: true)]
    private ?int $manualPremiumGrantedBy = null;

    /**
     * =========================
     * STRIPE
     * =========================
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $subscriptionStatus = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $subscriptionCancelAtPeriodEnd = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $subscriptionCurrentPeriodEndAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $premiumActivatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $premiumEndedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $billingPeriod = null;

    /** @var Collection<int, Recipe> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Recipe::class, orphanRemoval: true)]
    private Collection $recipes;

    /** @var Collection<int, Ingredient> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Ingredient::class, orphanRemoval: true)]
    private Collection $ingredients;

    /** @var Collection<int, UserIngredient> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserIngredient::class, orphanRemoval: true)]
    private Collection $userIngredients;

    public function __construct()
    {
        $this->recipes = new ArrayCollection();
        $this->ingredients = new ArrayCollection();
        $this->userIngredients = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->isVerified = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;
        return $this;
    }

    public function getAppleId(): ?string
    {
        return $this->appleId;
    }

    public function setAppleId(?string $appleId): static
    {
        $this->appleId = $appleId;
        return $this;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;
        return $this;
    }

    public function getPasswordResetRequestedAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetRequestedAt;
    }

    public function setPasswordResetRequestedAt(?\DateTimeImmutable $passwordResetRequestedAt): static
    {
        $this->passwordResetRequestedAt = $passwordResetRequestedAt;
        return $this;
    }

    public function getPasswordResetExpiresAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetExpiresAt;
    }

    public function setPasswordResetExpiresAt(?\DateTimeImmutable $passwordResetExpiresAt): static
    {
        $this->passwordResetExpiresAt = $passwordResetExpiresAt;
        return $this;
    }

    public function clearPasswordReset(): static
    {
        $this->passwordResetToken = null;
        $this->passwordResetRequestedAt = null;
        $this->passwordResetExpiresAt = null;
        return $this;
    }

    public function isPasswordResetTokenValid(string $token, \DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        if ($this->passwordResetToken === null || $this->passwordResetExpiresAt === null) {
            return false;
        }

        if (!hash_equals($this->passwordResetToken, $token)) {
            return false;
        }

        return $this->passwordResetExpiresAt > $now;
    }

    public function getTrialStartedAt(): ?\DateTimeImmutable
    {
        return $this->trialStartedAt;
    }

    public function setTrialStartedAt(?\DateTimeImmutable $trialStartedAt): static
    {
        $this->trialStartedAt = $trialStartedAt;
        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;
        return $this;
    }

    public function getManualPremiumStartsAt(): ?\DateTimeImmutable
    {
        return $this->manualPremiumStartsAt;
    }

    public function setManualPremiumStartsAt(?\DateTimeImmutable $manualPremiumStartsAt): static
    {
        $this->manualPremiumStartsAt = $manualPremiumStartsAt;
        return $this;
    }

    public function getManualPremiumEndsAt(): ?\DateTimeImmutable
    {
        return $this->manualPremiumEndsAt;
    }

    public function setManualPremiumEndsAt(?\DateTimeImmutable $manualPremiumEndsAt): static
    {
        $this->manualPremiumEndsAt = $manualPremiumEndsAt;
        return $this;
    }

    public function isManualPremiumIsLifetime(): bool
    {
        return $this->manualPremiumIsLifetime;
    }

    public function setManualPremiumIsLifetime(bool $manualPremiumIsLifetime): static
    {
        $this->manualPremiumIsLifetime = $manualPremiumIsLifetime;
        return $this;
    }

    public function getManualPremiumReason(): ?string
    {
        return $this->manualPremiumReason;
    }

    public function setManualPremiumReason(?string $manualPremiumReason): static
    {
        $this->manualPremiumReason = $manualPremiumReason;
        return $this;
    }

    public function getManualPremiumGrantedBy(): ?int
    {
        return $this->manualPremiumGrantedBy;
    }

    public function setManualPremiumGrantedBy(?int $manualPremiumGrantedBy): static
    {
        $this->manualPremiumGrantedBy = $manualPremiumGrantedBy;
        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): static
    {
        $this->stripePriceId = $stripePriceId;
        return $this;
    }

    public function getSubscriptionStatus(): ?string
    {
        return $this->subscriptionStatus;
    }

    public function setSubscriptionStatus(?string $subscriptionStatus): static
    {
        $this->subscriptionStatus = $subscriptionStatus;
        return $this;
    }

    public function isSubscriptionCancelAtPeriodEnd(): bool
    {
        return $this->subscriptionCancelAtPeriodEnd;
    }

    public function setSubscriptionCancelAtPeriodEnd(bool $subscriptionCancelAtPeriodEnd): static
    {
        $this->subscriptionCancelAtPeriodEnd = $subscriptionCancelAtPeriodEnd;
        return $this;
    }

    public function getSubscriptionCurrentPeriodEndAt(): ?\DateTimeImmutable
    {
        return $this->subscriptionCurrentPeriodEndAt;
    }

    public function setSubscriptionCurrentPeriodEndAt(?\DateTimeImmutable $subscriptionCurrentPeriodEndAt): static
    {
        $this->subscriptionCurrentPeriodEndAt = $subscriptionCurrentPeriodEndAt;
        return $this;
    }

    public function getPremiumActivatedAt(): ?\DateTimeImmutable
    {
        return $this->premiumActivatedAt;
    }

    public function setPremiumActivatedAt(?\DateTimeImmutable $premiumActivatedAt): static
    {
        $this->premiumActivatedAt = $premiumActivatedAt;
        return $this;
    }

    public function getPremiumEndedAt(): ?\DateTimeImmutable
    {
        return $this->premiumEndedAt;
    }

    public function setPremiumEndedAt(?\DateTimeImmutable $premiumEndedAt): static
    {
        $this->premiumEndedAt = $premiumEndedAt;
        return $this;
    }

    public function getBillingPeriod(): ?string
    {
        return $this->billingPeriod;
    }

    public function setBillingPeriod(?string $billingPeriod): static
    {
        $this->billingPeriod = $billingPeriod;
        return $this;
    }

    public function hasActiveTrial(): bool
    {
        return $this->trialEndsAt !== null && $this->trialEndsAt > new \DateTimeImmutable();
    }

    public function hasActiveManualPremium(): bool
    {
        if ($this->manualPremiumIsLifetime) {
            return true;
        }

        return $this->manualPremiumEndsAt !== null
            && $this->manualPremiumEndsAt > new \DateTimeImmutable();
    }

    public function hasActiveStripePremium(): bool
    {
        return $this->subscriptionStatus === 'active'
            || $this->subscriptionStatus === 'trialing';
    }

    public function canUsePremium(): bool
    {
        return $this->hasActiveTrial()
            || $this->hasActiveManualPremium()
            || $this->hasActiveStripePremium();
    }

    public function getPremiumSource(): ?string
    {
        if ($this->hasActiveTrial()) {
            return 'trial';
        }

        if ($this->hasActiveManualPremium()) {
            return 'manual';
        }

        if ($this->hasActiveStripePremium()) {
            return 'stripe';
        }

        return null;
    }

    /** @return Collection<int, Recipe> */
    public function getRecipes(): Collection
    {
        return $this->recipes;
    }

    public function addRecipe(Recipe $recipe): static
    {
        if (!$this->recipes->contains($recipe)) {
            $this->recipes->add($recipe);
            $recipe->setUser($this);
        }

        return $this;
    }

    public function removeRecipe(Recipe $recipe): static
    {
        $this->recipes->removeElement($recipe);
        return $this;
    }

    /** @return Collection<int, Ingredient> */
    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    public function addIngredient(Ingredient $ingredient): static
    {
        if (!$this->ingredients->contains($ingredient)) {
            $this->ingredients->add($ingredient);
            $ingredient->setUser($this);
        }

        return $this;
    }

    public function removeIngredient(Ingredient $ingredient): static
    {
        $this->ingredients->removeElement($ingredient);
        return $this;
    }

    /** @return Collection<int, UserIngredient> */
    public function getUserIngredients(): Collection
    {
        return $this->userIngredients;
    }

    public function addUserIngredient(UserIngredient $userIngredient): static
    {
        if (!$this->userIngredients->contains($userIngredient)) {
            $this->userIngredients->add($userIngredient);
            $userIngredient->setUser($this);
        }

        return $this;
    }

    public function removeUserIngredient(UserIngredient $userIngredient): static
    {
        $this->userIngredients->removeElement($userIngredient);
        return $this;
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // noop
    }
}