<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\AiShoppingAddHandler;

class ShoppingAddActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly AiShoppingAddHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::SHOPPING_ADD;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $data): array
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \RuntimeException('shopping.add: items manquant.');
        }

        return $this->handler->handle($user, [
            'items' => $data['items'],
        ]);
    }
}