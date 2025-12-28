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

    // Decimal stocké en string (standard Doctrine) + valeur par défaut
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => 0])]
    private string $quantity = '0.00';

    #[ORM\ManyToOne(inversedBy: 'userIngredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'userIngredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ingredient $ingredient = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Quantité en string (decimal doctrine)
     * Exemple: "1.00" (1 pot) / "250.00" (250 g)
     */
    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Helpers optionnels (pratiques pour le métier/UI),
     * sans changer le stockage en BDD.
     */
    public function getQuantityFloat(): float
    {
        return (float) str_replace(',', '.', $this->quantity);
    }

    public function setQuantityFloat(float $quantity): static
    {
        // 2 décimales comme ton scale=2
        $this->quantity = number_format($quantity, 2, '.', '');
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(?Ingredient $ingredient): static
    {
        $this->ingredient = $ingredient;
        return $this;
    }
}
