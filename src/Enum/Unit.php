<?php
// src/Enum/Unit.php
namespace App\Enum;

enum Unit: string
{
    case G = 'g';
    case KG = 'kg';
    case ML = 'ml';
    case L = 'l';

    case PIECE = 'piece'; 
    case POT = 'pot';
    case BOITE = 'boite';
    case SACHET = 'sachet';
    case TRANCHE = 'tranche';
    case PAQUET = 'paquet';
}
