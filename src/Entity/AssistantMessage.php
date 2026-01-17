<?php

namespace App\Entity;

use App\Repository\AssistantMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssistantMessageRepository::class)]
#[ORM\Table(name: 'assistant_message')]
#[ORM\Index(name: 'idx_conversation_created', columns: ['conversation_id', 'created_at'])]
class AssistantMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssistantConversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssistantConversation $conversation;

    #[ORM\Column(type: 'string', length: 20)]
    private string $role;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        AssistantConversation $conversation,
        string $role,
        string $content,
        ?array $payload = null
    ) {
        $this->conversation = $conversation;
        $this->role = $role;
        $this->content = $content;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): AssistantConversation
    {
        return $this->conversation;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }
}
