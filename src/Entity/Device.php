<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\DeviceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['device:read']],
    denormalizationContext: ['groups' => ['device:write']],
)]
class Device
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['device:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'devices')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['device:read', 'device:write'])]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    #[Groups(['device:read', 'device:write'])]
    private ?string $deviceId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['device:read', 'device:write'])]
    private ?string $deviceName = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['device:read'])]
    private ?\DateTimeInterface $lastActiveAt = null;

    public function __construct()
    {
        $this->lastActiveAt = new \DateTime();
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

    public function getDeviceId(): ?string
    {
        return $this->deviceId;
    }

    public function setDeviceId(string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function setDeviceName(?string $deviceName): static
    {
        $this->deviceName = $deviceName;

        return $this;
    }

    public function getLastActiveAt(): ?\DateTimeInterface
    {
        return $this->lastActiveAt;
    }

    public function setLastActiveAt(\DateTimeInterface $lastActiveAt): static
    {
        $this->lastActiveAt = $lastActiveAt;

        return $this;
    }
}
