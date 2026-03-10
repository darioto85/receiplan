<?php

namespace App\Service\Assistant;

use App\Service\Ai\OpenAi\OpenAiStructuredClient;

class AssistantLlmService
{
    public function __construct(
        private readonly OpenAiStructuredClient $structuredClient,
    ) {}

    /**
     * @param array{
     *     messages?: array<int, array{role:string, content:string}>,
     *     actions_state?: array<int, array<string, mixed>>
     * } $context
     *
     * @return array<string, mixed>
     */
    public function complete(string $systemPrompt, array $context, array $schema): array
    {
        $userText = $this->buildUserText($context);

        return $this->structuredClient->callJsonSchema(
            $userText,
            $systemPrompt,
            [
                'name' => 'kuko_assistant_response',
                'schema' => $schema,
            ]
        );
    }

    /**
     * @param array{
     *     messages?: array<int, array{role:string, content:string}>,
     *     actions_state?: array<int, array<string, mixed>>
     * } $context
     */
    private function buildUserText(array $context): string
    {
        $messages = $context['messages'] ?? [];
        $actionsState = $context['actions_state'] ?? [];

        $parts = [];

        $parts[] = "CONTEXTE DE CONVERSATION";
        $parts[] = "-----------------------";

        if (\count($messages) === 0) {
            $parts[] = "Aucun message précédent.";
        } else {
            foreach ($messages as $index => $message) {
                $role = (string) ($message['role'] ?? 'user');
                $content = trim((string) ($message['content'] ?? ''));

                if ($content === '') {
                    continue;
                }

                $parts[] = sprintf(
                    '%d. [%s] %s',
                    $index + 1,
                    $role,
                    $content
                );
            }
        }

        $parts[] = '';
        $parts[] = "ÉTAT ACTUEL DES ACTIONS";
        $parts[] = "-----------------------";

        if (\count($actionsState) === 0) {
            $parts[] = "Aucune action identifiée pour le moment.";
        } else {
            $json = json_encode(
                $actionsState,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $parts[] = $json !== false ? $json : '[]';
        }

        $parts[] = '';
        $parts[] = "INSTRUCTION";
        $parts[] = "-----------";
        $parts[] = "Analyse la conversation complète, tiens compte des actions déjà identifiées,";
        $parts[] = "mets à jour les actions existantes si nécessaire, et retourne uniquement un JSON valide conforme au schéma.";

        return implode("\n", $parts);
    }
}