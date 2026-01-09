<?php

namespace App\Entity;

use App\Repository\DailyMealSuggestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DailyMealSuggestionRepository::class)]
#[ORM\Table(name: 'daily_meal_suggestion')]
#[ORM\UniqueConstraint(name: 'uniq_daily_suggestion_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(name: 'idx_daily_suggestion_date', columns: ['date'])]
#[ORM\Index(name: 'idx_daily_suggestion_user', columns: ['user_id'])]
class DailyMealSuggestion
{
    public const STATUS_PROPOSED      = 'proposed';
    public const STATUS_NONE_POSSIBLE = 'none_possible';
    public const STATUS_DISMISSED     = 'dismissed';
    public const STATUS_ACCEPTED      = 'accepted';

    public const CONTEXT_TODAY_AUTO   = 'today_auto';
    public const CONTEXT_CRON_BACKFILL = 'cron_backfill';
    public const CONTEXT_MANUAL       = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    // ✅ SET NULL si le mealplan est supprimé
    #[ORM\OneToOne(targetEntity: MealPlan::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MealPlan $mealPlan = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PROPOSED;

    #[ORM\Column(length: 32)]
    private string $context = self::CONTEXT_TODAY_AUTO;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }

    public function getMealPlan(): ?MealPlan { return $this->mealPlan; }
    public function setMealPlan(?MealPlan $mealPlan): self { $this->mealPlan = $mealPlan; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getContext(): string { return $this->context; }
    public function setContext(string $context): self { $this->context = $context; return $this; }

    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function setGeneratedAt(\DateTimeImmutable $generatedAt): self { $this->generatedAt = $generatedAt; return $this; }

    public function getMeta(): ?array { return $this->meta; }
    public function setMeta(?array $meta): self { $this->meta = $meta; return $this; }
}
