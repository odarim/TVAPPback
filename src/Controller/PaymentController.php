<?php

namespace App\Controller;

use App\Entity\Package;
use App\Entity\PendingPayment;
use App\Entity\Subscription;
use App\Repository\PendingPaymentRepository;
use App\Service\PapiPaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/api/payment')]
class PaymentController extends AbstractController
{
    /**
     * Step 1: User selects a package.
     * Creates a PendingPayment record, calls Papi, and returns the payment redirect URL.
     */
    #[Route('/checkout', name: 'api_payment_checkout', methods: ['POST'])]
    public function checkout(
        Request $request,
        EntityManagerInterface $em,
        PapiPaymentService $papi,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (empty($data['packageId'])) {
            return new JsonResponse(['message' => 'Missing required field: packageId'], 400);
        }

        $package = $em->getRepository(Package::class)->find($data['packageId']);

        if (!$package) {
            return new JsonResponse(['message' => 'Package not found'], 404);
        }

        if (!$package->isIsActive()) {
            return new JsonResponse(['message' => 'Package is not currently available'], 400);
        }

        // Build a unique reference that identifies this payment attempt
        $reference = 'TV-' . strtoupper(bin2hex(random_bytes(5))) . '-' . substr((string) $user->getId(), 0, 8);

        // Persist a pending payment so we can match the webhook later
        $pending = new PendingPayment();
        $pending->setUser($user);
        $pending->setPackage($package);
        $pending->setReference($reference);

        $em->persist($pending);
        $em->flush();

        // Ask Papi to create a hosted payment session
        try {
            $frontendUrl = $request->headers->get('Origin', 'http://localhost:5173');
            $notificationUrl = $urlGenerator->generate('api_payment_notification', [], UrlGeneratorInterface::ABSOLUTE_URL);

            $papiData = $papi->createPaymentLink(
                amount: (float) $package->getPrice(),
                clientName: $user->getEmail(),
                reference: $reference,
                description: 'Abonnement ' . $package->getName(),
                successUrl: $frontendUrl . '/payment/success?ref=' . $reference,
                failureUrl: $frontendUrl . '/payment/failure?ref=' . $reference,
                notificationUrl: $notificationUrl
            );
        } catch (\RuntimeException $e) {
            // Roll back the pending record if Papi is unreachable
            $em->remove($pending);
            $em->flush();

            return new JsonResponse(['message' => 'Payment gateway error: ' . $e->getMessage()], 502);
        }

        return new JsonResponse([
            'paymentLink' => $papiData['paymentLink'],
            'reference'   => $reference,
        ]);
    }

    /**
     * Step 2 (server-to-server): Papi calls this webhook after a successful payment.
     * This is the ONLY authoritative place where subscriptions are activated.
     * This route is PUBLIC (no JWT required) — see security.yaml.
     */
    #[Route('/notification', name: 'api_payment_notification', methods: ['POST'])]
    public function notification(
        Request $request,
        EntityManagerInterface $em,
        PendingPaymentRepository $repo
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $reference = $data['reference'] ?? null;
        $status    = $data['status']    ?? null;

        // Only process confirmed successful payments
        if (!$reference || strtolower((string) $status) !== 'success') {
            return new JsonResponse(['ok' => false, 'msg' => 'ignored'], 200);
        }

        $pending = $repo->findOneBy(['reference' => $reference]);

        if (!$pending) {
            return new JsonResponse(['ok' => false, 'msg' => 'reference not found'], 200);
        }

        if ($pending->isProcessed()) {
            // Idempotent — Papi may send the webhook more than once
            return new JsonResponse(['ok' => true, 'msg' => 'already processed']);
        }

        // Activate the subscription
        $subscription = new Subscription();
        $subscription->setUser($pending->getUser());
        $subscription->setPackage($pending->getPackage());
        $subscription->setStartDate(new \DateTime());
        $subscription->setEndDate((new \DateTime())->modify('+30 days'));
        $subscription->setIsActive(true);

        $pending->setProcessed(true);

        $em->persist($subscription);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
