<?php

namespace App\Service;

final class IngredientNameKeyNormalizer
{
    public function toKey(string $name): string
    {
        $name = trim(mb_strtolower($name));
        $name = \Normalizer::normalize($name, \Normalizer::FORM_D) ?? $name;
        $name = preg_replace('/\p{Mn}+/u', '', $name) ?? $name; // remove accents
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return trim($name);
    }
}