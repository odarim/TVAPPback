<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PapiPaymentService
{
    private const PAPI_BASE_URL = 'https://app.papi.mg/dashboard/api';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $papiApiKey,        // Inject from .env: PAPI_API_KEY
    ) {}

    /**
     * Creates a Papi payment link and returns the redirect URL.
     *
     * @return array{paymentLink: string, paymentReference: string, linkExpirationDateTime: int}
     * @throws \RuntimeException on API failure
     */
    public function createPaymentLink(
        float $amount,
        string $clientName,
        string $reference,
        string $description,
        string $successUrl,
        string $failureUrl,
        string $notificationUrl,
        int $validDurationHours = 2,
    ): array {
        $response = $this->httpClient->request('POST', self::PAPI_BASE_URL . '/payment-links', [
            'headers' => [
                'Token' => $this->papiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'amount'          => $amount,
                'validDuration'   => $validDurationHours,
                'clientName'      => $clientName,
                'reference'       => $reference,
                'description'     => $description,
                'successUrl'      => $successUrl,
                'failureUrl'      => $failureUrl,
                'notificationUrl' => $notificationUrl,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Papi API error: ' . $response->getContent(false));
        }

        $result = $response->toArray();
        return $result['data'] ?? $result;
    }
}
