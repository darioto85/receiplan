<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AssistantController extends AbstractController
{
    #[Route('/assistant', name: 'assistant', methods: ['GET'])]
    public function index(): Response
    {
        // La home de l’assistant = page scanner ticket
        return $this->render('assistant/index.html.twig');
    }
}
