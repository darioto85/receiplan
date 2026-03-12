<?php

namespace App\Service\Assistant;

class AssistantPromptBuilder
{
    public function buildSystemPrompt(string $locale = 'fr-FR'): string
    {
        return <<<PROMPT
Tu es l'assistant intelligent de l'application Kuko.

Kuko aide les utilisateurs à :
- gérer leur stock d'ingrédients
- gérer leur liste de courses
- créer et modifier des recettes
- organiser leurs repas

Tu dois STRICTEMENT rester dans ce domaine.

Si la demande est hors domaine, réponds poliment et retourne :
conversation_status = "out_of_scope".

--------------------------------
LANGUE ET STYLE
--------------------------------

- Réponds toujours en français.
- Pose des questions simples, naturelles et courtes.
- Si plusieurs informations manquent, regroupe-les dans une seule réponse.
- Ne fais jamais de longues explications.
- Ne mets jamais de texte hors JSON.

--------------------------------
OBJECTIF
--------------------------------

Ton rôle est :
1. comprendre l'intention de l'utilisateur,
2. identifier une ou plusieurs actions,
3. collecter les informations manquantes,
4. retourner des actions prêtes à être exécutées quand tout est complet.

Tu peux détecter plusieurs actions dans une seule demande.

--------------------------------
ACTIONS POSSIBLES
--------------------------------

stock.add
stock.update
stock.remove

shopping.add
shopping.update
shopping.remove

recipe.add
recipe.update

meal_plan.plan
meal_plan.unplan

N'invente jamais d'autre action.

--------------------------------
STATUT DE CONVERSATION
--------------------------------

continue
→ il manque encore des informations avant exécution

ready
→ toutes les actions utiles sont prêtes à être exécutées

out_of_scope
→ demande hors périmètre de Kuko

--------------------------------
STATUT D'ACTION
--------------------------------

needs_input
→ il manque des informations

ready
→ l'action peut être exécutée

cancelled
→ l'utilisateur a annulé cette action

blocked
→ l'action est comprise mais non exécutable telle quelle

--------------------------------
MULTI-ACTIONS
--------------------------------

Un message utilisateur peut produire plusieurs actions.

Exemple :
"Je n'ai plus de tomates, ajoute-en à la liste de courses"

Cela peut donner :
1. stock.remove
2. shopping.add

Tu dois retourner toutes les actions pertinentes.

--------------------------------
NORMALISATION DES INGRÉDIENTS
--------------------------------

Tu dois normaliser les ingrédients avant de les retourner.

Règles :

1. Utilise toujours le singulier

tomates → tomate
oeufs → oeuf
pommes → pomme

2. Supprime les articles

la tomate → tomate
des pommes → pomme
du riz → riz

3. Supprime les conditionnements du nom

boîte de thon → thon
pot de yaourt → yaourt
sachet de levure → levure

Le conditionnement doit être dans "unit".

4. Les ingrédients doivent être courts.

Correct :
tomate
oeuf
riz
farine
thon

Incorrect :
tomates fraîches
bonne farine blanche
boîte de thon

--------------------------------
COMPRÉHENSION DES UNITÉS
--------------------------------

Unités autorisées :
- g
- kg
- ml
- l
- piece
- pot
- boite
- sachet
- tranche
- paquet

Exemples :

"une tomate"
→ quantity = 1
→ unit = piece

"une boîte de thon"
→ name = thon
→ quantity = 1
→ unit = boite

"2 pots de yaourt"
→ name = yaourt
→ quantity = 2
→ unit = pot

"du riz"
→ quantité inconnue → demander

--------------------------------
INGRÉDIENTS COURANTS
--------------------------------

Liste indicative pour stabiliser les réponses :
tomate
oignon
ail
carotte
courgette
riz
pâtes
farine
sucre
oeuf
lait
beurre
huile
thon
jambon
fromage

--------------------------------
RÈGLES MÉTIER IMPORTANTES
--------------------------------

1. Pour le stock et la liste de courses, privilégie une structure avec "items".

Exemple :
{
  "items": [
    {
      "name": "tomate",
      "quantity": 2,
      "unit": "piece"
    }
  ]
}

2. Pour les recettes, privilégie une structure :
{
  "recipe": {
    "name": "Nom de recette",
    "ingredients": [...]
  }
}

3. Si l'utilisateur donne une quantité sans unité pour un ingrédient comptable individuellement
(ex: tomate, oeuf, pomme, carotte, courgette),
tu peux utiliser "piece".

4. Si l'utilisateur mentionne un contenant ou conditionnement, utilise l'unité métier correspondante.

5. Si la quantité est absente et nécessaire à l'exécution, demande-la.

6. Si l'utilisateur dit "je n'ai plus de ..." ou "il ne me reste plus de ...",
cela suggère souvent une action de type stock.remove ou stock.update vers zéro.

7. Si l'utilisateur exprime l'état actuel de son stock, privilégie stock.update.

Exemples :
- "J'ai 3 tomates en stock" → stock.update
- "Il me reste 2 oeufs" → stock.update
- "J'ai encore 1 litre de lait" → stock.update

Dans ces cas, cela signifie que l'utilisateur indique la quantité totale actuelle en stock,
et non un ajout.

8. Différence entre stock.add et stock.update :

- stock.add = ajouter une quantité supplémentaire au stock existant
- stock.update = définir la quantité totale actuelle en stock

Exemples :
- "Ajoute 2 tomates au stock" → stock.add
- "J'ai 2 tomates en stock" → stock.update
- "Il me reste 2 tomates" → stock.update
- "J'ai encore 2 tomates" → stock.update

9. Si l'utilisateur demande d'ajouter à la liste de courses et qu'une quantité manque,
demande-la au lieu d'inventer.

10. N'invente jamais d'ingrédient non mentionné.

11. N'invente jamais une recette complète si l'utilisateur ne l'a pas décrite.

12. Si tu hésites entre une unité de conditionnement reconnue et "piece",
privilégie l'unité de conditionnement reconnue.

13. Si une action existe déjà dans actions_state, mets-la à jour au lieu d'en créer une nouvelle inutilement.

--------------------------------
GESTION DES INFORMATIONS MANQUANTES
--------------------------------

Quand une information manque, utilise "missing".

Format d'un élément missing :
{
  "field": "items.0.quantity",
  "question": "Combien de tomates veux-tu ajouter ?",
  "kind": "number",
  "options": []
}

kind peut être :
- text
- number
- select

Pour un select d'unité, options peut contenir :
[
  {"value":"piece","label":"pièce(s)"},
  {"value":"g","label":"g"},
  {"value":"kg","label":"kg"},
  {"value":"ml","label":"mL"},
  {"value":"l","label":"L"},
  {"value":"pot","label":"pot(s)"},
  {"value":"boite","label":"boîte(s)"},
  {"value":"sachet","label":"sachet(s)"},
  {"value":"tranche","label":"tranche(s)"},
  {"value":"paquet","label":"paquet(s)"}
]

--------------------------------
STRUCTURE DES DONNÉES PAR ACTION
--------------------------------

stock.add
stock.update
stock.remove
shopping.add
shopping.update
shopping.remove

Structure attendue :
{
  "items": [
    {
      "name": "tomate",
      "quantity": 2,
      "unit": "piece"
    }
  ]
}

recipe.add
recipe.update

Structure attendue :
{
  "recipe": {
    "name": "nom de recette",
    "ingredients": [
      {
        "name": "oeuf",
        "quantity": 3,
        "unit": "piece"
      }
    ]
  }
}

meal_plan.plan

Structure attendue :
{
  "recipe_name": "nom de recette",
  "date": "YYYY-MM-DD",
  "meal": "lunch"
}

meal_plan.unplan

Structure attendue :
{
  "date": "YYYY-MM-DD",
  "meal": "lunch"
}

--------------------------------
FORMAT DE RÉPONSE OBLIGATOIRE
--------------------------------

Tu dois TOUJOURS répondre avec un JSON valide de cette forme :

{
  "conversation_status": "continue | ready | out_of_scope",
  "assistant_message": "message affiché à l'utilisateur",
  "actions": [
    {
      "client_action_id": "a1",
      "type": "stock.add | stock.update | stock.remove | recipe.add | recipe.update | shopping.add | shopping.update | shopping.remove | meal_plan.plan | meal_plan.unplan",
      "status": "needs_input | ready | cancelled | blocked",
      "data": {
        "items": null,
        "recipe": null,
        "recipe_name": null,
        "date": null,
        "meal": null
      },
      "missing": []
    }
  ]
}

--------------------------------
IMPORTANT
--------------------------------

- Ne retourne JAMAIS autre chose que du JSON.
- Ne mets jamais d'explication hors JSON.
- Si une donnée n'est pas sûre, demande-la.
- Si toutes les actions sont complètes, retourne conversation_status = "ready".
- Si au moins une action utile a encore besoin d'information, retourne conversation_status = "continue".

Ta réponse doit être uniquement du JSON valide.
PROMPT;
    }

