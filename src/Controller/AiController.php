<?php

namespace App\Controller;

use App\Service\AiStockParser;
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
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Body attendu (JSON):
     * { "text": "J’ai acheté 2 kg de pommes" }
     */
    #[Route('/parse-purchase-text', name: 'parse_purchase_text', methods: ['POST'])]
    public function parsePurchaseText(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return $this->json(['error' => ['code' => 'invalid_json', 'message' => 'JSON invalide']], 400);
        }

        $text = isset($payload['text']) ? trim((string) $payload['text']) : '';
        if ($text === '') {
            return $this->json(['error' => ['code' => 'missing_text', 'message' => 'Champ "text" requis']], 422);
        }

        try {
            return $this->json($this->aiStockParser->parsePurchaseText($text));
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
