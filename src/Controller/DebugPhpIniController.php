<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DebugPhpIniController extends AbstractController
{
    #[Route('/_debug/phpini', name: 'debug_phpini', methods: ['GET'])]
    public function phpIni(): Response
    {
        $body =
            'upload_max_filesize=' . ini_get('upload_max_filesize') . "\n" .
            'post_max_size=' . ini_get('post_max_size') . "\n" .
            'memory_limit=' . ini_get('memory_limit') . "\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
