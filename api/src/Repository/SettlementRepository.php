<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Settlement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Settlement>
 */
final class SettlementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Settlement::class);
    }

    public function findOneByStripeId(string $stripeId): ?Settlement
    {
        return $this->findOneBy(['stripeId' => $stripeId]);
    }
}
