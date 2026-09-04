<?php

namespace App\Serializer;

use App\Entity\Channel;
use App\Service\ChannelViewerService;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Decorates API Platform's plain-JSON item normalizer (`api_platform.serializer.normalizer.item`)
 * to add the computed live "watching now" count (`liveViewers`) to Channel payloads, while
 * keeping the cumulative viewCount untouched.
 *
 * The decorator is transparent: support checks, normalization and denormalization are forwarded
 * to the decorated normalizer for every resource, and only Channel output is amended. The
 * serializer injection is forwarded too — without it the wrapped normalizer cannot normalize
 * relations ("The injected serializer must be an instance of NormalizerInterface").
 */
final class ChannelNormalizer implements NormalizerInterface, DenormalizerInterface, SerializerAwareInterface
{
    public function __construct(
        private readonly NormalizerInterface $decorated,
        private readonly ChannelViewerService $channelViewerService,
    ) {
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->decorated->normalize($object, $format, $context);

        if ($object instanceof Channel && \is_array($data) && \in_array('channel:read', (array) ($context['groups'] ?? []), true)) {
            $data['liveViewers'] = $this->channelViewerService->countForChannel($object);
        }

        return $data;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->decorated instanceof DenormalizerInterface
            && $this->decorated->supportsDenormalization($data, $type, $format, $context);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (!$this->decorated instanceof DenormalizerInterface) {
            throw new \LogicException(sprintf('The decorated normalizer "%s" cannot denormalize.', $this->decorated::class));
        }

        return $this->decorated->denormalize($data, $type, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->decorated->getSupportedTypes($format);
    }

    public function setSerializer(SerializerInterface $serializer): void
    {
        if ($this->decorated instanceof SerializerAwareInterface) {
            $this->decorated->setSerializer($serializer);
        }
    }
}
