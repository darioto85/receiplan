<?php

namespace App\Service;

use App\Entity\Recipe;
use App\Image\Storage\ImageStorageInterface;

final class RecipeImageResolver
{
    public const PLACEHOLDER_URL = '/img/recipes/placeholder.png';

    public function __construct(
        private readonly ImageStorageInterface $storage,
    ) {}

    public function getStorageKey(Recipe $recipe): string
    {
        $id = $recipe->getId();
        if (!$id) {
            throw new \RuntimeException('Recipe id manquant.');
        }

        return 'recipes/' . $id . '.png';
    }

    public function hasImage(Recipe $recipe): bool
    {
        $id = $recipe->getId();
        if (!$id) {
            return false;
        }

        return $this->storage->exists('recipes/' . $id . '.png');
    }

    public function getPublicUrl(Recipe $recipe): string
    {
        $id = $recipe->getId();
        if (!$id) {
            return self::PLACEHOLDER_URL;
        }

        $key = 'recipes/' . $id . '.png';

        return $this->storage->exists($key)
            ? $this->storage->publicUrl($key)
            : self::PLACEHOLDER_URL;
    }
}
