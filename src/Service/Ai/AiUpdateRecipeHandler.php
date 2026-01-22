<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Repository\IngredientRepository;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AiUpdateRecipeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecipeRepository $recipeRepository,
        private readonly IngredientRepository $ingredientRepository,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function handle(User $user, array $draft): array
    {
        $warnings = [];

        $targetName = $draft['target']['recipe_name_raw'] ?? null;
        $targetName = is_string($targetName) ? trim($targetName) : '';
        if ($targetName === '') {
            throw new \RuntimeException('update_recipe_missing_target');
        }

        $candidates = $this->findRecipesForUserByNameLike($user, $targetName, 10);

        if (count($candidates) === 0) {
            throw new \RuntimeException('update_recipe_not_found');
        }

        $recipe = $this->pickBestRecipeCandidate($candidates, $targetName);
        if (!$recipe) {
            $names = array_map(fn(Recipe $r) => (string)$r->getName(), $candidates);
            throw new \RuntimeException('update_recipe_ambiguous: ' . implode(' | ', $names));
        }

        $patch = $draft['patch'] ?? [];
        if (!is_array($patch)) $patch = [];

        $applied = [
            'renamed' => false,
            'added' => 0,
            'removed' => 0,
            'updated' => 0,
        ];

        // Rename
        $newName = $patch['new_name'] ?? null;
        if (is_string($newName)) {
            $newName = trim($newName);
            if ($newName !== '' && $newName !== $recipe->getName()) {
                // setName() met aussi nameKey si vide
                $recipe->setName($newName);
                $applied['renamed'] = true;
            }
        }

        // Index existants par ingredient_id
        /** @var array<int, RecipeIngredient> $byIngredientId */
        $byIngredientId = [];
        foreach ($recipe->getRecipeIngredients() as $ri) {
            $iid = $ri->getIngredient()?->getId();
            if ($iid) $byIngredientId[$iid] = $ri;
        }

        // Remove
        $remove = $patch['remove_ingredients'] ?? [];
        if (is_array($remove)) {
            foreach ($remove as $row) {
                if (!is_array($row)) continue;

                $name = trim((string)($row['name'] ?? $row['name_raw'] ?? ''));
                if ($name === '') continue;

                $ingredient = $this->resolveVisibleIngredient($user, $name, $warnings);
                if (!$ingredient) {
                    $warnings[] = "Ingrédient introuvable à retirer : ".$name;
                    continue;
                }

                $iid = $ingredient->getId();
                if (!$iid || !isset($byIngredientId[$iid])) {
                    $warnings[] = "Ingrédient déjà absent : ".$ingredient->getName();
                    continue;
                }

                $ri = $byIngredientId[$iid];
                $recipe->removeRecipeIngredient($ri);
                $this->em->remove($ri);

                unset($byIngredientId[$iid]);
                $applied['removed']++;
            }
        }

        // Update (quantity uniquement)
        $updates = $patch['update_ingredients'] ?? [];
        if (is_array($updates)) {
            foreach ($updates as $row) {
                if (!is_array($row)) continue;

                $name = trim((string)($row['name'] ?? $row['name_raw'] ?? ''));
                if ($name === '') continue;

                $ingredient = $this->resolveVisibleIngredient($user, $name, $warnings);
                if (!$ingredient) {
                    $warnings[] = "Ingrédient introuvable à modifier : ".$name;
                    continue;
                }

                $iid = $ingredient->getId();
                if (!$iid || !isset($byIngredientId[$iid])) {
                    $warnings[] = "Ingrédient non présent dans la recette : ".$ingredient->getName();
                    continue;
                }

                $qty = $this->normalizeQuantityToDecimalString($row['quantity'] ?? null, $row['quantity_raw'] ?? null);
                if ($qty === null) {
                    $warnings[] = "Quantité invalide pour ".$ingredient->getName();
                    continue;
                }

                $byIngredientId[$iid]->setQuantity($qty);
                $applied['updated']++;
            }
        }

        // Add
        $adds = $patch['add_ingredients'] ?? [];
        if (is_array($adds)) {
            foreach ($adds as $row) {
                if (!is_array($row)) continue;

                $name = trim((string)($row['name'] ?? $row['name_raw'] ?? ''));
                if ($name === '') continue;

                $ingredient = $this->resolveVisibleIngredient($user, $name, $warnings);
                if (!$ingredient) {
                    $warnings[] = "Ingrédient introuvable à ajouter : ".$name;
                    continue;
                }

                $iid = $ingredient->getId();
                if ($iid && isset($byIngredientId[$iid])) {
                    // Déjà présent : si qty fournie => overwrite, sinon warning
                    $qty = $this->normalizeQuantityToDecimalString($row['quantity'] ?? null, $row['quantity_raw'] ?? null);
                    if ($qty !== null) {
                        $byIngredientId[$iid]->setQuantity($qty);
                        $applied['updated']++;
                    } else {
                        $warnings[] = "Ingrédient déjà présent : ".$ingredient->getName();
                    }
                    continue;
                }

                $qty = $this->normalizeQuantityToDecimalString($row['quantity'] ?? null, $row['quantity_raw'] ?? null);
                if ($qty === null) {
                    $qty = '1.00';
                    $warnings[] = "Quantité manquante pour ".$ingredient->getName()." → mise à 1.00";
                }

                $ri = new RecipeIngredient();
                $ri->setRecipe($recipe);
                $ri->setIngredient($ingredient);
                $ri->setQuantity($qty);

                $recipe->addRecipeIngredient($ri);
                $this->em->persist($ri);

                $byIngredientId[(int)$iid] = $ri;
                $applied['added']++;
            }
        }

        $this->em->persist($recipe);
        $this->em->flush();

        return [
            'recipe' => [
                'id' => (int)$recipe->getId(),
                'name' => (string)$recipe->getName(),
            ],
            'applied' => $applied,
            'warnings' => $warnings,
        ];
    }

    private function resolveVisibleIngredient(User $user, string $name, array &$warnings): ?Ingredient
    {
        $name = trim($name);
        if ($name === '') return null;

        // Ingredient.nameKey est basé sur Ingredient::normalizeName()
        $nameKey = Ingredient::normalizeName($name);

        $ing = $this->ingredientRepository->findOneVisibleByNameKey($user, $nameKey);
        if ($ing instanceof Ingredient) return $ing;

        $res = $this->ingredientRepository->searchVisibleToUser($user, $name, 3);
        if (count($res) === 1) {
            $warnings[] = "Ingrédient approx. : « $name » → « ".$res[0]->getName()." »";
            return $res[0];
        }

        if (count($res) > 1) {
            $warnings[] = "Ingrédient ambigu : « $name » (plusieurs résultats)";
        }

        return null;
    }

    /**
     * @return Recipe[]
     */
    private function findRecipesForUserByNameLike(User $user, string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') return [];

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
            $n = trim((string)$r->getName());
            if (mb_strtolower($n) === $targetLower) return $r;
        }

        foreach ($candidates as $r) {
            $k = (string)($r->getNameKey() ?? '');
            if ($k !== '' && $k === $targetKey) return $r;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function normalizeQuantityToDecimalString(mixed $quantity, mixed $quantityRaw): ?string
    {
        if (is_numeric($quantity)) {
            $f = (float)$quantity;
            if ($f <= 0) return null;
            return number_format($f, 2, '.', '');
        }

        if (is_string($quantityRaw)) {
            $t = trim($quantityRaw);
            if ($t === '') return null;

            $t = str_replace(',', '.', $t);
            if (preg_match('/([0-9]+(\.[0-9]+)?)/', $t, $m)) {
                $f = (float)$m[1];
                if ($f <= 0) return null;
                return number_format($f, 2, '.', '');
            }
        }

        return null;
    }
}
