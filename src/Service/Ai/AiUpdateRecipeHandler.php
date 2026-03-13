<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\Unit;
use App\Repository\RecipeRepository;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiUpdateRecipeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecipeRepository $recipeRepository,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function handle(User $user, array $draft): array
    {
        $warnings = [];

        $targetName = $this->extractTargetRecipeName($draft);
        if ($targetName === '') {
            throw new \RuntimeException('update_recipe_missing_target');
        }

        $candidates = $this->findRecipesForUserByNameLike($user, $targetName, 10);

        if (count($candidates) === 0) {
            throw new \RuntimeException('update_recipe_not_found');
        }

        $recipe = $this->pickBestRecipeCandidate($candidates, $targetName);
        if (!$recipe) {
            $names = array_map(fn(Recipe $r) => (string) $r->getName(), $candidates);
            throw new \RuntimeException('update_recipe_ambiguous: ' . implode(' | ', $names));
        }

        $patch = $draft['patch'] ?? [];
        if (!is_array($patch)) {
            $patch = [];
        }

        if (
            (!isset($patch['update_ingredients']) || !is_array($patch['update_ingredients']))
            && isset($draft['recipe'])
            && is_array($draft['recipe'])
        ) {
            $recipeIngredients = $draft['recipe']['ingredients'] ?? null;
            if (is_array($recipeIngredients) && $recipeIngredients !== []) {
                $patch['update_ingredients'] = $recipeIngredients;
            }
        }

        $applied = [
            'renamed' => false,
            'added' => 0,
            'removed' => 0,
            'updated' => 0,
        ];

        $newName = $patch['new_name'] ?? null;
        if (is_string($newName)) {
            $newName = trim($newName);
            if ($newName !== '' && $newName !== $recipe->getName()) {
                $recipe->setName($newName);
                $applied['renamed'] = true;
            }
        }

        /** @var array<int, RecipeIngredient> $byIngredientId */
        $byIngredientId = [];
        foreach ($recipe->getRecipeIngredients() as $ri) {
            $iid = $ri->getIngredient()?->getId();
            if ($iid) {
                $byIngredientId[$iid] = $ri;
            }
        }

        // Remove explicite
        $remove = $patch['remove_ingredients'] ?? [];
        if (is_array($remove)) {
            foreach ($remove as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? $row['name_raw'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $ingredient = $this->resolveIngredient($user, $name, $row['unit'] ?? null);
                if (!$ingredient) {
                    $warnings[] = 'Ingrédient introuvable à retirer : ' . $name;
                    continue;
                }

                $iid = $ingredient->getId();
                if (!$iid || !isset($byIngredientId[$iid])) {
                    $warnings[] = 'Ingrédient déjà absent : ' . $ingredient->getName();
                    continue;
                }

                $ri = $byIngredientId[$iid];
                $recipe->removeRecipeIngredient($ri);
                $this->em->remove($ri);

                unset($byIngredientId[$iid]);
                $applied['removed']++;
            }
        }

        // Update / replace
        $updates = $patch['update_ingredients'] ?? [];
        if (is_array($updates)) {
            foreach ($updates as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? $row['name_raw'] ?? ''));
                $replaceFrom = trim((string) ($row['replace_from'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $qty = $this->normalizeQuantityToDecimalString(
                    $row['quantity'] ?? null,
                    $row['quantity_raw'] ?? null
                );
                $unit = $this->normalizeUnit($row['unit'] ?? null);

                // Cas remplacement : replace_from = source, name = cible
                if ($replaceFrom !== '') {
                    $sourceIngredient = $this->resolveIngredientAlreadyInRecipe($user, $recipe, $replaceFrom, $warnings);
                    if (!$sourceIngredient) {
                        $warnings[] = 'Ingrédient source introuvable dans la recette : ' . $replaceFrom;
                        continue;
                    }

                    $sourceIid = $sourceIngredient->getId();
                    if (!$sourceIid || !isset($byIngredientId[$sourceIid])) {
                        $warnings[] = 'Ingrédient source absent de la recette : ' . $replaceFrom;
                        continue;
                    }

                    $targetIngredient = $this->resolveIngredient($user, $name, $row['unit'] ?? null);
                    if (!$targetIngredient) {
                        $warnings[] = 'Ingrédient de remplacement introuvable : ' . $name;
                        continue;
                    }

                    $sourceRi = $byIngredientId[$sourceIid];

                    if ($qty === null) {
                        $warnings[] = 'Quantité manquante pour le remplacement de ' . $replaceFrom . ' par ' . $name;
                        continue;
                    }

                    if ($unit === null) {
                        $warnings[] = 'Unité manquante pour le remplacement de ' . $replaceFrom . ' par ' . $name;
                        continue;
                    }

                    $recipe->removeRecipeIngredient($sourceRi);
                    $this->em->remove($sourceRi);
                    unset($byIngredientId[$sourceIid]);
                    $applied['removed']++;

                    $targetIid = $targetIngredient->getId();
                    if ($targetIid && isset($byIngredientId[$targetIid])) {
                        $existing = $byIngredientId[$targetIid];
                        $existing->setQuantity($qty);
                        $existing->setUnit($unit);
                        $applied['updated']++;
                    } else {
                        $newRi = new RecipeIngredient();
                        $newRi->setRecipe($recipe);
                        $newRi->setIngredient($targetIngredient);
                        $newRi->setQuantity($qty);
                        $newRi->setUnit($unit);

                        $recipe->addRecipeIngredient($newRi);
                        $this->em->persist($newRi);

                        if ($targetIid) {
                            $byIngredientId[$targetIid] = $newRi;
                        }

                        $applied['added']++;
                    }

                    continue;
                }

                // Update normal
                $ingredient = $this->resolveIngredient($user, $name, $row['unit'] ?? null);
                if (!$ingredient) {
                    $warnings[] = 'Ingrédient introuvable à modifier : ' . $name;
                    continue;
                }

                $iid = $ingredient->getId();
                if (!$iid || !isset($byIngredientId[$iid])) {
                    $warnings[] = 'Ingrédient non présent dans la recette : ' . $ingredient->getName();
                    continue;
                }

                if ($qty === null && $unit === null) {
                    $warnings[] = 'Modification vide pour ' . $ingredient->getName();
                    continue;
                }

                $ri = $byIngredientId[$iid];

                if ($qty !== null) {
                    $ri->setQuantity($qty);
                }

                if ($unit !== null) {
                    $ri->setUnit($unit);
                }

                $applied['updated']++;
            }
        }

        // Add explicite
        $adds = $patch['add_ingredients'] ?? [];
        if (is_array($adds)) {
            foreach ($adds as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? $row['name_raw'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $ingredient = $this->resolveIngredient($user, $name, $row['unit'] ?? null);
                if (!$ingredient) {
                    $warnings[] = 'Ingrédient introuvable à ajouter : ' . $name;
                    continue;
                }

                $iid = $ingredient->getId();
                $qty = $this->normalizeQuantityToDecimalString(
                    $row['quantity'] ?? null,
                    $row['quantity_raw'] ?? null
                );
                $unit = $this->normalizeUnit($row['unit'] ?? null);

                if ($qty === null) {
                    $qty = '1.00';
                    $warnings[] = 'Quantité manquante pour ' . $ingredient->getName() . ' → mise à 1.00';
                }

                if ($unit === null) {
                    $warnings[] = 'Unité manquante pour ' . $ingredient->getName() . ' → unité conservée ou par défaut';
                }

                if ($iid && isset($byIngredientId[$iid])) {
                    $existing = $byIngredientId[$iid];

                    $newQty = $existing->getQuantityFloat() + (float) $qty;
                    $existing->setQuantityFloat($newQty);

                    if ($unit !== null) {
                        $existing->setUnit($unit);
                    }

                    $applied['updated']++;
                    continue;
                }

                $ri = new RecipeIngredient();
                $ri->setRecipe($recipe);
                $ri->setIngredient($ingredient);
                $ri->setQuantity($qty);

                if ($unit !== null) {
                    $ri->setUnit($unit);
                }

                $recipe->addRecipeIngredient($ri);
                $this->em->persist($ri);

                if ($iid) {
                    $byIngredientId[$iid] = $ri;
                }

                $applied['added']++;
            }
        }

        $this->em->persist($recipe);
        $this->em->flush();

        if (
            $applied['renamed'] === false
            && $applied['added'] === 0
            && $applied['removed'] === 0
            && $applied['updated'] === 0
        ) {
            throw new \RuntimeException(
                count($warnings) > 0 ? implode(' | ', $warnings) : 'update_recipe_no_change_applied'
            );
        }

        return [
            'recipe' => [
                'id' => (int) $recipe->getId(),
                'name' => (string) $recipe->getName(),
            ],
            'applied' => $applied,
            'warnings' => $warnings,
        ];
    }

    private function extractTargetRecipeName(array $draft): string
    {
        $candidates = [
            $draft['target']['recipe_name_raw'] ?? null,
            $draft['target']['recipe_name'] ?? null,
            $draft['recipe_name'] ?? null,
            is_array($draft['recipe'] ?? null) ? ($draft['recipe']['name'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function normalizeUnit(mixed $unit): ?Unit
    {
        if (!is_string($unit) || trim($unit) === '') {
            return null;
        }

        $unit = trim($unit);

        return match ($unit) {
            'g' => Unit::G,
            'kg' => Unit::KG,
            'ml' => Unit::ML,
            'l' => Unit::L,
            'piece' => Unit::PIECE,
            'pot' => Unit::POT,
            'boite' => Unit::BOITE,
            'sachet' => Unit::SACHET,
            'tranche' => Unit::TRANCHE,
            'paquet' => Unit::PAQUET,
            default => null,
        };
    }

    private function resolveIngredient(User $user, string $name, mixed $unitValue = null): ?Ingredient
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $unit = is_string($unitValue) ? trim($unitValue) : null;
        $unit = $unit !== '' ? $unit : null;

        return $this->ingredientResolver->resolveOrCreate($user, $name, $unit);
    }

    private function resolveIngredientAlreadyInRecipe(
        User $user,
        Recipe $recipe,
        string $name,
        array &$warnings
    ): ?Ingredient {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $targetKey = Ingredient::normalizeName($name);

        foreach ($recipe->getRecipeIngredients() as $ri) {
            $ingredient = $ri->getIngredient();
            if (!$ingredient) {
                continue;
            }

            $ingredientKey = Ingredient::normalizeName((string) $ingredient->getName());
            if ($ingredientKey === $targetKey) {
                return $ingredient;
            }
        }

        return $this->resolveIngredient($user, $name);
    }

    /**
     * @return Recipe[]
     */
    private function findRecipesForUserByNameLike(User $user, string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $this->recipeRepository->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->andWhere('r.name LIKE :q OR r.nameKey LIKE :qk')
            ->setParameter('q', '%' . $q . '%')
            ->setParameter('qk', '%' . Recipe::normalizeNameKey($q) . '%')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function pickBestRecipeCandidate(array $candidates, string $targetName): ?Recipe
    {
        $targetName = trim($targetName);
        $targetLower = mb_strtolower($targetName);
        $targetKey = Recipe::normalizeNameKey($targetName);

        foreach ($candidates as $r) {
            $n = trim((string) $r->getName());
            if (mb_strtolower($n) === $targetLower) {
                return $r;
            }
        }

        foreach ($candidates as $r) {
            $k = (string) ($r->getNameKey() ?? '');
            if ($k !== '' && $k === $targetKey) {
                return $r;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function normalizeQuantityToDecimalString(mixed $quantity, mixed $quantityRaw): ?string
    {
        if (is_numeric($quantity)) {
            $f = (float) $quantity;
            if ($f <= 0) {
                return null;
            }

            return number_format($f, 2, '.', '');
        }

        if (is_string($quantityRaw)) {
            $t = trim($quantityRaw);
            if ($t === '') {
                return null;
            }

            $t = str_replace(',', '.', $t);
            if (preg_match('/([0-9]+(\.[0-9]+)?)/', $t, $m)) {
                $f = (float) $m[1];
                if ($f <= 0) {
                    return null;
                }

                return number_format($f, 2, '.', '');
            }
        }

        return null;
    }
}