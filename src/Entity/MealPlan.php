<?php

namespace App\Entity;

use App\Repository\MealPlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MealPlanRepository::class)]
#[ORM\Table(name: 'meal_plan')]
#[ORM\Index(name: 'idx_meal_plan_user_date', columns: ['user_id', 'date'])]
#[ORM\Index(name: 'idx_meal_plan_user_validated', columns: ['user_id', 'validated'])]
#[ORM\UniqueConstraint(name: 'uniq_meal_plan_user_recipe_date', columns: ['user_id', 'recipe_id', 'date'])]
class MealPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Recipe::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Recipe $recipe = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: 'boolean')]
    private bool $validated = false;

    public function __construct(?User $user = null, ?Recipe $recipe = null, ?\DateTimeImmutable $date = null)
    {
        $this->user = $user;
        $this->recipe = $recipe;
        $this->date = $date;
        $this->validated = false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(Recipe $recipe): self
    {
        $this->recipe = $recipe;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function isValidated(): bool
    {
        return $this->validated;
    }

    public function setValidated(bool $validated): self
    {
        $this->validated = $validated;
        return $this;
    }

    public function validate(): self
    {
        $this->validated = true;
        return $this;
    }

    public function unvalidate(): self
    {
        $this->validated = false;
        return $this;
    }
}
