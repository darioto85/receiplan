<?php

namespace App\Controller;

use App\Service\Image\AutoImageGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cron')]
final class CronImageController extends AbstractController
{
    #[Route('/images/generate-one', name: 'cron_images_generate_one', methods: ['POST'])]
    public function generateOne(
        Request $request,
        AutoImageGenerationService $service,
        #[Autowire('%env(CRON_SECRET)%')] string $cronSecret,
    ): JsonResponse {
        $token = (string) $request->headers->get('X-CRON-SECRET', '');

        if ($cronSecret === '' || !hash_equals($cronSecret, $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            $result = $service->generateOne();
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'generation_failed',
                'detail' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }
}
