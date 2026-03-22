<?php

namespace App\Service\Assistant;

use App\Entity\User;
use App\Enum\AssistantConversationStatus;
use Doctrine\ORM\EntityManagerInterface;

class AssistantConversationFlow
{
    public function __construct(
        private readonly AssistantConversationManager $conversationManager,
        private readonly AssistantMessageManager $messageManager,
        private readonly AssistantRunContextBuilder $contextBuilder,
        private readonly AssistantPromptBuilder $promptBuilder,
        private readonly AssistantLlmService $llmService,
        private readonly AssistantRunActionManager $runActionManager,
        private readonly AssistantActionExecutor $actionExecutor,
        private readonly AssistantUiPayloadBuilder $uiPayloadBuilder,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array{
     *     assistant_message: \App\Entity\AssistantMessage,
     *     actions: array<int, array<string, mixed>>,
     *     status: string,
     *     execution?: array{
     *         success: bool,
     *         results: array<int, array<string, mixed>>,
     *         errors: array<int, array<string, mixed>>
     *     }
     * }
     */
    public function handleUserMessage(User $user, string $text): array
    {
        $conversation = $this->conversationManager->getOrCreateConversation($user);
        $run = $this->conversationManager->getOrCreateActiveRun($conversation);

        $this->messageManager->addUserMessage($conversation, $run, $text);
        $this->em->flush();

        $context = $this->contextBuilder->buildLlmInput($run);

        $systemPrompt = $this->promptBuilder->buildSystemPrompt();
        $schema = $this->promptBuilder->buildJsonSchema();

        $result = $this->llmService->complete(
            $systemPrompt,
            $context,
            $schema
        );

        $actions = [];
        if (isset($result['actions']) && is_array($result['actions'])) {
            $actions = $result['actions'];
            $this->runActionManager->syncActions($run, $actions);

            // Important : les actions doivent être flushées avant relecture
            $this->em->flush();
        }

        $assistantText = trim((string) ($result['assistant_message'] ?? ''));
        if ($assistantText === '') {
            $assistantText = 'Je n’ai pas su formuler de réponse.';
        }

        $status = (string) ($result['conversation_status'] ?? AssistantConversationStatus::CONTINUE->value);
        $execution = null;

        // Si une action a des missing, on force la conversation à rester ouverte
        if ($this->runActionManager->hasBlockingMissing($run)) {
            $status = AssistantConversationStatus::CONTINUE->value;
        }

        /**
         * --------------------------------
         * EXECUTION DES ACTIONS
         * --------------------------------
         */
        if ($status === AssistantConversationStatus::READY->value) {
            $execution = $this->actionExecutor->executeRun($user, $run);

            if (($execution['success'] ?? false) === true) {
                $assistantText = '✅ C’est fait.';
            } else {
                $assistantText = '⚠️ J’ai bien compris ta demande, mais je n’ai pas réussi à tout appliquer.';
            }

            $payload = $this->buildAssistantPayload(
                $result,
                $actions,
                $status,
                $execution
            );

            $assistantMessage = $this->messageManager->addAssistantMessage(
                $conversation,
                $run,
                $assistantText,
                $payload
            );

            $this->conversationManager->closeRun($run, AssistantConversationStatus::READY);
            $this->em->flush();

            return [
                'assistant_message' => $assistantMessage,
                'actions' => $actions,
                'status' => $status,
                'execution' => $execution,
            ];
        }

        /**
         * --------------------------------
         * FIN DE CONVERSATION SANS ACTION
         * --------------------------------
         */
        if ($status === AssistantConversationStatus::DONE->value) {
            $payload = $this->buildAssistantPayload($result, $actions, $status);

            $assistantMessage = $this->messageManager->addAssistantMessage(
                $conversation,
                $run,
                $assistantText,
                $payload
            );

            $this->conversationManager->closeRun($run, AssistantConversationStatus::DONE);
            $this->em->flush();

            return [
                'assistant_message' => $assistantMessage,
                'actions' => [],
                'status' => $status,
            ];
        }

        /**
         * --------------------------------
         * HORS PERIMETRE
         * --------------------------------
         */
        if ($status === AssistantConversationStatus::OUT_OF_SCOPE->value) {
            $payload = $this->buildAssistantPayload($result, $actions, $status);

            $assistantMessage = $this->messageManager->addAssistantMessage(
                $conversation,
                $run,
                $assistantText,
                $payload
            );

            $this->conversationManager->closeRun($run, AssistantConversationStatus::OUT_OF_SCOPE);
            $this->em->flush();

            return [
                'assistant_message' => $assistantMessage,
                'actions' => [],
                'status' => $status,
            ];
        }

        /**
         * --------------------------------
         * CONVERSATION EN COURS
         * --------------------------------
         */
        $payload = $this->buildAssistantPayload($result, $actions, $status);

        $assistantMessage = $this->messageManager->addAssistantMessage(
            $conversation,
            $run,
            $assistantText,
            $payload
        );

        $this->em->flush();

        return [
            'assistant_message' => $assistantMessage,
            'actions' => $actions,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, array<string, mixed>> $actions
     * @param array{
     *     success: bool,
     *     results: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>
     * }|null $execution
     *
     * @return array<string, mixed>
     */
    private function buildAssistantPayload(
        array $result,
        array $actions,
        string $status,
        ?array $execution = null,
    ): array {
        $payload = $this->uiPayloadBuilder->build($result, $actions, $status, $execution);

        $payload['llm_result'] = $result;

        if ($execution !== null) {
            $payload['execution'] = $execution;
        }

        return $payload;
    }
}