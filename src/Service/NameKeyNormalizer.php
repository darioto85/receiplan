<?php

namespace App\Service;

final class NameKeyNormalizer
{
    /**
     * Normalise un nom en clé canonique :
     * - minuscules
     * - suppression des accents
     * - caractères non alphanumériques -> séparateur
     * - séparateur unique "-"
     * - trim des "-"
     *
     * Exemples :
     *  "Crème fraîche épaisse"  => "creme-fraiche-epaisse"
     *  "  OEUFS (bio)  "        => "oeufs-bio"
     *  "Lait 1/2 écrémé"        => "lait-1-2-ecreme"
     */
    public function toKey(string $name): string
    {
        // 1) Trim + minuscules UTF-8
        $key = trim(mb_strtolower($name));

        // 2) Suppression des accents (Unicode safe)
        if (class_exists(\Normalizer::class)) {
            $key = \Normalizer::normalize($key, \Normalizer::FORM_D) ?? $key;
            $key = preg_replace('/\p{Mn}+/u', '', $key) ?? $key;
        }

        // 3) Tout ce qui n'est pas lettre ou chiffre => "-"
        $key = preg_replace('/[^a-z0-9]+/u', '-', $key) ?? $key;

        // 4) Collapse des "-" multiples
        $key = preg_replace('/-+/', '-', $key) ?? $key;

        // 5) Trim des "-"
        return trim($key, '-');
    }
}
