<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PushNotifier
{
    private WebPush $webPush;

    public function __construct(
        private readonly PushSubscriptionRepository $repo,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')] string $vapidPublicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')] string $vapidPrivateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')] string $vapidSubject,
    ) {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidPublicKey,
                'privateKey' => $vapidPrivateKey,
            ],
        ]);

        // Optionnel : tu peux régler des defaults ici si tu veux
        // $this->webPush->setReuseVAPIDHeaders(true);
    }

    /**
     * @param array{title?:string, body?:string, url?:string, icon?:string, badge?:string} $payload
     * @return array{sent:int, failed:int, deleted:int}
     */
    public function notifyUser(User $user, array $payload): array
    {
        $subs = $this->repo->findByUser($user);

        if ($subs === []) {
            return ['sent' => 0, 'failed' => 0, 'deleted' => 0];
        }

        // ✅ Payload "béton" (évite les push vides)
        $finalPayload = array_merge([
            'title' => 'Receiplan',
            'body' => '',
            'url' => '/meal-plan',
        ], $payload);

        try {
            $jsonPayload = json_encode($finalPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // fallback ultra safe
            $jsonPayload = '{"title":"Receiplan","body":"","url":"/meal-plan"}';
        }

        $sent = 0;
        $failed = 0;
        $deleted = 0;

        foreach ($subs as $ps) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => $ps->getEndpoint(),
                    'publicKey' => $ps->getP256dh(),
                    'authToken' => $ps->getAuth(),
                    'contentEncoding' => $ps->getContentEncoding(), // souvent "aesgcm"
                ]);

                // ✅ Options utiles : TTL/urgency (surtout pour "du jour")
                $report = $this->webPush->sendOneNotification(
                    $subscription,
                    $jsonPayload,
                    [
                        'TTL' => 60 * 60 * 6,      // 6h (à ajuster)
                        'urgency' => 'normal',     // 'very-low'|'low'|'normal'|'high'
                    ]
                );

                if ($report->isSuccess()) {
                    $sent++;
                    $ps->markUsed();
                } else {
                    $failed++;

                    $statusCode = $report->getResponse()?->getStatusCode();
                    if (in_array($statusCode, [404, 410], true)) {
                        $this->em->remove($ps);
                        $deleted++;
                    }
                }
            } catch (\Throwable) {
                // En cas d'erreur sur une subscription, on continue les autres
                $failed++;
            }
        }

        $this->em->flush();

        return ['sent' => $sent, 'failed' => $failed, 'deleted' => $deleted];
    }
}
