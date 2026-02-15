<?php

namespace App\Entity;

use App\Enum\CategoryEnum;
use App\Enum\Unit;
use App\Repository\IngredientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IngredientRepository::class)]
#[ORM\Table(name: 'ingredient')]
#[ORM\UniqueConstraint(
    name: 'uniq_ingredient_user_name_key',
    columns: ['user_id', 'name_key']
)]
class Ingredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // ✅ Clé normalisée pour anti-doublon par utilisateur
    #[ORM\Column(name: 'name_key', length: 255)]
    private ?string $nameKey = null;

    /**
     * ✅ Catégorie d'ingrédient (Enum stockée en string)
     * Ex : fruit, legume, viande, poisson...
     */
    #[ORM\Column(enumType: CategoryEnum::class, nullable: true)]
    private ?CategoryEnum $category = null;

    /**
     * ✅ Unité de base / par défaut de l'ingrédient (pivot)
     *
     * IMPORTANT :
     * - Le stock utilisateur (UserIngredient) possède maintenant sa propre unité.
     * - Les quantités en recette (RecipeIngredient) possèdent aussi leur propre unité.
     *
     * Ce champ sert donc d'unité "par défaut" (ex: pour pré-remplir un formulaire,
     * ou comme pivot futur pour conversions), et ne doit PAS être considéré comme
     * l'unité unique utilisée partout.
     */
    #[ORM\Column(enumType: Unit::class)]
    private Unit $unit = Unit::G;

    // 🔐 Propriétaire de l’ingrédient (nullable => ingrédient global)
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'ingredients')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** @var Collection<int, RecipeIngredient> */
    #[ORM\OneToMany(mappedBy: 'ingredient', targetEntity: RecipeIngredient::class, orphanRemoval: true)]
    private Collection $recipeIngredients;

    /** @var Collection<int, UserIngredient> */
    #[ORM\OneToMany(mappedBy: 'ingredient', targetEntity: UserIngredient::class, orphanRemoval: true)]
    private Collection $userIngredients;

    #[ORM\Column(options: ['default' => false])]
    private bool $imgGenerated = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imgGeneratedAt = null;

    public function __construct()
    {
        $this->recipeIngredients = new ArrayCollection();
        $this->userIngredients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        // ✅ Ne pas écraser si la clé a déjà été calculée en amont (Form/IA/Ticket/Quick-create)
        if ($this->nameKey === null || $this->nameKey === '') {
            // fallback legacy : tu peux laisser temporairement
            $this->nameKey = self::normalizeName($name);
        }

        return $this;
    }

    public function getNameKey(): ?string
    {
        return $this->nameKey;
    }

    public function setNameKey(string $nameKey): static
    {
        $this->nameKey = $nameKey;
        return $this;
    }

    public function getCategory(): ?CategoryEnum
    {
        return $this->category;
    }

    public function setCategory(?CategoryEnum $category): static
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Optionnel: si tu veux facilement renvoyer la valeur string côté JSON
     */
    public function getCategoryValue(): ?string
    {
        return $this->category?->value;
    }

    /**
     * Unité de base (alias "unit" pour compat)
     */
    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    /**
     * ✅ Alias explicites : "base unit"
     * (pour rendre le code plus clair sans casser l’existant)
     */
    public function getBaseUnit(): Unit
    {
        return $this->unit;
    }

    public function setBaseUnit(Unit $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function getUnitValue(): string
    {
        return $this->unit->value;
    }

    public function getBaseUnitValue(): string
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

    public function getBaseUnitLabel(): string
    {
        return $this->getUnitLabel();
    }

    /**
     * Tolérant pour IA/ticket/import :
     * - g, gramme(s), gr
     * - kg, kilo(s)
     * - ml, millilitre(s)
     * - l, litre(s)
     * - pièce(s), pcs, pc, pce
     * - boîte(s), boite(s), can
     * - sachet(s)
     * - tranche(s)
     * - paquet(s), pack
     *
     * Ne throw pas : si inconnu -> conserve l'unité actuelle.
     */
    public function setUnitFromString(string $unit): static
    {
        $raw = trim(mb_strtolower($unit));

        if ($raw === '') {
            return $this;
        }

        $map = [
            // grammes
            'g' => 'g',
            'gr' => 'g',
            'gramme' => 'g',
            'grammes' => 'g',

            // kilos
            'kg' => 'kg',
            'kilo' => 'kg',
            'kilos' => 'kg',
            'kilogramme' => 'kg',
            'kilogrammes' => 'kg',

            // millilitres
            'ml' => 'ml',
            'millilitre' => 'ml',
            'millilitres' => 'ml',

            // litres
            'l' => 'l',
            'litre' => 'l',
            'litres' => 'l',

            // pièces
            'piece' => 'piece',
            'pièce' => 'piece',
            'pieces' => 'piece',
            'pièces' => 'piece',
            'pcs' => 'piece',
            'pc' => 'piece',
            'pce' => 'piece',

            // pot
            'pot' => 'pot',
            'pots' => 'pot',

            // boîte
            'boite' => 'boite',
            'boîtes' => 'boite',
            'boite(s)' => 'boite',
            'boîte' => 'boite',
            'boites' => 'boite',
            'can' => 'boite',

            // sachet
            'sachet' => 'sachet',
            'sachets' => 'sachet',

            // tranche
            'tranche' => 'tranche',
            'tranches' => 'tranche',

            // paquet
            'paquet' => 'paquet',
            'paquets' => 'paquet',
            'pack' => 'paquet',
        ];

        $normalized = $map[$raw] ?? $raw;

        $parsed = Unit::tryFrom($normalized);
        if ($parsed !== null) {
            $this->unit = $parsed;
        }

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    // ✅ accepte null : null => ingrédient global
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function isImgGenerated(): bool
    {
        return $this->imgGenerated;
    }

    public function setImgGenerated(bool $imgGenerated): static
    {
        $this->imgGenerated = $imgGenerated;
        return $this;
    }

    public function getImgGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->imgGeneratedAt;
    }

    public function setImgGeneratedAt(?\DateTimeImmutable $at): static
    {
        $this->imgGeneratedAt = $at;
        return $this;
    }

    public static function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_strtolower($name);

        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) {
                $name = $tr->transliterate($name);
            }
        }

        return $name;
    }

    /** @return Collection<int, RecipeIngredient> */
    public function getRecipeIngredients(): Collection
    {
        return $this->recipeIngredients;
    }

    public function addRecipeIngredient(RecipeIngredient $recipeIngredient): static
    {
        if (!$this->recipeIngredients->contains($recipeIngredient)) {
            $this->recipeIngredients->add($recipeIngredient);
            $recipeIngredient->setIngredient($this);
        }

        return $this;
    }

    public function removeRecipeIngredient(RecipeIngredient $recipeIngredient): static
    {
        if ($this->recipeIngredients->removeElement($recipeIngredient)) {
            if ($recipeIngredient->getIngredient() === $this) {
                $recipeIngredient->setIngredient(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, UserIngredient> */
    public function getUserIngredients(): Collection
    {
        return $this->userIngredients;
    }

    public function addUserIngredient(UserIngredient $userIngredient): static
    {
        if (!$this->userIngredients->contains($userIngredient)) {
            $this->userIngredients->add($userIngredient);
            $userIngredient->setIngredient($this);
        }

        return $this;
    }

    public function removeUserIngredient(UserIngredient $userIngredient): static
    {
        if ($this->userIngredients->removeElement($userIngredient)) {
            if ($userIngredient->getIngredient() === $this) {
                $userIngredient->setIngredient(null);
            }
        }

        return $this;
    }
}
