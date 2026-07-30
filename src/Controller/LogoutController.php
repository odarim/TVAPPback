<?php

namespace App\Controller;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class LogoutController extends AbstractController
{
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenValue = $data['refresh_token'] ?? null;

        if ($refreshTokenValue) {
            $repo = $em->getRepository(RefreshToken::class);
            $refreshToken = $repo->findOneBy(['refreshToken' => $refreshTokenValue]);
            if ($refreshToken) {
                $em->remove($refreshToken);
                $em->flush();
            }
        }

        return new JsonResponse(['message' => 'Logged out successfully']);
    }
}
