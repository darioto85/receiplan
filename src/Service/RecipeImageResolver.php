<?php

namespace App\Service;

use App\Entity\Recipe;
use App\Service\Image\Storage\ImageStorageInterface;

final class RecipeImageResolver
{
    private const PLACEHOLDER_KEY = '/img/recipes/placeholder.png';

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
            return $this->storage->publicUrl(self::PLACEHOLDER_KEY);
        }

        return $this->storage->exists($key)
            ? $this->storage->publicUrl($key)
            : $this->storage->publicUrl(self::PLACEHOLDER_KEY);
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
