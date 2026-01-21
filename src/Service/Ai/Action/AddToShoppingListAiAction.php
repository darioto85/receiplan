<?php

namespace App\Service\Ai\Action;

use App\Entity\User;
use App\Service\Ai\AiShoppingAddHandler;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;

final class AddToShoppingListAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly AiShoppingAddHandler $handler,
    ) {}

    public function name(): string
    {
        return 'add_to_shopping_list';
    }

    public function extractDraft(string $text, AiContext $ctx): array
    {
        $schema = [
            'name' => 'receiplan_add_to_shopping_list_v1',
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
            "\nContexte add_to_shopping_list : l'utilisateur veut ajouter des ingrédients à sa liste de courses.\n" .
            "- Si la quantité est absente, mets quantity=null et quantity_raw=null.\n";

        $result = $this->client->callJsonSchema($text, $system, $schema);
        $this->assertHasItems($result);
        return $result;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        // pas de normalisation spécifique V1
        return $draft;
    }

    public function buildClarifyQuestions(array $draft, AiContext $ctx): array
    {
        $items = $draft['items'] ?? null;
        if (!is_array($items)) return [];

        $questions = [];
        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') $name = 'cet ingrédient';

            $qty = $it['quantity'] ?? null;
            $missingQty = ($qty === null || $qty === '' || (is_string($qty) && trim($qty) === ''));

            if ($missingQty) {
                $questions[] = [
                    'path' => "items.$idx.quantity",
                    'label' => "Quelle quantité pour $name (liste de courses) ?",
                    'kind' => 'number',
                    'placeholder' => 'ex: 2',
                ];
            }

            if (count($questions) >= 6) break;
        }

        return $questions;
    }

    public function buildConfirmText(array $draft, AiContext $ctx): string
    {
        $items = $draft['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return "Je peux ajouter à ta liste de courses. Tu confirmes ?";
        }

        $parts = [];
        foreach (array_slice($items, 0, 5) as $it) {
            if (!is_array($it)) continue;
            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') continue;

            $q = $it['quantity'] ?? null;
            $qRaw = trim((string)($it['quantity_raw'] ?? ''));

            if ($q !== null && $q !== '' && is_numeric($q)) {
                $parts[] = rtrim(rtrim((string)$q, '0'), '.') . ' ' . $name;
            } elseif ($qRaw !== '') {
                $parts[] = $qRaw . ' ' . $name;
            } else {
                $parts[] = $name;
            }
        }

        $summary = count($parts) > 0 ? implode(', ', $parts) : "des éléments";
        $more = (count($items) > 5) ? " (+".(count($items) - 5).")" : "";

        return "Je peux ajouter à ta liste de courses : ".$summary.$more.". Tu confirmes ?";
    }

    public function apply(User $user, array $draft): array
    {
        return $this->handler->handle($user, $draft);
    }

    // ---- shared helpers ----

    private function commonExtractionPrompt(): string
    {
        return
            "Tu extrais des données structurées depuis un texte en français pour une application de cuisine.\n" .
            "Retourne UNIQUEMENT un JSON conforme au schema.\n\n" .
            "Règles générales :\n" .
            "- name_raw : extrait tel quel.\n" .
            "- name : normalisé (singulier si possible).\n" .
            "- confidence : entre 0 et 1.\n" .
            "- quantity : nombre ou null.\n" .
            "- quantity_raw : forme brute ou null.\n" .
            "- unit : g, kg, ml, l, piece, ou null.\n" .
            "- unit_raw : unité brute ou null.\n" .
            "- notes : infos utiles ou null.\n";
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
