<?php

namespace App\Service\Ai\Action;

use App\Entity\User;
use App\Service\Ai\AiShoppingRemoveHandler;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;

final class RemoveFromShoppingListAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly AiShoppingRemoveHandler $handler,
    ) {}

    public function name(): string
    {
        return 'remove_from_shopping_list';
    }

    public function extractDraft(string $text, AiContext $ctx): array
    {
        $schema = [
            'name' => 'receiplan_remove_from_shopping_list_v1',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => $this->ingredientSchemaV2(),
                    ],
                ],
                'required' => ['items'],
            ],
        ];

        $system =
            $this->commonExtractionPrompt() .
            "\nContexte remove_from_shopping_list : l'utilisateur veut enlever des éléments de sa liste de courses.\n" .
            "- Si aucune quantité n'est indiquée, mets quantity=null (cela signifie \"supprimer la ligne\").\n";

        $result = $this->client->callJsonSchema($text, $system, $schema);
        $this->assertHasItems($result);
        return $result;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        return $draft;
    }

    public function buildClarifyQuestions(array $draft, AiContext $ctx): array
    {
        // V1 : pas de clarify, car qty=null => delete ligne
        return [];
    }

    public function buildConfirmText(array $draft, AiContext $ctx): string
    {
        $items = $draft['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return "Je peux retirer des éléments de ta liste de courses. Tu confirmes ?";
        }

        $parts = [];
        foreach (array_slice($items, 0, 5) as $it) {
            if (!is_array($it)) continue;
            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') continue;

            $q = $it['quantity'] ?? null;
            if ($q !== null && $q !== '' && is_numeric($q)) {
                $parts[] = rtrim(rtrim((string)$q, '0'), '.') . ' ' . $name;
            } else {
                $parts[] = $name;
            }
        }

        $summary = count($parts) > 0 ? implode(', ', $parts) : "des éléments";
        $more = (count($items) > 5) ? " (+".(count($items) - 5).")" : "";

        return "Je peux retirer de ta liste de courses : ".$summary.$more.". Tu confirmes ?";
    }

    public function apply(User $user, array $draft): array
    {
        return $this->handler->handle($user, $draft);
    }

    private function commonExtractionPrompt(): string
    {
        return
            "Tu extrais des données structurées depuis un texte en français pour une application de cuisine.\n" .
            "Retourne UNIQUEMENT un JSON conforme au schema.\n\n";
    }

    private function ingredientSchemaV2(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name_raw' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'quantity' => ['type' => ['number', 'null']],
                'quantity_raw' => ['type' => ['string', 'null']],
                'unit' => ['type' => ['string', 'null'], 'enum' => ['g', 'kg', 'ml', 'l', 'piece', null]],
                'unit_raw' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['name_raw', 'name', 'quantity', 'quantity_raw', 'unit', 'unit_raw', 'notes', 'confidence'],
        ];
    }

    private function assertHasItems(array $payload): void
    {
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new \RuntimeException("OpenAI: payload invalide (items manquant).");
        }
    }
}
