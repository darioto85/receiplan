<?php

namespace App\Controller;

use App\Entity\MealCookedPrompt;
use App\Repository\MealCookedPromptRepository;
use App\Service\PushActionTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MealCookedPromptFallbackController extends AbstractController
{
    #[Route('/meal-cooked/respond/{token}', name: 'meal_cooked_prompt_respond', methods: ['GET'])]
    public function respond(
        string $token,
        PushActionTokenService $tokens,
        MealCookedPromptRepository $prompts,
    ): Response {
        $data = $tokens->verify($token);
        if ($data === null) {
            return new Response('Lien invalide ou expiré.', 400);
        }

        $promptId = $data['promptId'] ?? null;
        if (!is_int($promptId)) {
            return new Response('Lien invalide.', 400);
        }

        $prompt = $prompts->find($promptId);
        if (!$prompt) {
            return new Response('Demande introuvable.', 404);
        }

        if ($prompt->getStatus() === MealCookedPrompt::STATUS_EXPIRED) {
            return new Response('Cette demande a expiré.', 410);
        }

        // Si déjà répondu, on affiche un écran simple
        if ($prompt->getStatus() === MealCookedPrompt::STATUS_ANSWERED) {
            return $this->render('meal_cooked_prompt/respond.html.twig', [
                'mode' => 'already',
                'recipeName' => (string) $prompt->getMealPlan()?->getRecipe()?->getName(),
                'answer' => $prompt->getAnswer(),
                'yesUrl' => null,
                'noUrl' => null,
            ]);
        }

        $recipeName = (string) $prompt->getMealPlan()?->getRecipe()?->getName();
        if ($recipeName === '') {
            return new Response('Recette introuvable.', 500);
        }

        // On génère les 2 tokens de réponse (POST)
        $exp = time() + 60 * 60 * 48; // 48h
        $yesToken = $tokens->sign([
            'promptId' => (int) $prompt->getId(),
            'answer' => MealCookedPrompt::ANSWER_YES,
            'exp' => $exp,
        ]);
        $noToken = $tokens->sign([
            'promptId' => (int) $prompt->getId(),
            'answer' => MealCookedPrompt::ANSWER_NO,
            'exp' => $exp,
        ]);

        return $this->render('meal_cooked_prompt/respond.html.twig', [
            'mode' => 'ask',
            'recipeName' => $recipeName,
            'answer' => null,
            'yesUrl' => "/push/meal-cooked/{$yesToken}",
            'noUrl' => "/push/meal-cooked/{$noToken}",
        ]);
    }
}
