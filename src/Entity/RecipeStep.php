<?php

namespace App\Entity;

use App\Repository\RecipeStepRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecipeStepRepository::class)]
#[ORM\Index(name: 'idx_recipe_step_recipe_position', columns: ['recipe_id', 'position'])]
class RecipeStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Texte de l'étape
    #[ORM\Column(type: 'text')]
    private ?string $content = null;

    // Ordre d'affichage (1,2,3...)
    #[ORM\Column(options: ['default' => 1])]
    private int $position = 1;

    #[ORM\ManyToOne(inversedBy: 'recipeSteps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Recipe $recipe = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
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
}
