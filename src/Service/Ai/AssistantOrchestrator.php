<?php

namespace App\Service\Ai;

use App\Entity\User;
use App\Service\Ai\Action\ActionRegistry;
use App\Service\Ai\Action\AiContext;
use App\Service\Ai\OpenAi\AiIntentClassifier;

final class AssistantOrchestrator
{
    public function __construct(
        private readonly AiIntentClassifier $classifier,
        private readonly ActionRegistry $registry,
    ) {}

    /**
     * Retourne [assistantText, assistantPayload]
     *
     * @return array{0:string,1:?array}
     */
    public function propose(User $user, string $text, AiContext $ctx): array
    {
        $text = trim($text);
        if ($text === '') {
            return [
                "Tu peux reformuler ?",
                $ctx->debug ? ['type' => 'empty_text'] : null,
            ];
        }

        $actionName = $this->classifier->classify($text);

        // Action inconnue / pas encore enregistrée
        if ($actionName === 'unknown' || !$this->registry->has($actionName)) {
            return [
                "Je ne suis pas sûr de ce que tu veux faire. Tu peux reformuler ?",
                $ctx->debug ? [
                    'type' => 'unknown',
                    'action' => $actionName,
                    'registry_has' => $this->registry->has($actionName),
                ] : null,
            ];
        }

        $action = $this->registry->get($actionName);

        try {
            $draft = $action->extractDraft($text, $ctx);
            if (!is_array($draft)) {
                throw new \RuntimeException('ai_action_invalid_draft');
            }

            $draft = $action->normalizeDraft($draft, $ctx);
            if (!is_array($draft)) {
                throw new \RuntimeException('ai_action_invalid_normalized_draft');
            }

            $questions = $action->buildClarifyQuestions($draft, $ctx);

            if (count($questions) > 0) {
                return [
                    "J’ai besoin d’une précision avant de continuer :",
                    [
                        'type' => 'clarify',
                        'action' => $actionName,
                        // compat front
                        'action_payload' => $draft,
                        'draft' => $draft,
                        'questions' => $questions,
                        'clarified' => null,
                        'clarified_at' => null,
                    ],
                ];
            }

            return [
                $action->buildConfirmText($draft, $ctx),
                [
                    'type' => 'confirm',
                    'action' => $actionName,
                    // compat front
                    'action_payload' => $draft,
                    'draft' => $draft,
                    'confirmed' => null,
                    'confirmed_at' => null,
                ],
            ];
        } catch (\Throwable $e) {
            // L’orchestrator ne doit jamais casser la requête
            return [
                "⚠️ Désolé, je n’ai pas réussi à analyser ta demande. Réessaie.",
                $ctx->debug ? [
                    'type' => 'propose_error',
                    'action' => $actionName,
                    'error' => [
                        'class' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ] : null,
            ];
        }
    }

    /**
     * @return array{0:string,1:array} assistantText + appliedPayload
     */
    public function apply(User $user, string $actionName, array $draft, AiContext $ctx): array
    {
        if (!$this->registry->has($actionName)) {
            throw new \RuntimeException('unknown_ai_action');
        }

        $action = $this->registry->get($actionName);

        // Optionnel: renormaliser (draft édité côté UI)
        $draft = $action->normalizeDraft($draft, $ctx);

        $result = $action->apply($user, $draft);

        return [
            "✅ C’est fait.",
            [
                'type' => 'applied',
                'action' => $actionName,
                'result' => $result,
            ],
        ];
    }

    /**
     * Clarify: applique des answers sur le draft.
     * - Stock-like (items.N.quantity/unit)
     * - Plan/unplan (date, recipe_id, recipe.name)
     */
    public function applyClarifyAnswers(string $actionName, array $draft, array $answers, AiContext $ctx): array
    {
        // ✅ 1) Plan/unplan : paths simples
        if (in_array($actionName, ['plan_recipe', 'unplan_recipe'], true)) {
            foreach ($answers as $path => $value) {
                if (!is_string($path)) {
                    continue;
                }

                if ($path === 'date') {
                    $draft['date'] = is_string($value) ? trim($value) : (string) $value;
                    continue;
                }

                if ($path === 'recipe_id') {
                    if (is_numeric($value)) {
                        $draft['recipe_id'] = (int) $value;
                    }
                    continue;
                }

                if ($path === 'recipe.name') {
                    if (!isset($draft['recipe']) || !is_array($draft['recipe'])) {
                        $draft['recipe'] = ['name_raw' => '', 'name' => ''];
                    }
                    $v = is_string($value) ? trim($value) : (string) $value;
                    $draft['recipe']['name'] = $v;
                    $draft['recipe']['name_raw'] = $draft['recipe']['name_raw'] ?: $v;
                    continue;
                }
            }

            // Re-normalize (utile si l’action transforme/complète)
            if ($this->registry->has($actionName)) {
                $action = $this->registry->get($actionName);
                $draft = $action->normalizeDraft($draft, $ctx);
            }

            return $draft;
        }

        // ✅ 1bis) update_recipe : gestion des réponses clarify
        if ($actionName === 'update_recipe') {
            foreach ($answers as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                // Clarify: nom de la recette cible
                if ($key === 'target_recipe_name') {
                    if (!isset($draft['target']) || !is_array($draft['target'])) {
                        $draft['target'] = ['recipe_name_raw' => null];
                    }

                    $v = is_string($value) ? trim($value) : (string) $value;
                    $draft['target']['recipe_name_raw'] = $v !== '' ? $v : null;
                    continue;
                }

                // Clarify: description libre du changement (fallback)
                if ($key === 'what_to_change') {
                    $v = is_string($value) ? trim($value) : (string) $value;
                    if (!isset($draft['_clarify_hints']) || !is_array($draft['_clarify_hints'])) {
                        $draft['_clarify_hints'] = [];
                    }
                    $draft['_clarify_hints']['what_to_change'] = $v;
                    continue;
                }
            }

            // Re-normalisation après injection des réponses
            if ($this->registry->has($actionName)) {
                $action = $this->registry->get($actionName);
                $draft = $action->normalizeDraft($draft, $ctx);
            }

            return $draft;
        }
        // ✅ 2) Legacy: items.N.quantity/unit (add_stock, shopping, update_stock, consume...)
        if (!isset($draft['items']) || !is_array($draft['items'])) {
            return $draft;
        }

        foreach ($answers as $path => $value) {
            if (!is_string($path)) continue;
            if (!preg_match('/^items\.(\d+)\.(quantity|unit)$/', $path, $m)) continue;

            $idx = (int) $m[1];
            $field = $m[2];

            if (!isset($draft['items'][$idx]) || !is_array($draft['items'][$idx])) continue;

            if ($field === 'quantity') {
                if ($value === null || $value === '' || !is_numeric($value)) continue;
                $draft['items'][$idx]['quantity'] = (float) $value;
                $draft['items'][$idx]['quantity_raw'] = (string) $value;
            } elseif ($field === 'unit') {
                $v = is_string($value) ? trim($value) : '';
                if ($v === '') continue;
                $draft['items'][$idx]['unit'] = $v;
                $draft['items'][$idx]['unit_raw'] = null;
            }
        }

        // Re-normalize (defaults, cleanups)
        if ($this->registry->has($actionName)) {
            $action = $this->registry->get($actionName);
            $draft = $action->normalizeDraft($draft, $ctx);
        }

        return $draft;
    }
}
