<?php

namespace App\Service\Ai;

use App\Entity\User;
use App\Entity\UserIngredient;
use App\Service\AiIngredientNormalizer;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiAddStockHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
        private readonly AiIngredientNormalizer $normalizer,
    ) {}

    /**
     * @param array{
     *   items: array<int, array{
     *     name_raw:string,
     *     name:string,
     *     quantity:float|null,
     *     quantity_raw:string|null,
     *     unit:('g'|'kg'|'ml'|'l'|'piece'|'pack'|null),
     *     unit_raw:string|null,
     *     notes:string|null,
     *     confidence:float
     *   }>
     * } $payload
     *
     * @return array{
     *   updated: int,
     *   needs_confirmation: bool,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    public function handle(User $user, array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('payload.items manquant.');
        }

        $globalNeedsConfirmation = false;
        $warningsByIndex = [];
        $updated = 0;

        /**
         * Cache in-memory pour éviter de créer 2 UserIngredient identiques
         * dans la même requête (surtout quand Ingredient est nouveau et n’a pas encore d’id).
         *
         * @var array<string, UserIngredient>
         */
        $userIngredientCache = [];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $norm = $this->normalizer->normalize($item);

            if (!empty($norm['needs_confirmation'])) {
                $globalNeedsConfirmation = true;
            }
            if (!empty($norm['warnings']) && is_array($norm['warnings'])) {
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => $norm['warnings']];
            }

            $ingName = trim((string) ($norm['ingredient']['name'] ?? ''));
            if ($ingName === '') {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => ['empty_name']];
                continue;
            }

            $quantity = $norm['ingredient']['quantity'] ?? null;
            $unit = $norm['ingredient']['unit'] ?? null;

            if ($quantity === null) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => ['missing_quantity']];
                continue;
            }

            // ✅ Ingredient user-aware + cache anti-doublons (via IngredientResolver)
            $ingredient = $this->ingredientResolver->resolveOrCreate(
                $user,
                $ingName,
                is_string($unit) ? $unit : null
            );

            // ✅ Clé de cache UserIngredient : id si dispo, sinon instance (spl_object_id)
            $ingId = method_exists($ingredient, 'getId') ? $ingredient->getId() : null;
            $uiKey = $ingId ? ('id:' . $ingId) : ('obj:' . spl_object_id($ingredient));

            if (isset($userIngredientCache[$uiKey])) {
                $ui = $userIngredientCache[$uiKey];
            } else {
                // Si l’ingredient existe déjà en DB (id non null), on peut tenter un findOneBy
                $ui = null;

                if ($ingId !== null) {
                    /** @var UserIngredient|null $ui */
                    $ui = $this->em->getRepository(UserIngredient::class)->findOneBy([
                        'user' => $user,
                        'ingredient' => $ingredient,
                    ]);
                }

                if (!$ui) {
                    $ui = new UserIngredient();
                    $ui->setUser($user);
                    $ui->setIngredient($ingredient);
                    $ui->setQuantity(0.0);
                    $this->em->persist($ui);
                }

                $userIngredientCache[$uiKey] = $ui;
            }

            // Incrément stock
            $ui->setQuantity((float) $ui->getQuantity() + (float) $quantity);
            $updated++;

            if ($unit === null) {
                $globalNeedsConfirmation = true;
            }
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'needs_confirmation' => $globalNeedsConfirmation,
            'warnings' => $warningsByIndex,
        ];
    }
}
