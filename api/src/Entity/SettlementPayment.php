<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SettlementPaymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Links a Settlement to the Payment rows it swept. A real join entity
 * (not a bare ManyToMany) because "a payment can be swept by at most one
 * settlement" is a real invariant worth enforcing at the database level —
 * see the unique constraint on `payment_id` via the JoinColumn below.
 *
 * A Payment with no SettlementPayment row is, by definition, "still to
 * come" — that's a NOT EXISTS query against this table, never a comparison
 * against availableOn.
 */
#[ORM\Entity(repositoryClass: SettlementPaymentRepository::class)]
#[ORM\Table(name: 'settlement_payment')]
#[ORM\UniqueConstraint(name: 'uniq_settlement_payment', columns: ['settlement_id', 'payment_id'])]
final class SettlementPayment
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    private Ulid $id;

    #[ORM\ManyToOne(targetEntity: Settlement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Settlement $settlement;

    #[ORM\ManyToOne(targetEntity: Payment::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Payment $payment;

    public function __construct()
    {
        $this->id = new Ulid();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function getSettlement(): Settlement
    {
        return $this->settlement;
    }

    public function setSettlement(Settlement $settlement): self
    {
        $this->settlement = $settlement;

        return $this;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function setPayment(Payment $payment): self
    {
        $this->payment = $payment;

        return $this;
    }
}
