<?php

namespace App\Entity;

use App\Repository\ActiveSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ActiveSessionRepository::class)]
#[ORM\Table(name: 'active_session')]
class ActiveSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'activeSessions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $token = null;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

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

    /**
     * Returns true if the session has not received a heartbeat within $ttlSeconds.
     */
    public function isExpired(int $ttlSeconds = 120): bool
    {
        if ($this->lastHeartbeatAt === null) {
            return true;
        }

        $expiresAt = (clone $this->lastHeartbeatAt)->modify("+{$ttlSeconds} seconds");

        return $expiresAt < new \DateTime();
    }
}
