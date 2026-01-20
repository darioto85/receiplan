<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Service\Ai\AssistantOrchestrator;
use App\Service\Ai\Action\AiContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AssistantController extends AbstractController
{
    public function __construct(
        private readonly AssistantConversationRepository $conversationRepo,
        private readonly AssistantMessageRepository $messageRepo,
        private readonly EntityManagerInterface $em,
        private readonly AssistantOrchestrator $assistantOrchestrator,
        private readonly KernelInterface $kernel,
    ) {}

    #[Route('/assistant', name: 'assistant', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('assistant/chat.html.twig');
    }

    #[Route('/assistant/history', name: 'assistant_history', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => ['code' => 'unauthorized', 'message' => 'Non authentifié.']], 401);
        }

        $today = new \DateTimeImmutable('today');
        $conversation = $this->conversationRepo->findForUserAndDay($user, $today);

        if (!$conversation instanceof AssistantConversation) {
            return $this->json(['day' => $today->format('Y-m-d'), 'messages' => []]);
        }

        $messages = $this->messageRepo->findForConversation($conversation);

        return $this->json([
            'day' => $today->format('Y-m-d'),
            'messages' => array_map([$this, 'serializeMessage'], $messages),
        ]);
    }

    /**
     * Étape 1: Proposer une action (confirm/clarify) sans appliquer.
     */
    #[Route('/assistant/message', name: 'assistant_message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => ['code' => 'unauthorized', 'message' => 'Non authentifié.']], 401);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return $this->json(['error' => ['code' => 'invalid_json', 'message' => 'JSON invalide.']], 400);
        }

        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '') {
            return $this->json(['error' => ['code' => 'missing_text', 'message' => 'Champ text requis.']], 422);
        }

        $today = new \DateTimeImmutable('today');

        $conversation = $this->conversationRepo->findForUserAndDay($user, $today);
        if (!$conversation instanceof AssistantConversation) {
            $conversation = new AssistantConversation($user, $today);
            $this->em->persist($conversation);
        }

        // Persist user message
        $userMsg = new AssistantMessage($conversation, AssistantMessage::ROLE_USER, $text);
        $this->em->persist($userMsg);

        try {
            $ctx = new AiContext(locale: (string)($payload['locale'] ?? 'fr-FR'), debug: $this->kernel->isDebug());

            [$assistantText, $assistantPayload] = $this->assistantOrchestrator->propose($user, $text, $ctx);

            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                $assistantText,
                $assistantPayload
            );
            $this->em->persist($assistantMsg);

            $this->em->flush();

            return $this->json([
                'day' => $today->format('Y-m-d'),
                'messages' => [$this->serializeMessage($assistantMsg)],
            ]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                "⚠️ Désolé, je n’ai pas réussi à analyser ta demande. Réessaie.",
                $debug ? ['error' => ['type' => get_class($e), 'detail' => $e->getMessage()]] : null
            );
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json([
                'day' => $today->format('Y-m-d'),
                'messages' => [$this->serializeMessage($assistantMsg)],
            ], 200);
        }
    }

    /**
     * Étape 2 (+ Étape 4 override): appliquer / annuler après confirmation user.
     * Body: { "message_id": 123, "decision": "yes"|"no", "action_payload"?: {...} }
     */
    #[Route('/assistant/confirm', name: 'assistant_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => ['code' => 'unauthorized', 'message' => 'Non authentifié.']], 401);
        }

        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return $this->json(['error' => ['code' => 'invalid_json', 'message' => 'JSON invalide.']], 400);
        }

        $messageId = $body['message_id'] ?? null;
        $decision = (string)($body['decision'] ?? '');
        $overridePayload = $body['action_payload'] ?? null;

        if (!is_numeric($messageId)) {
            return $this->json(['error' => ['code' => 'missing_message_id', 'message' => 'message_id requis.']], 422);
        }
        if (!in_array($decision, ['yes', 'no'], true)) {
            return $this->json(['error' => ['code' => 'invalid_decision', 'message' => 'decision doit être yes ou no.']], 422);
        }

        /** @var AssistantMessage|null $confirmMsg */
        $confirmMsg = $this->em->getRepository(AssistantMessage::class)->find((int)$messageId);
        if (!$confirmMsg instanceof AssistantMessage) {
            return $this->json(['error' => ['code' => 'message_not_found', 'message' => 'Message introuvable.']], 404);
        }

        $conversation = $confirmMsg->getConversation();
        if ($conversation->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => ['code' => 'forbidden', 'message' => 'Accès interdit.']], 403);
        }

        $p = $confirmMsg->getPayload();
        if (!is_array($p) || ($p['type'] ?? null) !== 'confirm') {
            return $this->json(['error' => ['code' => 'not_confirmable', 'message' => 'Ce message ne demande pas de confirmation.']], 422);
        }

        // idempotence
        if (($p['confirmed'] ?? null) !== null) {
            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                "C’est déjà pris en compte.",
                ['type' => 'info', 'ref_message_id' => $confirmMsg->getId()]
            );
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]]);
        }

        $p['confirmed'] = $decision;
        $p['confirmed_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $action = (string)($p['action'] ?? 'unknown');
        $draft = $p['action_payload'] ?? ($p['draft'] ?? null);

        if ($decision === 'yes' && is_array($overridePayload)) {
            $draft = $overridePayload;
            $p['edited'] = true;
            $p['edited_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }

        try {
            if ($decision === 'no') {
                $confirmMsg->setPayload($p);

                $assistantMsg = new AssistantMessage(
                    $conversation,
                    AssistantMessage::ROLE_ASSISTANT,
                    "OK, j’annule 👍",
                    ['type' => 'cancel', 'ref_message_id' => $confirmMsg->getId()]
                );
                $this->em->persist($assistantMsg);
                $this->em->flush();

                return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]]);
            }

            if (!is_array($draft)) {
                throw new \RuntimeException('missing_action_payload');
            }

            // persist final draft used
            $p['action_payload'] = $draft;
            $p['draft'] = $draft;
            $confirmMsg->setPayload($p);

            // apply
            [$fallbackText, $appliedPayload] = $this->assistantOrchestrator->apply($user, $action, $draft);

            // nicer user-facing text (keep your current behavior)
            $result = $appliedPayload['result'] ?? null;
            $assistantText = match ($action) {
                'add_stock' => $this->formatStockAppliedResult($result),
                'add_recipe' => $this->formatRecipeAppliedResult($result),
                default => "✅ C’est fait.",
            };

            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                $assistantText ?: ($fallbackText ?: "✅ C’est fait."),
                array_merge($appliedPayload, ['ref_message_id' => $confirmMsg->getId()])
            );
            $this->em->persist($assistantMsg);

            $this->em->flush();

            return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            $p['apply_error'] = $debug
                ? ['type' => get_class($e), 'detail' => $e->getMessage()]
                : true;

            $confirmMsg->setPayload($p);

            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                "⚠️ Je n’ai pas réussi à appliquer. Tu peux réessayer ou reformuler.",
                $debug ? ['error' => ['type' => get_class($e), 'detail' => $e->getMessage()]] : null
            );
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]], 200);
        }
    }

    #[Route('/assistant/clarify', name: 'assistant_clarify', methods: ['POST'])]
    public function clarify(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => ['code' => 'unauthorized', 'message' => 'Non authentifié.']], 401);
        }

        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            return $this->json(['error' => ['code' => 'invalid_json', 'message' => 'JSON invalide.']], 400);
        }

        $messageId = $body['message_id'] ?? null;
        $answers = $body['answers'] ?? null;

        if (!is_numeric($messageId)) {
            return $this->json(['error' => ['code' => 'missing_message_id', 'message' => 'message_id requis.']], 422);
        }
        if (!is_array($answers)) {
            return $this->json(['error' => ['code' => 'missing_answers', 'message' => 'answers requis.']], 422);
        }

        /** @var AssistantMessage|null $clarifyMsg */
        $clarifyMsg = $this->em->getRepository(AssistantMessage::class)->find((int)$messageId);
        if (!$clarifyMsg instanceof AssistantMessage) {
            return $this->json(['error' => ['code' => 'message_not_found', 'message' => 'Message introuvable.']], 404);
        }

        $conversation = $clarifyMsg->getConversation();
        if ($conversation->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => ['code' => 'forbidden', 'message' => 'Accès interdit.']], 403);
        }

        $p = $clarifyMsg->getPayload();
        if (!is_array($p) || ($p['type'] ?? null) !== 'clarify') {
            return $this->json(['error' => ['code' => 'not_clarifiable', 'message' => 'Ce message ne demande pas de précision.']], 422);
        }

        // idempotence
        if (($p['clarified'] ?? null) === true) {
            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                "C’est déjà pris en compte.",
                ['type' => 'info', 'ref_message_id' => $clarifyMsg->getId()]
            );
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]]);
        }

        $action = (string)($p['action'] ?? 'unknown');
        $draft = $p['action_payload'] ?? ($p['draft'] ?? null);

        // Safe: clarify only for add_stock (same as before)
        if ($action !== 'add_stock' || !is_array($draft)) {
            return $this->json(['error' => ['code' => 'unsupported_action', 'message' => 'Clarify non supporté pour cette action.']], 422);
        }

        try {
            $updatedDraft = $this->assistantOrchestrator->applyClarifyAnswers($action, $draft, $answers);

            $p['action_payload'] = $updatedDraft;
            $p['draft'] = $updatedDraft;
            $p['clarified'] = true;
            $p['clarified_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $p['answers'] = $answers;
            $clarifyMsg->setPayload($p);

            // Build a confirm message after clarify:
            // We keep behavior stable by re-using the orchestrator propose() confirm text builder:
            // (No new OpenAI call; we just generate confirm text)
            $ctx = new AiContext(locale: (string)($body['locale'] ?? 'fr-FR'), debug: $this->kernel->isDebug());

            // We can't call propose() because it would re-run classification+extraction.
            // So we generate confirm text locally for add_stock (stable).
            $assistantText = $this->buildConfirmTextForStock($updatedDraft);

            $confirmMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                $assistantText,
                [
                    'type' => 'confirm',
                    'action' => $action,
                    'action_payload' => $updatedDraft,
                    'draft' => $updatedDraft,
                    'confirmed' => null,
                    'confirmed_at' => null,
                    'from_clarify_message_id' => $clarifyMsg->getId(),
                ]
            );
            $this->em->persist($confirmMsg);

            $this->em->flush();

            return $this->json(['messages' => [$this->serializeMessage($confirmMsg)]]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                "⚠️ Je n’ai pas réussi à prendre en compte ces précisions. Réessaie.",
                $debug ? ['error' => ['type' => get_class($e), 'detail' => $e->getMessage()]] : null
            );
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]], 200);
        }
    }

    // ---------------------------
    // Helpers (UI text + serialization)
    // ---------------------------

    /**
     * Keep this helper here for now to keep behavior stable.
     * Later, move into AddStockAiAction (single source of truth).
     */
    private function buildConfirmTextForStock(array $payloadAi): string
    {
        $items = $payloadAi['items'] ?? null;
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

    private function formatStockAppliedResult(mixed $result): string
    {
        if (!is_array($result)) return "✅ Stock mis à jour.";

        $updated = $result['updated'] ?? $result['added'] ?? null;
        $parts = [];

        if (is_numeric($updated)) $parts[] = "✅ Stock mis à jour (".$updated." élément(s)).";
        else $parts[] = "✅ Stock mis à jour.";

        $warningsText = $this->stringifyWarnings($result['warnings'] ?? null);
        if ($warningsText) $parts[] = "ℹ️ Notes : ".$warningsText;

        return implode(' ', $parts);
    }

    private function formatRecipeAppliedResult(mixed $result): string
    {
        if (!is_array($result)) return "✅ Recette ajoutée.";

        // handler retourne ['recipe' => ['id'=>..., 'name'=>...]]
        $name = null;
        if (isset($result['recipe']) && is_array($result['recipe'])) {
            $name = $result['recipe']['name'] ?? null;
        } else {
            $name = $result['name'] ?? $result['recipe_name'] ?? null;
        }

        $parts = [];
        if (is_string($name) && $name !== '') $parts[] = "✅ Recette enregistrée : ".$name.".";
        else $parts[] = "✅ Recette enregistrée.";

        $warningsText = $this->stringifyWarnings($result['warnings'] ?? null);
        if ($warningsText) $parts[] = "ℹ️ Notes : ".$warningsText;

        return implode(' ', $parts);
    }

    private function stringifyWarnings(mixed $warnings): ?string
    {
        if (!is_array($warnings) || count($warnings) === 0) return null;

        $parts = [];
        foreach ($warnings as $w) {
            if (is_string($w) && trim($w) !== '') {
                $parts[] = $w;
                continue;
            }
            if (is_scalar($w)) {
                $parts[] = (string)$w;
                continue;
            }
            $json = json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $parts[] = $json !== false ? $json : '[warning]';
        }
        return implode(' · ', $parts);
    }

    private function serializeMessage(AssistantMessage $m): array
    {
        return [
            'id' => $m->getId(),
            'role' => $m->getRole(),
            'content' => $m->getContent(),
            'payload' => $m->getPayload(),
            'created_at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
