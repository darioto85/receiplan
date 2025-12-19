<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AiStockParser;
use App\Service\Ai\AiAddRecipeHandler;
use App\Service\Ai\AiAddStockHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\KernelInterface;


#[Route('/api/ai', name: 'api_ai_')]
final class AiController extends AbstractController
{
    public function __construct(
        private readonly AiStockParser $aiStockParser,
        private readonly AiAddRecipeHandler $aiAddRecipeHandler,
        private readonly AiAddStockHandler $aiAddStockHandler,
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Body attendu (JSON):
     * { "text": "J’ai acheté 2 kg de pommes" }
     */
    #[Route('/parse', name: 'parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $payloadIn = json_decode($request->getContent() ?: '{}', true);
        $text = trim((string)($payloadIn['text'] ?? ''));

        $userId = $payloadIn['user_id'] ?? null;
        if (!is_numeric($userId)) {
            return $this->json(['error' => ['code' => 'missing_user_id', 'message' => 'user_id requis']], 422);
        }

        $user = $this->em->getRepository(User::class)->find((int)$userId);
        if (!$user instanceof User) {
            return $this->json(['error' => ['code' => 'user_not_found', 'message' => 'User introuvable']], 404);
        }

        try {
            $result = $this->aiStockParser->parse($text);

            return match ($result['action']) {
                'add_recipe' => $this->json($this->aiAddRecipeHandler->handle($user, $result['payload'])),
                'add_stock'  => $this->json($this->aiAddStockHandler->handle($user, $result['payload'])),
                default      => $this->json($result),
            };
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            return $this->json([
                'error' => [
                    'code' => 'ai_parse_failed',
                    'message' => 'Impossible de parser le texte pour le moment.',
                    // ⚠️ uniquement en dev
                    'debug' => $debug ? [
                        'type' => get_class($e),
                        'detail' => $e->getMessage(),
                    ] : null,
                ],
            ], 502);
        }
    }
}
