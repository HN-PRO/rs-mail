<?php

namespace App\Repository;

use App\Entity\MailServerConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailServerConfig>
 */
class MailServerConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailServerConfig::class);
    }

    /**
     * 查找所有活跃的邮箱服务器配置
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.domainPattern', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 根据域名查找匹配的服务器配置
     */
    public function findMatchingConfig(string $domain): ?MailServerConfig
    {
        // 获取所有配置，不受活跃状态的限制
        $configs = $this->createQueryBuilder('c')
            ->orderBy('c.domainPattern', 'ASC')
            ->getQuery()
            ->getResult();
        
        // 先尝试找活跃的配置
        foreach ($configs as $config) {
            if ($config->isActive()) {
                $pattern = $config->getDomainPattern();
                // 如果域名以模式结尾，则认为匹配
                if (str_ends_with($domain, $pattern)) {
                    return $config;
                }
            }
        }
        
        // 如果没有找到活跃的匹配配置，返回null
        return null;
    }

    /**
     * 保存配置
     */
    public function save(MailServerConfig $config, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        
        if ($config->getId() === null) {
            $entityManager->persist($config);
        } else {
            $config->setUpdatedAt(new \DateTimeImmutable());
        }
        
        if ($flush) {
            $entityManager->flush();
        }
    }

    /**
     * 删除配置
     */
    public function remove(MailServerConfig $config, bool $flush = true): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($config);
        
        if ($flush) {
            $entityManager->flush();
        }
    }
} 