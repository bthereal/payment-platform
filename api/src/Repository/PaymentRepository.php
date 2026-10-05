<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organisation;
use App\Entity\Payment;
use App\Entity\SettlementPayment;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
final class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findOneByStripeId(string $stripeId): ?Payment
    {
        return $this->findOneBy(['stripeId' => $stripeId]);
    }

    public function findLatestActivityDate(Organisation $organisation): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('p')
            ->select('MAX(p.createdAt)')
            ->where('p.organisation = :organisation')
            ->setParameter('organisation', $organisation->getId(), 'ulid')
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? new \DateTimeImmutable((string) $result) : null;
    }

    public function sumGrossAmount(Organisation $organisation, PaymentDirection $direction): int
    {
        return $this->sumByDirection($organisation, 'grossAmount', $direction);
    }

    /**
     * Same as sumGrossAmount(), but scoped to one PaymentSource too — needed
     * now that more than one PaymentSource shares PaymentDirection::DEBIT
     * (REFUND and DISPUTE), so a direction-only sum would conflate refunds
     * issued to buyers with disputes withheld by the card network.
     */
    public function sumGrossAmountBySource(Organisation $organisation, PaymentDirection $direction, PaymentSource $source): int
    {
        return $this->sumByDirection($organisation, 'grossAmount', $direction, $source);
    }

    public function sumApplicationFee(Organisation $organisation, PaymentDirection $direction): int
    {
        return $this->sumByDirection($organisation, 'applicationFee', $direction);
    }

    public function sumStripeFee(Organisation $organisation, PaymentDirection $direction): int
    {
        return $this->sumByDirection($organisation, 'stripeFee', $direction);
    }

    public function sumNetAmount(Organisation $organisation): int
    {
        $result = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.netAmount), 0)')
            ->where('p.organisation = :organisation')
            ->setParameter('organisation', $organisation->getId(), 'ulid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function sumGrossAmountBetween(
        Organisation $organisation,
        PaymentDirection $direction,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
    ): int {
        $result = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.grossAmount), 0)')
            ->where('p.organisation = :organisation')
            ->andWhere('p.direction = :direction')
            ->andWhere('p.createdAt BETWEEN :since AND :until')
            ->setParameter('organisation', $organisation->getId(), 'ulid')
            ->setParameter('direction', $direction->value, Types::STRING)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Net balance grouped by availableOn for every Payment not yet linked
     * to a SettlementPayment — "still to come", derived the same way the
     * seed command derives it: NOT EXISTS, never a date comparison (see
     * SettlementPayment's own docblock for why).
     *
     * @return array<array{availableOn: string, amount: int}>
     */
    public function pendingBuckets(Organisation $organisation): array
    {
        $notSwept = $this->getEntityManager()->createQueryBuilder()
            ->select('sp.id')
            ->from(SettlementPayment::class, 'sp')
            ->where('sp.payment = p');

        $qb = $this->createQueryBuilder('p');
        $rows = $qb
            ->select('p.availableOn AS availableOn', 'SUM(p.netAmount) AS amount')
            ->where('p.organisation = :organisation')
            ->andWhere($qb->expr()->not($qb->expr()->exists($notSwept->getDQL())))
            ->groupBy('p.availableOn')
            ->orderBy('p.availableOn', 'ASC')
            ->setParameter('organisation', $organisation->getId(), 'ulid')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'availableOn' => $row['availableOn'] instanceof \DateTimeImmutable
                    ? $row['availableOn']->format('Y-m-d')
                    : (string) $row['availableOn'],
                'amount' => (int) $row['amount'],
            ],
            $rows,
        );
    }

    private function sumByDirection(
        Organisation $organisation,
        string $property,
        PaymentDirection $direction,
        ?PaymentSource $source = null,
    ): int {
        $qb = $this->createQueryBuilder('p')
            ->select(\sprintf('COALESCE(SUM(p.%s), 0)', $property))
            ->where('p.organisation = :organisation')
            ->andWhere('p.direction = :direction')
            ->setParameter('organisation', $organisation->getId(), 'ulid')
            ->setParameter('direction', $direction->value, Types::STRING);

        if ($source !== null) {
            $qb->andWhere('p.source = :source')
                ->setParameter('source', $source->value, Types::STRING);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
