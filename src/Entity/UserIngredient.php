<?php

namespace App\Entity;

use App\Enum\Unit;
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

    /**
     * ✅ Unité du stock utilisateur (peut différer de l'unité "de base" de l'ingrédient)
     * Ex : 2 POT de yaourt, 1 BOITE de thon, 250 G de farine...
     */
    #[ORM\Column(enumType: Unit::class)]
    private Unit $unit = Unit::G;

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

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function getUnitValue(): string
    {
        return $this->unit->value;
    }

    public function getUnitLabel(): string
    {
        return match ($this->unit) {
            Unit::G => 'g',
            Unit::KG => 'kg',
            Unit::ML => 'ml',
            Unit::L => 'L',
            Unit::PIECE => 'pièce',
            Unit::POT => 'pot',
            Unit::BOITE => 'boîte',
            Unit::SACHET => 'sachet',
            Unit::TRANCHE => 'tranche',
            Unit::PAQUET => 'paquet',
        };
    }

    /**
     * Pratique pour l'UI (ex: "2,00 pots" / "250,00 g")
     * (Tu peux adapter le formatage FR si tu veux)
     */
    public function getQuantityWithUnitLabel(): string
    {
        // Affichage simple, sans imposer de format FR côté serveur
        return rtrim(rtrim($this->quantity, '0'), '.') . ' ' . $this->getUnitLabel();
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
