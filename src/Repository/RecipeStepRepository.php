<?php

namespace App\Repository;

use App\Entity\Recipe;
use App\Entity\RecipeStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecipeStep>
 */
class RecipeStepRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecipeStep::class);
    }

    /**
     * @return RecipeStep[]
     */
    public function findByRecipeOrdered(Recipe $recipe): array
    {
        return $this->createQueryBuilder('rs')
            ->andWhere('rs.recipe = :recipe')
            ->setParameter('recipe', $recipe)
            ->orderBy('rs.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getNextPositionForRecipe(Recipe $recipe): int
    {
        $max = $this->createQueryBuilder('rs')
            ->select('MAX(rs.position)')
            ->andWhere('rs.recipe = :recipe')
            ->setParameter('recipe', $recipe)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
