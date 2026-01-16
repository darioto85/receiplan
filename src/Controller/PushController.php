<?php

namespace App\Controller;

use App\Entity\MealCookedPrompt;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\MealCookedPromptRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\PushActionTokenService;
use App\Service\PushNotifier;
use App\Service\RecipeUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/push')]
final class PushController extends AbstractController
{
    #[Route('/subscribe', name: 'push_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        PushSubscriptionRepository $repo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireUser();

        $payload = $request->toArray();

        $endpoint = $payload['endpoint'] ?? null;
        $keys = $payload['keys'] ?? null;
        $p256dh = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $auth = is_array($keys) ? ($keys['auth'] ?? null) : null;

        if (!is_string($endpoint) || $endpoint === '' || !is_string($p256dh) || $p256dh === '' || !is_string($auth) || $auth === '') {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $contentEncoding = $payload['contentEncoding'] ?? 'aesgcm';
        if (!is_string($contentEncoding) || $contentEncoding === '') {
            $contentEncoding = 'aesgcm';
        }

        $existing = $repo->findOneByEndpoint($endpoint);

        if ($existing) {
            $existing
                ->setUser($user)
                ->setP256dh($p256dh)
                ->setAuth($auth)
                ->setContentEncoding($contentEncoding)
                ->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));

            $existing->touch();
            $em->flush();

            return new JsonResponse(['ok' => true, 'mode' => 'updated']);
        }

        $sub = (new PushSubscription())
            ->setUser($user)
            ->setEndpoint($endpoint)
            ->setP256dh($p256dh)
            ->setAuth($auth)
            ->setContentEncoding($contentEncoding)
            ->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));

        $em->persist($sub);
        $em->flush();

        return new JsonResponse(['ok' => true, 'mode' => 'created']);
    }

    #[Route('/unsubscribe', name: 'push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(
        Request $request,
        PushSubscriptionRepository $repo,
    ): JsonResponse {
        $user = $this->requireUser();

        $payload = $request->toArray();
        $endpoint = $payload['endpoint'] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $deleted = $repo->deleteByEndpointForUser($user, $endpoint);

        return new JsonResponse(['ok' => true, 'deleted' => $deleted]);
    }

    /**
     * Endpoint appelé depuis le Service Worker ou la page fallback quand l'utilisateur répond "Oui/Non".
     * Sécurisé via token signé (pas besoin d'être connecté).
     */
    #[Route('/meal-cooked/{token}', name: 'push_meal_cooked_answer', methods: ['POST'])]
    public function mealCookedAnswer(
        string $token,
        PushActionTokenService $tokens,
        MealCookedPromptRepository $prompts,
        EntityManagerInterface $em,
        RecipeUpdater $recipeUpdater,
    ): JsonResponse {
        $data = $tokens->verify($token);
        if ($data === null) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_token'], 400);
        }

        $promptId = $data['promptId'] ?? null;
        $answer = $data['answer'] ?? null;

        if (!is_int($promptId) || !in_array($answer, [MealCookedPrompt::ANSWER_YES, MealCookedPrompt::ANSWER_NO], true)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $prompt = $prompts->find($promptId);
        if (!$prompt) {
            return new JsonResponse(['ok' => false, 'error' => 'not_found'], 404);
        }

        if ($prompt->getStatus() === MealCookedPrompt::STATUS_EXPIRED) {
            return new JsonResponse(['ok' => false, 'error' => 'expired'], 410);
        }

        $mealPlan = $prompt->getMealPlan();
        if (!$mealPlan) {
            return new JsonResponse(['ok' => false, 'error' => 'mealplan_missing'], 409);
        }

        // ✅ Idempotent : si déjà répondu, on ne re-décrémente jamais
        if ($prompt->getStatus() === MealCookedPrompt::STATUS_ANSWERED) {
            return new JsonResponse([
                'ok' => true,
                'status' => 'already_answered',
                'answer' => $prompt->getAnswer(),
                'mealplan_validated' => $mealPlan->isValidated(),
            ]);
        }

        if ($answer === MealCookedPrompt::ANSWER_YES) {
            $prompt->answerYes();

            // ✅ Valider + décrémenter stock via ton service existant
            try {
                $recipeUpdater->validateMealPlan($mealPlan);
            } catch (\DomainException $e) {
                // Stock insuffisant => on garde la réponse YES enregistrée,
                // mais on ne valide pas le mealplan. (Ou tu peux décider de revert.)
                $em->flush();

                return new JsonResponse([
                    'ok' => true,
                    'status' => 'answered_but_not_validated',
                    'answer' => MealCookedPrompt::ANSWER_YES,
                    'mealplan_validated' => false,
                    'error' => 'insufficient_stock',
                    'message' => $e->getMessage(),
                ], 200);
            }
        } else {
            $prompt->answerNo();
            $em->flush(); // enregistre la réponse NO
        }

        return new JsonResponse([
            'ok' => true,
            'status' => 'answered',
            'answer' => $answer,
            'mealplan_validated' => $mealPlan->isValidated(),
        ]);
    }

    #[Route('/test', name: 'push_test', methods: ['POST'])]
    public function test(
        Request $request,
        PushNotifier $notifier,
        UserRepository $users,
    ): JsonResponse {
        $user = $users->find(1);
        if (!$user) {
            return new JsonResponse(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        $result = $notifier->notifyUser($user, [
            'title' => 'Receiplan',
            'body' => 'Notif test ✅',
            'url' => '/meal-plan',
        ]);

        return new JsonResponse(['ok' => true] + $result);
    }

    private function requireUser(): User
    {
        $u = $this->getUser();

        if (!$u instanceof User) {
            throw $this->createAccessDeniedException('Unauthorized');
        }

        return $u;
    }
}
