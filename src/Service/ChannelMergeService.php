<?php

namespace App\Service;

use App\Entity\Channel;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
class ChannelMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChannelRepository $channelRepository,
    ) {}

    /**
     * Merges channels with the exact same slug.
     * Keeps the first one found, moves all streams to it, and deletes the rest.
     * 
     * @return array{total_merged: int, total_deleted: int}
     */
    public function mergeDuplicates(): array
    {
        $channels = $this->channelRepository->findAll();
        
        $slugMap = [];
        $stats = ['total_merged' => 0, 'total_deleted' => 0];

        foreach ($channels as $channel) {
            $slug = $channel->getSlug();
            if (!$slug) {
                continue;
            }

            if (!isset($slugMap[$slug])) {
                $slugMap[$slug] = $channel;
            } else {
                // We found a duplicate
                $master = $slugMap[$slug];
                
                // Move streams from duplicate to master
                foreach ($channel->getStreams() as $stream) {
                    // Check if master already has this stream URL
                    $streamExists = false;
                    foreach ($master->getStreams() as $masterStream) {
                        if ($masterStream->getUrl() === $stream->getUrl()) {
                            $streamExists = true;
                            break;
                        }
                    }

                    if (!$streamExists) {
                        $stream->setLabel(CountryLanguageMapper::getStreamLabel($channel->getLanguage(), $channel->getCountry()));
                        $master->addStream($stream);
                        $stats['total_merged']++;
                    }
                }

                // Delete the duplicate channel
                $this->em->remove($channel);
                $stats['total_deleted']++;
            }
        }

        $this->em->flush();

        return $stats;
    }
}
