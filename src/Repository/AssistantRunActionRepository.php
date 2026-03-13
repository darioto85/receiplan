<?php

namespace App\Repository;

use App\Entity\AssistantRun;
use App\Entity\AssistantRunAction;
use App\Enum\AssistantActionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AssistantRunActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantRunAction::class);
    }

    /**
     * @return AssistantRunAction[]
     */
    public function findByRunOrdered(AssistantRun $run): array
    {
        return $this->findBy(
            ['run' => $run],
            ['executionOrder' => 'ASC', 'id' => 'ASC']
        );
    }

    /**
     * @return AssistantRunAction[]
     */
    public function findReadyByRunOrdered(AssistantRun $run): array
    {
        return $this->findBy(
            [
                'run' => $run,
                'status' => AssistantActionStatus::READY,
            ],
            ['executionOrder' => 'ASC', 'id' => 'ASC']
        );
    }
}