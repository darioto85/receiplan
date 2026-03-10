<?php

namespace App\Service\Assistant;

use App\Entity\AssistantRun;
use App\Entity\User;
use App\Service\Assistant\Handler\AssistantActionHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class AssistantActionExecutor
{
    /**
     * @var array<string, AssistantActionHandlerInterface>
     */
    private array $handlers = [];

    /**
     * @param iterable<AssistantActionHandlerInterface> $handlers
     */
    public function __construct(
        private readonly AssistantRunActionManager $runActionManager,
        #[TaggedIterator('app.assistant_action_handler')]
        iterable $handlers,
        private readonly EntityManagerInterface $em,
    ) {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->type()->value] = $handler;
        }
    }

    /**
     * @return array{
     *     success: bool,
     *     results: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>
     * }
     */
    public function executeRun(User $user, AssistantRun $run): array
    {
        $actions = $this->runActionManager->getReadyActions($run);

        $results = [];
        $errors = [];

        foreach ($actions as $action) {
            $handler = $this->handlers[$action->getType()->value] ?? null;

            if (!$handler instanceof AssistantActionHandlerInterface) {
                $error = [
                    'type' => 'missing_handler',
                    'message' => sprintf('Aucun handler pour l’action "%s".', $action->getType()->value),
                ];

                $action->markError($error);
                $errors[] = [
                    'action_id' => $action->getId(),
                    'client_action_id' => $action->getClientActionId(),
                    'type' => $action->getType()->value,
                    'error' => $error,
                ];

                continue;
            }

            try {
                $result = $handler->execute($user, $action->getData());

                if (!is_array($result)) {
                    $result = ['result' => $result];
                }

                $action->markExecuted($result);

                $results[] = [
                    'action_id' => $action->getId(),
                    'client_action_id' => $action->getClientActionId(),
                    'type' => $action->getType()->value,
                    'result' => $result,
                ];
            } catch (\Throwable $e) {
                $error = [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                ];

                $action->markError($error);

                $errors[] = [
                    'action_id' => $action->getId(),
                    'client_action_id' => $action->getClientActionId(),
                    'type' => $action->getType()->value,
                    'error' => $error,
                ];
            }
        }

        $this->em->flush();

        return [
            'success' => count($errors) === 0,
            'results' => $results,
            'errors' => $errors,
        ];
    }
}