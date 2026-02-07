<?php

namespace App\Entity;

use App\Repository\RecipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecipeRepository::class)]
class Recipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'recipes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** @var Collection<int, RecipeIngredient> */
    #[ORM\OneToMany(mappedBy: 'recipe', targetEntity: RecipeIngredient::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $recipeIngredients;

    /** @var Collection<int, RecipeStep> */
    #[ORM\OneToMany(mappedBy: 'recipe', targetEntity: RecipeStep::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $recipeSteps;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nameKey = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $imgGenerated = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imgGeneratedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $favorite = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $draft = false;

    public function __construct()
    {
        $this->recipeIngredients = new ArrayCollection();
        $this->recipeSteps = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }

    public function setName(string $name): static
    {
        $this->name = $name;

        // fallback si pas calculé en amont (form / IA / import)
        if ($this->nameKey === null || $this->nameKey === '') {
            $this->nameKey = self::normalizeNameKey($name);
        }

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

    public function isFavorite(): bool
    {
        return $this->favorite;
    }

    public function setFavorite(bool $favorite): static
    {
        $this->favorite = $favorite;
        return $this;
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    public function setDraft(bool $draft): static
    {
        $this->draft = $draft;
        return $this;
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
            $recipeIngredient->setRecipe($this);
        }

        return $this;
    }

    public function removeRecipeIngredient(RecipeIngredient $recipeIngredient): static
    {
        if ($this->recipeIngredients->removeElement($recipeIngredient)) {
            if ($recipeIngredient->getRecipe() === $this) {
                $recipeIngredient->setRecipe(null);
            }
        }

        return $this;
    }

    /** @return Collection<int, RecipeStep> */
    public function getRecipeSteps(): Collection
    {
        return $this->recipeSteps;
    }

    public function addRecipeStep(RecipeStep $step): static
    {
        if (!$this->recipeSteps->contains($step)) {
            $this->recipeSteps->add($step);
            $step->setRecipe($this);
        }

        return $this;
    }

    public function removeRecipeStep(RecipeStep $step): static
    {
        if ($this->recipeSteps->removeElement($step)) {
            if ($step->getRecipe() === $this) {
                $step->setRecipe(null);
            }
        }

        return $this;
    }

    public function getNameKey(): ?string
    {
        return $this->nameKey;
    }

    public function setNameKey(?string $nameKey): self
    {
        $this->nameKey = $nameKey;
        return $this;
    }

    public static function normalizeNameKey(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_strtolower($name);

        // accents
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) {
                $name = $tr->transliterate($name);
            }
        }

        // espaces -> tirets
        $name = preg_replace('/\s+/u', '-', $name) ?? $name;

        return $name;
    }
}
