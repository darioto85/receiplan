<?php

namespace App\Service\Image;

use App\Entity\Ingredient;
use App\Service\IngredientImageResolver;

final class IngredientImageTarget implements ImageTargetInterface
{
    public function __construct(
        private readonly IngredientImageResolver $resolver,
    ) {}

    public function hasImage(object $entity): bool
    {
        if (!$entity instanceof Ingredient) {
            throw new \InvalidArgumentException('IngredientImageTarget attend un Ingredient.');
        }

        return $this->resolver->hasImage($entity);
    }

    public function getStorageKey(object $entity): string
    {
        if (!$entity instanceof Ingredient) {
            throw new \InvalidArgumentException('IngredientImageTarget attend un Ingredient.');
        }

        return $this->resolver->getStorageKey($entity);
    }

    public function buildPrompt(object $entity): string
    {
        if (!$entity instanceof Ingredient) {
            throw new \InvalidArgumentException('IngredientImageTarget attend un Ingredient.');
        }

        $name = $entity->getName() ?? 'ingredient';

        return
            "Illustration stylisée semi-réaliste d'un(e) {$name}, " .
            "style kawaii doux et moderne, SANS visage (pas d'yeux, pas de bouche). " .
            "Rendu avec volume et relief visibles, dégradés doux, reflets subtils, " .
            "matière lisse et légèrement brillante, détails simples mais lisibles. " .
            "Contours fins et colorés (pas noirs), pas de style vectoriel. " .
            "Ombre portée douce sous l'objet pour donner de la profondeur. " .
            "Fond blanc pur #FFFFFF, objet centré, marge généreuse, format carré. " .
            "Aucun texte, aucun décor, aucun packaging, aucun personnage. " .
            "Éviter absolument: flat design, pictogramme, icône 2D, clipart, cartoon plat.";
    }
}
