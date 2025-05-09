<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\Mail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * 查找指定邮箱用户的所有令牌
     */
    public function findByMail(Mail $mail): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.mail = :mail')
            ->setParameter('mail', $mail)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 根据令牌字符串查找令牌实体
     */
    public function findByToken(string $token): ?ApiToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * 查找所有已过期的令牌
     */
    public function findExpiredTokens(): array
    {
        $now = new \DateTimeImmutable();
        
        return $this->createQueryBuilder('t')
            ->andWhere('t.expiresAt IS NOT NULL')
            ->andWhere('t.expiresAt < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * 保存令牌
     */
    public function save(ApiToken $token, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($token);
        
        if ($flush) {
            $entityManager->flush();
        }
    }

    /**
     * 删除令牌
     */
    public function remove(ApiToken $token, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($token);
        
        if ($flush) {
            $entityManager->flush();
        }
    }
} 