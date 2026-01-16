<?php

namespace App\Service;

use App\Entity\MealCookedPrompt;
use App\Repository\MealCookedPromptRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MealCookedPromptNotifyService
{
    public function __construct(
        private readonly MealCookedPromptRepository $promptRepo,
        private readonly PushNotifier $pushNotifier,
        private readonly PushActionTokenService $tokens,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Notifie les prompts PENDING de la veille (limit = nb de users traités).
     *
     * @return array{
     *   date:string,
     *   limit:int,
     *   prompts:int,
     *   marked_sent:int,
     *   push: array{users_notified:int, sent:int, failed:int, deleted:int}
     * }
     */
    public function notifyYesterday(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');
        $dateStr = $yesterday->format('Y-m-d');

        // limit = nb prompts => nb users (unique user/date)
        $prompts = $this->promptRepo->findBy(
            ['status' => MealCookedPrompt::STATUS_PENDING, 'date' => $yesterday],
            ['id' => 'ASC'],
            $limit
        );

        $pushSentUsers = 0;
        $pushSentTotal = 0;
        $pushFailedTotal = 0;
        $pushDeletedTotal = 0;

        $markedSent = 0;

        foreach ($prompts as $prompt) {
            $user = $prompt->getUser();
            $mealPlan = $prompt->getMealPlan();
            $recipe = $mealPlan?->getRecipe();

            if (!$user || !$mealPlan || !$recipe || !$prompt->getId()) {
                // évite boucle infinie sur données incohérentes
                $prompt->expire();
                continue;
            }

            $recipeName = (string) $recipe->getName();

            // ✅ Tokens signés (réponse + fallback page), valables 48h
            $exp = time() + 60 * 60 * 48;

            $yesToken = $this->tokens->sign([
                'promptId' => (int) $prompt->getId(),
                'answer' => MealCookedPrompt::ANSWER_YES,
                'exp' => $exp,
            ]);

            $noToken = $this->tokens->sign([
                'promptId' => (int) $prompt->getId(),
                'answer' => MealCookedPrompt::ANSWER_NO,
                'exp' => $exp,
            ]);

            // Token "open" (sans answer) pour la page fallback (Windows / plateformes sans boutons)
            $openToken = $this->tokens->sign([
                'promptId' => (int) $prompt->getId(),
                'exp' => $exp,
            ]);

            // ⚠️ Relatif = OK si même origin (SW). En prod, tu peux passer en absolu si besoin.
            $yesUrl = "/push/meal-cooked/{$yesToken}";
            $noUrl  = "/push/meal-cooked/{$noToken}";

            // ✅ fallback universel (clic sur la notif)
            $fallbackUrl = "/meal-cooked/respond/{$openToken}";

            $pushResult = $this->pushNotifier->notifyUser($user, [
                'title' => 'Receiplan',
                'body'  => sprintf('Avez-vous cuisiné votre "%s" hier ?', $recipeName),

                // ✅ Sur Windows (pas de boutons), clic sur notif => page Oui/Non
                'url'   => $fallbackUrl,

                // ✅ boutons Oui / Non (si supportés par l’OS)
                'actions' => [
                    ['action' => 'yes', 'title' => 'Oui'],
                    ['action' => 'no',  'title' => 'Non'],
                ],
                'yesUrl' => $yesUrl,
                'noUrl'  => $noUrl,

                // anti-spam UI
                'tag' => "cooked-check-{$dateStr}",
                'renotify' => false,
            ]);

            $sent = (int) ($pushResult['sent'] ?? 0);
            $failed = (int) ($pushResult['failed'] ?? 0);
            $deleted = (int) ($pushResult['deleted'] ?? 0);

            if ($sent > 0) {
                $pushSentUsers++;
                $prompt->markSent();
                $markedSent++;
            }
            // sinon on laisse PENDING pour retenter au prochain run

            $pushSentTotal += $sent;
            $pushFailedTotal += $failed;
            $pushDeletedTotal += $deleted;
        }

        $this->em->flush();

        return [
            'date' => $dateStr,
            'limit' => $limit,
            'prompts' => \count($prompts),
            'marked_sent' => $markedSent,
            'push' => [
                'users_notified' => $pushSentUsers,
                'sent' => $pushSentTotal,
                'failed' => $pushFailedTotal,
                'deleted' => $pushDeletedTotal,
            ],
        ];
    }
}
