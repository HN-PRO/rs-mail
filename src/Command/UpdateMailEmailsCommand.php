<?php

namespace App\Command;

use App\Entity\Mail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-mail-emails',
    description: '将Mail实体的email字段更新为完整的邮箱地址',
)]
class UpdateMailEmailsCommand extends Command
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
        $io->title('开始更新邮箱记录');

        // 查询所有邮箱记录
        $mails = $this->entityManager->getRepository(Mail::class)->findAll();
        $total = count($mails);
        $updated = 0;
        $skipped = 0;

        $io->progressStart($total);

        foreach ($mails as $mail) {
            $currentEmail = $mail->getEmail();
            $domain = $mail->getDomain();

            // 如果email已经包含@，或者没有关联的域名，则跳过
            if (strpos($currentEmail, '@') !== false || !$domain) {
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            // 更新为完整邮箱地址
            $fullEmail = $currentEmail . '@' . $domain->getDomain();
            $mail->setEmail($fullEmail);
            $updated++;

            $io->progressAdvance();

            // 每100条记录刷新一次，减少内存使用
            if ($updated % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        // 最后一次刷新
        $this->entityManager->flush();
        $io->progressFinish();

        $io->success([
            '邮箱记录更新完成',
            "总记录数: $total",
            "更新记录数: $updated",
            "跳过记录数: $skipped"
        ]);

        return Command::SUCCESS;
    }
} 