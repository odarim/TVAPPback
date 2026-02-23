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
        private SluggerInterface $slugger
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
                if (empty($item['name']) || empty($item['nanoid'])) {
                    $stats['errors']++;
                    continue;
                }
 
                $channel = $this->channelRepository->findOneBy(['nanoid' => $item['nanoid']]);
                $isNew = false;
 
                if (!$channel) {
                    $channel = new Channel();
                    $channel->setNanoid($item['nanoid']);
                    $isNew = true;
                }
 
                $channel->setName($item['name']);
                $channel->setSlug($this->slugger->slug($item['name'])->lower());
                $channel->setLanguage($item['language'] ?? 'eng');
                $channel->setCountry($item['country'] ?? 'us');
                $channel->setIsGeoBlocked($item['isGeoBlocked'] ?? false);
                $channel->setCategory($forcedCategory ?? $defaultCategory);
                $channel->setLogo($item['logo'] ?? null);
 
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
