<?php

namespace App\Entity;

use App\Repository\ChannelViewerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row = one device/user actively watching a channel "right now".
 * Rows are heartbeated by the player and expire automatically after a TTL,
 * so counting rows per channel == live "watching now" viewers.
 */
#[ORM\Entity(repositoryClass: ChannelViewerRepository::class)]
#[ORM\Table(name: 'channel_viewer')]
#[ORM\Index(columns: ['channel_id', 'last_heartbeat_at'])]
class ChannelViewer
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'viewers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Channel $channel = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $deviceId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $lastHeartbeatAt = null;

    public function __construct()
    {
        $this->startedAt = new \DateTime();
        $this->lastHeartbeatAt = new \DateTime();
        $this->token = bin2hex(random_bytes(32));
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getChannel(): ?Channel
    {
        return $this->channel;
    }

    public function setChannel(?Channel $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function setDeviceId(?string $deviceId): static
    {
        $this->deviceId = $deviceId;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getLastHeartbeatAt(): ?\DateTimeInterface
    {
        return $this->lastHeartbeatAt;
    }

    public function setLastHeartbeatAt(\DateTimeInterface $lastHeartbeatAt): static
    {
        $this->lastHeartbeatAt = $lastHeartbeatAt;
        return $this;
    }
}