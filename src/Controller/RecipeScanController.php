<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\RecipeStep;
use App\Entity\User;
use App\Repository\IngredientRepository;
use App\Service\Ai\RecipePhotoExtractionService;
use App\Service\Image\RecipeScanImageResizer;
use App\Service\NameKeyNormalizer;
use App\Service\UnitStringMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/recipe/scan', name: 'recipe_scan_')]
#[IsGranted('ROLE_USER')]
final class RecipeScanController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $last = $request->getSession()->get('recipe_scan.last_upload');
        $lastResult = $request->getSession()->get('recipe_scan.last_result'); // debug optionnel

        return $this->render('recipe_scan/index.html.twig', [
            'last_upload' => $last,
            'last_result' => $lastResult,
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, RecipeScanImageResizer $resizer): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('recipe_scan_upload', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('recipe_scan_index');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'Aucune image reçue.');
            return $this->redirectToRoute('recipe_scan_index');
        }

        if (!$file->isValid()) {
            $this->addFlash('danger', 'Upload invalide : ' . $file->getErrorMessage());
            return $this->redirectToRoute('recipe_scan_index');
        }

        // Limite app (8 Mo) - tes limites PHP sont maintenant plus hautes
        $maxBytes = 8 * 1024 * 1024;
        if (($file->getSize() ?? 0) > $maxBytes) {
            $this->addFlash('danger', 'Image trop lourde (max 8 Mo).');
            return $this->redirectToRoute('recipe_scan_index');
        }

        $allowed = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
        ];

        $mime = $file->getMimeType() ?? '';
        if (!in_array($mime, $allowed, true)) {
            $this->addFlash('danger', 'Format non supporté. Utilise JPG, PNG, WEBP (ou HEIC sur iPhone).');
            return $this->redirectToRoute('recipe_scan_index');
        }

        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $fs = new Filesystem();

        $targetDir = $projectDir . '/var/uploads/recipe_scan/user_' . $user->getId();
        if (!$fs->exists($targetDir)) {
            $fs->mkdir($targetDir, 0775);
        }

        $ext = $file->guessExtension() ?: 'jpg';
        $safeExt = preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'jpg';

        $base = (new \DateTimeImmutable())->format('Ymd_His') . '_' . bin2hex(random_bytes(6));
        $originalFilename = sprintf('scan_%s.%s', $base, strtolower($safeExt));

        // 1) Sauvegarde original
        $file->move($targetDir, $originalFilename);
        $originalAbs = $targetDir . '/' . $originalFilename;

        // 2) Version IA redimensionnée (JPEG)
        $aiFilename = sprintf('scan_%s_ai.jpg', $base);
        $aiAbs = $targetDir . '/' . $aiFilename;

        try {
            $resizer->resizeToJpeg($originalAbs, $aiAbs);
        } catch (\Throwable $e) {
            $this->addFlash('warning', 'Redimensionnement impossible, utilisation de l’original : ' . $e->getMessage());
            $aiAbs = $originalAbs;
            $aiFilename = $originalFilename;
        }

        // Supprime l’original si on a un _ai
        if ($aiAbs !== $originalAbs) {
            @unlink($originalAbs);
        }

        $storedPath = 'var/uploads/recipe_scan/user_' . $user->getId() . '/' . $aiFilename;

        $request->getSession()->set('recipe_scan.last_upload', [
            'originalName' => $file->getClientOriginalName(),
            'mime' => $mime,
            'storedPath' => $storedPath,
            'uploadedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        // reset debug résultat précédent
        $request->getSession()->remove('recipe_scan.last_result');

        $this->addFlash('success', 'Photo reçue ✅ (optimisée pour l’analyse)');

        return $this->redirectToRoute('recipe_scan_index');
    }

    #[Route('/analyze', name: 'analyze', methods: ['POST'])]
    public function analyze(
        Request $request,
        RecipePhotoExtractionService $extractor,
        IngredientRepository $ingredientRepository,
        EntityManagerInterface $em,
        NameKeyNormalizer $nameKeyNormalizer,
        UnitStringMapper $unitMapper,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('recipe_scan_analyze', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('recipe_scan_index');
        }

        $last = $request->getSession()->get('recipe_scan.last_upload');
        if (!is_array($last) || empty($last['storedPath'])) {
            $this->addFlash('danger', 'Aucune photo à analyser.');
            return $this->redirectToRoute('recipe_scan_index');
        }

        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $absPath = $projectDir . '/' . ltrim((string) $last['storedPath'], '/');

        if (!is_file($absPath)) {
            $this->addFlash('danger', 'Fichier introuvable côté serveur (ré-essaie l’upload).');
            return $this->redirectToRoute('recipe_scan_index');
        }

        try {
            // 1) Extraction IA -> tableau structuré
            $data = $extractor->extractRecipeFromImage($absPath);

            // (debug optionnel)
            $request->getSession()->set('recipe_scan.last_result', $data);

            // 2) Persist Recipe draft + ingrédients + steps
            $recipe = $em->wrapInTransaction(function () use (
                $data,
                $user,
                $nameKeyNormalizer,
                $ingredientRepository,
                $em,
                $unitMapper
            ) {
                $recipe = new Recipe();
                $recipe->setUser($user);
                $recipe->setDraft(true);
                $recipe->setFavorite(false);

                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw new \RuntimeException('Nom de recette vide.');
                }

                $recipe->setName($name);
                $recipe->setNameKey($nameKeyNormalizer->toKey($name));

                $em->persist($recipe);

                // Ingrédients
                $ingredients = is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [];
                foreach ($ingredients as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rawName = trim((string) ($row['name'] ?? ''));
                    if ($rawName === '') {
                        continue;
                    }

                    $ingKey = $nameKeyNormalizer->toKey($rawName);

                    // On force “par utilisateur” (unique constraint user_id + name_key)
                    $ingredient = $ingredientRepository->findOneBy([
                        'user' => $user,
                        'nameKey' => $ingKey,
                    ]);

                    if (!$ingredient) {
                        $ingredient = new Ingredient();
                        $ingredient->setUser($user);
                        $ingredient->setName($this->cleanIngredientDisplayName($rawName));
                        $ingredient->setNameKey($ingKey);

                        // unité (enum) : map depuis string IA, fallback g
                        $unit = $unitMapper->map(isset($row['unit']) ? (string) $row['unit'] : null);
                        $ingredient->setUnit($unit);

                        $em->persist($ingredient);
                    }

                    $qty = $row['quantity'] ?? null;
                    $qtyFloat = null;
                    if ($qty !== null && $qty !== '') {
                        $qtyFloat = (float) $qty;
                    }

                    // Si pas de quantité (ex poivre) on met 0.00 (l’utilisateur corrigera en preview)
                    $qtyFloat = $qtyFloat ?? 0.0;

                    $ri = new RecipeIngredient();
                    $ri->setRecipe($recipe);
                    $ri->setIngredient($ingredient);
                    $ri->setQuantity(number_format($qtyFloat, 2, '.', ''));

                    $em->persist($ri);
                }

                // Étapes
                $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
                $pos = 1;
                foreach ($steps as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $content = trim((string) ($row['text'] ?? ''));
                    if ($content === '') {
                        continue;
                    }

                    $position = (int) ($row['position'] ?? 0);
                    if ($position <= 0) {
                        $position = $pos;
                    }

                    $step = new RecipeStep();
                    $step->setRecipe($recipe);
                    $step->setContent($content);
                    $step->setPosition($position);

                    $em->persist($step);
                    $pos++;
                }

                // Renumérote proprement 1..N si besoin (sécurité)
                // On ne refait pas une requête: on fait simple avec $pos ci-dessus.

                return $recipe;
            });

            // Nettoyage session photo (optionnel)
            $request->getSession()->remove('recipe_scan.last_upload');

            $this->addFlash('success', 'Recette importée en brouillon ✅');

            // 3) Redirect preview wizard existant
            return $this->redirectToRoute('recipe_wizard_preview', [
                'id' => $recipe->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Analyse / import impossible : ' . $e->getMessage());
            return $this->redirectToRoute('recipe_scan_index');
        }
    }

    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('recipe_scan_reset', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('recipe_scan_index');
        }

        $request->getSession()->remove('recipe_scan.last_upload');
        $request->getSession()->remove('recipe_scan.last_result');

        $this->addFlash('info', 'Photo et résultat temporaire supprimés.');

        return $this->redirectToRoute('recipe_scan_index');
    }

    private function cleanIngredientDisplayName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        // on laisse la casse “humaine” mais on évite un nom tout en uppercase
        return mb_strtolower($name);
    }
}
