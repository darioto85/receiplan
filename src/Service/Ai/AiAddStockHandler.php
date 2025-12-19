<?php

namespace App\Service\Ai;

use App\Entity\User;
use App\Entity\UserIngredient;
use App\Entity\Ingredient;
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
     *     unit:('g'|'kg'|'ml'|'l'|'piece'|null),
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

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $norm = $this->normalizer->normalize($item);
            if ($norm['needs_confirmation']) {
                $globalNeedsConfirmation = true;
            }
            if (!empty($norm['warnings'])) {
                $warningsByIndex[] = ['index' => (int)$idx, 'warnings' => $norm['warnings']];
            }

            $ingName = trim((string)$norm['ingredient']['name']);
            if ($ingName === '') {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int)$idx, 'warnings' => ['empty_name']];
                continue;
            }

            $quantity = $norm['ingredient']['quantity'];
            $unit = $norm['ingredient']['unit'];

            // Si quantity inconnue, on ne modifie pas le stock (ou on met +1 pièce ?)
            // Ici: on ne touche pas, mais on force confirmation.
            if ($quantity === null) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int)$idx, 'warnings' => ['missing_quantity']];
                continue;
            }

            $ingredient = $this->ingredientResolver->resolveOrCreate($ingName, $unit);
            $ingredient->setUser($user);

            /** @var UserIngredient|null $ui */
            $ui = $this->em->getRepository(UserIngredient::class)->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            if (!$ui) {
                $ui = new UserIngredient();
                $ui->setUser($user);
                $ui->setIngredient($ingredient);

                // Si ton UserIngredient a une notion d'unité, adapte ici
                // if (method_exists($ui, 'setUnit')) { $ui->setUnit($unit); }

                $ui->setQuantity(0.0);

                $this->em->persist($ui);
            }

            // Incrément
            $ui->setQuantity((float)$ui->getQuantity() + (float)$quantity);
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
