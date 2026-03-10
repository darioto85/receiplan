<?php

namespace App\Entity;

use App\Enum\AssistantConversationStatus;
use App\Repository\AssistantRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssistantRunRepository::class)]
#[ORM\Table(name: 'assistant_run')]
#[ORM\Index(name: 'idx_assistant_run_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_assistant_run_status', columns: ['status'])]
class AssistantRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'runs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssistantConversation $conversation;

    #[ORM\Column(enumType: AssistantConversationStatus::class)]
    private AssistantConversationStatus $status = AssistantConversationStatus::CONTINUE;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\OneToMany(mappedBy: 'run', targetEntity: AssistantRunAction::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $actions;

    #[ORM\OneToMany(mappedBy: 'run', targetEntity: AssistantMessage::class)]
    private Collection $messages;

    public function __construct(AssistantConversation $conversation)
    {
        $this->conversation = $conversation;
        $this->actions = new ArrayCollection();
        $this->messages = new ArrayCollection();

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): AssistantConversation
    {
        return $this->conversation;
    }

    public function getStatus(): AssistantConversationStatus
    {
        return $this->status;
    }

    public function setStatus(AssistantConversationStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function close(AssistantConversationStatus $status): void
    {
        $this->status = $status;
        $this->isActive = false;
        $this->closedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, AssistantRunAction>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(AssistantRunAction $action): void
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
        }
    }

    /**
     * @return Collection<int, AssistantMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(AssistantMessage $message): void
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
        }
    }
}