<?php

namespace App\Controller;

use App\Entity\Preinscription;
use App\Form\PreinscriptionType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', priority: 10, methods: ['GET'])]
    public function index(): Response
    {
        $form = $this->createForm(PreinscriptionType::class, new Preinscription(), [
            'action' => $this->generateUrl('app_preinscription_ajax'),
            'method' => 'POST',
        ]);

        return $this->render('home/index.html.twig', [
            'preinscriptionForm' => $form->createView(),
        ]);
    }

    #[Route('/preinscription', name: 'app_preinscription_ajax', methods: ['POST'])]
    public function preinscriptionAjax(
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        // Optionnel mais pratique : on n'accepte que l'AJAX
        if (!$request->isXmlHttpRequest()) {
            return $this->json(['ok' => false, 'message' => 'Requête invalide.'], 400);
        }

        $preinscription = new Preinscription();
        $form = $this->createForm(PreinscriptionType::class, $preinscription);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->json(['ok' => false, 'message' => 'Formulaire non soumis.'], 400);
        }

        if (!$form->isValid()) {
            return $this->json([
                'ok' => false,
                'message' => 'Veuillez corriger les erreurs.',
                'errors' => $this->getFormErrors($form),
            ], 422);
        }

        try {
            $em->persist($preinscription);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // sécurité: double submit / race => on renvoie une erreur propre
            $form->get('email')->addError(new FormError('Cet email est déjà inscrit.'));
            return $this->json([
                'ok' => false,
                'message' => 'Veuillez corriger les erreurs.',
                'errors' => $this->getFormErrors($form),
            ], 422);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Merci ! Ton email a bien été enregistré 😊',
        ]);
    }

    private function getFormErrors($form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $name = $origin?->getName() ?? '_global';
            $errors[$name][] = $error->getMessage();
        }
        return $errors;
    }
}