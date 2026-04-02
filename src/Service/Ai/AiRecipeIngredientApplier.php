<?php

namespace App\Service\Ai;

use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\Unit;
use App\Service\AiIngredientNormalizer;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiRecipeIngredientApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
        private readonly AiIngredientNormalizer $normalizer,
    ) {}

    /**
     * Format attendu = format assistant déjà existant.
     *
     * @param array<int, array{
     *   name_raw:string,
     *   name:string,
     *   quantity:float|null,
     *   quantity_raw:string|null,
     *   unit:('g'|'kg'|'ml'|'l'|'piece'|'pot'|'boite'|'sachet'|'tranche'|'paquet'|null),
     *   unit_raw:string|null,
     *   notes:string|null,
     *   confidence:float
     * }> $items
     *
     * @return array{
     *   needs_confirmation: bool,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    public function applyAssistantItems(User $user, Recipe $recipe, array $items): array
    {
        return $this->applyInternal($user, $recipe, $items);
    }

    /**
     * Adaptateur pour le scan photo.
     *
     * @param array<int, array{
     *   name:string,
     *   quantity:float|int|string|null,
     *   unit:string|null
     * }> $rows
     *
     * @return array{
     *   needs_confirmation: bool,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    public function applyScanRows(User $user, Recipe $recipe, array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $unitRaw = isset($row['unit']) && $row['unit'] !== null ? (string) $row['unit'] : null;

            $quantity = $row['quantity'] ?? null;
            $quantityFloat = null;

            if (is_int($quantity) || is_float($quantity)) {
                $quantityFloat = (float) $quantity;
            } elseif (is_string($quantity) && trim($quantity) !== '' && is_numeric(str_replace(',', '.', $quantity))) {
                $quantityFloat = (float) str_replace(',', '.', $quantity);
            }

            $items[] = [
                'name_raw' => $name,
                'name' => $name,
                'quantity' => $quantityFloat,
                'quantity_raw' => is_scalar($quantity) ? (string) $quantity : null,
                'unit' => $this->normalizeScanUnitToAssistantUnit($unitRaw),
                'unit_raw' => $unitRaw,
                'notes' => null,
                'confidence' => 1.0,
            ];
        }

        return $this->applyInternal($user, $recipe, $items);
    }

    /**
     * @param array<int, array{
     *   name_raw:string,
     *   name:string,
     *   quantity:float|null,
     *   quantity_raw:string|null,
     *   unit:string|null,
     *   unit_raw:string|null,
     *   notes:string|null,
     *   confidence:float
     * }> $items
     *
     * @return array{
     *   needs_confirmation: bool,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    private function applyInternal(User $user, Recipe $recipe, array $items): array
    {
        /** @var array<string, RecipeIngredient> $lineByMergeKey */
        $lineByMergeKey = [];

        $globalNeedsConfirmation = false;
        $warningsByIndex = [];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $norm = $this->normalizer->normalize($item);

            if (!empty($norm['needs_confirmation'])) {
                $globalNeedsConfirmation = true;
            }

            if (!empty($norm['warnings'])) {
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => $norm['warnings'],
                ];
            }

            $ingName = trim((string) ($norm['ingredient']['name'] ?? ''));
            if ($ingName === '') {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['empty_name'],
                ];
                continue;
            }

            $quantity = $norm['ingredient']['quantity'] ?? null;
            $unitValue = $norm['ingredient']['unit'] ?? null;

            if ($quantity === null) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['missing_quantity'],
                ];
                $quantity = 0.0;
            }

            $unit = null;
            if (is_string($unitValue) && $unitValue !== '') {
                $unit = Unit::from($unitValue);
            }

            if (!$unit instanceof Unit) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['missing_unit'],
                ];
                continue;
            }

            $ingredient = $this->ingredientResolver->resolveOrCreate($user, $ingName, $unitValue);

            $mergeKey = method_exists($ingredient, 'getNameKey')
                ? (string) $ingredient->getNameKey()
                : $ingName;

            if (!isset($lineByMergeKey[$mergeKey])) {
                $ri = new RecipeIngredient();
                $ri->setRecipe($recipe);
                $ri->setIngredient($ingredient);

                // IMPORTANT : la recette porte sa propre unité, indépendante de l'unité par défaut de Ingredient
                $ri->setQuantityFloat((float) $quantity);
                $ri->setUnit($unit);

                $this->em->persist($ri);
                $lineByMergeKey[$mergeKey] = $ri;
            } else {
                $existing = $lineByMergeKey[$mergeKey];

                if ($existing->getUnit() !== $unit) {
                    $globalNeedsConfirmation = true;
                    $warningsByIndex[] = [
                        'index' => (int) $idx,
                        'warnings' => ['merged_duplicate_with_different_unit'],
                    ];
                    continue;
                }

                $existing->setQuantityFloat(
                    $existing->getQuantityFloat() + (float) $quantity
                );

                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['merged_duplicate_ingredient'],
                ];
            }
        }

        return [
            'needs_confirmation' => $globalNeedsConfirmation,
            'warnings' => $warningsByIndex,
        ];
    }

    private function normalizeScanUnitToAssistantUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $u = trim(mb_strtolower($unit));
        if ($u === '') {
            return null;
        }

        return match ($u) {
            'g', 'gramme', 'grammes', 'gr' => 'g',
            'kg', 'kilo', 'kilos', 'kilogramme', 'kilogrammes' => 'kg',
            'ml', 'millilitre', 'millilitres' => 'ml',
            'cl', 'centilitre', 'centilitres' => 'cl',
            'dl', 'decilitre', 'decilitres' => 'dl',
            'l', 'litre', 'litres' => 'l',
            'piece', 'pieces', 'pièce', 'pièces', 'unite', 'unites', 'unité', 'unités' => 'piece',
            'pot', 'pots' => 'pot',
            'boite', 'boites', 'boîte', 'boîtes' => 'boite',
            'sachet', 'sachets' => 'sachet',
            'tranche', 'tranches' => 'tranche',
            'paquet', 'paquets' => 'paquet',
            default => $u,
        };
    }
}