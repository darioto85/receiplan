<?php

namespace App\Service;

final class NameKeyNormalizer
{
    /**
     * Normalise un nom en clé canonique.
     */
    public function toKey(string $name): string
    {
        $key = trim(mb_strtolower($name));

        if (class_exists(\Normalizer::class)) {
            $key = \Normalizer::normalize($key, \Normalizer::FORM_D) ?? $key;
            $key = preg_replace('/\p{Mn}+/u', '', $key) ?? $key;
        }

        $key = preg_replace('/[^a-z0-9]+/u', '-', $key) ?? $key;
        $key = preg_replace('/-+/', '-', $key) ?? $key;

        return trim($key, '-');
    }

    /**
     * Génère des clés candidates "robustes" (FR light) pour la résolution.
     * - key exacte
     * - singulier naïf (tomates -> tomate, oeufs -> oeuf, noix -> noix)
     * - suppression de stopwords en tête ("de", "des", "du", ...)
     * - variantes par tokens (tomates-cerises -> tomate-cerise)
     *
     * @return string[] (unique, ordre = priorité)
     */
    public function toCandidateKeys(string $name): array
    {
        $base = $this->toKey($name);
        if ($base === '') return [];

        $candidates = [$base];

        // tokenisation
        $tokens = array_values(array_filter(explode('-', $base), fn($t) => $t !== ''));

        // 1) enlever stopwords en tête (fr)
        $stop = ['de','des','du','d','la','le','les','un','une','aux','a'];
        $tokensNoStop = $tokens;
        while (count($tokensNoStop) > 0 && in_array($tokensNoStop[0], $stop, true)) {
            array_shift($tokensNoStop);
        }
        if (count($tokensNoStop) > 0) {
            $candidates[] = implode('-', $tokensNoStop);
        }

        // 2) singulier naïf sur dernier token
        if (count($tokens) > 0) {
            $t = $tokens;
            $t[count($t) - 1] = $this->singularizeFrToken($t[count($t) - 1]);
            $candidates[] = implode('-', $t);
        }

        // 3) singulier naïf sur tous les tokens
        if (count($tokens) > 0) {
            $t = array_map([$this, 'singularizeFrToken'], $tokens);
            $candidates[] = implode('-', $t);
        }

        // 4) tokens sans stopwords + singulier
        if (count($tokensNoStop) > 0) {
            $t = array_map([$this, 'singularizeFrToken'], $tokensNoStop);
            $candidates[] = implode('-', $t);
        }

        // dédoublonnage en conservant l'ordre
        $seen = [];
        $out = [];
        foreach ($candidates as $k) {
            $k = trim($k, '-');
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $k;
        }

        return $out;
    }

    /**
     * Singularisation FR "light" (volontairement simple et stable).
     * - tomates -> tomate
     * - oeufs -> oeuf
     * - oignons -> oignon
     * - mais: riz, mais, noix, jus => inchangé (terminaisons particulières)
     */
    private function singularizeFrToken(string $token): string
    {
        $t = $token;

        // exceptions fréquentes à ne pas toucher
        $noChange = ['riz','mais','noix','jus','couscous'];
        if (in_array($t, $noChange, true)) return $t;

        // mots très courts: évite de casser
        if (mb_strlen($t) <= 3) return $t;

        // terminaison "x" (ex: "poireaux" -> poireau ; "choux" -> chou)
        if (str_ends_with($t, 'aux')) {
            // poissons -> poisson (pas aux)
            // "chevaux" -> cheval (règle aux->al) mais pour ingrédients, souvent -aux -> -au
            return mb_substr($t, 0, -1); // aux -> au
        }

        if (str_ends_with($t, 'x')) {
            return mb_substr($t, 0, -1);
        }

        // terminaison "s"
        if (str_ends_with($t, 's')) {
            return mb_substr($t, 0, -1);
        }

        return $t;
    }
}
