<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SettlementStatus;
use App\Repository\SettlementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Money leaving the organisation's Stripe balance for its own bank account.
 * Deliberately not named `Payout` and deliberately not a Payment — no
 * Customer is a party to it, so it sits outside the customer-payment ledger
 * and only ever reads it via SettlementPayment.
 */
#[ORM\Entity(repositoryClass: SettlementRepository::class)]
#[ORM\Table(name: 'settlement')]
final class Settlement
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $stripeId;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Organisation $organisation;

    #[ORM\Column]
    private int $totalAmount;

    #[ORM\Column(length: 10, enumType: SettlementStatus::class)]
    private SettlementStatus $status;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $arrivalDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getStripeId(): string
    {
        return $this->stripeId;
    }

    public function setStripeId(string $stripeId): self
    {
        $this->stripeId = $stripeId;

        return $this;
    }

    public function getOrganisation(): Organisation
    {
        return $this->organisation;
    }

    public function setOrganisation(Organisation $organisation): self
    {
        $this->organisation = $organisation;

        return $this;
    }

    public function getTotalAmount(): int
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(int $totalAmount): self
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getStatus(): SettlementStatus
    {
        return $this->status;
    }

    public function setStatus(SettlementStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getArrivalDate(): \DateTimeImmutable
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(\DateTimeImmutable $arrivalDate): self
    {
        $this->arrivalDate = $arrivalDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
