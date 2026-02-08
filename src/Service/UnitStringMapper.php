<?php

namespace App\Service;

use App\Enum\Unit;

final class UnitStringMapper
{
    public function map(?string $raw): Unit
    {
        if ($raw === null) {
            return Unit::G;
        }

        $u = trim(mb_strtolower($raw));

        // Normalisations fréquentes
        $u = str_replace(['.', ' ', "\u{00A0}"], '', $u); // points / espaces / espace insécable
        $u = str_replace(['é', 'è', 'ê'], 'e', $u);
        $u = str_replace(['à', 'â'], 'a', $u);
        $u = str_replace(['î', 'ï'], 'i', $u);
        $u = str_replace(['ô'], 'o', $u);
        $u = str_replace(['û', 'ù', 'ü'], 'u', $u);

        // Synonymes / variantes
        if (in_array($u, ['g', 'gr', 'gramme', 'grammes'], true)) {
            return Unit::G;
        }
        if (in_array($u, ['kg', 'kilo', 'kilogramme', 'kilogrammes'], true)) {
            return Unit::KG;
        }
        if (in_array($u, ['ml', 'millilitre', 'millilitres'], true)) {
            return Unit::ML;
        }
        if (in_array($u, ['l', 'litre', 'litres'], true)) {
            return Unit::L;
        }

        // pièce(s)
        if (in_array($u, ['piece', 'pieces', 'unite', 'unites'], true)) {
            return Unit::PIECE;
        }

        // contenants
        if (in_array($u, ['pot', 'pots'], true)) {
            return Unit::POT;
        }
        if (in_array($u, ['boite', 'boites'], true)) {
            return Unit::BOITE;
        }
        if (in_array($u, ['sachet', 'sachets'], true)) {
            return Unit::SACHET;
        }
        if (in_array($u, ['tranche', 'tranches'], true)) {
            return Unit::TRANCHE;
        }
        if (in_array($u, ['paquet', 'paquets'], true)) {
            return Unit::PAQUET;
        }

        // cuillères (pas dans ton enum) -> fallback "pièce"
        // càs / cas / c.à.s / cuillere a soupe, etc.
        if (str_contains($u, 'cas') || str_contains($u, 'cuillereasoupe') || str_contains($u, 'cuilleresoupe')) {
            return Unit::PIECE;
        }
        if (str_contains($u, 'cac') || str_contains($u, 'cuillereacafe') || str_contains($u, 'cuillerecafe')) {
            return Unit::PIECE;
        }

        // fallback safe
        return Unit::G;
    }
}
