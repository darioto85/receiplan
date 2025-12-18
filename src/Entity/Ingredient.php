<?php

namespace App\Entity;

use App\Repository\IngredientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IngredientRepository::class)]
#[ORM\Table(name: 'ingredient')]
#[ORM\UniqueConstraint(name: 'uniq_ingredient_name_key', columns: ['name_key'])]
class Ingredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    // ✅ Clé normalisée pour anti-doublon (trim + lower + espaces + accents optionnels)
    #[ORM\Column(name: 'name_key', length: 255, unique: true)]
    private ?string $nameKey = null;

    #[ORM\Column(length: 255)]
    private ?string $unit = null;

    /** @var Collection<int, RecipeIngredient> */
    #[ORM\OneToMany(mappedBy: 'ingredient', targetEntity: RecipeIngredient::class, orphanRemoval: true)]
    private Collection $recipeIngredients;

    /** @var Collection<int, UserIngredient> */
    #[ORM\OneToMany(mappedBy: 'ingredient', targetEntity: UserIngredient::class, orphanRemoval: true)]
    private Collection $userIngredients;

    public function __construct()
    {
        $this->recipeIngredients = new ArrayCollection();
        $this->userIngredients = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->nameKey = self::normalizeName($name);

        return $this;
    }

    public function getNameKey(): ?string
    {
        return $this->nameKey;
    }

    public function getUnit(): ?string { return $this->unit; }

    public function setUnit(string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    /**
     * Normalise un nom d'ingrédient pour éviter les doublons :
     * - trim
     * - espaces multiples -> un seul
     * - minuscule (UTF-8)
     * - suppression des accents si l'extension intl est dispo
     */
    public static function normalizeName(string $name): string
    {
        $name = trim($name);

        // espaces multiples -> un seul
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        // lowercase utf-8
        $name = mb_strtolower($name);

        // supprime les accents (optionnel, mais très pratique)
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
                // ici OK si un jour tu détaches (rare). Sinon tu peux enlever cette ligne.
                $userIngredient->setIngredient(null);
            }
        }

        return $this;
    }
}
