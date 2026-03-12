<?php

namespace App\Service\Assistant\Handler;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Enum\AssistantActionType;
use App\Service\Ai\AiConsumeStockHandler;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

class StockRemoveActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly AiConsumeStockHandler $consumeHandler,
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::STOCK_REMOVE;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $data): array
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \RuntimeException('stock.remove: items manquant.');
        }

        $itemsToConsume = [];
        $deleted = 0;
        $notFound = 0;
        $warnings = [];

        $ingredientRepo = $this->em->getRepository(Ingredient::class);
        $userIngredientRepo = $this->em->getRepository(UserIngredient::class);

        foreach ($data['items'] as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                $warnings[] = ['index' => (int) $idx, 'warnings' => ['empty_name']];
                continue;
            }

            $quantity = $item['quantity'] ?? null;
            $unit = $item['unit'] ?? null;

            // Cas 1 : quantité connue => on consomme normalement
            if (is_numeric($quantity) && (float) $quantity > 0) {
                $itemsToConsume[] = [
                    'name_raw' => (string) ($item['name_raw'] ?? $name),
                    'name' => $name,
                    'quantity' => (float) $quantity,
                    'quantity_raw' => array_key_exists('quantity_raw', $item)
                        ? ($item['quantity_raw'] !== null ? (string) $item['quantity_raw'] : null)
                        : (string) $quantity,
                    'unit' => $unit !== null && $unit !== '' ? (string) $unit : null,
                    'unit_raw' => array_key_exists('unit_raw', $item)
                        ? ($item['unit_raw'] !== null ? (string) $item['unit_raw'] : null)
                        : ($unit !== null && $unit !== '' ? (string) $unit : null),
                    'notes' => array_key_exists('notes', $item)
                        ? ($item['notes'] !== null ? (string) $item['notes'] : null)
                        : null,
                    'confidence' => array_key_exists('confidence', $item) && is_numeric($item['confidence'])
                        ? (float) $item['confidence']
                        : 1.0,
                ];

                continue;
            }

            // Cas 2 : pas de quantité => "je n'en ai plus" => suppression de la ligne stock
            $ingredient = null;

            $ingredientId = $item['ingredient_id'] ?? null;
            if (is_numeric($ingredientId)) {
                $found = $ingredientRepo->find((int) $ingredientId);
                if ($found instanceof Ingredient) {
                    $ingredient = $found;
                }
            }

            if (!$ingredient instanceof Ingredient) {
                $ingredient = $this->ingredientResolver->resolveOrCreate(
                    $user,
                    $name,
                    is_string($unit) ? $unit : null
                );
            }

            if (!$ingredient instanceof Ingredient) {
                $warnings[] = ['index' => (int) $idx, 'warnings' => ['ingredient_not_resolved']];
                continue;
            }

            /** @var UserIngredient|null $userIngredient */
            $userIngredient = $userIngredientRepo->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            if (!$userIngredient instanceof UserIngredient) {
                $notFound++;
                continue;
            }

            $this->em->remove($userIngredient);
            $deleted++;
        }

        $consumeResult = [
            'updated' => 0,
            'deleted' => 0,
            'not_found' => 0,
            'warnings' => [],
        ];

        if ($itemsToConsume !== []) {
            $consumeResult = $this->consumeHandler->handle($user, [
                'items' => $itemsToConsume,
            ]);
        }

        $this->em->flush();

        return [
            'updated' => (int) ($consumeResult['updated'] ?? 0),
            'deleted' => $deleted + (int) ($consumeResult['deleted'] ?? 0),
            'not_found' => $notFound + (int) ($consumeResult['not_found'] ?? 0),
            'warnings' => array_merge(
                $warnings,
                is_array($consumeResult['warnings'] ?? null) ? $consumeResult['warnings'] : []
            ),
        ];
    }
}