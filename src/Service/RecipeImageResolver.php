<?php

namespace App\Service;

use App\Entity\Recipe;
use App\Service\Image\Storage\ImageStorageInterface;

final class RecipeImageResolver
{
    public const PLACEHOLDER_URL = '/img/recipes/placeholder.png';

    public function __construct(
        private readonly ImageStorageInterface $storage,
    ) {}

    public function getStorageKey(Recipe $recipe): string
    {
        $key = $this->buildKey($recipe);
        if ($key === null) {
            throw new \RuntimeException('Recipe nameKey manquant.');
        }

        return $key;
    }

    public function hasImage(Recipe $recipe): bool
    {
        $key = $this->buildKey($recipe);
        if ($key === null) {
            return false;
        }

        return $this->storage->exists($key);
    }

    public function getPublicUrl(Recipe $recipe): string
    {
        $key = $this->buildKey($recipe);
        if ($key === null) {
            return self::PLACEHOLDER_URL;
        }

        return $this->storage->exists($key)
            ? $this->storage->publicUrl($key)
            : self::PLACEHOLDER_URL;
    }

    private function buildKey(Recipe $recipe): ?string
    {
        $nameKey = trim((string) ($recipe->getNameKey() ?? ''));
        if ($nameKey === '') {
            return null;
        }

        return 'recipes/' . $nameKey . '.png';
    }
}
