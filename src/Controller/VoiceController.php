<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VoiceController extends AbstractController
{
    #[Route('/voice', name: 'voice_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('ui/voice.html.twig', [
            'user' => $user,
        ]);
    }
}
