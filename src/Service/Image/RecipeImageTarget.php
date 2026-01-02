<?php

namespace App\Service\Image;

use App\Entity\Recipe;
use App\Service\RecipeImageResolver;

final class RecipeImageTarget implements ImageTargetInterface
{
    public function __construct(
        private readonly RecipeImageResolver $resolver,
    ) {}

    public function hasImage(object $entity): bool
    {
        if (!$entity instanceof Recipe) {
            throw new \InvalidArgumentException('RecipeImageTarget attend une Recipe.');
        }

        return $this->resolver->hasImage($entity);
    }

    public function getStorageKey(object $entity): string
    {
        if (!$entity instanceof Recipe) {
            throw new \InvalidArgumentException('RecipeImageTarget attend une Recipe.');
        }

        return $this->resolver->getStorageKey($entity);
    }

    public function buildPrompt(object $entity): string
    {
        if (!$entity instanceof Recipe) {
            throw new \InvalidArgumentException('RecipeImageTarget attend une Recipe.');
        }

        $name = $entity->getName() ?? 'plat';

        $keywords = [];
        if (method_exists($entity, 'getRecipeIngredients')) {
            foreach ($entity->getRecipeIngredients() as $ri) {
                if (method_exists($ri, 'getIngredient') && $ri->getIngredient()) {
                    $keywords[] = $ri->getIngredient()->getName();
                }
                if (count($keywords) >= 3) break;
            }
        }

        $kw = $keywords ? (' (avec ' . implode(', ', $keywords) . ')') : '';

        return
            "Illustration stylisée semi-réaliste d'un plat : {$name}{$kw}, " .
            "style kawaii doux et moderne, sans visage, sans personnage. " .
            "Plat présenté dans une assiette simple, rendu avec volume et relief, " .
            "dégradés doux, reflets subtils, matière lisse légèrement brillante, " .
            "détails simples mais lisibles. Contours fins et colorés (pas noirs). " .
            "Ombre portée douce sous l'assiette. Fond blanc pur #FFFFFF, " .
            "objet centré, marge généreuse, format carré. " .
            "Aucun texte, aucun décor, pas de table, pas de main, pas de packaging. " .
            "Éviter: flat design, pictogramme, icône 2D, clipart, cartoon plat.";
    }
}
