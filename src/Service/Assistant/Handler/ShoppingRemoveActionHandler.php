<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\AiShoppingRemoveHandler;

class ShoppingRemoveActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly AiShoppingRemoveHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::SHOPPING_REMOVE;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $data): array
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \RuntimeException('shopping.remove: items manquant.');
        }

        $items = [];

        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = $item['quantity'] ?? null;
            $unit = $item['unit'] ?? null;

            $items[] = [
                'name_raw' => (string) ($item['name_raw'] ?? $name),
                'name' => $name,
                'quantity' => is_numeric($quantity) ? (float) $quantity : null,
                'quantity_raw' => array_key_exists('quantity_raw', $item)
                    ? ($item['quantity_raw'] !== null ? (string) $item['quantity_raw'] : null)
                    : (is_numeric($quantity) ? (string) $quantity : null),
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
        }

        if ($items === []) {
            throw new \RuntimeException('shopping.remove: aucun item exploitable.');
        }

        return $this->handler->handle($user, [
            'items' => $items,
        ]);
    }
}