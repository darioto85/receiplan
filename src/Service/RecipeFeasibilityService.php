<?php

namespace App\Service;

use App\Entity\Recipe;
use App\Entity\User;
use App\Entity\UserIngredient;
use Doctrine\ORM\EntityManagerInterface;

final class RecipeFeasibilityService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array<int, array{
     *   recipe: Recipe,
     *   items: array<int, array{
     *     recipeIngredient: mixed,
     *     ingredient: mixed,
     *     needed: float|null,
     *     stock: float,
     *     unit: string|null,
     *     is_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int
     * }>
     */
    public function getFeasibleRecipes(User $user): array
    {
        $views = $this->buildRecipeViews($user);

        return array_values(array_filter($views, static fn(array $v) => $v['is_feasible'] === true));
    }

    /**
     * @return array<int, array{...same as getFeasibleRecipes...}>
     */
    public function getInsufficientRecipes(User $user): array
    {
        $views = $this->buildRecipeViews($user);

        return array_values(array_filter($views, static fn(array $v) => $v['is_feasible'] === false));
    }

    /**
     * Calcule une vue enrichie par recette (besoin vs stock).
     *
     * @return array<int, array{...}>
     */
    private function buildRecipeViews(User $user): array
    {
        // 1) Charger recipes + recipeIngredients + ingredient en une requête (évite N+1)
        /** @var Recipe[] $recipes */
        $recipes = $this->em->createQueryBuilder()
            ->select('r', 'ri', 'i')
            ->from(Recipe::class, 'r')
            ->leftJoin('r.recipeIngredients', 'ri')
            ->leftJoin('ri.ingredient', 'i')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        // 2) Charger stock utilisateur et construire une map ingredientId -> quantity
        /** @var UserIngredient[] $stockRows */
        $stockRows = $this->em->createQueryBuilder()
            ->select('ui', 'ing')
            ->from(UserIngredient::class, 'ui')
            ->leftJoin('ui.ingredient', 'ing')
            ->andWhere('ui.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $stockByIngredientId = [];
        foreach ($stockRows as $ui) {
            $ing = $ui->getIngredient();
            if (!$ing) continue;

            $id = $ing->getId();
            $qty = (float) ($ui->getQuantity() ?? 0);
            $stockByIngredientId[$id] = ($stockByIngredientId[$id] ?? 0) + $qty;
        }

        // 3) Construire la “vue” par recette
        $views = [];

        foreach ($recipes as $recipe) {
            $items = [];
            $missingCount = 0;

            foreach ($recipe->getRecipeIngredients() as $ri) {
                $ing = $ri->getIngredient();
                $neededRaw = $ri->getQuantity();
                $needed = $neededRaw === null ? null : (float) $neededRaw;

                $stock = 0.0;
                $unit = null;

                if ($ing) {
                    $stock = (float) ($stockByIngredientId[$ing->getId()] ?? 0.0);
                    $unit = $ing->getUnit(); // ou null
                }

                $isMissing = ($needed !== null && $needed > 0 && $stock < $needed);
                $missingAmount = $isMissing ? ($needed - $stock) : null;

                if ($isMissing) {
                    $missingCount++;
                }

                $items[] = [
                    'recipeIngredient' => $ri,
                    'ingredient' => $ing,
                    'needed' => $needed,
                    'stock' => $stock,
                    'unit' => $unit,
                    'is_missing' => $isMissing,
                    'missing_amount' => $missingAmount,
                ];
            }

            $views[] = [
                'recipe' => $recipe,
                'items' => $items,
                'is_feasible' => ($missingCount === 0),
                'missing_count' => $missingCount,
            ];
        }

        return $views;
    }
}
