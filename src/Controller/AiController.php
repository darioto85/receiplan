<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AiStockParser;
use App\Service\AiTicketParser;
use App\Service\Ai\AiAddRecipeHandler;
use App\Service\Ai\AiAddStockHandler;
use App\Service\Ai\AiTranscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly AiTranscriptionService $aiTranscriptionService,
        private readonly AiTicketParser $aiTicketParser
    ) {}

    /**
     * Body attendu (multipart/form-data):
     * - audio: fichier (webm/ogg/wav/mp3… selon ce que tu acceptes)
     * - locale: string (optionnel, défaut fr-FR)
     *
     * Réponse JSON:
     * { "text": "...", "locale": "fr-FR" }
     */
    #[Route('/transcribe', name: 'transcribe', methods: ['POST'])]
    public function transcribe(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('audio');
        $locale = (string) $request->request->get('locale', 'fr-FR');

        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => ['code' => 'missing_audio', 'message' => 'Fichier audio requis (champ "audio").']], 400);
        }

        // ✅ Validations simples (tu peux ajuster selon ton front)
        $maxBytes = 10 * 1024 * 1024; // 10MB
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            return $this->json(['error' => ['code' => 'audio_too_large', 'message' => 'Fichier audio trop volumineux (max 10MB).']], 413);
        }

        // Mime côté client parfois approximatif, mais utile en garde-fou
        $allowedMimes = [
            'audio/webm',
            'video/webm', // certains navigateurs
            'audio/ogg',
            'audio/oga',
            'audio/wav',
            'audio/wave',   // ✅ Firefox
            'audio/x-wav',  // ✅ Firefox
            'audio/mpeg',   // mp3
            'audio/mp4',    // m4a selon plateformes
            'audio/x-m4a',
        ];

        $mime = (string) ($file->getMimeType() ?? '');
        if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
            return $this->json([
                'error' => [
                    'code' => 'unsupported_audio_mime',
                    'message' => 'Format audio non supporté.',
                    'debug' => $this->kernel->isDebug() ? ['mime' => $mime] : null,
                ],
            ], 415);
        }

        try {
            // ⚠️ IMPORTANT :
            // UploadedFile::getPathname() pointe vers le fichier temporaire uploadé.
            // Lis / transfère ce fichier dans ton service de transcription.
            $path = $file->getPathname();

            $text = $this->aiTranscriptionService->transcribe($path, $locale);

            return $this->json([
                'text' => $text,
                'locale' => $locale,
            ]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            return $this->json([
                'error' => [
                    'code' => 'ai_transcribe_failed',
                    'message' => 'Impossible de transcrire l’audio pour le moment.',
                    'debug' => $debug ? [
                        'type' => get_class($e),
                        'detail' => $e->getMessage(),
                    ] : null,
                ],
            ], 502);
        }
    }

    /**
     * Body attendu (JSON):
     * { "text": "J’ai acheté 2 kg de pommes", "user_id": 1 }
     */
    #[Route('/parse', name: 'parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $payloadIn = json_decode($request->getContent() ?: '{}', true);
        $text = trim((string) ($payloadIn['text'] ?? ''));

        $userId = $payloadIn['user_id'] ?? null;
        if (!is_numeric($userId)) {
            return $this->json(['error' => ['code' => 'missing_user_id', 'message' => 'user_id requis']], 422);
        }

        $user = $this->em->getRepository(User::class)->find((int) $userId);
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

    /**
     * Body attendu (multipart/form-data):
     * - image: fichier (jpg/png/webp)
     * - user_id: int (optionnel si tu peux le déduire via auth plus tard)
     *
     * Réponse JSON (mock pour l’instant):
     * {
     *   "items": [
     *     { "name_raw": "TOMATES", "name": "tomate", "quantity": 6, "unit": "piece", "confidence": 0.86, "needs_confirmation": false, "notes": null }
     *   ],
     *   "warnings": []
     * }
     */
    #[Route('/ticket/parse', name: 'ticket_parse', methods: ['POST'])]
    public function ticketParse(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('image');

        if (!$file instanceof UploadedFile) {
            return $this->json([
                'error' => [
                    'code' => 'missing_image',
                    'message' => 'Image requise (champ "image").',
                ],
            ], 400);
        }

        // Taille max (ajuste si besoin)
        $maxBytes = 10 * 1024 * 1024; // 10MB
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            return $this->json([
                'error' => [
                    'code' => 'image_too_large',
                    'message' => 'Image trop volumineuse (max 10MB).',
                ],
            ], 413);
        }

        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        $mime = (string) ($file->getMimeType() ?? '');
        if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
            return $this->json([
                'error' => [
                    'code' => 'unsupported_image_mime',
                    'message' => 'Format image non supporté (jpg, png, webp).',
                    'debug' => $this->kernel->isDebug() ? ['mime' => $mime] : null,
                ],
            ], 415);
        }

        // Pour l’instant on ne fait rien avec l’image, on prépare le terrain.
        // Tu peux déjà vérifier qu’elle est accessible :
        $path = $file->getPathname();
        if (!is_file($path) || !is_readable($path)) {
            return $this->json([
                'error' => [
                    'code' => 'image_unreadable',
                    'message' => 'Impossible de lire l’image uploadée.',
                ],
            ], 422);
        }

        try {
            $imageBinary = file_get_contents($path);
            if ($imageBinary === false) {
                throw new \RuntimeException('image_read_failed');
            }

            $result = $this->aiTicketParser->parseImage($imageBinary, $mime);

            return $this->json([
                'items' => $result['items'],
                'warnings' => $result['warnings'],
                'debug' => $this->kernel->isDebug() ? [
                    'filename' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                ] : null,
            ]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            return $this->json([
                'error' => [
                    'code' => 'ai_ticket_parse_failed',
                    'message' => 'Impossible d’analyser le ticket pour le moment.',
                    'debug' => $debug ? [
                        'type' => get_class($e),
                        'detail' => $e->getMessage(),
                    ] : null,
                ],
            ], 502);
        }
    }

    /**
     * Body attendu (JSON):
     * {
     *   "items": [
     *     { "name_raw": "...", "name": "...", "quantity": 1, "unit": "l", "notes": null, "confidence": 0.9 }
     *   ],
     *   "user_id": 1 (optionnel si user en session)
     * }
     *
     * Réponse:
     * { "updated": 3, "needs_confirmation": false, "warnings": [...] }
     */
    #[Route('/ticket/apply', name: 'ticket_apply', methods: ['POST'])]
    public function ticketApply(Request $request): JsonResponse
    {
        $payloadIn = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payloadIn)) {
            return $this->json([
                'error' => [
                    'code' => 'invalid_json',
                    'message' => 'JSON invalide.',
                ],
            ], 400);
        }

        // ✅ User: d’abord via session (si ton app est authentifiée)
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Fallback: user_id comme sur /parse
            $userId = $payloadIn['user_id'] ?? null;
            if (!is_numeric($userId)) {
                return $this->json([
                    'error' => [
                        'code' => 'missing_user_id',
                        'message' => 'user_id requis (ou authentification).',
                    ],
                ], 422);
            }

            $user = $this->em->getRepository(User::class)->find((int) $userId);
            if (!$user instanceof User) {
                return $this->json([
                    'error' => [
                        'code' => 'user_not_found',
                        'message' => 'User introuvable',
                    ],
                ], 404);
            }
        }

        $items = $payloadIn['items'] ?? null;
        if (!is_array($items)) {
            return $this->json([
                'error' => [
                    'code' => 'missing_items',
                    'message' => 'items requis.',
                ],
            ], 422);
        }

        // On aligne la shape avec ce que AiAddStockHandler sait normaliser
        // (quantity_raw/unit_raw peuvent être null)
        $normalizedPayload = [
            'items' => array_values(array_map(static function ($it) {
                if (!is_array($it)) {
                    return [];
                }

                return [
                    'name_raw' => (string)($it['name_raw'] ?? ($it['name'] ?? '')),
                    'name' => (string)($it['name'] ?? ''),
                    'quantity' => isset($it['quantity']) && $it['quantity'] !== '' ? (float) $it['quantity'] : null,
                    'quantity_raw' => $it['quantity_raw'] ?? null,
                    'unit' => $it['unit'] ?? null,
                    'unit_raw' => $it['unit_raw'] ?? null,
                    'notes' => $it['notes'] ?? null,
                    'confidence' => isset($it['confidence']) ? (float) $it['confidence'] : 0.0,
                ];
            }, $items)),
        ];

        try {
            $result = $this->aiAddStockHandler->handle($user, $normalizedPayload);

            return $this->json([
                'ok' => true,
                ...$result,
            ]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            return $this->json([
                'error' => [
                    'code' => 'ticket_apply_failed',
                    'message' => 'Impossible d’ajouter au stock pour le moment.',
                    'debug' => $debug ? [
                        'type' => get_class($e),
                        'detail' => $e->getMessage(),
                    ] : null,
                ],
            ], 502);
        }
    }

}
