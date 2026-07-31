<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Channel;
use App\Entity\ChannelStream;
use App\Repository\CategoryRepository;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class ChannelImportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChannelRepository $channelRepository,
        private CategoryRepository $categoryRepository,
        private SluggerInterface $slugger,
        private ChannelLogoService $channelLogoService
    ) {
    }

    public function import(array $data, ?Category $forcedCategory = null): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];
 
        // Ensure default category exists
        $defaultCategory = $this->categoryRepository->findOneBy(['slug' => 'uncategorized']);
        if (!$defaultCategory && !$forcedCategory) {
            $defaultCategory = new Category();
            $defaultCategory->setName('Uncategorized');
            $defaultCategory->setSlug('uncategorized');
            $this->em->persist($defaultCategory);
            $this->em->flush();
        }
 
        foreach ($data as $item) {
            try {
                $nanoid = $item['nanoid'] ?? null;
                if (empty($nanoid) || empty($item['name'])) {
                    $stats['errors']++;
                    continue;
                }

                $baseSlug = $this->slugger->slug($item['name'])->lower();

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
                    $channel->setSlug($baseSlug . '-' . substr($nanoid, -8));
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }

                $channel->setName($item['name']);
                $channel->setLanguage($item['language'] ?? 'eng');
                $channel->setCountry($item['country'] ?? 'us');
                $channel->setIsGeoBlocked($item['isGeoBlocked'] ?? false);
                $channel->setCategory($forcedCategory ?? $defaultCategory);

                // Auto-resolve a logo when none is provided in the payload
                $logo = $item['logo'] ?? null;
                if (!$logo) {
                    $logo = $this->channelLogoService->resolve($item['name'], $item['country'] ?? null);
                }
                $channel->setLogo($logo);
 
                // Handle streams
                foreach ($channel->getStreams() as $stream) {
                    $this->em->remove($stream);
                }
                
                // 1. Array-based IPTV URLs
                if (!empty($item['iptv_urls'])) {
                    foreach ($item['iptv_urls'] as $url) {
                        $stream = new ChannelStream();
                        $stream->setType('IPTV');
                        $stream->setUrl($url);
                        $channel->addStream($stream);
                        $this->em->persist($stream);
                    }
                }

                // 2. Generic single URL support (fallback)
                $singleUrl = $item['url'] ?? $item['stream'] ?? null;
                if ($singleUrl && empty($item['iptv_urls'])) {
                    $stream = new ChannelStream();
                    $stream->setType('IPTV');
                    $stream->setUrl($singleUrl);
                    $channel->addStream($stream);
                    $this->em->persist($stream);
                }
 
                if (!empty($item['youtube_urls'])) {
                    foreach ($item['youtube_urls'] as $url) {
                        $stream = new ChannelStream();
                        $stream->setType('YouTube');
                        $stream->setUrl($url);
                        $channel->addStream($stream);
                        $this->em->persist($stream);
                    }
                }
 
                $this->em->persist($channel);
                
                if ($isNew) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
 
            } catch (\Exception $e) {
                $stats['errors']++;
            }
        }
 
        $this->em->flush();
 
        return $stats;
    }
}
