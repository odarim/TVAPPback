<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/account')]
class AccountController extends AbstractController
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    #[Route('/verify-password', methods: ['POST'])]
    public function verifyPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $password = $data['password'] ?? null;

        if (!is_string($password) || $password === '') {
            return new JsonResponse(['valid' => false], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Checks against the account's login password hash — completely
        // separate secret from the adult-content-lock password/hash.
        $valid = $this->passwordHasher->isPasswordValid($user, $password);

        return new JsonResponse(['valid' => $valid]);
    }
}