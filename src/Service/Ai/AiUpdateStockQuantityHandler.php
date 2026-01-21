<?php

namespace App\Service\Ai;

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
     *     name_raw:string,
     *     name:string,
     *     quantity:float|null,
     *     quantity_raw:string|null,
     *     unit:string|null,
     *     unit_raw:string|null,
     *     notes:string|null,
     *     confidence:float
     *   }>
     * } $payload
     *
     * @return array{updated:int,warnings:array<int,array{index:int,warnings:string[]}>}
     */
    public function handle(User $user, array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('payload.items manquant.');
        }

        $updated = 0;
        $warnings = [];

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['empty_name']];
                continue;
            }

            $qty = $it['quantity'] ?? null;
            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['missing_quantity']];
                continue;
            }

            $ingredient = $this->ingredientResolver->resolveOrCreate($user, $name, null);

            /** @var UserIngredient|null $ui */
            $ui = $this->em->getRepository(UserIngredient::class)->findOneBy([
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

            $ui->setQuantityFloat(max(0.0, (float)$qty));
            $updated++;
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'warnings' => $warnings,
        ];
    }
}
