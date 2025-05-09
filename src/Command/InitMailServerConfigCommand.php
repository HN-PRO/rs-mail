<?php

namespace App\Command;

use App\Entity\MailServerConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:init-mail-server-config',
    description: '初始化默认的邮箱服务器配置',
)]
class InitMailServerConfigCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 默认配置
        $defaultConfigs = [
            [
                'domain_pattern' => 'gmail.com',
                'host' => 'imap.gmail.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'use_full_email' => true,
                'description' => 'Gmail邮箱服务器'
            ],
            [
                'domain_pattern' => 'outlook.com',
                'host' => 'outlook.office365.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'use_full_email' => true,
                'description' => 'Outlook邮箱服务器'
            ],
            [
                'domain_pattern' => 'hotmail.com',
                'host' => 'outlook.office365.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'use_full_email' => true,
                'description' => 'Hotmail邮箱服务器'
            ],
            [
                'domain_pattern' => 'yahoo.com',
                'host' => 'imap.mail.yahoo.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'use_full_email' => true,
                'description' => 'Yahoo邮箱服务器'
            ],
            [
                'domain_pattern' => '163.com',
                'host' => 'imap.163.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'use_full_email' => true,
                'description' => '网易163邮箱服务器'
            ],
            [
                'domain_pattern' => 'qq.com',
                'host' => 'imap.qq.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'use_full_email' => true,
                'description' => '腾讯QQ邮箱服务器'
            ]
        ];

        $addedCount = 0;

        // 开始事务
        $this->entityManager->beginTransaction();

        try {
            foreach ($defaultConfigs as $configData) {
                // 检查是否已存在相同域名模式的配置
                $existingConfig = $this->entityManager->getRepository(MailServerConfig::class)
                    ->findOneBy(['domainPattern' => $configData['domain_pattern']]);

                if (!$existingConfig) {
                    $config = new MailServerConfig();
                    $config->setDomainPattern($configData['domain_pattern'])
                        ->setHost($configData['host'])
                        ->setPort($configData['port'])
                        ->setEncryption($configData['encryption'])
                        ->setValidateCert($configData['validate_cert'])
                        ->setUseFullEmail($configData['use_full_email'])
                        ->setIsActive(true)
                        ->setDescription($configData['description']);

                    $this->entityManager->persist($config);
                    $addedCount++;
                }
            }

            // 提交事务
            $this->entityManager->flush();
            $this->entityManager->commit();

            $io->success(sprintf('成功初始化了 %d 个邮箱服务器配置', $addedCount));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            // 回滚事务
            $this->entityManager->rollback();
            $io->error('初始化邮箱服务器配置失败: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 