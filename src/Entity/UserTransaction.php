<?php

namespace App\Entity;

use App\Repository\UserTransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserTransactionRepository::class)]
#[ORM\Table(name: 'user_transaction')]
#[ORM\Index(name: 'idx_user_transaction_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_user_transaction_type', columns: ['type'])]
#[ORM\Index(name: 'idx_user_transaction_created_at', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_stripe_event_id', columns: ['stripe_event_id'])]
class UserTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * =========================
     * USER
     * =========================
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * =========================
     * TYPE / STATUS
     * =========================
     */
    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = null;

    /**
     * =========================
     * MONTANT
     * =========================
     */
    #[ORM\Column(nullable: true)]
    private ?int $amount = null; // en centimes

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = null;

    /**
     * =========================
     * STRIPE IDS
     * =========================
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeInvoiceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeEventId = null;

    /**
     * =========================
     * DATA
     * =========================
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }

    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getType(): string { return $this->type; }

    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getStatus(): ?string { return $this->status; }

    public function setStatus(?string $status): static { $this->status = $status; return $this; }

    public function getAmount(): ?int { return $this->amount; }

    public function setAmount(?int $amount): static { $this->amount = $amount; return $this; }

    public function getCurrency(): ?string { return $this->currency; }

    public function setCurrency(?string $currency): static { $this->currency = $currency; return $this; }

    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }

    public function setStripeCustomerId(?string $stripeCustomerId): static { $this->stripeCustomerId = $stripeCustomerId; return $this; }

    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static { $this->stripeSubscriptionId = $stripeSubscriptionId; return $this; }

    public function getStripeCheckoutSessionId(): ?string { return $this->stripeCheckoutSessionId; }

    public function setStripeCheckoutSessionId(?string $stripeCheckoutSessionId): static { $this->stripeCheckoutSessionId = $stripeCheckoutSessionId; return $this; }

    public function getStripeInvoiceId(): ?string { return $this->stripeInvoiceId; }

    public function setStripeInvoiceId(?string $stripeInvoiceId): static { $this->stripeInvoiceId = $stripeInvoiceId; return $this; }

    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static { $this->stripePaymentIntentId = $stripePaymentIntentId; return $this; }

    public function getStripeEventId(): ?string { return $this->stripeEventId; }

    public function setStripeEventId(?string $stripeEventId): static { $this->stripeEventId = $stripeEventId; return $this; }

    public function getPayload(): ?array { return $this->payload; }

    public function setPayload(?array $payload): static { $this->payload = $payload; return $this; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static { $this->occurredAt = $occurredAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }
}