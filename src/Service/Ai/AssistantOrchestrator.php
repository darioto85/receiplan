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
        $actionName = $this->classifier->classify($text);

        if ($actionName === 'unknown' || !$this->registry->has($actionName)) {
            return [
                "Je ne suis pas sûr de ce que tu veux faire. Tu peux reformuler ?",
                $ctx->debug ? ['type' => 'unknown', 'action' => $actionName] : null,
            ];
        }

        $action = $this->registry->get($actionName);

        $draft = $action->extractDraft($text, $ctx);
        $draft = $action->normalizeDraft($draft, $ctx);

        $questions = $action->buildClarifyQuestions($draft, $ctx);

        if (count($questions) > 0) {
            return [
                "J’ai besoin d’une précision avant de continuer :",
                [
                    'type' => 'clarify',
                    'action' => $actionName,
                    'action_payload' => $draft, // compat front
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
                'action_payload' => $draft, // compat front
                'draft' => $draft,
                'confirmed' => null,
                'confirmed_at' => null,
            ],
        ];
    }

    /**
     * @return array{0:string,1:array} assistantText + appliedPayload
     */
    public function apply(User $user, string $actionName, array $draft): array
    {
        $action = $this->registry->get($actionName);
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
     * Clarify: applique les answers sur le draft via la convention "items.0.quantity"
     * (On garde ton comportement existant, puis on pourra rendre ça action-specific plus tard si besoin.)
     */
    public function applyClarifyAnswers(string $actionName, array $draft, array $answers): array
    {
        if ($actionName !== 'add_stock') {
            throw new \RuntimeException('clarify_not_supported_for_action');
        }

        if (!isset($draft['items']) || !is_array($draft['items'])) {
            return $draft;
        }

        foreach ($answers as $path => $value) {
            if (!is_string($path)) continue;

            if (!preg_match('/^items\.(\d+)\.(quantity|unit)$/', $path, $m)) continue;

            $idx = (int)$m[1];
            $field = $m[2];

            if (!isset($draft['items'][$idx]) || !is_array($draft['items'][$idx])) continue;

            if ($field === 'quantity') {
                if ($value === null || $value === '' || !is_numeric($value)) continue;
                $draft['items'][$idx]['quantity'] = (float)$value;
                $draft['items'][$idx]['quantity_raw'] = (string)$value;
            } elseif ($field === 'unit') {
                $v = is_string($value) ? trim($value) : '';
                if ($v === '') continue;
                $draft['items'][$idx]['unit'] = $v;
                $draft['items'][$idx]['unit_raw'] = null;
            }
        }

        return $draft;
    }
}
