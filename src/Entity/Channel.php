<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use App\Repository\ChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use App\Filter\GlobalSearchFilter;

#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['channel:read']],
    denormalizationContext: ['groups' => ['channel:write']],
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Delete(),
        new Patch(
            inputFormats: ['json' => ['application/merge-patch+json', 'application/json']],
        ),
    ]
)]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt', 'isActive', 'category.name', 'viewCount', 'isWorking', 'language'])]
#[ApiFilter(GlobalSearchFilter::class)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'category.name' => 'partial', 'language' => 'exact', 'category' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive', 'isGeoBlocked', 'isWorking'])]
class Channel
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['channel:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $nanoid = null;

    #[ORM\Column(length: 255)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $slug = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $language = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $country = null;

    #[ORM\Column]
    #[Groups(['channel:read', 'channel:write'])]
    private ?bool $isGeoBlocked = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $logo = null;

    #[ORM\ManyToOne(inversedBy: 'channels')]
    #[Groups(['channel:read', 'channel:write'])]
    private ?Category $category = null;

    #[ORM\Column]
    #[Groups(['channel:read', 'channel:write'])]
    private ?bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['channel:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\OneToMany(mappedBy: 'channel', targetEntity: ChannelStream::class, orphanRemoval: true, cascade: ['persist'])]
    #[Groups(['channel:read', 'channel:write'])]
    private Collection $streams;

    #[ORM\ManyToMany(targetEntity: Package::class, mappedBy: 'channels')]
    #[Groups(['channel:read', 'channel:write'])]
    private Collection $packages;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['channel:read', 'channel:write'])]
    private ?bool $isWorking = true;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['channel:read', 'channel:write'])]
    private ?int $viewCount = 0;

    public function __construct()
    {
        $this->streams = new ArrayCollection();
        $this->packages = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->viewCount = 0;
        $this->isWorking = true;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getNanoid(): ?string
    {
        return $this->nanoid;
    }

    public function setNanoid(?string $nanoid): static
    {
        $this->nanoid = $nanoid;

        return $this;
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function isIsGeoBlocked(): ?bool
    {
        return $this->isGeoBlocked;
    }

    public function setIsGeoBlocked(bool $isGeoBlocked): static
    {
        $this->isGeoBlocked = $isGeoBlocked;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, ChannelStream>
     */
    public function getStreams(): Collection
    {
        return $this->streams;
    }

    public function addStream(ChannelStream $stream): static
    {
        if (!$this->streams->contains($stream)) {
            $this->streams->add($stream);
            $stream->setChannel($this);
        }

        return $this;
    }

    public function removeStream(ChannelStream $stream): static
    {
        if ($this->streams->removeElement($stream)) {
            // set the owning side to null (unless already changed)
            if ($stream->getChannel() === $this) {
                $stream->setChannel(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Package>
     */
    public function getPackages(): Collection
    {
        return $this->packages;
    }

    public function addPackage(Package $package): static
    {
        if (!$this->packages->contains($package)) {
            $this->packages->add($package);
            $package->addChannel($this);
        }

        return $this;
    }

    public function removePackage(Package $package): static
    {
        if ($this->packages->removeElement($package)) {
            $package->removeChannel($this);
        }

        return $this;
    }

    public function getViewCount(): ?int
    {
        return $this->viewCount;
    }

    public function setViewCount(int $viewCount): static
    {
        $this->viewCount = $viewCount;

        return $this;
    }

    public function isIsWorking(): ?bool
    {
        return $this->isWorking;
    }

    public function setIsWorking(bool $isWorking): static
    {
        $this->isWorking = $isWorking;

        return $this;
    }
}
