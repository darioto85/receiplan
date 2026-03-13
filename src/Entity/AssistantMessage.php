<?php

namespace App\Entity;

use App\Enum\AssistantMessageRole;
use App\Repository\AssistantMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssistantMessageRepository::class)]
#[ORM\Table(name: 'assistant_message')]
#[ORM\Index(name: 'idx_assistant_message_role', columns: ['role'])]
#[ORM\Index(name: 'idx_assistant_message_created_at', columns: ['created_at'])]
class AssistantMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssistantConversation $conversation;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AssistantRun $run = null;

    #[ORM\Column(enumType: AssistantMessageRole::class)]
    private AssistantMessageRole $role;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        AssistantConversation $conversation,
        AssistantMessageRole|string $role,
        string $content,
        ?array $payload = null,
        ?AssistantRun $run = null,
    ) {
        $this->conversation = $conversation;
        $this->role = $role instanceof AssistantMessageRole ? $role : AssistantMessageRole::from($role);
        $this->content = $content;
        $this->payload = $payload;
        $this->run = $run;
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

    public function getRun(): ?AssistantRun
    {
        return $this->run;
    }

    public function setRun(?AssistantRun $run): void
    {
        $this->run = $run;
    }

    public function getRole(): string
    {
        return $this->role->value;
    }

    public function getRoleEnum(): AssistantMessageRole
    {
        return $this->role;
    }

    public function setRole(AssistantMessageRole|string $role): void
    {
        $this->role = $role instanceof AssistantMessageRole ? $role : AssistantMessageRole::from($role);
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): void
    {
        $this->payload = $payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}