<?php

namespace App\Entity;

use App\Enum\Unit;
use App\Repository\RecipeIngredientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecipeIngredientRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_recipe_ingredient', columns: ['recipe_id', 'ingredient_id'])]
class RecipeIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ✅ souvent mieux en decimal pour 0.5, 1.25, etc.
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $quantity = null;

    /**
     * ✅ Unité côté recette (peut différer du stock utilisateur)
     * Ex : "200 g" de farine, "1 pot" de yaourt, "2 pièces" d'oeufs...
     */
    #[ORM\Column(enumType: Unit::class)]
    private Unit $unit = Unit::G;

    #[ORM\ManyToOne(inversedBy: 'recipeIngredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Recipe $recipe = null;

    #[ORM\ManyToOne(inversedBy: 'recipeIngredients')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ingredient $ingredient = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Helpers optionnels (pratiques pour le métier/UI)
     */
    public function getQuantityFloat(): float
    {
        return (float) str_replace(',', '.', (string) ($this->quantity ?? '0.00'));
    }

    public function setQuantityFloat(float $quantity): static
    {
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

    public function getQuantityWithUnitLabel(): string
    {
        $q = $this->quantity ?? '0.00';
        return rtrim(rtrim($q, '0'), '.') . ' ' . $this->getUnitLabel();
    }

    public function getRecipe(): ?Recipe
    {
        return $this->recipe;
    }

    public function setRecipe(?Recipe $recipe): static
    {
        $this->recipe = $recipe;
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
