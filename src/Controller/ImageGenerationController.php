<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\User;
use App\Service\Image\ImageGenerator;
use App\Service\Image\IngredientImageTarget;
use App\Service\Image\RecipeImageTarget;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/image')]
final class ImageGenerationController extends AbstractController
{
    #[Route('/ingredient/{id}/generate', name: 'app_image_generate_ingredient', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generateIngredient(
        Ingredient $ingredient,
        ImageGenerator $generator,
        IngredientImageTarget $target,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // ✅ Autorisé si global (user NULL) ou privé appartenant au user courant
        if ($ingredient->getUser() !== null && $ingredient->getUser() !== $user) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $generator->generateAndStore($target, $ingredient, overwrite: true);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/recipe/{id}/generate', name: 'app_image_generate_recipe', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generateRecipe(
        Recipe $recipe,
        ImageGenerator $generator,
        RecipeImageTarget $target,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // ✅ Recette toujours privée => doit appartenir au user
        if ($recipe->getUser() !== $user) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $generator->generateAndStore($target, $recipe, overwrite: true);

        return new JsonResponse(['ok' => true]);
    }
}
