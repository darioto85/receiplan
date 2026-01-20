<?php

namespace App\Service\Ai\Action;

use App\Entity\User;
use App\Service\Ai\AiAddStockHandler;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;

final class AddStockAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly AiAddStockHandler $handler,
    ) {}

    public function name(): string
    {
        return 'add_stock';
    }

    public function extractDraft(string $text, AiContext $ctx): array
    {
        $schema = [
            'name' => 'receiplan_add_stock_v2',
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

        $system = $this->commonExtractionPrompt() .
            "\nContexte add_stock : l'utilisateur indique des achats / ajouts au stock.\n";

        $result = $this->client->callJsonSchema($text, $system, $schema);
        $this->assertStockPayloadV2($result);

        return $result;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        // Reprend ton normalizeStockDraft()
        $items = $draft['items'] ?? null;
        if (!is_array($items)) return $draft;

        foreach ($items as $i => $it) {
            if (!is_array($it)) continue;
            $q = $it['quantity'] ?? null;
            $unit = $it['unit'] ?? null;

            if (($unit === null || $unit === '' || (is_string($unit) && trim($unit) === ''))
                && $q !== null && $q !== '' && is_numeric($q)
            ) {
                $draft['items'][$i]['unit'] = 'piece';
                $draft['items'][$i]['unit_raw'] = null;
            }
        }

        return $draft;
    }

    public function buildClarifyQuestions(array $draft, AiContext $ctx): array
    {
        // Copie de buildClarifyQuestionsForStock()
        $items = $draft['items'] ?? null;
        if (!is_array($items) || count($items) === 0) return [];

        $questions = [];

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') $name = 'cet ingrédient';

            $confidence = isset($it['confidence']) ? (float)$it['confidence'] : 0.0;
            $quantity = $it['quantity'] ?? null;
            $unit = $it['unit'] ?? null;

            $missingQty = ($quantity === null || $quantity === '' || (is_string($quantity) && trim($quantity) === ''));
            $missingUnit = ($unit === null || $unit === '' || (is_string($unit) && trim($unit) === ''));

            if (!$missingQty && $missingUnit) {
                $missingUnit = false;
            }

            $needs = false;
            if ($confidence > 0 && $confidence < 0.6) $needs = true;
            if ($missingQty) $needs = true;
            if ($missingUnit) $needs = true;

            if (!$needs) continue;

            if ($missingQty) {
                $questions[] = [
                    'path' => "items.$idx.quantity",
                    'label' => "Quelle quantité pour $name ?",
                    'kind' => 'number',
                    'placeholder' => 'ex: 2',
                ];
            }

            if ($missingUnit) {
                $questions[] = [
                    'path' => "items.$idx.unit",
                    'label' => "Quelle unité pour $name ?",
                    'kind' => 'select',
                    'options' => [
                        ['value' => 'piece', 'label' => 'pièce(s)'],
                        ['value' => 'g', 'label' => 'g'],
                        ['value' => 'kg', 'label' => 'kg'],
                        ['value' => 'ml', 'label' => 'mL'],
                        ['value' => 'l', 'label' => 'L'],
                    ],
                ];
            }

            if (count($questions) >= 6) break;
        }

        return $questions;
    }

    public function buildConfirmText(array $draft, AiContext $ctx): string
    {
        // Copie de buildConfirmTextForStock()
        $items = $draft['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return "Je peux ajouter au stock. Tu confirmes ?";
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

        return "Je peux ajouter au stock : ".$summary.$more.". Tu confirmes ?";
    }

    public function apply(User $user, array $draft): array
    {
        return $this->handler->handle($user, $draft);
    }

    // --- schema/prompt/assert (copie depuis AiStockParser) ---

    private function commonExtractionPrompt(): string
    {
        return
            "Tu extrais des données structurées depuis un texte en français pour une application de cuisine.\n" .
            "Retourne UNIQUEMENT un JSON conforme au schema.\n\n" .
            "Règles générales :\n" .
            "- name_raw : extrait tel quel.\n" .
            "- name : normalisé (singulier si possible, sans marque si possible).\n" .
            "- confidence : entre 0 et 1.\n\n" .
            "Quantité :\n" .
            "- quantity doit être un nombre ou null.\n" .
            "- quantity_raw contient la forme brute.\n" .
            "- Si tu peux déduire un nombre fiable, mets-le dans quantity, sinon null.\n\n" .
            "Unités :\n" .
            "- unit doit être UNE des valeurs: g, kg, ml, l, piece, ou null.\n" .
            "- unit_raw contient l'unité d'origine ou null.\n" .
            "- Si l'unité d'origine n'est pas dans la liste, alors unit=null et unit_raw=unité d'origine.\n" .
            "- notes peut contenir des infos packaging.\n";
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

    private function assertStockPayloadV2(array $payload): void
    {
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new \RuntimeException("OpenAI: payload stock invalide (items manquant).");
        }
        foreach ($payload['items'] as $i => $item) {
            if (!is_array($item)) throw new \RuntimeException("OpenAI: items[$i] invalide.");
        }
    }
}
