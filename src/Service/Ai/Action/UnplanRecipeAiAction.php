<?php

namespace App\Service\Ai\Action;

use App\Entity\Recipe;
use App\Entity\User;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;
use App\Service\Ai\UnplanRecipeHandler;
use App\Service\NameKeyNormalizer;
use Doctrine\ORM\EntityManagerInterface;

final class UnplanRecipeAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly EntityManagerInterface $em,
        private readonly NameKeyNormalizer $nameKeyNormalizer,
        private readonly UnplanRecipeHandler $handler,
    ) {}

    public function name(): string
    {
        return 'unplan_recipe';
    }

    public function extractDraft(string $text, AiContext $ctx): array
    {
        $schema = [
            'name' => 'receiplan_unplan_recipe_v2',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'date' => [
                        'type' => ['string', 'null'],
                        'description' => 'YYYY-MM-DD',
                    ],
                    'recipe' => [
                        'type' => ['object', 'null'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name_raw' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                        ],
                        'required' => ['name_raw', 'name'],
                    ],
                ],
                'required' => ['date', 'recipe'],
            ],
        ];

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $system =
            "Tu extrais une intention d'annulation de planification.\n" .
            "Retourne UNIQUEMENT un JSON conforme au schema.\n\n" .
            "Nous sommes le {$today}.\n" .
            "- date doit être au format YYYY-MM-DD.\n" .
            "- 'demain' => {$tomorrow}\n" .
            "- 'aujourd\\'hui' => {$today}\n" .
            "- Si la date est absente ou incompréhensible, mets null.\n" .
            "- recipe peut être null si l'utilisateur veut déplanifier toute la journée.\n" .
            "- Sinon recipe.name_raw + recipe.name doivent être remplis.\n";

        $draft = $this->client->callJsonSchema($text, $system, $schema);

        if (!is_array($draft)) {
            throw new \RuntimeException('openai_invalid_draft');
        }

        $draft['date'] = $draft['date'] !== null ? (string)$draft['date'] : null;

        if ($draft['recipe'] !== null) {
            if (!is_array($draft['recipe'])) {
                $draft['recipe'] = null;
            } else {
                $draft['recipe']['name_raw'] = (string)($draft['recipe']['name_raw'] ?? '');
                $draft['recipe']['name'] = (string)($draft['recipe']['name'] ?? $draft['recipe']['name_raw'] ?? '');
            }
        }

        return $draft;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        // Résolution server-side vers recipe_id si recipe != null
        $draft['recipe_id'] = null;
        $draft['candidates'] = [];

        if (!isset($draft['recipe']) || $draft['recipe'] === null || !is_array($draft['recipe'])) {
            return $draft;
        }

        $name = trim((string)($draft['recipe']['name'] ?? $draft['recipe']['name_raw'] ?? ''));
        if ($name === '') {
            return $draft;
        }

        $nameKey = $this->nameKeyNormalizer->toKey($name);
        $repo = $this->em->getRepository(Recipe::class);

        // 1) match exact sur la recette du user
        $exact = $repo->findOneBy([
            'user' => $ctx->user,
            'nameKey' => $nameKey,
        ]);

        if ($exact instanceof Recipe) {
            $draft['recipe_id'] = $exact->getId();
            $draft['recipe']['name'] = (string)$exact->getName();
            return $draft;
        }

        // 2) fallback candidates LIKE (limité)
        $qb = $repo->createQueryBuilder('r')
            ->andWhere('r.user = :u')
            ->andWhere('r.nameKey LIKE :k OR LOWER(r.name) LIKE :q')
            ->setParameter('u', $ctx->user)
            ->setParameter('k', '%'.$nameKey.'%')
            ->setParameter('q', '%'.mb_strtolower($name).'%')
            ->setMaxResults(10)
            ->orderBy('r.id', 'ASC');

        $cands = $qb->getQuery()->getResult();

        $draft['candidates'] = array_map(static function (Recipe $r) {
            return [
                'id' => $r->getId(),
                'name' => (string)$r->getName(),
                'nameKey' => (string)$r->getNameKey(),
            ];
        }, $cands);

        if (count($draft['candidates']) === 1) {
            $draft['recipe_id'] = (int)$draft['candidates'][0]['id'];
            $draft['recipe']['name'] = (string)$draft['candidates'][0]['name'];
        }

        return $draft;
    }

    public function buildClarifyQuestions(array $draft, AiContext $ctx): array
    {
        $questions = [];

        $date = $draft['date'] ?? null;
        if ($date === null || trim((string)$date) === '') {
            $questions[] = [
                'path' => 'date',
                'label' => 'Pour quelle date ? (YYYY-MM-DD)',
                'kind' => 'text',
                'placeholder' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            ];
        }

        // Si recipe est null => on déplanifie la journée (pas de question recette)
        if (!array_key_exists('recipe', $draft) || $draft['recipe'] === null) {
            return $questions;
        }

        $rid = $draft['recipe_id'] ?? null;
        if (!is_int($rid) || $rid <= 0) {
            $cands = $draft['candidates'] ?? [];
            if (is_array($cands) && count($cands) > 0) {
                $questions[] = [
                    'path' => 'recipe_id',
                    'label' => 'Quelle recette veux-tu déplanifier ?',
                    'kind' => 'select',
                    'options' => array_map(static function ($c) {
                        return [
                            'value' => (string)($c['id'] ?? ''),
                            'label' => (string)($c['name'] ?? ''),
                        ];
                    }, array_slice($cands, 0, 10)),
                ];
            } else {
                $questions[] = [
                    'path' => 'recipe.name',
                    'label' => "Je ne trouve pas cette recette. Tu peux préciser le nom exact ?",
                    'kind' => 'text',
                    'placeholder' => 'ex: Pâtes à la bolognaise',
                ];
            }
        }

        return $questions;
    }

    public function buildConfirmText(array $draft, AiContext $ctx): string
    {
        $date = trim((string)($draft['date'] ?? ''));
        $hasRecipe = isset($draft['recipe']) && is_array($draft['recipe']);

        if (!$hasRecipe) {
            if ($date !== '') {
                return "Je retire tous les repas planifiés pour le {$date}. Tu confirmes ?";
            }
            return "Je peux retirer des repas planifiés. Tu confirmes ?";
        }

        $name = trim((string)($draft['recipe']['name'] ?? $draft['recipe']['name_raw'] ?? ''));
        if ($name === '') $name = 'cette recette';

        if ($date !== '') {
            return "Je retire la planification de « {$name} » pour le {$date}. Tu confirmes ?";
        }

        return "Je peux retirer la planification de « {$name} ». Tu confirmes ?";
    }

    public function apply(User $user, array $draft): array
    {
        // On applique via handler (qui supporte recipe_id en priorité)
        return $this->handler->handle($user, $draft);
    }
}
