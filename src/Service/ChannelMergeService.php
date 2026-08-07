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
     * Merges channels that share the same normalized name.
     * Imported slugs carry a unique nanoid suffix (e.g. "france-2-5ccf7f3d"),
     * so matching on the slug would never find duplicates across sync runs.
     * Keeps the first channel found, moves all unique streams to it, and deletes the rest.
     * 
     * @return array{total_merged: int, total_deleted: int}
     */
    public function mergeDuplicates(): array
    {
        $channels = $this->channelRepository->findAll();
        
        $nameMap = [];
        $stats = ['total_merged' => 0, 'total_deleted' => 0];

        foreach ($channels as $channel) {
            $name = mb_strtolower(trim($channel->getName() ?? ''));
            if ($name === '') {
                continue;
            }

            if (!isset($nameMap[$name])) {
                $nameMap[$name] = $channel;
            } else {
                // We found a duplicate
                $master = $nameMap[$name];
                
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
                        if (!$stream->getLabel()) {
                            $stream->setLabel(CountryLanguageMapper::getStreamLabel($channel->getLanguage(), $channel->getCountry()));
                        }
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
