<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ChannelHealthController extends AbstractController
{
    #[Route('/api/check-health', name: 'api_check_health', methods: ['POST'])]
    public function checkHealth(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $urls = $data['urls'] ?? [];

        if (!is_array($urls) || empty($urls)) {
            return new JsonResponse(['error' => 'No URLs provided in the request body.'], 400);
        }

        $results = [];

        foreach ($urls as $url) {
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, // HEAD request is faster, doesn't download the video stream
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            $status = 'unknown';

            if ($error) {
                $status = 'error';
            } elseif ($httpCode >= 200 && $httpCode < 400) {
                $status = 'working';
            } elseif ($httpCode === 403) {
                $status = 'blocked'; // Geo-blocked or token restricted
            } elseif ($httpCode === 404) {
                $status = 'dead';
            }

            $results[] = [
                'url' => $url,
                'status' => $status,
                'http_code' => $httpCode,
                'error_msg' => $error ?: null
            ];
        }

        return new JsonResponse($results, 200);
    }
}
