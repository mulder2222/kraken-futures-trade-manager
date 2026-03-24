<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Trade;
use App\Enum\TradeStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trade::class);
    }

    public function findActiveTrade(): ?Trade
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('statuses', [TradeStatus::PENDING->value, TradeStatus::ACTIVE->value, TradeStatus::TP1_FILLED->value])
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
