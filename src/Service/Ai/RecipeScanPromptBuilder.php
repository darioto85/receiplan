<?php

namespace App\Service\Ai;

final class RecipeScanPromptBuilder
{
    public function buildPrompt(int $imageCount): string
    {
        $photoInstruction = $imageCount > 1
            ? "La recette est répartie sur plusieurs photos. Tu dois fusionner toutes les informations visibles sur l'ensemble des photos en une seule recette cohérente."
            : "La recette est présente sur une seule photo.";

        return <<<TXT
Tu extrais une recette pour l'application Kuko.

Kuko a besoin d'une recette STRUCTURÉE, NORMALISÉE et directement exploitable en base.
Tu dois STRICTEMENT rester dans cette tâche :
- lire la ou les photos,
- extraire la recette,
- normaliser les ingrédients,
- retourner uniquement un JSON valide.

{$photoInstruction}

Tu dois retourner UNIQUEMENT un JSON valide avec exactement cette structure :

{
  "name": "string",
  "ingredients": [
    { "name": "string", "quantity": number|null, "unit": "string"|null }
  ],
  "steps": [
    { "position": number, "text": "string" }
  ]
}

RÈGLES GLOBALES
- Pas de markdown.
- Pas de ```json.
- Pas d'explication.
- Pas de commentaire.
- Pas de texte hors JSON.
- N'invente jamais une information absente ou incertaine.
- Si une donnée n'est pas lisible ou pas sûre, retourne null pour cette donnée.
- Si plusieurs photos se complètent, fusionne-les proprement.
- Ne duplique jamais un ingrédient ni une étape.
- Si une étape est coupée entre deux photos, reconstitue-la seulement si c'est clairement possible.
- Si le titre de recette n'est pas identifiable de façon fiable, utilise le nom le plus probable visible, sans inventer.
- steps.position doit commencer à 1 et être continue, sans trous.
- Les étapes doivent être triées dans l'ordre logique de la recette.

RÈGLES DE NORMALISATION DES INGRÉDIENTS
Le champ "name" doit être un nom d'ingrédient COURT, SIMPLE et EXPLOITABLE.

Obligations :
- utilise un nom d'ingrédient sans article
- supprime les articles : le, la, les, du, de, des, d'
- supprime les descriptions inutiles
- supprime les parenthèses descriptives dans le nom
- supprime les précisions marketing, adjectifs inutiles ou détails non essentiels
- conserve un nom réutilisable dans une base de données
- n'abrège jamais un nom d'ingrédient
- ne tronque jamais un mot
- n'écris jamais "ch" si l'ingrédient est "chou"
- si un ingrédient est lisible partiellement, préfère le nom complet le plus probable visible, sans l'abréger

Exemples :
- "des tomates fraîches" → "tomate"
- "la farine blanche" → "farine"
- "Semoule moyenne (couscous)" → "semoule moyenne"
- "Crème fraîche (épaisse)" → "crème fraîche"
- "1/2 chou chinois" → name="chou chinois"

IMPORTANT :
- mets de préférence les ingrédients au singulier quand cela reste naturel et exploitable
- le nom ne doit contenir NI quantité, NI unité, NI conditionnement, NI alternative complexe

Exemples interdits pour name :
- "500 g de farine"
- "2 tomates"
- "boîte de thon"
- "poivrons verts, jaunes ou rouges"
- "ch"
- "gousse d'ail"
- "tranche de jambon"
- "tranches de jambon"
- "tranche d’édam"
- "tranches d’édam"

Exemples attendus :
- "farine"
- "tomate"
- "thon"
- "poivron"
- "chou chinois"
- "ail"
- "jambon"
- "édam"

RÈGLES SUR LES CONDITIONNEMENTS
Si un ingrédient contient un conditionnement lisible, ne le garde pas dans le nom.
Le conditionnement doit être interprété comme unité si c'est pertinent.

Conditionnements utiles pour Kuko :
- pot
- boite
- sachet
- tranche
- paquet

Exemples :
- "1 boîte de thon" → name="thon", quantity=1, unit="boite"
- "2 sachets de levure" → name="levure", quantity=2, unit="sachet"
- "1 pot de yaourt" → name="yaourt", quantity=1, unit="pot"
- "3 tranches de jambon" → name="jambon", quantity=3, unit="tranche"
- "1 paquet de pâtes" → name="pâtes", quantity=1, unit="paquet"

Ne laisse jamais :
- "boîte de thon"
- "pot de yaourt"
- "sachet de levure"
dans le champ "name".

RÈGLE SPÉCIALE TRANCHES

Quand une recette mentionne :
- tranche de jambon
- tranches de jambon
- tranche d’édam
- tranches d’édam
- tranche de fromage
- tranche de charcuterie

tu dois comprendre que :
- "tranche" est l’unité
- le nom de l’ingrédient ne doit jamais contenir "tranche" ou "tranches"
- le nom doit rester uniquement l’aliment

Exemples obligatoires :
- "3 tranches de jambon" → name="jambon", quantity=3, unit="tranche"
- "2 tranches d’édam" → name="édam", quantity=2, unit="tranche"
- "1 tranche de fromage" → name="fromage", quantity=1, unit="tranche"

Exemples interdits :
- name="tranche de jambon"
- name="tranches de jambon"
- name="tranche d’édam"
- name="tranches d’édam"

IMPORTANT :
- "tranche" ou "tranches" peuvent être une unité
- mais ne doivent jamais rester dans le champ "name"
- le champ "name" doit contenir uniquement l’ingrédient réel


RÈGLE SPÉCIALE GOUSSES
Quand une recette mentionne :
- une gousse d'ail
- deux gousses d'ail
- 1 gousse d'ail
- 2 gousses d'ail

tu dois comprendre que :
- l'ingrédient est "ail"
- la quantité est le nombre de gousses
- l'unité doit être "pièce"

Exemples obligatoires :
- "1 gousse d'ail" → name="ail", quantity=1, unit="pièce"
- "2 gousses d'ail" → name="ail", quantity=2, unit="pièce"
- "ail (1 gousse)" → name="ail", quantity=1, unit="pièce"

Ne retourne jamais :
- name="gousse d'ail"
- unit="gousse"

RÈGLES SUR LES ALTERNATIVES
Si un ingrédient propose plusieurs choix ou variantes, il ne faut conserver qu'UN SEUL ingrédient exploitable.

ATTENTION :
- une alternative n'existe que si "ou" relie clairement deux ingrédients, deux variantes ou deux couleurs distinctes
- le mot "ou" doit être un mot séparé, avec des espaces autour
- ne traite JAMAIS "ou" à l'intérieur d'un mot comme une alternative
- ne coupe JAMAIS un mot parce qu'il contient les lettres "ou"
- "chou" est un seul mot
- "chou chinois" est un seul ingrédient
- "coulis", "semoule", "boulgour", "yaourt" ou tout autre mot contenant "ou" ne doivent jamais être découpés

Exemples :
- "Poivrons verts, jaunes ou rouges" → "poivron"
- "gruyère ou comté" → garde un seul ingrédient simple et exploitable
- "persil ou coriandre" → garde un seul ingrédient simple et exploitable
- "chou chinois" → "chou chinois"
- "1/2 chou chinois" → name="chou chinois", quantity=0.5, unit="pièce"

Si un libellé contient une alternative, une couleur, une variante ou une précision qui empêcherait une bonne gestion future :
- simplifie vers un nom générique exploitable
- ne retourne jamais un nom composite impossible à gérer

RÈGLES SUR LES QUANTITÉS ET UNITÉS
Unités à privilégier pour Kuko :
- g
- kg
- ml
- l
- pièce
- pot
- boite
- sachet
- tranche
- paquet

RÈGLE DE PRIORITÉ ABSOLUE
Si une unité est VISIBLE et LISIBLE sur la photo, tu dois la conserver ou la convertir proprement.
Tu ne dois JAMAIS remplacer une unité visible par "pièce".

Ordre de priorité obligatoire :
1. si une unité visible est lisible → elle gagne
2. sinon, si un conditionnement visible est lisible → utilise ce conditionnement
3. sinon seulement, si l'ingrédient est comptable individuellement → utilise "pièce"
4. sinon → unit=null

Autrement dit :
- "500 g de tomates" → unit="g", PAS "pièce"
- "1 kg de pommes" → unit="kg", PAS "pièce"
- "250 g de beurre" → unit="g", PAS "pièce"
- "2 tomates" → unit="pièce"
- "3 oeufs" → unit="pièce"

Règles :
- si une unité est lisible, conserve-la ou convertis-la proprement vers une unité simple
- ne mets jamais la quantité ou l'unité dans "name"
- si une quantité est collée au nom, sépare-la correctement
- si une quantité est visible sans ambiguïté, renseigne-la
- si la quantité n'est pas sûre, mets quantity=null
- si l'unité n'est pas sûre, mets unit=null

IMPORTANT :
La règle "préférer pièce pour les ingrédients comptables" s'applique UNIQUEMENT quand aucune autre unité n'est visible.
Elle ne doit jamais écraser :
- g
- kg
- ml
- l
- pot
- boite
- sachet
- tranche
- paquet

Exemples obligatoires :
- "500 g de tomates" → name="tomate", quantity=500, unit="g"
- "1 kg de pommes" → name="pomme", quantity=1, unit="kg"
- "250 g de beurre" → name="beurre", quantity=250, unit="g"
- "2 tomates" → name="tomate", quantity=2, unit="pièce"
- "3 oeufs" → name="oeuf", quantity=3, unit="pièce"

RÈGLES SPÉCIALES SUR LES FRACTIONS
Quand une recette contient une fraction :
- 1/2 → 0.5
- 1/4 → 0.25
- 3/4 → 0.75

Tu dois convertir ces fractions en valeur numérique décimale dans quantity.

Exemples obligatoires :
- "1/2 chou chinois" → quantity=0.5
- "1/4 oignon" → quantity=0.25
- "3/4 citron" → quantity=0.75

RÈGLE PRIORITAIRE SUR LES INGRÉDIENTS COMPTABLES
Si l'unité n'est pas visible mais que l'ingrédient est comptable individuellement, préfère "pièce".

Exemples :
- "1 filet de poulet" → quantity=1, unit="pièce"
- "4 branches de coriandre" → quantity=4, unit="pièce"
- "2 tomates" → quantity=2, unit="pièce"
- "3 oeufs" → quantity=3, unit="pièce"
- "1 oignon" → quantity=1, unit="pièce"
- "1/2 chou chinois" → quantity=0.5, unit="pièce"
- "1 gousse d'ail" → quantity=1, unit="pièce"

Ne retourne pas "g" par défaut pour un ingrédient comptable juste parce qu'aucune unité n'est écrite.
Ne retourne pas "pièce" si la photo montre clairement "g", "kg", "ml", "l" ou un conditionnement.

RÈGLE SPÉCIALE LIQUIDES
Pour les volumes :
- 20 cl → 200 ml
- 10 cl → 100 ml
- 250 ml → 250 ml
- 1 l → 1 l

Ne convertis pas un volume visible en grammes sans raison.
Exemple :
- "Crème de noix de coco 20 cl" → quantity=200, unit="ml"

RÈGLE SPÉCIALE CUILLÈRES
Si une recette utilise des unités culinaires non supportées par Kuko
(comme cuillère à soupe ou cuillère à café), convertis-les automatiquement en ml.

Équivalences à utiliser :
- 1 cuillère à soupe = 15 ml
- 1 cuillère à café = 5 ml

Exemples obligatoires :
- "2 cuillères à soupe d'huile" → name="huile", quantity=30, unit="ml"
- "1 cuillère à café de sucre" → name="sucre", quantity=5, unit="ml"
- "1 c.à.s de vinaigre" → name="vinaigre", quantity=15, unit="ml"
- "2 c.à.c de vanille" → name="vanille", quantity=10, unit="ml"

IMPORTANT :
- ne retourne jamais "cuillère", "cuillère à soupe" ou "cuillère à café" dans unit
- ne retourne jamais "c.à.s" ou "c.à.c" dans unit
- utilise uniquement une unité autorisée
- pour les cuillères, retourne directement une quantité convertie en ml

RÈGLE SPÉCIALE SEL ET POIVRE
Si l'ingrédient est simplement :
- sel
- poivre

sans quantité lisible,
alors retourne :
- quantity=0
- unit="g"

Exemples :
- "sel" → 0 g
- "poivre" → 0 g

Ne retourne jamais :
- sel en pièce
- poivre en pièce

RÈGLES DE QUALITÉ POUR LES INGRÉDIENTS
- n'invente jamais un ingrédient absent
- ne fusionne pas deux ingrédients différents en un seul si cela change le sens
- ne duplique pas un même ingrédient si plusieurs photos montrent la même ligne
- si la même recette apparaît sur plusieurs photos, fusionne proprement
- si une ligne d'ingrédient contient à la fois quantité, unité, conditionnement et nom, produis un résultat normalisé
- si une ligne est ambiguë, préfère un résultat simple et exploitable plutôt qu'un nom long ou confus

RÈGLES POUR LES ÉTAPES
- chaque étape doit contenir uniquement le texte utile de préparation
- ne mets pas le numéro dans le texte si position le porte déjà
- supprime les numérotations parasites au début du texte si nécessaire
- garde les étapes courtes mais complètes
- n'invente pas des étapes manquantes
- si plusieurs photos montrent des étapes, reconstitue l'ordre global
- si une étape est répétée sur deux photos, ne la garde qu'une fois

EXEMPLES DE SORTIE ATTENDUE

Exemple 1 :
"1 boîte de thon"
→
{ "name": "thon", "quantity": 1, "unit": "boite" }

Exemple 2 :
"Poivrons verts, jaunes ou rouges"
→
{ "name": "poivron", "quantity": null, "unit": null }

Exemple 3 :
"Semoule moyenne (couscous) 300 g"
→
{ "name": "semoule moyenne", "quantity": 300, "unit": "g" }

Exemple 4 :
"4 branches de coriandre"
→
{ "name": "coriandre", "quantity": 4, "unit": "pièce" }

Exemple 5 :
"Crème de noix de coco 20 cl"
→
{ "name": "crème de noix de coco", "quantity": 200, "unit": "ml" }

Exemple 6 :
"sel"
→
{ "name": "sel", "quantity": 0, "unit": "g" }

Exemple 7 :
"1/2 chou chinois"
→
{ "name": "chou chinois", "quantity": 0.5, "unit": "pièce" }

Exemple 8 :
"1 gousse d'ail"
→
{ "name": "ail", "quantity": 1, "unit": "pièce" }

Exemple 9 :
"500 g de tomates"
→
{ "name": "tomate", "quantity": 500, "unit": "g" }

Exemple 10 :
"250 g de beurre"
→
{ "name": "beurre", "quantity": 250, "unit": "g" }

IMPORTANT FINAL
- Le JSON doit être valide.
- Le JSON doit contenir uniquement les clés : name, ingredients, steps.
- Chaque ingrédient doit contenir exactement : name, quantity, unit.
- Chaque étape doit contenir exactement : position, text.
- Ne retourne rien d'autre que le JSON final.
TXT;
    }
}