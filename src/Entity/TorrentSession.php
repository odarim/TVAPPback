<?php

namespace App\Entity;

use App\Repository\TorrentSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * TorrentSession
 *
 * Persists the server-side record of a user's torrent streaming session.
 *
 * This entity is completely separate from ActiveSession (which handles IPTV
 * stream concurrency).  TorrentSession tracks:
 *  - Who started the session (user)
 *  - The worker's internal session ID (sessionId)
 *  - The current lifecycle status (mirroring the worker's STATUS values)
 *  - A short-lived stream token used to authorise stream-proxy requests
 *  - Timestamps for auditing and cleanup
 */
#[ORM\Entity(repositoryClass: TorrentSessionRepository::class)]
#[ORM\Table(name: 'torrent_session')]
#[ORM\HasLifecycleCallbacks]
class TorrentSession
{
    // ── Status constants — must mirror sessionManager.js STATUS values ──────

    public const STATUS_STARTING           = 'STARTING';
    public const STATUS_FETCHING_METADATA  = 'FETCHING_METADATA';
    public const STATUS_BUFFERING          = 'BUFFERING';
    public const STATUS_READY              = 'READY';
    public const STATUS_PLAYING            = 'PLAYING';
    public const STATUS_STOPPED            = 'STOPPED';
    public const STATUS_ERROR              = 'ERROR';

    // ── Primary key ─────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    // ── Relationships ────────────────────────────────────────────────────────

    /** The user who owns this session. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    // ── Worker session reference ──────────────────────────────────────────────

    /** UUID assigned by the worker when the session was created. */
    #[ORM\Column(length: 36, unique: true)]
    private ?string $sessionId = null;

    // ── Status ───────────────────────────────────────────────────────────────

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_STARTING;

    // ── Security token ───────────────────────────────────────────────────────

    /**
     * Short-lived token given to the React client.
     * The client uses this token (NOT the worker sessionId) to access all
     * Symfony stream endpoints, preventing direct worker access.
     * Generated as 32 random bytes → 64 hex characters.
     */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $streamToken = null;

    // ── Timestamps ───────────────────────────────────────────────────────────

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastActivity = null;

    // ── Constructor ──────────────────────────────────────────────────────────

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt    = $now;
        $this->updatedAt    = $now;
        $this->lastActivity = $now;
        $this->streamToken  = bin2hex(random_bytes(32));
    }

    // ── Lifecycle callbacks ──────────────────────────────────────────────────

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Getters & Setters ────────────────────────────────────────────────────

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

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): static
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStreamToken(): ?string
    {
        return $this->streamToken;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastActivity(): ?\DateTimeImmutable
    {
        return $this->lastActivity;
    }

    public function touchLastActivity(): static
    {
        $this->lastActivity = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Returns true if the session is in a terminal state
     * (stopped or errored) and can be safely ignored / cleaned up.
     */
    public function isTerminal(): bool
    {
        return \in_array($this->status, [self::STATUS_STOPPED, self::STATUS_ERROR], true);
    }
}
