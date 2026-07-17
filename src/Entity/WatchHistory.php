<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WatchHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WatchHistoryRepository::class)]
#[ORM\Table(name: 'watch_history')]
#[ORM\UniqueConstraint(name: 'user_tmdb_season_episode_unique', columns: ['user_id', 'tmdb_id', 'type', 'season', 'episode'])]
class WatchHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column]
    private ?int $tmdbId = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\Column(nullable: true)]
    private ?int $season = null;

    #[ORM\Column(nullable: true)]
    private ?int $episode = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $imdbId = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $titleName = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $posterUrl = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
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

    public function getTmdbId(): ?int
    {
        return $this->tmdbId;
    }

    public function setTmdbId(int $tmdbId): static
    {
        $this->tmdbId = $tmdbId;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSeason(): ?int
    {
        return $this->season;
    }

    public function setSeason(?int $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getEpisode(): ?int
    {
        return $this->episode;
    }

    public function setEpisode(?int $episode): static
    {
        $this->episode = $episode;

        return $this;
    }

    public function getImdbId(): ?string { return $this->imdbId; }
    public function setImdbId(?string $imdbId): static { $this->imdbId = $imdbId; return $this; }

    public function getTitleName(): ?string { return $this->titleName; }
    public function setTitleName(?string $titleName): static { $this->titleName = $titleName; return $this; }

    public function getPosterUrl(): ?string { return $this->posterUrl; }
    public function setPosterUrl(?string $posterUrl): static { $this->posterUrl = $posterUrl; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
