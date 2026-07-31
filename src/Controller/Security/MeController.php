<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MeController extends AbstractController
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
    ) {
    }

    #[Route('/api/me', name: 'app_me', methods: ['GET'])]
    public function index(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        // Find the user's active subscription (if any) to expose package access.
        $subscription = $this->subscriptionRepository->createQueryBuilder('s')
            ->innerJoin('s.package', 'p')
            ->where('s.user = :user')
            ->andWhere('s.isActive = true')
            ->andWhere('s.endDate IS NULL OR s.endDate > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $package = $subscription?->getPackage();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'fullName' => $user->getFullName(),
            'hasVodAccess' => in_array('ROLE_ADMIN', $user->getRoles(), true) || ($package ? (bool) $package->isHasVodAccess() : false),
            'activePackage' => $package ? [
                'id' => $package->getId(),
                'name' => $package->getName(),
                'maxDevices' => $package->getMaxDevices(),
                'maxConnections' => $package->getMaxConnections(),
            ] : null,
        ]);
    }
}
