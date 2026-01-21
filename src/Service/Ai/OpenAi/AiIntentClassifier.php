<?php

namespace App\Service\Ai\OpenAi;

final class AiIntentClassifier
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
    ) {}

    /**
     * @return
     *  'add_stock'
     *  |'add_recipe'
     *  |'add_to_shopping_list'
     *  |'remove_from_shopping_list'
     *  |'update_stock_quantity'
     *  |'consume_stock'
     *  |'plan_recipe'
     *  |'unplan_recipe'
     *  |'update_recipe'
     *  |'unknown'
     */
    public function classify(string $userText): string
    {
        $userText = trim($userText);
        if ($userText === '') {
            return 'unknown';
        }

        $allowed = [
            'add_stock',
            'add_recipe',
            'add_to_shopping_list',
            'remove_from_shopping_list',
            'update_stock_quantity',
            'consume_stock',
            'plan_recipe',
            'unplan_recipe',
            'update_recipe',
            'unknown',
        ];

        $schema = [
            'name' => 'receiplan_action_classifier_v2',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => $allowed,
                    ],
                ],
                'required' => ['action'],
            ],
        ];

        $system =
            "Tu es un classifieur d'intention pour une application de cuisine.\n" .
            "Tu dois retourner UNIQUEMENT un JSON conforme au schema.\n\n" .
            "Choisis UNE action parmi :\n" .
            "- add_stock : l'utilisateur ajoute des ingrédients à son stock (achats, ajout, \"j'ai pris...\").\n" .
            "- update_stock_quantity : l'utilisateur fixe/ajuste un stock à une valeur (\"mets à 0\", \"il me reste 200g\", \"j'ai plus de...\").\n" .
            "- consume_stock : l'utilisateur indique qu'il a utilisé/consommé des ingrédients (décrémenter : \"j'ai utilisé 2 oeufs\").\n" .
            "- add_to_shopping_list : l'utilisateur veut ajouter des éléments à la liste de courses (\"ajoute à ma liste...\").\n" .
            "- remove_from_shopping_list : l'utilisateur veut retirer des éléments de la liste de courses (\"enlève de ma liste...\").\n" .
            "- add_recipe : l'utilisateur veut créer/ajouter une recette.\n" .
            "- update_recipe : l'utilisateur veut modifier une recette existante (ajouter/enlever ingrédient, changer quantité, renommer).\n" .
            "- plan_recipe : l'utilisateur veut planifier une recette à une date (\"prévois ... demain\").\n" .
            "- unplan_recipe : l'utilisateur veut enlever/annuler une planification.\n" .
            "- unknown : si ambigu ou hors sujet.\n\n" .
            "Règles de décision :\n" .
            "- Si le texte parle d'ACHETER / AJOUTER au stock => add_stock.\n" .
            "- Si le texte parle de METTRE À JOUR une quantité existante (reste, plus de, mets à zéro) => update_stock_quantity.\n" .
            "- Si le texte parle d'UTILISER / CONSOMMER => consume_stock.\n" .
            "- Si le texte mentionne explicitement \"liste de courses\" ou \"courses\" => actions shopping.\n" .
            "- Si le texte parle de \"planifier\"/\"prévoir\"/\"programmer\" un repas à une date => plan_recipe.\n" .
            "- Si le texte parle d'annuler/supprimer un repas planifié => unplan_recipe.\n" .
            "- Si le texte demande de créer une recette => add_recipe.\n" .
            "- Si le texte demande de modifier une recette existante => update_recipe.\n";

        try {
            $result = $this->client->callJsonSchema($userText, $system, $schema);
        } catch (\Throwable) {
            // classifier doit rester "safe" et ne jamais casser l’app
            return 'unknown';
        }

        $action = $result['action'] ?? null;
        if (!is_string($action)) {
            return 'unknown';
        }

        $action = trim($action);
        if ($action === '' || !in_array($action, $allowed, true)) {
            return 'unknown';
        }

        return $action;
    }
}
