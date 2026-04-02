<?php

namespace App\Service;

final class AiIngredientNormalizer
{
    private const CONFIDENCE_THRESHOLD = 0.75;

    /**
     * @param array{
     *   name:string,
     *   quantity:float|null,
     *   quantity_raw:string|null,
     *   unit:string|null,
     *   unit_raw:string|null,
     *   notes:string|null,
     *   confidence:float
     * } $item
     *
     * @return array{
     *   ingredient: array{
     *     name:string,
     *     quantity:float|null,
     *     unit:string|null,
     *     quantity_raw:string|null,
     *     unit_raw:string|null,
     *     notes:string|null
     *   },
     *   warnings: string[],
     *   needs_confirmation: bool
     * }
     */
    public function normalize(array $item): array
    {
        $warnings = [];

        $name = $this->normalizeIngredientName((string) ($item['name'] ?? ''));

        $quantity = $item['quantity'] ?? null;
        $quantityRaw = $item['quantity_raw'] ?? null;
        $unitRaw = $item['unit_raw'] ?? null;

        $normalizedUnitInfo = $this->normalizeUnitWithConversion(
            is_string($item['unit'] ?? null) ? (string) $item['unit'] : null,
            is_numeric($quantity) ? (float) $quantity : null
        );

        $unit = $normalizedUnitInfo['unit'];
        $quantity = $normalizedUnitInfo['quantity'];

        if (!empty($normalizedUnitInfo['converted'])) {
            $warnings[] = 'unit_converted';
        }

        if ($unit === null && is_string($unitRaw) && trim($unitRaw) !== '') {
            $normalizedFromRaw = $this->normalizeUnitWithConversion(
                $unitRaw,
                is_numeric($quantity) ? (float) $quantity : null
            );

            if ($normalizedFromRaw['unit'] !== null) {
                $unit = $normalizedFromRaw['unit'];
                $quantity = $normalizedFromRaw['quantity'];
                $warnings[] = 'unit_normalized_from_raw';

                if (!empty($normalizedFromRaw['converted'])) {
                    $warnings[] = 'unit_converted_from_raw';
                }
            }
        }

        if ($quantity === null && is_string($quantityRaw)) {
            $parsed = $this->parseQuantityFromRaw($quantityRaw);
            if ($parsed !== null) {
                $quantity = $parsed;
                $warnings[] = 'quantity_parsed_from_raw';
            }
        }

        if ($quantity !== null && $unit === null && is_string($unitRaw) && trim($unitRaw) !== '') {
            $normalizedFromRaw = $this->normalizeUnitWithConversion($unitRaw, (float) $quantity);

            if ($normalizedFromRaw['unit'] !== null) {
                $unit = $normalizedFromRaw['unit'];
                $quantity = $normalizedFromRaw['quantity'];
                $warnings[] = 'unit_normalized_from_raw_after_quantity_parse';

                if (!empty($normalizedFromRaw['converted'])) {
                    $warnings[] = 'unit_converted_from_raw';
                }
            }
        }

        if ($unit === null && is_string($unitRaw) && trim($unitRaw) !== '') {
            $warnings[] = 'unsupported_unit';
        }

        if ($quantity !== null && $unit !== null) {
            if ($this->isSuspiciousQuantity($quantity, $unit, $name)) {
                $warnings[] = 'suspicious_quantity_for_unit';
            }
        }

        if (($item['confidence'] ?? 1.0) < self::CONFIDENCE_THRESHOLD) {
            $warnings[] = 'low_confidence';
        }

        $hasExplicitRawUnit = is_string($unitRaw) && trim($unitRaw) !== '';

        if ($unit === null && !$hasExplicitRawUnit) {
            $unit = 'piece';
            $warnings[] = 'unit_defaulted_to_piece';
        }

        $needsConfirmation =
            !empty($warnings) ||
            $quantity === null ||
            $unit === null;

        return [
            'ingredient' => [
                'name' => $name,
                'quantity' => $quantity,
                'unit' => $unit,
                'quantity_raw' => $quantityRaw,
                'unit_raw' => $unitRaw,
                'notes' => $item['notes'] ?? null,
            ],
            'warnings' => array_values(array_unique($warnings)),
            'needs_confirmation' => $needsConfirmation,
        ];
    }

