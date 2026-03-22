<?php

namespace App\Enum;

enum CategoryEnum: string
{
    // 🥕 Produits frais
    case FRUIT = 'fruit';
    case LEGUME = 'legume';
    case HERBE_AROMATIQUE = 'herbe_aromatique';
    case CHAMPIGNON = 'champignon';

    // 🥩 Protéines animales
    case VIANDE = 'viande';
    case VOLAILLE = 'volaille';
    case POISSON = 'poisson';
    case FRUIT_DE_MER = 'fruit_de_mer';
    case CHARCUTERIE = 'charcuterie';

    // 🥚 Produits animaux & assimilés
    case OEUF = 'oeuf';
    case PRODUIT_LAITIER = 'produit_laitier';
    case FROMAGE = 'fromage';

    // 🌾 Féculents & céréales
    case CEREALE = 'cereale';
    case PATE = 'pate';
    case RIZ = 'riz';
    case LEGUMINEUSE = 'legumineuse';
    case POMME_DE_TERRE = 'pomme_de_terre';
    case PAIN = 'pain';

    // 🛢️ Matières grasses & huiles
    case HUILE = 'huile';
    case BEURRE_MARGARINE = 'beurre_margarine';
    case CREME = 'creme';

    // 🧂 Assaisonnements & saveurs
    case EPICE = 'epice';
    case CONDIMENT = 'condiment';
    case SAUCE = 'sauce';
    case SEL_POIVRE = 'sel_poivre';
    case SUCRE_EDULCORANT = 'sucre_edulcorant';
    case VINAIGRE = 'vinaigre';

    // 🍫 Produits sucrés
    case PATISSERIE = 'patisserie';
    case CHOCOLAT = 'chocolat';
    case CONFITURE_MIEL = 'confiture_miel';
    case DESSERT = 'dessert';

    // 🥫 Épicerie & conserves
    case CONSERVE = 'conserve';
    case BOCAL = 'bocal';
    case SURGELE = 'surgele';
    case PRODUIT_SEC = 'produit_sec';

    // 🥤 Boissons
    case BOISSON = 'boisson';
    case BOISSON_ALCOOLISEE = 'boisson_alcoolisee';

    // 🧑‍🍳 Autres / cas particuliers
    case PLAT_PREPARE = 'plat_prepare';
    case AIDE_CULINAIRE = 'aide_culinaire'; // levure, gélatine, bouillon…
    case AUTRE = 'autre';
    case FRUIT_SEC = 'fruit_sec';

    public function label(): string
    {
        return match ($this) {
            self::FRUIT => 'Fruits',
            self::LEGUME => 'Légumes',
            self::HERBE_AROMATIQUE => 'Herbes aromatiques',
            self::CHAMPIGNON => 'Champignons',
            self::VIANDE => 'Viande',
            self::VOLAILLE => 'Volaille',
            self::POISSON => 'Poisson',
            self::FRUIT_DE_MER => 'Fruits de mer',
            self::CHARCUTERIE => 'Charcuterie',
            self::OEUF => 'Œufs',
            self::PRODUIT_LAITIER => 'Produits laitiers',
            self::FROMAGE => 'Fromages',
            self::CEREALE => 'Céréales',
            self::PATE => 'Pâtes',
            self::RIZ => 'Riz',
            self::LEGUMINEUSE => 'Légumineuses',
            self::POMME_DE_TERRE => 'Pommes de terre',
            self::PAIN => 'Pain',
            self::HUILE => 'Huiles',
            self::BEURRE_MARGARINE => 'Beurre / margarine',
            self::CREME => 'Crèmes',
            self::EPICE => 'Épices',
            self::CONDIMENT => 'Condiments',
            self::SAUCE => 'Sauces',
            self::SEL_POIVRE => 'Sel & poivre',
            self::SUCRE_EDULCORANT => 'Sucres',
            self::VINAIGRE => 'Vinaigres',
            self::PATISSERIE => 'Pâtisserie',
            self::CHOCOLAT => 'Chocolat',
            self::CONFITURE_MIEL => 'Confitures & miel',
            self::DESSERT => 'Desserts',
            self::CONSERVE => 'Conserves',
            self::BOCAL => 'Bocaux',
            self::SURGELE => 'Surgelés',
            self::PRODUIT_SEC => 'Produits secs',
            self::BOISSON => 'Boissons',
            self::BOISSON_ALCOOLISEE => 'Boissons alcoolisées',
            self::PLAT_PREPARE => 'Plats préparés',
            self::AIDE_CULINAIRE => 'Aides culinaires',
            self::AUTRE => 'Autres',
            self::FRUIT_SEC => 'Fruits secs',
        };
    }

    public static function optional(): array
    {
        return [
            // 🟢 Toujours facultatif
            self::EPICE,
            self::HERBE_AROMATIQUE,
            self::CONDIMENT,
            self::SAUCE,
            self::SEL_POIVRE,
            self::VINAIGRE,
            self::SUCRE_EDULCORANT,
            self::BOISSON_ALCOOLISEE,
            self::FRUIT_SEC,
        ];
    }
}
