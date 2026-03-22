<?php

namespace App\Service;

use App\Entity\MealPlan;
use App\Entity\Recipe;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Enum\CategoryEnum;
use App\Enum\Unit;
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
     *     is_optional: bool,
     *     is_missing: bool,
     *     is_blocking_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int,
     *   optional_missing_count: int
     * }>
     */
    public function getFeasibleRecipes(User $user): array
    {
        $views = $this->buildRecipeViews($user);

        return array_values(array_filter($views, static fn(array $v) => $v['is_feasible'] === true));
    }

    /**
     * @return array<int, array{
     *   recipe: Recipe,
     *   items: array<int, array{
     *     recipeIngredient: mixed,
     *     ingredient: mixed,
     *     needed: float|null,
     *     stock: float,
     *     unit: mixed,
     *     is_optional: bool,
     *     is_missing: bool,
     *     is_blocking_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int,
     *   optional_missing_count: int
     * }>
     */
    public function getInsufficientRecipes(User $user): array
    {
        $views = $this->buildRecipeViews($user);

        return array_values(array_filter($views, static fn(array $v) => $v['is_feasible'] === false));
    }

    /**
     * ✅ recettes planifiées (sur une période) qui sont insuffisantes.
     * Par défaut on ne prend que le planning non validé.
     *
     * @return array<int, array{
     *   recipe: Recipe,
     *   items: array<int, array{
     *     recipeIngredient: mixed,
     *     ingredient: mixed,
     *     needed: float|null,
     *     stock: float,
     *     unit: mixed,
     *     is_optional: bool,
     *     is_missing: bool,
     *     is_blocking_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int,
     *   optional_missing_count: int
     * }>
     */
    public function getInsufficientPlannedRecipes(
        User $user,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $onlyUnvalidated = true
    ): array {
        $plans = $this->mealPlanRepository->findBetween($user, $from, $to);

        if ($onlyUnvalidated) {
            $plans = array_values(array_filter(
                $plans,
                static fn(MealPlan $mp) => $mp->isValidated() === false
            ));
        }

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
                $recipeUnit = $ri->getUnit();

                if (!$ing || $needed === null || $needed <= 0) {
                    continue;
                }

                $stockQty = 0.0;
                $stockUnit = null;

                $ingId = $ing->getId();
                if ($ingId && isset($stockByIngredientId[$ingId])) {
                    $stockQty = $stockByIngredientId[$ingId]['qty'];
                    $stockUnit = $stockByIngredientId[$ingId]['unit'];
                }

                $comparableStock = $this->convertQuantity($stockQty, $stockUnit, $recipeUnit);
                if ($comparableStock === null) {
                    $comparableStock = 0.0;
                }

                $isMissing = $comparableStock < $needed;
                $isOptional = $this->isOptionalIngredient($ing);

                if ($isMissing && !$isOptional) {
                    $ok = false;
                    break;
                }
            }

            $recipeId = $recipe->getId();
            if ($recipeId !== null) {
                $map[$recipeId] = $ok;
            }
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
     * @return array<int, array{
     *   recipe: Recipe,
     *   items: array<int, array{
     *     recipeIngredient: mixed,
     *     ingredient: mixed,
     *     needed: float|null,
     *     stock: float,
     *     unit: mixed,
     *     is_optional: bool,
     *     is_missing: bool,
     *     is_blocking_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int,
     *   optional_missing_count: int
     * }>
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
     *     is_optional: bool,
     *     is_missing: bool,
     *     is_blocking_missing: bool,
     *     missing_amount: float|null
     *   }>,
     *   is_feasible: bool,
     *   missing_count: int,
     *   optional_missing_count: int
     * }>
     */
    private function buildViewsForRecipes(User $user, array $recipes): array
    {
        $stockByIngredientId = $this->buildStockMap($user);

        $views = [];

        foreach ($recipes as $recipe) {
            $items = [];
            $missingCount = 0;
            $optionalMissingCount = 0;

            foreach ($recipe->getRecipeIngredients() as $ri) {
                $ing = $ri->getIngredient();
                $neededRaw = $ri->getQuantity();
                $needed = $neededRaw === null ? null : (float) $neededRaw;

                $stock = 0.0;
                $unit = $ri->getUnit();

                if ($ing) {
                    $ingId = $ing->getId();

                    if ($ingId && isset($stockByIngredientId[$ingId])) {
                        $stockQty = $stockByIngredientId[$ingId]['qty'];
                        $stockUnit = $stockByIngredientId[$ingId]['unit'];

                        $convertedStock = $this->convertQuantity($stockQty, $stockUnit, $unit);
                        $stock = $convertedStock ?? 0.0;
                    }
                }

                $isMissing = ($needed !== null && $needed > 0 && $stock < $needed);
                $isOptional = $this->isOptionalIngredient($ing);
                $isBlockingMissing = $isMissing && !$isOptional;
                $missingAmount = $isMissing ? round($needed - $stock, 2) : null;

                if ($isBlockingMissing) {
                    $missingCount++;
                }

                if ($isMissing && $isOptional) {
                    $optionalMissingCount++;
                }

                $items[] = [
                    'recipeIngredient' => $ri,
                    'ingredient' => $ing,
                    'needed' => $needed,
                    'stock' => round($stock, 2),
                    'unit' => $unit,
                    'is_optional' => $isOptional,
                    'is_missing' => $isMissing,
                    'is_blocking_missing' => $isBlockingMissing,
                    'missing_amount' => $missingAmount,
                ];
            }

            $views[] = [
                'recipe' => $recipe,
                'items' => $items,
                'is_feasible' => ($missingCount === 0),
                'missing_count' => $missingCount,
                'optional_missing_count' => $optionalMissingCount,
            ];
        }

        return $views;
    }

    /**
     * @return array<int, array{qty: float, unit: Unit}> ingredientId => ['qty' => ..., 'unit' => ...]
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
            if (!$id) {
                continue;
            }

            $stockByIngredientId[$id] = [
                'qty' => (float) ($ui->getQuantity() ?? 0),
                'unit' => $ui->getUnit(),
            ];
        }

        return $stockByIngredientId;
    }

    private function isOptionalIngredient(mixed $ingredient): bool
    {
        if (!$ingredient || !method_exists($ingredient, 'getCategory')) {
            return false;
        }

        $category = $ingredient->getCategory();

        if (!$category instanceof CategoryEnum) {
            return false;
        }

        return in_array($category, CategoryEnum::optional(), true);
    }

    private function convertQuantity(float $quantity, ?Unit $from, ?Unit $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        if ($from === $to) {
            return $quantity;
        }

        $fromMeta = $this->getUnitMeta($from);
        $toMeta = $this->getUnitMeta($to);

        if ($fromMeta === null || $toMeta === null) {
            return null;
        }

        if ($fromMeta['family'] !== $toMeta['family']) {
            return null;
        }

        $baseQuantity = $quantity * $fromMeta['factor'];

        return $baseQuantity / $toMeta['factor'];
    }

    /**
     * factor = multiplicateur vers l'unité canonique de la famille
     * - poids    : base = g
     * - volume   : base = ml
     * - unités   : base = unité elle-même
     *
     * @return array{family: string, factor: float}|null
     */
    private function getUnitMeta(Unit $unit): ?array
    {
        return match ($unit) {
            Unit::G => ['family' => 'weight', 'factor' => 1.0],
            Unit::KG => ['family' => 'weight', 'factor' => 1000.0],

            Unit::ML => ['family' => 'volume', 'factor' => 1.0],
            Unit::L => ['family' => 'volume', 'factor' => 1000.0],

            Unit::PIECE => ['family' => 'piece', 'factor' => 1.0],
            Unit::POT => ['family' => 'pot', 'factor' => 1.0],
            Unit::BOITE => ['family' => 'boite', 'factor' => 1.0],
            Unit::SACHET => ['family' => 'sachet', 'factor' => 1.0],
            Unit::TRANCHE => ['family' => 'tranche', 'factor' => 1.0],
            Unit::PAQUET => ['family' => 'paquet', 'factor' => 1.0],
        };
    }
}