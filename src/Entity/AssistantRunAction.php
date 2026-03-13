<?php

namespace App\Entity;

use App\Enum\AssistantActionStatus;
use App\Enum\AssistantActionType;
use App\Repository\AssistantRunActionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssistantRunActionRepository::class)]
#[ORM\Table(name: 'assistant_run_action')]
#[ORM\UniqueConstraint(name: 'uniq_run_client_action', columns: ['run_id', 'client_action_id'])]
#[ORM\Index(name: 'idx_assistant_run_action_type', columns: ['type'])]
#[ORM\Index(name: 'idx_assistant_run_action_status', columns: ['status'])]
class AssistantRunAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssistantRun $run;

    #[ORM\Column(length: 50)]
    private string $clientActionId;

    #[ORM\Column(enumType: AssistantActionType::class)]
    private AssistantActionType $type;

    #[ORM\Column(enumType: AssistantActionStatus::class)]
    private AssistantActionStatus $status;

    #[ORM\Column(type: 'json')]
    private array $data = [];

    #[ORM\Column(type: 'json')]
    private array $missing = [];

    #[ORM\Column(options: ['default' => 100])]
    private int $executionOrder = 100;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $error = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $executedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        AssistantRun $run,
        string $clientActionId,
        AssistantActionType $type,
        AssistantActionStatus $status
    ) {
        $this->run = $run;
        $this->clientActionId = $clientActionId;
        $this->type = $type;
        $this->status = $status;

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): AssistantRun
    {
        return $this->run;
    }

    public function getClientActionId(): string
    {
        return $this->clientActionId;
    }

    public function getType(): AssistantActionType
    {
        return $this->type;
    }

    public function setType(AssistantActionType $type): void
    {
        $this->type = $type;
        $this->touch();
    }

    public function getStatus(): AssistantActionStatus
    {
        return $this->status;
    }

    public function setStatus(AssistantActionStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
        $this->touch();
    }

    public function getMissing(): array
    {
        return $this->missing;
    }

    public function setMissing(array $missing): void
    {
        $this->missing = $missing;
        $this->touch();
    }

    public function getExecutionOrder(): int
    {
        return $this->executionOrder;
    }

    public function setExecutionOrder(int $executionOrder): void
    {
        $this->executionOrder = $executionOrder;
        $this->touch();
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function markExecuted(array $result): void
    {
        $this->result = $result;
        $this->executedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getError(): ?array
    {
        return $this->error;
    }

    public function markError(array $error): void
    {
        $this->error = $error;
        $this->executedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function getExecutedAt(): ?\DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}