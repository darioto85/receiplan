<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($client, $accessToken): UserInterface {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $googleId = (string) $googleUser->getId();
                $email = (string) $googleUser->getEmail();

                // 1) si googleId existe déjà => login
                $user = $this->em->getRepository(User::class)->findOneBy(['googleId' => $googleId]);
                if ($user instanceof User) {
                    return $user;
                }

                // 2) sinon, si email existe => on attache googleId
                $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($user instanceof User) {
                    $user->setGoogleId($googleId);
                    // Option B: on peut considérer verified=true (Google fournit email vérifié dans certains cas)
                    $user->setIsVerified(true);
                    $this->em->flush();
                    return $user;
                }

                // 3) sinon => création user OAuth-only
                $user = new User();
                $user->setEmail($email);
                $user->setGoogleId($googleId);
                $user->setPassword(null); // OAuth-only
                $user->setIsVerified(true); // en pratique OK pour Google
                $this->em->persist($user);
                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?RedirectResponse
    {
        // redirige vers ton app
        return new RedirectResponse($this->urlGenerator->generate('meal_plan'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?RedirectResponse
    {
        // Option: flash + redirect login
        // $this->addFlash('danger', 'Connexion Google impossible.');
        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
