<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WatchHistory;
use App\Repository\WatchHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/history')]
final class WatchHistoryController extends AbstractController
{
    public function __construct(
        private readonly WatchHistoryRepository $historyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'get_watch_history', methods: ['GET'])]
    public function getHistory(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $records = $this->historyRepository->findBy(['user' => $user]);

        $data = array_map(function (WatchHistory $h) {
            return [
                'tmdb_id'    => $h->getTmdbId(),
                'imdb_id'    => $h->getImdbId(),
                'title_name' => $h->getTitleName(),
                'poster_url' => $h->getPosterUrl(),
                'type'       => $h->getType(),
                'season'     => $h->getSeason(),
                'episode'    => $h->getEpisode(),
                'updated_at' => $h->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $records);

        return $this->json(['history' => $data]);
    }

    #[Route('/mark', name: 'mark_watch_history', methods: ['POST'])]
    public function markHistory(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $content = json_decode($request->getContent(), true);
        if (!is_array($content)) {
            return $this->json(['error' => 'Invalid JSON payload'], 400);
        }

        $tmdbId = $content['tmdb_id'] ?? null;
        $type   = $content['type'] ?? null;
        
        if ($tmdbId === null || !in_array($type, ['movie', 'series'], true)) {
            return $this->json(['error' => 'Missing tmdb_id or invalid type'], 400);
        }

        $season  = null;
        $episode = null;

        if ($type === 'series') {
            $season  = $content['season'] ?? null;
            $episode = $content['episode'] ?? null;
            if ($season === null || $episode === null) {
                return $this->json(['error' => 'Missing season or episode for series'], 400);
            }
        }

        $imdbId    = $content['imdb_id'] ?? null;
        $titleName = $content['title_name'] ?? null;
        $posterUrl = $content['poster_url'] ?? null;

        // Check if history record already exists
        $record = $this->historyRepository->findOneBy([
            'user'    => $user,
            'tmdbId'  => $tmdbId,
            'type'    => $type,
            'season'  => $season,
            'episode' => $episode,
        ]);

        if (!$record) {
            $record = new WatchHistory();
            $record->setUser($user);
            $record->setTmdbId((int) $tmdbId);
            $record->setType($type);
            $record->setSeason($season !== null ? (int) $season : null);
            $record->setEpisode($episode !== null ? (int) $episode : null);
        }

        // Always update enrichment fields
        if ($imdbId)    $record->setImdbId($imdbId);
        if ($titleName) $record->setTitleName($titleName);
        if ($posterUrl) $record->setPosterUrl($posterUrl);
        $record->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $this->json(['status' => 'success']);
    }
}
