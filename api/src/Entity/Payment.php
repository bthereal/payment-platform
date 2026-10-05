<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * The ledger entry. Every charge and every refund in the source fixture
 * becomes exactly one Payment row here — Stripe's charge/refund/payout shapes
 * are an ingestion format the seed command translates, not this domain model.
 *
 * A refund never mutates the charge Payment it reverses (see relatedPayment);
 * it is its own row, direction DEBIT, source REFUND. This is what makes a
 * refund arriving after its charge has already been swept into a Settlement
 * representable without special-casing — see README.md.
 *
 * grossAmount/stripeFee/applicationFee are always stored as non-negative
 * magnitudes; the reporting layer decides add/subtract based on `direction`.
 * netAmount keeps Stripe's true signed value because it is meant to be
 * summed directly: SUM(net_amount) is the organisation's balance movement.
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\Index(columns: ['available_on'], name: 'idx_payment_available_on')]
#[ORM\Index(columns: ['direction'], name: 'idx_payment_direction')]
final class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $stripeId;

    #[ORM\ManyToOne(targetEntity: Organisation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Organisation $organisation;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Customer $customer = null;

    #[ORM\Column(length: 10, enumType: PaymentDirection::class)]
    private PaymentDirection $direction;

    #[ORM\Column(length: 10, enumType: PaymentSource::class)]
    private PaymentSource $source;

    #[ORM\Column]
    private int $grossAmount;

    #[ORM\Column]
    private int $stripeFee;

    #[ORM\Column]
    private int $applicationFee;

    #[ORM\Column]
    private int $netAmount;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $availableOn;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'related_payment_id', nullable: true)]
    private ?self $relatedPayment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $orderRef = null;

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

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function getDirection(): PaymentDirection
    {
        return $this->direction;
    }

    public function setDirection(PaymentDirection $direction): self
    {
        $this->direction = $direction;

        return $this;
    }

    public function getSource(): PaymentSource
    {
        return $this->source;
    }

    public function setSource(PaymentSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getGrossAmount(): int
    {
        return $this->grossAmount;
    }

    public function setGrossAmount(int $grossAmount): self
    {
        $this->grossAmount = $grossAmount;

        return $this;
    }

    public function getStripeFee(): int
    {
        return $this->stripeFee;
    }

    public function setStripeFee(int $stripeFee): self
    {
        $this->stripeFee = $stripeFee;

        return $this;
    }

    public function getApplicationFee(): int
    {
        return $this->applicationFee;
    }

    public function setApplicationFee(int $applicationFee): self
    {
        $this->applicationFee = $applicationFee;

        return $this;
    }

    public function getNetAmount(): int
    {
        return $this->netAmount;
    }

    public function setNetAmount(int $netAmount): self
    {
        $this->netAmount = $netAmount;

        return $this;
    }

    public function getAvailableOn(): \DateTimeImmutable
    {
        return $this->availableOn;
    }

    public function setAvailableOn(\DateTimeImmutable $availableOn): self
    {
        $this->availableOn = $availableOn;

        return $this;
    }

    public function getRelatedPayment(): ?self
    {
        return $this->relatedPayment;
    }

    public function setRelatedPayment(?self $relatedPayment): self
    {
        $this->relatedPayment = $relatedPayment;

        return $this;
    }

    public function getOrderRef(): ?string
    {
        return $this->orderRef;
    }

    public function setOrderRef(?string $orderRef): self
    {
        $this->orderRef = $orderRef;

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