    private function parseQuantityFromRaw(string $raw): ?float
    {
        $raw = trim(mb_strtolower($raw));

        if (preg_match('/^x\s*(\d+(?:[.,]\d+)?)$/u', $raw, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        if (preg_match('/^\d+(?:[.,]\d+)?$/u', $raw)) {
            return (float) str_replace(',', '.', $raw);
        }

        if (in_array($raw, ['une', 'un'], true)) {
            return 1.0;
        }

        return null;
    }

    /**
     * @return array{
     *   unit:string|null,
     *   quantity:float|null,
     *   converted:bool
     * }
     */
    private function normalizeUnitWithConversion(?string $unit, ?float $quantity): array
    {
        if ($unit === null) {
            return [
                'unit' => null,
                'quantity' => $quantity,
                'converted' => false,
            ];
        }

        $u = trim(mb_strtolower($unit));
        if ($u === '') {
            return [
                'unit' => null,
                'quantity' => $quantity,
                'converted' => false,
            ];
        }

        $u = str_replace('.', '', $u);
        $u = str_replace(['é', 'è', 'ê', 'ë'], 'e', $u);
        $u = str_replace(['à', 'â'], 'a', $u);
        $u = str_replace(['î', 'ï'], 'i', $u);
        $u = str_replace(['ô', 'ö'], 'o', $u);
        $u = str_replace(['ù', 'û', 'ü'], 'u', $u);
        $u = str_replace('ç', 'c', $u);

        if (in_array($u, ['cl', 'centilitre', 'centilitres'], true)) {
            return [
                'unit' => 'ml',
                'quantity' => $quantity !== null ? $quantity * 10 : null,
                'converted' => true,
            ];
        }

        if (in_array($u, ['dl', 'decilitre', 'decilitres'], true)) {
            return [
                'unit' => 'ml',
                'quantity' => $quantity !== null ? $quantity * 100 : null,
                'converted' => true,
            ];
        }

        $normalizedUnit = match ($u) {
            'g', 'gramme', 'grammes', 'gr' => 'g',
            'kg', 'kilo', 'kilos', 'kilogramme', 'kilogrammes' => 'kg',
            'ml', 'millilitre', 'millilitres' => 'ml',
            'l', 'litre', 'litres' => 'l',
            'piece', 'pieces', 'pièce', 'pièces', 'unite', 'unites', 'unité', 'unités' => 'piece',
            'pot', 'pots' => 'pot',
            'boite', 'boites', 'boîte', 'boîtes' => 'boite',
            'sachet', 'sachets' => 'sachet',
            'tranche', 'tranches' => 'tranche',
            'paquet', 'paquets' => 'paquet',
            default => null,
        };

        return [
            'unit' => $normalizedUnit,
            'quantity' => $quantity,
            'converted' => false,
        ];
    }

    private function normalizeIngredientName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = mb_strtolower($name);

        if ($name === '') {
            return $name;
        }

        $name = preg_replace(
            '/\s+(froid|froide|froids|froides|tempere|tempérée|temperee|tempérées|temperees)$/u',
            '',
            $name
        ) ?? $name;

        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name);

        return $name;
    }

    private function isSuspiciousQuantity(float $quantity, string $unit, string $name): bool
    {
        $lowerName = mb_strtolower(trim($name));

        if (in_array($lowerName, ['sel', 'poivre'], true) && $unit === 'g' && $quantity === 0.0) {
            return false;
        }

        return match ($unit) {
            'kg' => $quantity > 20,
            'l' => $quantity > 20,
            'g' => $quantity < 1,
            'ml' => $quantity < 1,
            'piece' => $quantity > 50,
            'pot' => $quantity > 50,
            'boite' => $quantity > 50,
            'sachet' => $quantity > 100,
            'tranche' => $quantity > 100,
            'paquet' => $quantity > 50,
            default => false,
        };
    }
}