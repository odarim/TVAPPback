<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\PackageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['package:read']],
    denormalizationContext: ['groups' => ['package:write']],
)]
class Package
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['package:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['package:read', 'package:write'])]
    private ?string $name = null;

    #[ORM\Column(length: 1024, nullable: true)]
    #[Groups(['package:read', 'package:write'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['package:read', 'package:write'])]
    private ?float $price = null;

    #[ORM\Column]
    #[Groups(['package:read', 'package:write'])]
    private ?int $maxDevices = 1;

    #[ORM\Column]
    #[Groups(['package:read', 'package:write'])]
    private ?bool $isActive = true;

    #[ORM\ManyToMany(targetEntity: Channel::class, inversedBy: 'packages')]
    private Collection $channels;

    #[ORM\OneToMany(mappedBy: 'package', targetEntity: Subscription::class)]
    private Collection $subscriptions;

    public function __construct()
    {
        $this->channels = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getMaxDevices(): ?int
    {
        return $this->maxDevices;
    }

    public function setMaxDevices(int $maxDevices): static
    {
        $this->maxDevices = $maxDevices;

        return $this;
    }

    public function isIsActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * @return Collection<int, Channel>
     */
    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(Channel $channel): static
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
        }

        return $this;
    }

    public function removeChannel(Channel $channel): static
    {
        $this->channels->removeElement($channel);

        return $this;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function addSubscription(Subscription $subscription): static
    {
        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
            $subscription->setPackage($this);
        }

        return $this;
    }

    public function removeSubscription(Subscription $subscription): static
    {
        if ($this->subscriptions->removeElement($subscription)) {
            // set the owning side to null (unless already changed)
            if ($subscription->getPackage() === $this) {
                $subscription->setPackage(null);
            }
        }

        return $this;
    }
}
