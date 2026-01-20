<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Ai\AiAddRecipeHandler;
use App\Service\Ai\AiAddStockHandler;
use App\Service\Ai\AiTranscriptionService;
use App\Service\Ai\AssistantOrchestrator;
use App\Service\Ai\Action\AiContext;
use App\Service\AiTicketParser; // ✅ FIX: namespace legacy (hors App\Service\Ai\...)
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/ai', name: 'api_ai_')]
final class AiController extends AbstractController
{
    public function __construct(
        private readonly AssistantOrchestrator $assistantOrchestrator,

        // encore utilisés par ticket/apply (et éventuellement par d’autres endpoints)
        private readonly AiAddRecipeHandler $aiAddRecipeHandler,
        private readonly AiAddStockHandler $aiAddStockHandler,

        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
        private readonly AiTranscriptionService $aiTranscriptionService,
        private readonly AiTicketParser $aiTicketParser // ✅ FIX: typehint corrigé
    ) {}

    #[Route('/transcribe', name: 'transcribe', methods: ['POST'])]
    public function transcribe(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('audio');
        $locale = (string) $request->request->get('locale', 'fr-FR');

        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => ['code' => 'missing_audio', 'message' => 'Fichier audio requis (champ "audio").']], 400);
        }

        $maxBytes = 10 * 1024 * 1024; // 10MB
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            return $this->json(['error' => ['code' => 'audio_too_large', 'message' => 'Fichier audio trop volumineux (max 10MB).']], 413);
        }

        $allowedMimes = [
            'audio/webm',
            'video/webm',
            'audio/ogg',
            'audio/oga',
            'audio/wav',
            'audio/wave',
            'audio/x-wav',
            'audio/mpeg',
            'audio/mp4',
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
     * { "text": "J’ai acheté 2 kg de pommes", "user_id": 1, "locale"?: "fr-FR" }
     *
     * - utilise le nouveau pipeline (classification + extraction)
     * - si clarify => renvoie la proposition
     * - si confirm => applique directement (comportement proche de l’ancien endpoint)
     */
    #[Route('/parse', name: 'parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $payloadIn = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payloadIn)) {
            return $this->json(['error' => ['code' => 'invalid_json', 'message' => 'JSON invalide.']], 400);
        }

        $text = trim((string) ($payloadIn['text'] ?? ''));
        if ($text === '') {
            return $this->json(['error' => ['code' => 'missing_text', 'message' => 'text requis']], 422);
        }

        $userId = $payloadIn['user_id'] ?? null;
        if (!is_numeric($userId)) {
            return $this->json(['error' => ['code' => 'missing_user_id', 'message' => 'user_id requis']], 422);
        }

        $user = $this->em->getRepository(User::class)->find((int) $userId);
        if (!$user instanceof User) {
            return $this->json(['error' => ['code' => 'user_not_found', 'message' => 'User introuvable']], 404);
        }

        $locale = (string) ($payloadIn['locale'] ?? 'fr-FR');
        $ctx = new AiContext(locale: $locale, debug: $this->kernel->isDebug());

        try {
            [$assistantText, $assistantPayload] = $this->assistantOrchestrator->propose($user, $text, $ctx);

            if (!is_array($assistantPayload) || !isset($assistantPayload['type'])) {
                return $this->json([
                    'type' => 'info',
                    'message' => $assistantText,
                    'payload' => $assistantPayload,
                ]);
            }

            $type = (string) ($assistantPayload['type'] ?? 'info');
            $action = (string) ($assistantPayload['action'] ?? 'unknown');

            if ($type === 'clarify') {
                return $this->json([
                    'type' => 'clarify',
                    'action' => $action,
                    'message' => $assistantText,
                    'questions' => $assistantPayload['questions'] ?? [],
                    'draft' => $assistantPayload['action_payload'] ?? ($assistantPayload['draft'] ?? null),
                    'payload' => $assistantPayload,
                ]);
            }

            if ($type === 'confirm') {
                $draft = $assistantPayload['action_payload'] ?? ($assistantPayload['draft'] ?? null);
                if (!is_array($draft)) {
                    return $this->json([
                        'type' => 'error',
                        'error' => ['code' => 'missing_draft', 'message' => 'Draft manquant.'],
                        'payload' => $assistantPayload,
                    ], 422);
                }

                [, $appliedPayload] = $this->assistantOrchestrator->apply($user, $action, $draft);
                $result = $appliedPayload['result'] ?? null;

                return $this->json([
                    'type' => 'applied',
                    'action' => $action,
                    'result' => $result,
                    'proposal' => [
                        'message' => $assistantText,
                        'payload' => $assistantPayload,
                    ],
                ]);
            }

            return $this->json([
                'type' => $type,
                'action' => $action,
                'message' => $assistantText,
                'payload' => $assistantPayload,
            ]);
        } catch (\Throwable $e) {
            $debug = $this->kernel->isDebug();

            return $this->json([
                'error' => [
                    'code' => 'ai_parse_failed',
                    'message' => 'Impossible de parser le texte pour le moment.',
                    'debug' => $debug ? [
                        'type' => get_class($e),
                        'detail' => $e->getMessage(),
                    ] : null,
                ],
            ], 502);
        }
    }

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

        $user = $this->getUser();
        if (!$user instanceof User) {
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
