<?php

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\UserRepository;

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
            ->setEndpoint($endpoint) // calcule endpointHash
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

        /*
        $csrf = (string) $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('push', $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf_invalid'], 419);
        }
        */

        $payload = $request->toArray();
        $endpoint = $payload['endpoint'] ?? null;

        if (!is_string($endpoint) || $endpoint === '') {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $deleted = $repo->deleteByEndpointForUser($user, $endpoint);

        return new JsonResponse(['ok' => true, 'deleted' => $deleted]);
    }

    private function requireUser(): User
    {
        $u = $this->getUser();

        if (!$u instanceof User) {
            // 401 si pas connecté, sinon 403 selon ton firewall/flow
            throw $this->createAccessDeniedException('Unauthorized');
        }

        return $u;
    }

    #[Route('/test', name: 'push_test', methods: ['POST'])]
    public function test(
        Request $request,
        PushNotifier $notifier,
        UserRepository $users,
    ): JsonResponse {

        $user = $users->find(1); // ou un userId dans le body
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

}
