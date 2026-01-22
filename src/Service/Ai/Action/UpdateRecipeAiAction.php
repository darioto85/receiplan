<?php

namespace App\Service\Ai\Action;

use App\Entity\User;
use App\Service\Ai\AiUpdateRecipeHandler;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;
use App\Service\IngredientResolver;
use App\Service\RecipeResolver;

final class UpdateRecipeAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly AiUpdateRecipeHandler $handler,
        private readonly RecipeResolver $recipeResolver,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    public function name(): string
    {
        return 'update_recipe';
    }

    public function extractDraft(string $text, AiContext $ctx): array
    {
        $schema = [
            'name' => 'receiplan_update_recipe_v1',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'target' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'recipe_name_raw' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['recipe_name_raw'],
                    ],
                    'patch' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'new_name' => ['type' => ['string', 'null']],
                            'add_ingredients' => ['type' => 'array', 'items' => $this->ingredientSchemaV2()],
                            'remove_ingredients' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'name_raw' => ['type' => 'string'],
                                        'name' => ['type' => 'string'],
                                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                                    ],
                                    'required' => ['name_raw', 'name', 'confidence'],
                                ],
                            ],
                            'update_ingredients' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'name_raw' => ['type' => 'string'],
                                        'name' => ['type' => 'string'],
                                        'quantity' => ['type' => ['number', 'null']],
                                        'quantity_raw' => ['type' => ['string', 'null']],
                                        'unit' => ['type' => ['string', 'null'], 'enum' => ['g','kg','ml','l','piece','pot','boite','sachet','tranche', null]],
                                        'unit_raw' => ['type' => ['string', 'null']],
                                        'notes' => ['type' => ['string', 'null']],
                                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                                    ],
                                    'required' => ['name_raw', 'name', 'quantity', 'quantity_raw', 'unit', 'unit_raw', 'notes', 'confidence'],
                                ],
                            ],
                        ],
                        'required' => ['new_name', 'add_ingredients', 'remove_ingredients', 'update_ingredients'],
                    ],
                ],
                'required' => ['target', 'patch'],
            ],
        ];

        $system = $this->commonExtractionPrompt() .
            "Contexte update_recipe : l'utilisateur veut modifier une recette existante.\n" .
            "Tu dois extraire :\n" .
            "- le nom de la recette ciblée (target.recipe_name_raw)\n" .
            "- le patch : rename et/ou modifications d'ingrédients\n\n" .
            "Règles :\n" .
            "- Si l'utilisateur renomme : mets patch.new_name.\n" .
            "- Si l'utilisateur ajoute un ingrédient : mets-le dans patch.add_ingredients.\n" .
            "- Si l'utilisateur retire un ingrédient : mets-le dans patch.remove_ingredients.\n" .
            "- Si l'utilisateur change quantité/unité d'un ingrédient : mets-le dans patch.update_ingredients.\n" .
            "- Si le nom de recette n'est pas clair, mets target.recipe_name_raw = null.\n" .
            "- Si aucun changement n'est demandé clairement, laisse les listes vides et new_name=null.\n";

        $result = $this->client->callJsonSchema($text, $system, $schema);
        $this->assertPayload($result);

        return $result;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        // trims & defaults
        $draft['target']['recipe_name_raw'] = $this->nullIfBlank($draft['target']['recipe_name_raw'] ?? null);

        $patch = $draft['patch'] ?? [];
        if (!is_array($patch)) $patch = [];

        $patch['new_name'] = $this->nullIfBlank($patch['new_name'] ?? null);

        foreach (['add_ingredients', 'remove_ingredients', 'update_ingredients'] as $k) {
            if (!isset($patch[$k]) || !is_array($patch[$k])) {
                $patch[$k] = [];
            }
        }

        // ---- Résolution recette (stable) ----
        if ($ctx->user instanceof \App\Entity\User) {
            $targetName = $draft['target']['recipe_name_raw'] ?? null;
            if (is_string($targetName) && trim($targetName) !== '') {
                $res = $this->recipeResolver->resolve($ctx->user, $targetName);

                if (($res['status'] ?? null) === 'matched' && isset($res['recipe']) && $res['recipe'] instanceof \App\Entity\Recipe) {
                    $recipe = $res['recipe'];
                    $draft['target']['recipe_id'] = $recipe->getId();
                    $draft['target']['recipe_name'] = $recipe->getName();
                    $draft['target']['recipe_name_key'] = $recipe->getNameKey();
                } else {
                    // on garde l'info pour clarify (ambigu ou not_found)
                    $draft['target']['_resolve'] = [
                        'status' => $res['status'] ?? 'not_found',
                        'candidates' => array_map(
                            fn($r) => ['id' => $r->getId(), 'name' => $r->getName()],
                            $res['candidates'] ?? []
                        ),
                    ];
                }
            }
        }

        // ---- Résolution ingrédients (stable) ----
        // Pour update_recipe : on veut éviter de créer "des ingrédients fantômes" si user se trompe.
        // Mais tu n'as pas un resolveWithoutCreate. Donc on fait:
        // - pour add_ingredients => resolveOrCreate (ok)
        // - pour remove/update => resolveOrCreate est discutable, mais on peut l'accepter (ou plus tard ajouter resolveOnly).
        $u = $ctx->user;

        // add_ingredients => resolveOrCreate
        if ($u instanceof \App\Entity\User) {
            foreach ($patch['add_ingredients'] as $i => $ing) {
                if (!is_array($ing)) continue;
                $ing = $this->normalizeIngredientRow($ing);

                $name = trim((string)($ing['name'] ?? $ing['name_raw'] ?? ''));
                if ($name === '') {
                    $patch['add_ingredients'][$i] = $ing;
                    continue;
                }

                $ingredient = $this->ingredientResolver->resolveOrCreate($u, $name, $ing['unit'] ?? null);
                $ing['ingredient_id'] = $ingredient->getId();
                $ing['name'] = (string)$ingredient->getName();
                $ing['name_key'] = (string)$ingredient->getNameKey();

                $patch['add_ingredients'][$i] = $ing;
            }

            // remove/update => on NE devrait pas créer idéalement.
            // Version actuelle : on resolveOrCreate (ça rend stable mais peut créer si typo).
            // Si tu veux éviter ça, je te donne ensuite resolveOnly() + clarify.
            foreach ($patch['remove_ingredients'] as $i => $ing) {
                if (!is_array($ing)) continue;
                $ing['name_raw'] = trim((string)($ing['name_raw'] ?? ''));
                $ing['name'] = trim((string)($ing['name'] ?? ''));

                $name = trim((string)($ing['name'] ?: $ing['name_raw']));
                if ($name !== '') {
                    $ingredient = $this->ingredientResolver->resolveOrCreate($u, $name, null);
                    $ing['ingredient_id'] = $ingredient->getId();
                    $ing['name'] = (string)$ingredient->getName();
                    $ing['name_key'] = (string)$ingredient->getNameKey();
                }

                $patch['remove_ingredients'][$i] = $ing;
            }

            foreach ($patch['update_ingredients'] as $i => $ing) {
                if (!is_array($ing)) continue;
                $ing = $this->normalizeIngredientRow($ing);

                $name = trim((string)($ing['name'] ?? $ing['name_raw'] ?? ''));
                if ($name !== '') {
                    $ingredient = $this->ingredientResolver->resolveOrCreate($u, $name, $ing['unit'] ?? null);
                    $ing['ingredient_id'] = $ingredient->getId();
                    $ing['name'] = (string)$ingredient->getName();
                    $ing['name_key'] = (string)$ingredient->getNameKey();
                }

                $patch['update_ingredients'][$i] = $ing;
            }
        } else {
            // no user in ctx => juste normalisation
            foreach ($patch['add_ingredients'] as $i => $ing) {
                if (!is_array($ing)) continue;
                $patch['add_ingredients'][$i] = $this->normalizeIngredientRow($ing);
            }
            foreach ($patch['update_ingredients'] as $i => $ing) {
                if (!is_array($ing)) continue;
                $patch['update_ingredients'][$i] = $this->normalizeIngredientRow($ing);
            }
        }

        $draft['patch'] = $patch;

        return $draft;
    }

    public function buildClarifyQuestions(array $draft, AiContext $ctx): array
    {
        $questions = [];

        // Si on a résolu la recette en ambigu -> propose choix
        $resolve = $draft['target']['_resolve'] ?? null;
        if (is_array($resolve) && ($resolve['status'] ?? null) === 'ambiguous') {
            $candidates = $resolve['candidates'] ?? [];
            if (is_array($candidates) && count($candidates) > 0) {
                $questions[] = [
                    'key' => 'recipe_id',
                    'label' => "Quelle recette veux-tu modifier ?",
                    'type' => 'select',
                    'required' => true,
                    'options' => array_map(
                        fn($c) => ['value' => (string)($c['id'] ?? ''), 'label' => (string)($c['name'] ?? '')],
                        $candidates
                    ),
                ];
                return $questions;
            }
        }

        // fallback: texte libre
        $targetName = $draft['target']['recipe_name_raw'] ?? null;
        if (!is_string($targetName) || trim($targetName) === '') {
            $questions[] = [
                'key' => 'target_recipe_name',
                'label' => "Quelle recette veux-tu modifier ? (donne le nom exact ou le plus proche)",
                'type' => 'text',
                'required' => true,
            ];
        }

        $patch = $draft['patch'] ?? [];
        $hasAnyChange =
            (is_string($patch['new_name'] ?? null) && trim((string)$patch['new_name']) !== '') ||
            (is_array($patch['add_ingredients'] ?? null) && count($patch['add_ingredients']) > 0) ||
            (is_array($patch['remove_ingredients'] ?? null) && count($patch['remove_ingredients']) > 0) ||
            (is_array($patch['update_ingredients'] ?? null) && count($patch['update_ingredients']) > 0);

        if (!$hasAnyChange) {
            $questions[] = [
                'key' => 'what_to_change',
                'label' => "Tu veux changer quoi exactement ? (ex: renommer, ajouter/enlever un ingrédient, changer une quantité)",
                'type' => 'text',
                'required' => true,
            ];
        }

        return $questions;
    }

    public function buildConfirmText(array $draft, AiContext $ctx): string
    {
        $target = trim((string)($draft['target']['recipe_name'] ?? $draft['target']['recipe_name_raw'] ?? ''));
        $patch = $draft['patch'] ?? [];

        $parts = [];

        if (($patch['new_name'] ?? null) && is_string($patch['new_name'])) {
            $parts[] = "renommer en « " . trim($patch['new_name']) . " »";
        }

        $addCount = is_array($patch['add_ingredients'] ?? null) ? count($patch['add_ingredients']) : 0;
        if ($addCount > 0) $parts[] = "ajouter " . $addCount . " ingrédient(s)";

        $rmCount = is_array($patch['remove_ingredients'] ?? null) ? count($patch['remove_ingredients']) : 0;
        if ($rmCount > 0) $parts[] = "retirer " . $rmCount . " ingrédient(s)";

        $updCount = is_array($patch['update_ingredients'] ?? null) ? count($patch['update_ingredients']) : 0;
        if ($updCount > 0) $parts[] = "modifier " . $updCount . " ingrédient(s)";

        $summary = count($parts) > 0 ? implode(', ', $parts) : "modifier la recette";

        if ($target !== '') {
            return "Je peux " . $summary . " pour la recette « " . $target . " ». Tu confirmes ?";
        }

        return "Je peux " . $summary . ". Tu confirmes ?";
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
                'unit' => ['type' => ['string', 'null'], 'enum' => ['g','kg','ml','l','piece','pot','boite','sachet','tranche', null]],
                'unit_raw' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['name_raw', 'name', 'quantity', 'quantity_raw', 'unit', 'unit_raw', 'notes', 'confidence'],
        ];
    }

    private function assertPayload(array $payload): void
    {
        $target = $payload['target'] ?? null;
        if (!is_array($target)) {
            throw new \RuntimeException("OpenAI: payload update_recipe invalide (target manquant).");
        }

        if (!array_key_exists('recipe_name_raw', $target)) {
            throw new \RuntimeException("OpenAI: payload update_recipe invalide (target.recipe_name_raw manquant).");
        }

        $patch = $payload['patch'] ?? null;
        if (!is_array($patch)) {
            throw new \RuntimeException("OpenAI: payload update_recipe invalide (patch manquant).");
        }

        foreach (['new_name', 'add_ingredients', 'remove_ingredients', 'update_ingredients'] as $k) {
            if (!array_key_exists($k, $patch)) {
                throw new \RuntimeException("OpenAI: payload update_recipe invalide (patch.$k manquant).");
            }
        }
    }

    private function nullIfBlank(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    private function normalizeIngredientRow(array $ing): array
    {
        $ing['name_raw'] = trim((string)($ing['name_raw'] ?? ''));
        $ing['name'] = trim((string)($ing['name'] ?? ''));

        $ing['quantity_raw'] = $this->nullIfBlank($ing['quantity_raw'] ?? null);
        $ing['unit_raw'] = $this->nullIfBlank($ing['unit_raw'] ?? null);
        $ing['notes'] = $this->nullIfBlank($ing['notes'] ?? null);

        $q = $ing['quantity'] ?? null;
        if (is_numeric($q)) {
            $ing['quantity'] = round((float)$q, 2);
        } else {
            $ing['quantity'] = null;
        }

        $unit = $ing['unit'] ?? null;
        $allowedUnits = ['g','kg','ml','l','piece','pot','boite','sachet','tranche', null];
        if (!in_array($unit, $allowedUnits, true)) {
            $ing['unit'] = null;
        }

        return $ing;
    }
}
