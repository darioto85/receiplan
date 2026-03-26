<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\User;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Service\Assistant\AssistantConversationFlow;
use App\Service\Assistant\AssistantMessageManager;
use App\Service\Premium\PremiumAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AssistantChatController extends AbstractController
{
    public function __construct(
        private readonly AssistantConversationFlow $conversationFlow,
        private readonly AssistantMessageManager $messageManager,
        private readonly AssistantConversationRepository $conversationRepository,
        private readonly AssistantMessageRepository $messageRepository,
        private readonly KernelInterface $kernel,
        private readonly PremiumAccessChecker $premiumAccessChecker,
    ) {
    }

    #[Route('/assistant/chat', name: 'assistant', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($this->premiumAccessChecker->shouldRedirectToPremium($user)) {
            return $this->redirectToRoute('premium_index');
        }

        return $this->render('assistant/chat.html.twig');
    }

    #[Route('/assistant/chat/history', name: 'assistant_chat_history', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Non authentifié.',
                ],
            ], 401);
        }

        if ($this->premiumAccessChecker->shouldRedirectToPremium($user)) {
            return $this->json([
                'error' => [
                    'code' => 'premium_required',
                    'message' => 'Cette fonctionnalité nécessite un accès premium.',
                ],
                'redirect_url' => $this->generateUrl('premium_index'),
            ], 403);
        }

        $todayStart = new \DateTimeImmutable('today');
        $tomorrowStart = $todayStart->modify('+1 day');

        $qb = $this->conversationRepository->createQueryBuilder('c');
        $conversation = $qb
            ->andWhere('c.user = :user')
            ->andWhere('c.createdAt >= :start')
            ->andWhere('c.createdAt < :end')
            ->setParameter('user', $user)
            ->setParameter('start', $todayStart)
            ->setParameter('end', $tomorrowStart)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$conversation instanceof AssistantConversation) {
            return $this->json([
                'messages' => [],
            ]);
        }

        $messages = $this->messageRepository->findBy(
            ['conversation' => $conversation],
            ['createdAt' => 'ASC']
        );

        return $this->json([
            'messages' => $this->messageManager->serializeMany($messages),
        ]);
    }

    #[Route('/assistant/chat/message', name: 'assistant_chat_message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json([
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'Non authentifié.',
                ],
            ], 401);
        }

        if ($this->premiumAccessChecker->shouldRedirectToPremium($user)) {
            return $this->json([
                'error' => [
                    'code' => 'premium_required',
                    'message' => 'Cette fonctionnalité nécessite un accès premium.',
                ],
                'redirect_url' => $this->generateUrl('premium_index'),
            ], 403);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);

        if (!is_array($payload)) {
            return $this->json([
                'error' => [
                    'code' => 'invalid_json',
                    'message' => 'JSON invalide.',
                ],
            ], 400);
        }

        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            return $this->json([
                'error' => [
                    'code' => 'missing_text',
                    'message' => 'Champ text requis.',
                ],
            ], 422);
        }

        try {
            $result = $this->conversationFlow->handleUserMessage($user, $text);

            /** @var AssistantMessage $assistantMessage */
            $assistantMessage = $result['assistant_message'];

            $response = [
                'messages' => [
                    $this->messageManager->serialize($assistantMessage),
                ],
                'actions' => $result['actions'] ?? [],
                'status' => $result['status'] ?? 'continue',
            ];

            if (isset($result['execution'])) {
                $response['execution'] = $result['execution'];
            }

            return $this->json($response);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            return $this->json([
                'messages' => [[
                    'id' => null,
                    'role' => 'assistant',
                    'content' => '⚠️ Désolé, je n’ai pas réussi à traiter ta demande. Réessaie.',
                    'payload' => $debug ? [
                        'error' => [
                            'type' => $e::class,
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ],
                    ] : null,
                    'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ]],
                'actions' => [],
                'status' => 'continue',
            ], 200);
        }
    }
}