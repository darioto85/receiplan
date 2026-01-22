<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiUpdateStockQuantityHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    /**
     * @param array{
     *   items: array<int, array{
     *     ingredient_id?: int|null,
     *     name_raw?: string,
     *     name?: string,
     *     name_key?: string,
     *     quantity: float|null,
     *     quantity_raw?: string|null,
     *     unit?: string|null,
     *     unit_raw?: string|null,
     *     notes?: string|null,
     *     confidence?: float
     *   }>
     * } $payload
     *
     * @return array{
     *   updated:int,
     *   warnings:array<int,array{index:int,warnings:string[]}>
     * }
     */
    public function handle(User $user, array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('payload.items manquant.');
        }

        $updated = 0;
        $warnings = [];

        $ingredientRepo = $this->em->getRepository(Ingredient::class);
        $uiRepo = $this->em->getRepository(UserIngredient::class);

        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                continue;
            }

            // 1) Quantité requise
            $qty = $it['quantity'] ?? null;
            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['missing_quantity']];
                continue;
            }
            $qty = (float) $qty;

            // 2) Résolution ingrédient
            $ingredient = null;

            // 2.a) Si ingredient_id est fourni (idéal : draft normalisé)
            $iid = $it['ingredient_id'] ?? null;
            if (is_numeric($iid)) {
                $found = $ingredientRepo->find((int)$iid);
                if ($found instanceof Ingredient) {
                    // sécurité légère: l'ingrédient peut être global (user=null) ou privé du user
                    $owner = $found->getUser();
                    if ($owner === null || $owner->getId() === $user->getId()) {
                        $ingredient = $found;
                    }
                }
            }

            // 2.b) Fallback: name/name_raw
            if (!$ingredient instanceof Ingredient) {
                $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
                if ($name === '') {
                    $warnings[] = ['index' => (int)$idx, 'warnings' => ['empty_name']];
                    continue;
                }

                $unitGuess = $it['unit'] ?? null;
                $ingredient = $this->ingredientResolver->resolveOrCreate(
                    $user,
                    $name,
                    is_string($unitGuess) ? $unitGuess : null
                );
            }

            if (!$ingredient instanceof Ingredient) {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['ingredient_not_resolved']];
                continue;
            }

            /** @var UserIngredient|null $ui */
            $ui = $uiRepo->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            if (!$ui) {
                $ui = new UserIngredient();
                $ui->setUser($user);
                $ui->setIngredient($ingredient);
                $ui->setQuantityFloat(0.0);
                $this->em->persist($ui);
            }

            $ui->setQuantityFloat(max(0.0, $qty));
            $updated++;
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'warnings' => $warnings,
        ];
    }
}
