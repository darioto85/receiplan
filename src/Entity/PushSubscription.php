<?php

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\Index(name: 'idx_push_subscription_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_endpoint_hash', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Hash SHA-256 de l'endpoint (hex, 64 chars)
     * → utilisé pour l’unicité / upsert
     */
    #[ORM\Column(name: 'endpoint_hash', type: Types::STRING, length: 64)]
    private string $endpointHash;

    /**
     * Endpoint Web Push complet (URL)
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $p256dh;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $auth;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $contentEncoding = 'aesgcm';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Initialise endpoint + hash en une seule fois
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        return $this;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->touch();
    }

    // ----------------- Getters / setters -----------------

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getEndpoint(): string { return $this->endpoint; }
    public function getEndpointHash(): string { return $this->endpointHash; }

    public function getP256dh(): string { return $this->p256dh; }
    public function setP256dh(string $p256dh): self { $this->p256dh = $p256dh; return $this; }

    public function getAuth(): string { return $this->auth; }
    public function setAuth(string $auth): self { $this->auth = $auth; return $this; }

    public function getContentEncoding(): string { return $this->contentEncoding; }
    public function setContentEncoding(string $contentEncoding): self { $this->contentEncoding = $contentEncoding; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): self { $this->lastUsedAt = $lastUsedAt; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }
}
