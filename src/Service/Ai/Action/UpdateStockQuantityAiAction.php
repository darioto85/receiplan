<?php

namespace App\Service\Ai\Action;

use App\Entity\User;
use App\Service\Ai\AiUpdateStockQuantityHandler;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;
use App\Service\IngredientResolver;

final class UpdateStockQuantityAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly AiUpdateStockQuantityHandler $handler,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    public function name(): string
    {
        return 'update_stock_quantity';
    }

    public function extractDraft(string $text, AiContext $ctx): array
    {
        $schema = [
            'name' => 'receiplan_update_stock_quantity_v1',
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
            "\nContexte update_stock_quantity : l'utilisateur veut fixer une quantité (SET) dans son stock.\n" .
            "- Ex: \"je n'ai plus de tomates\" => tomates quantity=0.\n" .
            "- Si quantité absente, mets quantity=null.\n";

        $result = $this->client->callJsonSchema($text, $system, $schema);
        $this->assertHasItems($result);

        return $result;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        $items = $draft['items'] ?? null;
        if (!is_array($items)) {
            $draft['items'] = [];
            return $draft;
        }

        // 1) Normalisation locale (safe)
        foreach ($items as $i => $it) {
            if (!is_array($it)) {
                unset($items[$i]);
                continue;
            }

            $it['name_raw'] = trim((string)($it['name_raw'] ?? ''));
            $it['name'] = trim((string)($it['name'] ?? ''));

            $it['quantity_raw'] = $this->nullIfBlank($it['quantity_raw'] ?? null);
            $it['unit_raw'] = $this->nullIfBlank($it['unit_raw'] ?? null);
            $it['notes'] = $this->nullIfBlank($it['notes'] ?? null);

            $q = $it['quantity'] ?? null;
            if (is_numeric($q)) {
                $it['quantity'] = round((float)$q, 2);
            } else {
                $it['quantity'] = null;
            }

            $unit = $it['unit'] ?? null;
            $allowedUnits = ['g', 'kg', 'ml', 'l', 'piece', 'pot', 'boite', 'sachet', 'tranche', null];
            if (!in_array($unit, $allowedUnits, true)) {
                $it['unit'] = null;
            }

            $items[$i] = $it;
        }

        // 2) Canonisation via resolver (stable tomates -> tomate, etc.)
        if ($ctx->user instanceof \App\Entity\User) {
            foreach ($items as $i => $it) {
                if (!is_array($it)) continue;

                $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
                if ($name === '') continue;

                $unitGuess = $it['unit'] ?? null;

                $ingredient = $this->ingredientResolver->resolveOrCreate(
                    $ctx->user,
                    $name,
                    is_string($unitGuess) ? $unitGuess : null
                );

                // On enrichit le draft pour que le handler ne dépende plus du libellé IA
                $it['ingredient_id'] = $ingredient->getId();
                $it['name'] = (string) $ingredient->getName();      // nom canonical DB
                $it['name_key'] = (string) $ingredient->getNameKey();

                $items[$i] = $it;
            }
        }

        $draft['items'] = array_values($items);

        return $draft;
    }

    /** @return array<int, array<string,mixed>> */
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
                    'label' => "Quelle quantité restante pour $name ?",
                    'kind' => 'number',
                    'placeholder' => 'ex: 0',
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
            return "Je peux mettre à jour ton stock. Tu confirmes ?";
        }

        $parts = [];
        foreach (array_slice($items, 0, 5) as $it) {
            if (!is_array($it)) continue;

            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') continue;

            $q = $it['quantity'] ?? null;
            if ($q !== null && $q !== '' && is_numeric($q)) {
                $parts[] = $name . " = " . rtrim(rtrim((string)$q, '0'), '.');
            } else {
                $parts[] = $name;
            }
        }

        $summary = count($parts) > 0 ? implode(', ', $parts) : "des éléments";
        $more = (count($items) > 5) ? " (+".(count($items) - 5).")" : "";

        return "Je peux mettre à jour ton stock : ".$summary.$more.". Tu confirmes ?";
    }

    public function apply(User $user, array $draft): array
    {
        return $this->handler->handle($user, $draft);
    }

    // --------------------
    // Helpers
    // --------------------

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
                'unit' => ['type' => ['string', 'null'], 'enum' => ['g', 'kg', 'ml', 'l', 'piece', 'pot', 'boite', 'sachet', 'tranche', null]],
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

    private function nullIfBlank(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
