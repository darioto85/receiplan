<?php

namespace App\Entity;

use App\Repository\UserIngredientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserIngredientRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_user_ingredient', columns: ['user_id', 'ingredient_id'])]
class UserIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ✅ souvent mieux en decimal pour 0.5, 1.25, etc.
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $quantity = null;

    #[ORM\ManyToOne(inversedBy: 'userIngredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'userIngredients')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ingredient $ingredient = null;

    public function getId(): ?int { return $this->id; }

    public function getQuantity(): ?string { return $this->quantity; }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUser(): ?User { return $this->user; }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getIngredient(): ?Ingredient { return $this->ingredient; }

    public function setIngredient(?Ingredient $ingredient): static
    {
        $this->ingredient = $ingredient;
        return $this;
    }
}
