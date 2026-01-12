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
    }

    /**
     * @param array{
     *   title?:string,
     *   body?:string,
     *   url?:string,
     *   icon?:string,
     *   badge?:string,
     *   image?:string,
     *   tag?:string,
     *   renotify?:bool
     * } $payload
     * @return array{sent:int, failed:int, deleted:int}
     */
    public function notifyUser(User $user, array $payload): array
    {
        $subs = $this->repo->findByUser($user);

        if ($subs === []) {
            return ['sent' => 0, 'failed' => 0, 'deleted' => 0];
        }

        $finalPayload = array_merge([
            'title' => 'Receiplan',
            'body' => '',
            'url' => '/meal-plan',
            // bonus: tu peux définir des defaults si tu veux
            // 'icon' => 'https://.../assets/push-icon.png',
            // 'badge' => 'https://.../assets/push-badge.png',
        ], $payload);

        try {
            $jsonPayload = json_encode(
                $finalPayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
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
                    'contentEncoding' => $ps->getContentEncoding(),
                ]);

                $report = $this->webPush->sendOneNotification(
                    $subscription,
                    $jsonPayload,
                    [
                        'TTL' => 60 * 60 * 6, // 6h
                        'urgency' => 'normal',
                    ]
                );

                if ($report->isSuccess()) {
                    $sent++;
                    $ps->markUsed();
                } else {
                    $failed++;

                    $statusCode = $report->getResponse()?->getStatusCode();
                    if (\in_array($statusCode, [404, 410], true)) {
                        $this->em->remove($ps);
                        $deleted++;
                    }
                }
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->em->flush();

        return ['sent' => $sent, 'failed' => $failed, 'deleted' => $deleted];
    }
}
