<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
    ) {
    }

    // ----------------------------
    // Register
    // ----------------------------

    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request, MailerInterface $mailer): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('assistant'); // adapte à ta route d'accueil
        }

        $user = new User();

        $form = $this->createFormBuilder($user)
            ->add('email')
            ->add('plainPassword', \Symfony\Component\Form\Extension\Core\Type\PasswordType::class, [
                'mapped' => false,
                'required' => true,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = (string) $form->get('plainPassword')->getData();

            $user->setIsVerified(false);
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            $this->em->persist($user);
            $this->em->flush();

            // Email confirm
            $this->sendVerificationEmail($user, $mailer);

            return $this->redirectToRoute('register_check_email', [
                'email' => $user->getEmail(),
            ]);
        }

        return $this->render('registration/register.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/register/check-email', name: 'register_check_email', methods: ['GET'])]
    public function checkEmail(Request $request): Response
    {
        $email = (string) $request->query->get('email', '');

        return $this->render('registration/check_email.html.twig', [
            'email' => $email,
        ]);
    }

    #[Route('/register/resend', name: 'register_resend', methods: ['POST'])]
    public function resendVerification(Request $request, MailerInterface $mailer): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('resend_verification', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // réponse neutre (anti-enum)
        $email = (string) $request->request->get('email', '');

        if ($email !== '') {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user instanceof User && !$user->isVerified()) {
                $this->sendVerificationEmail($user, $mailer);
            }
        }

        $this->addFlash('info', 'Si un compte existe pour cet email, un message de confirmation a été renvoyé.');

        return $this->redirectToRoute('register_check_email', [
            'email' => $email,
        ]);
    }

    #[Route('/verify/email', name: 'verify_email', methods: ['GET'])]
    public function verifyEmail(Request $request): RedirectResponse
    {
        if (!$this->uriSigner->checkRequest($request)) {
            $this->addFlash('danger', 'Lien de confirmation invalide.');
            return $this->redirectToRoute('register');
        }

        $userId = (int) $request->query->get('uid', 0);
        $expires = (int) $request->query->get('expires', 0);

        if ($userId <= 0 || $expires <= 0) {
            $this->addFlash('danger', 'Lien de confirmation incomplet.');
            return $this->redirectToRoute('register');
        }

        if ($expires < time()) {
            $this->addFlash('danger', 'Lien de confirmation expiré. Tu peux demander un nouvel email.');
            // si tu veux, tu peux aussi rediriger vers /register/check-email?email=...
            return $this->redirectToRoute('register_check_email');
        }

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            $this->addFlash('danger', 'Compte introuvable.');
            return $this->redirectToRoute('register');
        }

        if ($user->isVerified()) {
            return $this->redirectToRoute('register_confirmed');
        }

        $user->setIsVerified(true);
        $this->em->flush();

        return $this->redirectToRoute('register_confirmed');
    }

    #[Route('/register/confirmed', name: 'register_confirmed', methods: ['GET'])]
    public function confirmed(): Response
    {
        return $this->render('registration/confirmed.html.twig');
    }

    // ----------------------------
    // Login (page uniquement - si tu gardes un autre controller, tu peux supprimer cette action)
    // ----------------------------

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('assistant'); // adapte
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    // ----------------------------
    // Forgot password (demande)
    // ----------------------------

    #[Route('/forgot-password', name: 'forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request, MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST')) {
            $email = (string) $request->request->get('email', '');

            if ($email !== '') {
                /** @var User|null $user */
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

                if ($user instanceof User) {
                    $token = bin2hex(random_bytes(32)); // 64 chars, sécurisé

                    $now = new \DateTimeImmutable();
                    $expiresAt = $now->modify('+1 hour');

                    $user->setPasswordResetToken($token);
                    $user->setPasswordResetRequestedAt($now);
                    $user->setPasswordResetExpiresAt($expiresAt);

                    $this->em->flush();

                    $this->sendPasswordResetEmail($user, $token, $mailer);
                }
            }

            $this->addFlash('info', 'Si un compte existe pour cet email, un lien de réinitialisation a été envoyé.');
            return $this->redirectToRoute('forgot_password');
        }

        return $this->render('security/forgot_password.html.twig');
    }

    // ----------------------------
    // Reset password (via token)
    // ----------------------------

    #[Route('/reset-password', name: 'reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');
        if ($token === '') {
            $this->addFlash('danger', 'Lien de réinitialisation invalide.');
            return $this->redirectToRoute('forgot_password');
        }

        /** @var User|null $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['passwordResetToken' => $token]);
        if (!$user instanceof User || !$user->isPasswordResetTokenValid($token)) {
            $this->addFlash('danger', 'Lien de réinitialisation invalide ou expiré.');
            return $this->redirectToRoute('forgot_password');
        }

        if ($request->isMethod('POST')) {
            $plainPassword = (string) $request->request->get('plainPassword', '');
            if (mb_strlen($plainPassword) < 8) {
                $this->addFlash('danger', 'Le mot de passe doit contenir au moins 8 caractères.');
                return $this->render('security/reset_password.html.twig', [
                    'token' => $token,
                ]);
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            $user->clearPasswordReset();
            $this->em->flush();

            return $this->redirectToRoute('app_login', ['reset' => 1]);
        }

        return $this->render('security/reset_password.html.twig', [
            'token' => $token,
        ]);
    }

    // ----------------------------
    // Change password (connecté)
    // ----------------------------

    #[Route('/account/password', name: 'account_change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $requiresOldPassword = $user->hasPassword();

        if ($request->isMethod('POST')) {
            $oldPassword = (string) $request->request->get('oldPassword', '');
            $newPassword = (string) $request->request->get('newPassword', '');

            if (mb_strlen($newPassword) < 8) {
                $this->addFlash('danger', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
                return $this->redirectToRoute('account_change_password');
            }

            if ($requiresOldPassword) {
                if (!$this->passwordHasher->isPasswordValid($user, $oldPassword)) {
                    $this->addFlash('danger', 'Ancien mot de passe incorrect.');
                    return $this->redirectToRoute('account_change_password');
                }
            }

            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', 'Mot de passe mis à jour.');
            return $this->redirectToRoute('account_change_password');
        }

        return $this->render('security/change_password.html.twig', [
            'requiresOldPassword' => $requiresOldPassword,
        ]);
    }

    // ----------------------------
    // Emails helpers
    // ----------------------------

    private function sendVerificationEmail(User $user, MailerInterface $mailer): void
    {
        $expires = time() + 60 * 60 * 24; // 24h
        $url = $this->urlGenerator->generate('verify_email', [
            'uid' => $user->getId(),
            'expires' => $expires,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $signedUrl = $this->uriSigner->sign($url);

        $email = (new TemplatedEmail())
            ->to((string) $user->getEmail())
            ->subject('Confirme ton compte Receiplan')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->context([
                'signedUrl' => $signedUrl,
                'expiresAt' => (new \DateTimeImmutable())->setTimestamp($expires),
                'user' => $user,
            ]);

        $mailer->send($email);
    }

    private function sendPasswordResetEmail(User $user, string $token, MailerInterface $mailer): void
    {
        $url = $this->urlGenerator->generate('reset_password', [
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->to((string) $user->getEmail())
            ->subject('Réinitialisation de ton mot de passe Receiplan')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context([
                'resetUrl' => $url,
                'user' => $user,
                'expiresAt' => $user->getPasswordResetExpiresAt(),
            ]);

        $mailer->send($email);
    }
}
