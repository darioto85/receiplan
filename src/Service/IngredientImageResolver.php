<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Service\Image\Storage\ImageStorageInterface;

final class IngredientImageResolver
{
    public const PLACEHOLDER_URL = '/img/ingredients/placeholder.png';

    public function __construct(
        private readonly ImageStorageInterface $storage,
    ) {}

    public function getStorageKey(Ingredient $ingredient): string
    {
        $nameKey = $ingredient->getNameKey();
        if (!$nameKey) {
            throw new \RuntimeException('Ingredient nameKey manquant.');
        }

        return 'ingredients/' . $nameKey . '.png';
    }

    public function hasImage(Ingredient $ingredient): bool
    {
        $nameKey = $ingredient->getNameKey();
        if (!$nameKey) {
            return false;
        }

        return $this->storage->exists('ingredients/' . $nameKey . '.png');
    }

    public function getPublicUrl(Ingredient $ingredient): string
    {
        $nameKey = $ingredient->getNameKey();
        if (!$nameKey) {
            return self::PLACEHOLDER_URL;
        }

        $key = 'ingredients/' . $nameKey . '.png';

        return $this->storage->exists($key)
            ? $this->storage->publicUrl($key)
            : self::PLACEHOLDER_URL;
    }
}
