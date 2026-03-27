<?php

namespace App\Repository;

use App\Entity\UserTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTransaction::class);
    }

    /**
     * =========================
     * STRIPE IDEMPOTENCE
     * =========================
     */

    public function findOneByStripeEventId(string $eventId): ?UserTransaction
    {
        return $this->findOneBy([
            'stripeEventId' => $eventId,
        ]);
    }

    public function isStripeEventAlreadyProcessed(string $eventId): bool
    {
        return $this->findOneByStripeEventId($eventId) !== null;
    }

    /**
     * =========================
     * HELPERS
     * =========================
     */

    public function save(UserTransaction $transaction, bool $flush = true): void
    {
        $this->getEntityManager()->persist($transaction);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserTransaction $transaction, bool $flush = true): void
    {
        $this->getEntityManager()->remove($transaction);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}