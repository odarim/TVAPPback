<?php

namespace App\Serializer;

use App\Entity\Channel;
use App\Service\ChannelViewerService;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Decorates the item/collection normalizer to inject a live "watching now"
 * viewer count (computed), while keeping the cumulative viewCount untouched.
 */
class ChannelNormalizer implements NormalizerInterface
{
    public function __construct(
        private NormalizerInterface $decorated,
        private ChannelViewerService $channelViewerService,
    ) {
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        $isChannel = $data instanceof Channel;
        $hasGroup = ($context['groups'] ?? null) && in_array('channel:read', $context['groups'], true);
        if ($isChannel) {
            error_log(sprintf('ChannelNormalizer::supportsNormalization channel=%s hasGroup=%s groups=%s', $isChannel ? 'yes' : 'no', $hasGroup ? 'yes' : 'no', json_encode($context['groups'] ?? null)));
        }
        return $isChannel && $hasGroup;
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|null
    {
        /** @var Channel $object */
        $data = $this->decorated->normalize($object, $format, $context);

        if (is_array($data)) {
            $data['liveViewers'] = $this->channelViewerService->countForChannel($object);
        }

        return $data;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Channel::class => true];
    }
}