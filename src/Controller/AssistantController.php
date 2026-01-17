<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Service\AiStockParser;
use App\Service\Ai\AiAddRecipeHandler;
use App\Service\Ai\AiAddStockHandler;
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

        // IA
        private readonly AiStockParser $aiStockParser,
        private readonly AiAddRecipeHandler $aiAddRecipeHandler,
        private readonly AiAddStockHandler $aiAddStockHandler,

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
            return $this->json([
                'day' => $today->format('Y-m-d'),
                'messages' => [],
            ]);
        }

        $messages = $this->messageRepo->findForConversation($conversation);

        return $this->json([
            'day' => $today->format('Y-m-d'),
            'messages' => array_map([$this, 'serializeMessage'], $messages),
        ]);
    }

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

        // 1) get or create conversation du jour
        $conversation = $this->conversationRepo->findForUserAndDay($user, $today);
        if (!$conversation instanceof AssistantConversation) {
            $conversation = new AssistantConversation($user, $today);
            $this->em->persist($conversation);
        }

        // 2) persist message user
        $userMsg = new AssistantMessage($conversation, AssistantMessage::ROLE_USER, $text);
        $this->em->persist($userMsg);

        // 3) IA: parse uniquement -> on propose une action à confirmer
        try {
            $parse = $this->aiStockParser->parse($text);

            $action = (string)($parse['action'] ?? 'unknown');
            $payloadAi = $parse['payload'] ?? null;

            $assistantText = match ($action) {
                'add_stock'  => $this->buildConfirmTextForStock($payloadAi),
                'add_recipe' => $this->buildConfirmTextForRecipe($payloadAi),
                default      => "Je ne suis pas sûr de ce que tu veux faire. Tu peux reformuler ?",
            };

            $assistantPayload = null;

            if (in_array($action, ['add_stock', 'add_recipe'], true) && is_array($payloadAi)) {
                $assistantPayload = [
                    'type' => 'confirm',
                    'action' => $action,
                    'action_payload' => $payloadAi,
                    'confirmed' => null, // yes/no plus tard
                    'confirmed_at' => null,
                    'parse' => $parse, // utile debug
                ];
            }

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
                'messages' => [
                    $this->serializeMessage($assistantMsg),
                ],
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

    #[Route('/assistant/confirm', name: 'assistant_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => ['code' => 'unauthorized', 'message' => 'Non authentifié.']], 401);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return $this->json(['error' => ['code' => 'invalid_json', 'message' => 'JSON invalide.']], 400);
        }

        $messageId = $payload['message_id'] ?? null;
        $decision = (string)($payload['decision'] ?? '');

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

        // sécurité : le message doit appartenir au user courant
        $conversation = $confirmMsg->getConversation();
        if ($conversation->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => ['code' => 'forbidden', 'message' => 'Accès interdit.']], 403);
        }

        $p = $confirmMsg->getPayload();
        if (!is_array($p) || ($p['type'] ?? null) !== 'confirm') {
            return $this->json(['error' => ['code' => 'not_confirmable', 'message' => 'Ce message ne demande pas de confirmation.']], 422);
        }

        // idempotence : si déjà confirmé
        if (($p['confirmed'] ?? null) !== null) {
            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                "ℹ️ C’est déjà pris en compte.",
                ['type' => 'info', 'ref_message_id' => $confirmMsg->getId()]
            );
            $this->em->persist($assistantMsg);
            $this->em->flush();

            return $this->json([
                'messages' => [$this->serializeMessage($assistantMsg)],
            ]);
        }

        // marquer la décision sur le message de confirmation
        $p['confirmed'] = $decision;
        $p['confirmed_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $action = (string)($p['action'] ?? 'unknown');
        $actionPayload = $p['action_payload'] ?? null;

        $newAssistantMessages = [];

        try {
            if ($decision === 'no') {
                $confirmMsg->setPayload($p);

                $assistantMsg = new AssistantMessage(
                    $conversation,
                    AssistantMessage::ROLE_ASSISTANT,
                    "OK, je ne fais rien 👍",
                    ['type' => 'cancel', 'ref_message_id' => $confirmMsg->getId()]
                );
                $this->em->persist($assistantMsg);

                $this->em->flush();

                return $this->json([
                    'messages' => [$this->serializeMessage($assistantMsg)],
                ]);
            }

            // decision === 'yes' => APPLY
            if (!is_array($actionPayload)) {
                throw new \RuntimeException('missing_action_payload');
            }

            $result = null;
            $assistantText = null;

            switch ($action) {
                case 'add_stock':
                    $result = $this->aiAddStockHandler->handle($user, $actionPayload);
                    $assistantText = $this->formatStockAppliedResult($result);
                    break;

                case 'add_recipe':
                    $result = $this->aiAddRecipeHandler->handle($user, $actionPayload);
                    $assistantText = $this->formatRecipeAppliedResult($result);
                    break;

                default:
                    $assistantText = "Je ne sais pas appliquer cette action pour le moment.";
                    break;
            }

            $confirmMsg->setPayload($p);

            $assistantMsg = new AssistantMessage(
                $conversation,
                AssistantMessage::ROLE_ASSISTANT,
                $assistantText ?? "✅ C’est fait.",
                [
                    'type' => 'applied',
                    'action' => $action,
                    'result' => $result,
                    'ref_message_id' => $confirmMsg->getId(),
                ]
            );
            $this->em->persist($assistantMsg);

            $this->em->flush();

            return $this->json([
                'messages' => [$this->serializeMessage($assistantMsg)],
            ]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            // On enregistre quand même le choix "yes" mais on note l'erreur
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

            return $this->json([
                'messages' => [$this->serializeMessage($assistantMsg)],
            ], 200);
        }
    }

    // ---------------------------
    // Builders (proposition)
    // ---------------------------

    private function buildConfirmTextForStock(mixed $payloadAi): string
    {
        // attendu: ['items' => [...]] souvent
        if (!is_array($payloadAi)) {
            return "Je peux ajouter au stock. Tu confirmes ?";
        }

        $items = $payloadAi['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return "Je peux ajouter au stock. Tu confirmes ?";
        }

        $lines = [];
        foreach (array_slice($items, 0, 5) as $it) {
            if (!is_array($it)) continue;
            $name = (string)($it['name'] ?? $it['name_raw'] ?? '');
            if ($name === '') continue;

            $q = $it['quantity'] ?? null;
            $u = $it['unit'] ?? null;

            $part = $name;
            if ($q !== null && $q !== '') $part = $q.' '.$part;
            if ($u !== null && $u !== '') $part .= ' ('.$u.')';

            $lines[] = $part;
        }

        $summary = count($lines) > 0 ? implode(', ', $lines) : "des éléments";
        $more = (is_array($items) && count($items) > 5) ? " (+".(count($items) - 5).")" : "";

        return "Je peux ajouter au stock : ".$summary.$more.". Tu confirmes ?";
    }

    private function buildConfirmTextForRecipe(mixed $payloadAi): string
    {
        if (!is_array($payloadAi)) {
            return "Je peux ajouter une recette. Tu confirmes ?";
        }

        $name = (string)($payloadAi['name'] ?? $payloadAi['recipe_name'] ?? '');
        if ($name !== '') {
            return "Je peux ajouter la recette « ".$name." ». Tu confirmes ?";
        }

        return "Je peux ajouter une recette. Tu confirmes ?";
    }

    // ---------------------------
    // Formatters (après apply)
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

        $name = $result['name'] ?? $result['recipe_name'] ?? null;
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
