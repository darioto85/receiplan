<?php

namespace App\Service;

use App\Entity\MealPlan;
use App\Entity\Recipe;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Repository\MealPlanRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RecipeFeasibilityService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MealPlanRepository $mealPlanRepository,
    ) {}

    /**
     * @return array<int, array{
     *   recipe: Recipe,
     *   items: array<int, array{
     *     recipeIngredient: mixed,
     *     ingredient: mixed,
     *     needed: float|null,
     *     stock: float,
     *     unit: mixed,
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
     * ✅ NOUVEAU: recettes planifiées (sur une période) qui sont insuffisantes.
     * Par défaut on ne prend que le planning non validé.
     *
     * @return array<int, array{...same as getFeasibleRecipes...}>
     */
    public function getInsufficientPlannedRecipes(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $onlyUnvalidated = true
    ): array {
        $plans = $this->mealPlanRepository->findBetween($user, $from, $to);

        // Filtrer validés si demandé
        if ($onlyUnvalidated) {
            $plans = array_values(array_filter(
                $plans,
                static fn(MealPlan $mp) => $mp->isValidated() === false
            ));
        }

        // Extraire les recettes uniques (évite doublons)
        /** @var array<int, Recipe> $recipesById */
        $recipesById = [];

        foreach ($plans as $mp) {
            $recipe = $mp->getRecipe();
            if (!$recipe instanceof Recipe) {
                continue;
            }
            $rid = $recipe->getId();
            if ($rid) {
                $recipesById[$rid] = $recipe;
            }
        }

        if ($recipesById === []) {
            return [];
        }

        // Charger ces recettes avec leurs ingrédients en une requête (évite N+1)
        $recipeIds = array_keys($recipesById);

        /** @var Recipe[] $recipes */
        $recipes = $this->em->createQueryBuilder()
            ->select('r', 'ri', 'i')
            ->from(Recipe::class, 'r')
            ->leftJoin('r.recipeIngredients', 'ri')
            ->leftJoin('ri.ingredient', 'i')
            ->andWhere('r.user = :user')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $recipeIds)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        $views = $this->buildViewsForRecipes($user, $recipes);

        // Ne retourner que les insuffisantes
        return array_values(array_filter($views, static fn(array $v) => $v['is_feasible'] === false));
    }

    /**
     * Map recipeId => isFeasible (stock instantané), pour un sous-ensemble de recettes déjà chargées.
     *
     * @param Recipe[] $recipes
     * @return array<int, bool> recipeId => isFeasible
     */
    public function getFeasibilityMapForRecipes(User $user, array $recipes): array
    {
        $stockByIngredientId = $this->buildStockMap($user);

        $map = [];
        foreach ($recipes as $recipe) {
            if (!$recipe instanceof Recipe) {
                continue;
            }

            $ok = true;

            foreach ($recipe->getRecipeIngredients() as $ri) {
                $ing = $ri->getIngredient();
                $neededRaw = $ri->getQuantity();
                $needed = $neededRaw === null ? null : (float) $neededRaw;

                if (!$ing || $needed === null || $needed <= 0) {
                    continue;
                }

                $stock = (float) ($stockByIngredientId[$ing->getId()] ?? 0.0);
                if ($stock < $needed) {
                    $ok = false;
                    break;
                }
            }

            $map[$recipe->getId()] = $ok;
        }

        return $map;
    }

    /**
     * Helper pratique pour les cas AJAX “une seule recette”.
     */
    public function isRecipeFeasible(User $user, Recipe $recipe): bool
    {
        $map = $this->getFeasibilityMapForRecipes($user, [$recipe]);
        return $map[$recipe->getId()] ?? true;
    }

    /**
     * Calcule une vue enrichie par recette (besoin vs stock) pour toutes les recettes user.
     *
     * @return array<int, array{...}>
     */
    private function buildRecipeViews(User $user): array
    {
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

        return $this->buildViewsForRecipes($user, $recipes);
    }

    /**
     * Construit les views pour une liste de recettes déjà chargées (avec ingredients).
     *
     * @param Recipe[] $recipes
     * @return array<int, array{
     *   recipe: Recipe,
     *   items: array<int, array{
     *     recipeIngredient: mixed,
     *     ingredient: mixed,
     *     needed: float|null,
     *     stock: float,
     *     unit: mixed,
     *     is_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int
     * }>
     */
    private function buildViewsForRecipes(User $user, array $recipes): array
    {
        $stockByIngredientId = $this->buildStockMap($user);

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
                    $unit = $ing->getUnit();
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

    /**
     * @return array<int, float> ingredientId => quantity
     */
    private function buildStockMap(User $user): array
    {
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
            if (!$ing) {
                continue;
            }

            $id = $ing->getId();
            $qty = (float) ($ui->getQuantity() ?? 0);
            $stockByIngredientId[$id] = ($stockByIngredientId[$id] ?? 0) + $qty;
        }

        return $stockByIngredientId;
    }
}
