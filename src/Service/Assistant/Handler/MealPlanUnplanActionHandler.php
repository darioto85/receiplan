<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\UnplanRecipeHandler;

class MealPlanUnplanActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly UnplanRecipeHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::MEAL_PLAN_UNPLAN;
    }

    public function execute(User $user, array $data): array
    {
        $date = trim((string) ($data['date'] ?? ''));

        if ($date === '') {
            throw new \RuntimeException('meal_plan.unplan: date manquante.');
        }

        return $this->handler->handle($user, [
            'date' => $date,
        ]);
    }
}