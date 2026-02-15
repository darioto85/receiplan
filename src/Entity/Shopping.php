<?php

namespace App\Entity;

use App\Enum\Unit;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shopping')]
#[ORM\UniqueConstraint(name: 'uniq_user_ingredient', columns: ['user_id', 'ingredient_id'])]
class Shopping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ingredient $ingredient = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $quantity = '0.00';

    #[ORM\Column(length: 16)]
    private string $source = 'manual';

    #[ORM\Column(type: 'boolean')]
    private bool $checked = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    /**
     * ✅ Unité portée par la ligne de shopping (source de vérité pour l'achat)
     * (permet unités par recette / par ajout manuel)
     */
    #[ORM\Column(enumType: Unit::class)]
    private Unit $unit = Unit::G;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isAuto(): bool
    {
        return $this->source === 'auto';
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

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function setIngredient(Ingredient $ingredient): self
    {
        $this->ingredient = $ingredient;
        return $this;
    }

    public function getQuantity(): float
    {
        return (float) $this->quantity;
    }

    public function setQuantity(float $qty): self
    {
        $this->quantity = number_format(max(0, $qty), 2, '.', '');
        return $this;
    }

    public function addQuantity(float $delta): self
    {
        return $this->setQuantity($this->getQuantity() + $delta);
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        // sécurité soft
        $allowed = ['manual', 'auto'];
        $this->source = in_array($source, $allowed, true) ? $source : 'manual';
        return $this;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function setChecked(bool $checked): self
    {
        $this->checked = $checked;
        $this->checkedAt = $checked ? new \DateTimeImmutable() : null;
        return $this;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): self
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
}