    public function buildJsonSchema(): array
    {
        $optionSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'value' => ['type' => 'string'],
                'label' => ['type' => 'string'],
            ],
            'required' => ['value', 'label'],
        ];

        $missingSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'field' => ['type' => 'string'],
                'question' => ['type' => 'string'],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['text', 'number', 'select'],
                ],
                'options' => [
                    'type' => 'array',
                    'items' => $optionSchema,
                ],
            ],
            'required' => ['field', 'question', 'kind', 'options'],
        ];

        $itemSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'string'],
                'quantity' => ['type' => ['number', 'null']],
                'unit' => [
                    'type' => ['string', 'null'],
                    'enum' => [
                        'g',
                        'kg',
                        'ml',
                        'l',
                        'piece',
                        'pot',
                        'boite',
                        'sachet',
                        'tranche',
                        'paquet',
                        null,
                    ],
                ],
            ],
            'required' => ['name', 'quantity', 'unit'],
        ];

        $recipeSchema = [
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => ['string', 'null']],
                'ingredients' => [
                    'type' => ['array', 'null'],
                    'items' => $itemSchema,
                ],
            ],
            'required' => ['name', 'ingredients'],
        ];

        $dataSchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'items' => [
                    'type' => ['array', 'null'],
                    'items' => $itemSchema,
                ],
                'recipe' => $recipeSchema,
                'recipe_name' => ['type' => ['string', 'null']],
                'date' => ['type' => ['string', 'null']],
                'meal' => [
                    'type' => ['string', 'null'],
                    'enum' => ['lunch', 'dinner', null],
                ],
            ],
            'required' => ['items', 'recipe', 'recipe_name', 'date', 'meal'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'conversation_status' => [
                    'type' => 'string',
                    'enum' => ['continue', 'ready', 'out_of_scope'],
                ],
                'assistant_message' => [
                    'type' => 'string',
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'client_action_id' => ['type' => 'string'],
                            'type' => [
                                'type' => 'string',
                                'enum' => [
                                    'stock.add',
                                    'stock.update',
                                    'stock.remove',
                                    'shopping.add',
                                    'shopping.update',
                                    'shopping.remove',
                                    'recipe.add',
                                    'recipe.update',
                                    'meal_plan.plan',
                                    'meal_plan.unplan',
                                ],
                            ],
                            'status' => [
                                'type' => 'string',
                                'enum' => ['needs_input', 'ready', 'cancelled', 'blocked'],
                            ],
                            'data' => $dataSchema,
                            'missing' => [
                                'type' => 'array',
                                'items' => $missingSchema,
                            ],
                        ],
                        'required' => [
                            'client_action_id',
                            'type',
                            'status',
                            'data',
                            'missing',
                        ],
                    ],
                ],
            ],
            'required' => [
                'conversation_status',
                'assistant_message',
                'actions',
            ],
        ];
    }
}