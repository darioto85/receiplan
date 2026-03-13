<?php

namespace App\Service\Assistant;

use App\Entity\AssistantRun;
use App\Repository\AssistantMessageRepository;
use App\Repository\AssistantRunActionRepository;

class AssistantRunContextBuilder
{
    private const MAX_MESSAGES_FOR_LLM = 5;

    public function __construct(
        private readonly AssistantMessageRepository $messageRepository,
        private readonly AssistantRunActionRepository $actionRepository,
    ) {}

    /**
     * Construit les messages conversationnels à envoyer au LLM
     *
     * @return array<int, array{role:string, content:string}>
     */
    public function buildConversationMessages(AssistantRun $run): array
    {
        $messages = $this->messageRepository->findBy(
            ['run' => $run],
            ['createdAt' => 'DESC'],
            self::MAX_MESSAGES_FOR_LLM
        );

        $messages = array_reverse($messages);

        $result = [];

        foreach ($messages as $message) {
            $content = trim($message->getContent());
            if ($content === '') {
                continue;
            }

            $result[] = [
                'role' => $message->getRole(),
                'content' => $content,
            ];
        }

        return $result;
    }

    /**
     * Construit l'état actuel des actions détectées dans le run
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildCurrentActionState(AssistantRun $run): array
    {
        $actions = $this->actionRepository->findByRunOrdered($run);

        $result = [];

        foreach ($actions as $action) {
            $result[] = [
                'client_action_id' => $action->getClientActionId(),
                'type' => $action->getType()->value,
                'status' => $action->getStatus()->value,
                'data' => $action->getData(),
                'missing' => $action->getMissing(),
            ];
        }

        return $result;
    }

    /**
     * Construit le contexte complet envoyé au LLM
     *
     * @return array{
     *     messages: array<int, array{role:string, content:string}>,
     *     actions_state: array<int, array<string, mixed>>
     * }
     */
    public function buildLlmInput(AssistantRun $run): array
    {
        return [
            'messages' => $this->buildConversationMessages($run),
            'actions_state' => $this->buildCurrentActionState($run),
        ];
    }
}