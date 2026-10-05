<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organisation;
use App\Entity\Payment;
use App\Entity\SettlementPayment;
use App\Enum\SettlementStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SettlementPayment>
 */
final class SettlementPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettlementPayment::class);
    }

    public function findOneByPayment(Payment $payment): ?SettlementPayment
    {
        return $this->findOneBy(['payment' => $payment]);
    }

    /**
     * Sum of net amounts actually swept into a PAID settlement that has, as
     * of $now, actually arrived — queried from this side (rather than from
     * Settlement) because Settlement has no inverse collection back to its
     * SettlementPayment rows; this entity already holds both associations
     * needed to join settlement to payment directly.
     *
     * `status = PAID` alone isn't enough: Stripe (and this domain model,
     * via Settlement::$arrivalDate) can mark a payout PAID while the money
     * is still in transit to the organisation's bank — arrivalDate is the
     * real-world date it lands, which is a fact about the calendar, not
     * about whatever reference date the rest of the dashboard is using
     * (unlike salesWindow, "has this genuinely arrived yet" doesn't make
     * sense evaluated against a synthetic dateFrom). Callers pass $now
     * explicitly rather than this reading the clock itself, so it stays
     * deterministic to test.
     */
    public function sumPaidOutToDate(Organisation $organisation, \DateTimeImmutable $now): int
    {
        $result = $this->createQueryBuilder('sp')
            ->select('COALESCE(SUM(p.netAmount), 0)')
            ->join('sp.settlement', 's')
            ->join('sp.payment', 'p')
            ->where('s.organisation = :organisation')
            ->andWhere('s.status = :status')
            ->andWhere('s.arrivalDate <= :now')
            ->setParameter('organisation', $organisation->getId(), 'ulid')
            ->setParameter('status', SettlementStatus::PAID->value, Types::STRING)
            ->setParameter('now', $now, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
