<?php

namespace App\Entity;

use App\Repository\AssistantConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssistantConversationRepository::class)]
#[ORM\Table(name: 'assistant_conversation')]
#[ORM\Index(name: 'idx_assistant_conversation_user', columns: ['user_id'])]
class AssistantConversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, AssistantMessage>
     */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: AssistantMessage::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    /**
     * @var Collection<int, AssistantRun>
     */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: AssistantRun::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $runs;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->messages = new ArrayCollection();
        $this->runs = new ArrayCollection();

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
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

        $this->touch();
    }

    /**
     * @return Collection<int, AssistantRun>
     */
    public function getRuns(): Collection
    {
        return $this->runs;
    }

    public function addRun(AssistantRun $run): void
    {
        if (!$this->runs->contains($run)) {
            $this->runs->add($run);
        }

        $this->touch();
    }
}