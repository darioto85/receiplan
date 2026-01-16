<?php

namespace App\Entity;

use App\Repository\MealCookedPromptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MealCookedPromptRepository::class)]
#[ORM\Table(name: 'meal_cooked_prompt')]
#[ORM\UniqueConstraint(name: 'uniq_meal_cooked_prompt_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(name: 'idx_meal_cooked_prompt_status_date', columns: ['status', 'date'])]
#[ORM\Index(name: 'idx_meal_cooked_prompt_user_status', columns: ['user_id', 'status'])]
class MealCookedPrompt
{
    // ✅ Nouveau: le prompt existe mais le push n'a pas encore été envoyé
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SENT = 'SENT';
    public const STATUS_ANSWERED = 'ANSWERED';
    public const STATUS_EXPIRED = 'EXPIRED';

    public const ANSWER_YES = 'YES';
    public const ANSWER_NO = 'NO';

    public const CONTEXT_CRON_YESTERDAY_CHECK = 'cron_yesterday_check';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: MealPlan::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MealPlan $mealPlan = null;

    // Date du repas concerné (ex: hier)
    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $answer = null;

    // Date/heure d'envoi effectif de la notification
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $context = self::CONTEXT_CRON_YESTERDAY_CHECK;

    public function __construct(
        ?User $user = null,
        ?MealPlan $mealPlan = null,
        ?\DateTimeImmutable $date = null,
        string $context = self::CONTEXT_CRON_YESTERDAY_CHECK,
    ) {
        $this->user = $user;
        $this->mealPlan = $mealPlan;
        $this->date = $date;
        $this->context = $context;

        // ✅ par défaut: à notifier
        $this->status = self::STATUS_PENDING;
        $this->answer = null;
        $this->sentAt = null;
        $this->answeredAt = null;
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getMealPlan(): ?MealPlan { return $this->mealPlan; }
    public function setMealPlan(MealPlan $mealPlan): self { $this->mealPlan = $mealPlan; return $this; }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getAnswer(): ?string { return $this->answer; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getAnsweredAt(): ?\DateTimeImmutable { return $this->answeredAt; }

    public function getContext(): string { return $this->context; }
    public function setContext(string $context): self { $this->context = $context; return $this; }

    public function markPending(): self
    {
        $this->status = self::STATUS_PENDING;
        $this->sentAt = null;
        $this->answer = null;
        $this->answeredAt = null;
        return $this;
    }

    public function markSent(): self
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        return $this;
    }

    public function answerYes(): self
    {
        $this->status = self::STATUS_ANSWERED;
        $this->answer = self::ANSWER_YES;
        $this->answeredAt = new \DateTimeImmutable();
        return $this;
    }

    public function answerNo(): self
    {
        $this->status = self::STATUS_ANSWERED;
        $this->answer = self::ANSWER_NO;
        $this->answeredAt = new \DateTimeImmutable();
        return $this;
    }

    public function expire(): self
    {
        $this->status = self::STATUS_EXPIRED;
        return $this;
    }
}
