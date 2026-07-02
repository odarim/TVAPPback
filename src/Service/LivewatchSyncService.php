<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Channel;
use App\Entity\ChannelStream;
use App\Repository\CategoryRepository;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches all channel data from the livewatch.top public API and upserts it
 * into the local database. Called both by the CLI command and the admin HTTP endpoint.
 */
class LivewatchSyncService
{
    private const API_URL = 'https://livewatch.top/api/v1/public/all';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly CategoryRepository $categoryRepository,
        private readonly ChannelRepository $channelRepository,
    ) {}

    /**
     * Complete sync for CLI commands.
     */
    public function sync(): array
    {
        $data = $this->fetchRawData();
        return $this->processChunk($data['categories'] ?? [], $data['channels'] ?? []);
    }

    /**
     * Just fetches the raw API data and returns it so the frontend can chunk it.
     */
    public function fetchRawData(): array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $response->toArray();
    }

    /**
     * Processes a specific chunk of channels and categories.
     * @return array{categories_synced: int, created: int, updated: int, skipped: int, streams_added: int}
     */
    public function processChunk(array $apiCats, array $channels): array
    {
        // ── 1. Upsert categories with fuzzy matching ─────────────────────────
        $categoryMap = []; // name → Category entity
        $allExisting = $this->categoryRepository->findAll();

        foreach ($apiCats as $catName) {
            $catName = trim($catName);
            if ($catName === '') {
                continue;
            }

            $slug = $this->slugify($catName);
            $bestMatch = null;
            $bestPercent = 0;

            foreach ($allExisting as $existing) {
                // Exact slug match
                if ($existing->getSlug() === $slug) {
                    $bestMatch = $existing;
                    $bestPercent = 100;
                    break;
                }

                // Fuzzy match (e.g. 'sport' vs 'sports', 'info' vs 'infos')
                similar_text($slug, $existing->getSlug(), $percent);
                if ($percent > $bestPercent) {
                    $bestPercent = $percent;
                    $bestMatch = $existing;
                }
            }

            if ($bestMatch && $bestPercent >= 80) {
                $categoryMap[$catName] = $bestMatch;
            } else {
                $cat = new Category();
                $cat->setName($catName);
                $cat->setSlug($slug);
                $this->em->persist($cat);
                $categoryMap[$catName] = $cat;
                $allExisting[] = $cat; // Add to pool for subsequent matches
            }
        }

        $this->em->flush(); // flush new categories so they get IDs

        // ── 2. Upsert channels ────────────────────────────────────────────────
        $stats = [
            'categories_synced'=> count($categoryMap),
            'created'          => 0,
            'updated'          => 0,
            'skipped'          => 0,
            'streams_added'    => 0,
        ];

        $batchCount = 0;

        foreach ($channels as $item) {
            $nanoid   = $item['id']         ?? null;
            $name     = $item['name']       ?? null;
            $source   = $item['source']     ?? 'unknown';
            $country  = $item['country']    ?? null;
            $catNames = $item['categories'] ?? [];
            $embedUrl = $item['embed_url']  ?? null;

            if (!$nanoid || !$name) {
                $stats['skipped']++;
                continue;
            }

            $slug = $this->slugify($name) . '-' . substr($nanoid, -8); // Use full slug with nanoid suffix for URL
            $baseSlug = $this->slugify($name);

            // Group by name slug instead of nanoid to merge across different providers
            $channel = $this->channelRepository->createQueryBuilder('c')
                ->where('c.slug LIKE :slugPrefix')
                ->setParameter('slugPrefix', $baseSlug . '%')
                ->getQuery()
                ->setMaxResults(1)
                ->getOneOrNullResult();

            $isNew = ($channel === null);

            if ($isNew) {
                $channel = new Channel();
                $channel->setNanoid($nanoid);
                $channel->setSlug($slug); // set initial slug
                $stats['created']++;
            } else {
                $stats['updated']++;
            }

            $channel->setName($name);
            $channel->setCountry($country);
            $channel->setIsActive(true);
            $channel->setIsWorking(true);

            // Assign first matching category
            $assignedCat = null;
            foreach ($catNames as $cn) {
                $cn = trim($cn);
                if (isset($categoryMap[$cn])) {
                    $assignedCat = $categoryMap[$cn];
                    break;
                }
            }
            $channel->setCategory($assignedCat);

            // Add stream only if the embed_url is not already present
            if ($embedUrl) {
                $streamExists = false;
                foreach ($channel->getStreams() as $existing) {
                    if ($existing->getUrl() === $embedUrl) {
                        $existing->setType($source); // update source type if changed
                        $streamExists = true;
                        break;
                    }
                }
                if (!$streamExists) {
                    $stream = new ChannelStream();
                    $stream->setType($source);
                    $stream->setUrl($embedUrl);
                    $channel->addStream($stream);
                    $stats['streams_added']++;
                }
            }

            $this->em->persist($channel);

            // Batch flush every 50 records
            if (++$batchCount % 50 === 0) {
                $this->em->flush();
                // Clear the Entity Manager to prevent memory leaks and O(N^2) slowdown
                $this->em->clear();
                // We MUST reload the categories after clearing the EM, otherwise they become detached
                foreach ($categoryMap as $k => $cat) {
                    if ($cat->getId()) {
                        $categoryMap[$k] = $this->em->getReference(Category::class, $cat->getId());
                    }
                }
            }
        }

        $this->em->flush();

        return $stats;
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
}
