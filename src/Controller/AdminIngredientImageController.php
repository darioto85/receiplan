<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Service\IngredientImageGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/ingredient')]
final class AdminIngredientImageController extends AbstractController
{
    #[Route('/{id}/generate-image', name: 'admin_ingredient_generate_image', methods: ['POST'])]
    public function generate(Ingredient $ingredient, IngredientImageGenerator $generator): JsonResponse
    {
        // 🔒 à adapter à ton système (ROLE_ADMIN / user id / etc.)
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $generator->generateAndStore($ingredient, overwrite: true);

        return new JsonResponse(['ok' => true]);
    }
}
