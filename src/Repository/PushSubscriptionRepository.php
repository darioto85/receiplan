<?php

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findOneByEndpoint(string $endpoint): ?PushSubscription
    {
        $hash = hash('sha256', $endpoint);
        return $this->findOneBy(['endpointHash' => $hash]);
    }

    /**
     * @return PushSubscription[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('ps')
            ->andWhere('ps.user = :u')
            ->setParameter('u', $user)
            ->orderBy('ps.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Suppression lors d’un unsubscribe client
     */
    public function deleteByEndpointForUser(User $user, string $endpoint): int
    {
        $hash = hash('sha256', $endpoint);

        return $this->createQueryBuilder('ps')
            ->delete()
            ->andWhere('ps.user = :u')
            ->andWhere('ps.endpointHash = :h')
            ->setParameter('u', $user)
            ->setParameter('h', $hash)
            ->getQuery()
            ->execute();
    }
}
