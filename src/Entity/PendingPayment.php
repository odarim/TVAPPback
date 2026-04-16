<?php

namespace App\Entity;

use App\Repository\PendingPaymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a payment intent created in /api/payment/checkout.
 * Matched against Papi's notification by `reference`.
 */
#[ORM\Entity(repositoryClass: PendingPaymentRepository::class)]
class PendingPayment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Package $package = null;

    /** Unique reference sent to Papi and returned in the notification */
    #[ORM\Column(length: 64, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $processed = false;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getPackage(): ?Package { return $this->package; }
    public function setPackage(?Package $package): static { $this->package = $package; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function isProcessed(): bool { return $this->processed; }
    public function setProcessed(bool $processed): static { $this->processed = $processed; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
