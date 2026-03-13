<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;

interface AssistantActionHandlerInterface
{
    /**
     * Type d'action géré par ce handler
     */
    public function type(): AssistantActionType;

    /**
     * Exécute l'action
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $data): array;
}