<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Service\Ai\Action\ActionRegistry;
use App\Service\Ai\Action\AiContext;
use App\Service\Ai\AssistantOrchestrator;
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
        private readonly ActionRegistry $registry,
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

        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return $this->json(['error' => ['code' => 'missing_text', 'message' => 'Champ text requis.']], 422);
        }

        $today = new \DateTimeImmutable('today');

        $conversation = $this->conversationRepo->findForUserAndDay($user, $today);
        if (!$conversation instanceof AssistantConversation) {
            $conversation = new AssistantConversation($user, $today);
            $this->em->persist($conversation);
        }

        $userMsg = new AssistantMessage($conversation, AssistantMessage::ROLE_USER, $text);
        $this->em->persist($userMsg);

        try {
            $ctx = new AiContext(
                locale: (string)($payload['locale'] ?? 'fr-FR'),
                debug: $this->kernel->isDebug()
            );

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
     * Étape 2: appliquer / annuler après confirmation user.
     * Body: { "message_id": 123, "decision": "yes"|"no", "action_payload"?: {...}, "locale"?: "fr-FR" }
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
        $decision = (string) ($body['decision'] ?? '');
        $overridePayload = $body['action_payload'] ?? null;

        if (!is_numeric($messageId)) {
            return $this->json(['error' => ['code' => 'missing_message_id', 'message' => 'message_id requis.']], 422);
        }
        if (!in_array($decision, ['yes', 'no'], true)) {
            return $this->json(['error' => ['code' => 'invalid_decision', 'message' => 'decision doit être yes ou no.']], 422);
        }

        /** @var AssistantMessage|null $confirmMsg */
        $confirmMsg = $this->em->getRepository(AssistantMessage::class)->find((int) $messageId);
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

        $action = (string) ($p['action'] ?? 'unknown');
        $draft = $p['draft'] ?? ($p['action_payload'] ?? null);

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

            $p['action_payload'] = $draft;
            $p['draft'] = $draft;
            $confirmMsg->setPayload($p);

            $ctx = new AiContext(
                locale: (string)($body['locale'] ?? 'fr-FR'),
                debug: $this->kernel->isDebug()
            );

            // ✅ FIX: apply() attend 4 args
            [$fallbackText, $appliedPayload] = $this->assistantOrchestrator->apply($user, $action, $draft, $ctx);

            $result = $appliedPayload['result'] ?? null;
            $assistantText = match ($action) {
                'add_stock' => $this->formatStockAppliedResult($result),
                'add_recipe' => $this->formatRecipeAppliedResult($result),
                default => ($fallbackText ?: "✅ C’est fait."),
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

    /**
     * Clarify: applique answers sur le draft, puis renvoie soit un nouveau clarify, soit un confirm.
     * Body: { "message_id": 123, "answers": { ... }, "locale"?: "fr-FR" }
     */
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
        $clarifyMsg = $this->em->getRepository(AssistantMessage::class)->find((int) $messageId);
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

        $action = (string) ($p['action'] ?? 'unknown');
        $draft = $p['draft'] ?? ($p['action_payload'] ?? null);

        if (!is_array($draft)) {
            return $this->json(['error' => ['code' => 'missing_draft', 'message' => 'Draft manquant.']], 422);
        }

        if (!$this->registry->has($action)) {
            return $this->json(['error' => ['code' => 'unknown_action', 'message' => 'Action inconnue.']], 422);
        }

        try {
            $ctx = new AiContext(
                locale: (string)($body['locale'] ?? 'fr-FR'),
                debug: $this->kernel->isDebug()
            );

            // ✅ FIX: si ton orchestrator a aussi besoin de ctx ici, ajoute-le.
            // Si ta signature est (string, array, array, AiContext) -> OK.
            $updatedDraft = $this->assistantOrchestrator->applyClarifyAnswers($action, $draft, $answers, $ctx);

            // persist updated draft on clarify message
            $p['action_payload'] = $updatedDraft;
            $p['draft'] = $updatedDraft;
            $p['clarified'] = true;
            $p['clarified_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $p['answers'] = $answers;
            $clarifyMsg->setPayload($p);

            $aiAction = $this->registry->get($action);

            // re-normalize after answers (local, no OpenAI)
            $updatedDraft = $aiAction->normalizeDraft($updatedDraft, $ctx);

            $questions = $aiAction->buildClarifyQuestions($updatedDraft, $ctx);

            if (count($questions) > 0) {
                $assistantMsg = new AssistantMessage(
                    $conversation,
                    AssistantMessage::ROLE_ASSISTANT,
                    "J’ai encore besoin d’une précision :",
                    [
                        'type' => 'clarify',
                        'action' => $action,
                        'action_payload' => $updatedDraft,
                        'draft' => $updatedDraft,
                        'questions' => $questions,
                        'clarified' => null,
                        'clarified_at' => null,
                        'from_clarify_message_id' => $clarifyMsg->getId(),
                    ]
                );
                $this->em->persist($assistantMsg);
                $this->em->flush();

                return $this->json(['messages' => [$this->serializeMessage($assistantMsg)]]);
            }

            $confirmText = $aiAction->buildConfirmText($updatedDraft, $ctx);

            $confirmMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                $confirmText,
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
    // Helpers
    // ---------------------------

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
