<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    /** @var Collection<int, Recipe> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Recipe::class, orphanRemoval: true)]
    private Collection $recipes;

    /** @var Collection<int, Ingredient> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Ingredient::class, orphanRemoval: true)]
    private Collection $ingredients;

    /** @var Collection<int, UserIngredient> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserIngredient::class, orphanRemoval: true)]
    private Collection $userIngredients;

    public function __construct()
    {
        $this->recipes = new ArrayCollection();
        $this->ingredients = new ArrayCollection();
        $this->userIngredients = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /** @return Collection<int, Recipe> */
    public function getRecipes(): Collection
    {
        return $this->recipes;
    }

    public function addRecipe(Recipe $recipe): static
    {
        if (!$this->recipes->contains($recipe)) {
            $this->recipes->add($recipe);
            $recipe->setUser($this);
        }

        return $this;
    }

    public function removeRecipe(Recipe $recipe): static
    {
        $this->recipes->removeElement($recipe);
        return $this;
    }

    /** @return Collection<int, Ingredient> */
    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    public function addIngredient(Ingredient $ingredient): static
    {
        if (!$this->ingredients->contains($ingredient)) {
            $this->ingredients->add($ingredient);
            $ingredient->setUser($this);
        }

        return $this;
    }

    public function removeIngredient(Ingredient $ingredient): static
    {
        $this->ingredients->removeElement($ingredient);
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
            $userIngredient->setUser($this);
        }

        return $this;
    }

    public function removeUserIngredient(UserIngredient $userIngredient): static
    {
        $this->userIngredients->removeElement($userIngredient);
        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);

        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // noop
    }
}
