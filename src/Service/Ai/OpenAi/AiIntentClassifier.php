<?php

namespace App\Service\Ai\OpenAi;

final class AiIntentClassifier
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
    ) {}

    /**
     * @return 'add_stock'|'add_recipe'|'unknown'
     */
    public function classify(string $userText): string
    {
        $userText = trim($userText);
        if ($userText === '') return 'unknown';

        $schema = [
            'name' => 'receiplan_action_classifier_v1',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['add_stock', 'add_recipe', 'unknown']],
                ],
                'required' => ['action'],
            ],
        ];

        $system =
            "Tu es un classifieur d'intention pour une application de cuisine.\n" .
            "Tu dois retourner UNIQUEMENT un JSON conforme au schema.\n\n" .
            "Règles:\n" .
            "- add_stock si le texte parle d'achat/ajout au stock.\n" .
            "- add_recipe si le texte demande de créer/ajouter une recette.\n" .
            "- unknown si ambigu.\n";

        $result = $this->client->callJsonSchema($userText, $system, $schema);
        $action = $result['action'] ?? null;

        if (!is_string($action)) return 'unknown';
        if (!in_array($action, ['add_stock', 'add_recipe', 'unknown'], true)) return 'unknown';

        return $action;
    }
}
