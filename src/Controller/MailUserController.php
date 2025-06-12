<?php

namespace App\Controller;

use App\Entity\Mail;
use App\Entity\SystemLog;
use App\Repository\DomainRepository;
use App\Repository\MailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;
use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Service\ImapService;
use App\Service\PasswordHashService;

#[IsGranted('ROLE_USER')]
#[Route('/admin/mail-user')]
class MailUserController extends AbstractController
{
    #[Route('/', name: 'app_mail_user_index')]
    public function index(Request $request, MailRepository $mailRepository, DomainRepository $domainRepository, PaginatorInterface $paginator): Response
    {
        $searchTerm = $request->query->get('search', '');
        $domainFilter = $request->query->get('domain', '所有域名');
        
        $domains = $domainRepository->findAll();
        
        if (!empty($searchTerm)) {
            $usersQuery = $mailRepository->findByEmailQueryBuilder($searchTerm);
        } elseif ($domainFilter !== '所有域名') {
            $domain = $domainRepository->findOneBy(['domain' => $domainFilter]);
            if ($domain) {
                $usersQuery = $mailRepository->findByDomainQueryBuilder($domain->getId());
            } else {
                $usersQuery = $mailRepository->findAllQueryBuilder();
            }
        } else {
            $usersQuery = $mailRepository->findAllQueryBuilder();
        }
        
        // 分页处理
        $pagination = $paginator->paginate(
            $usersQuery,
            $request->query->getInt('page', 1), // 当前页码，默认为第1页
            20 // 每页显示20条记录
        );
        
        return $this->render('mail_user/index.html.twig', [
            'users' => $pagination,
            'domains' => $domains,
            'search_term' => $searchTerm,
            'domain_filter' => $domainFilter,
        ]);
    }
    
    #[Route('/new', name: 'app_mail_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, DomainRepository $domainRepository, PasswordHashService $passwordHashService): Response
    {
        $domains = $domainRepository->findAll();
        
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $domainId = $request->request->get('domain_id');
            $quota = $request->request->get('quota', 0);
            
            // 获取域名实体
            $domain = $domainRepository->find($domainId);
            if (!$domain) {
                $this->addFlash('error', '所选域名不存在');
                return $this->redirectToRoute('app_mail_user_index');
            }
            
            // 检查邮箱是否已存在
            $fullEmail = $email . '@' . $domain->getDomain();
            $existingMail = $entityManager->getRepository(Mail::class)->findOneBy(['email' => $fullEmail]);
            if ($existingMail) {
                $this->addFlash('error', '该邮箱地址已存在');
                return $this->redirectToRoute('app_mail_user_index');
            }
            
            $mail = new Mail();
            $mail->setDomain($domain);
            $mail->setEmail($fullEmail); // 设置完整邮箱地址
            $mail->setPassword($passwordHashService->hashPassword($password));
            if ($quota) {
                $mail->setQuota((int)$quota);
            }
            
            $entityManager->persist($mail);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('添加邮箱用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('创建新邮箱用户 ' . $fullEmail);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '邮箱用户已成功添加');
            return $this->redirectToRoute('app_mail_user_index');
        }
        
        // 如果请求是GET，我们不渲染独立模板，而是重定向回列表页
        return $this->redirectToRoute('app_mail_user_index');
    }
    
    #[Route('/{id}/edit', name: 'app_mail_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Mail $mail, EntityManagerInterface $entityManager, DomainRepository $domainRepository, PasswordHashService $passwordHashService): Response
    {
        $domains = $domainRepository->findAll();
        
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $domainId = $request->request->get('domain_id');
            $quota = $request->request->get('quota', 0);
            $active = $request->request->get('active', 1);
            
            // 获取域名实体
            $domain = $domainRepository->find($domainId);
            if (!$domain) {
                $this->addFlash('error', '所选域名不存在');
                return $this->redirectToRoute('app_mail_user_index');
            }
            
            // 检查邮箱是否已存在（除了当前编辑的邮箱）
            $fullEmail = $email . '@' . $domain->getDomain();
            $existingMail = $entityManager->getRepository(Mail::class)->findOneBy(['email' => $fullEmail]);
            if ($existingMail && $existingMail->getId() !== $mail->getId()) {
                $this->addFlash('error', '该邮箱地址已存在');
                return $this->redirectToRoute('app_mail_user_index');
            }
            
            $oldEmail = $mail->getEmail();
            $oldDomain = $mail->getDomain() ? $mail->getDomain()->getDomain() : '';
            
            $mail->setEmail($fullEmail);
            $mail->setDomain($domain);
            if ($quota) {
                $mail->setQuota((int)$quota);
            }
            
            // 设置状态
            $mail->setStatus($active ? '活跃' : '禁用');
            
            // 如果提供了新密码，则更新密码
            if (!empty($password)) {
                $mail->setPassword($passwordHashService->hashPassword($password));
            } else if ($passwordHashService->needsRehash($mail->getPassword())) {
                // 如果密码使用的是旧的哈希格式，而用户没有提供新密码，我们也应该进行重新哈希
                // 这种情况需要知道用户的原始密码，所以在这里不能直接重新哈希
                // 可以添加一个提示告诉用户需要更新密码
                $this->addFlash('warning', '当前密码使用的是旧格式，建议修改密码以提升安全性');
            }
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('编辑邮箱用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('编辑邮箱用户从 ' . $oldEmail . '@' . $oldDomain . ' 到 ' . $fullEmail);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '邮箱用户已成功更新');
            return $this->redirectToRoute('app_mail_user_index');
        }
        
        // 如果请求是GET，我们不渲染独立模板，而是重定向回列表页
        return $this->redirectToRoute('app_mail_user_index');
    }
    
    #[Route('/{id}/delete', name: 'app_mail_user_delete', methods: ['POST'])]
    public function delete(Request $request, Mail $mail, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$mail->getId(), $request->request->get('_token'))) {
            $email = $mail->getEmail();
            $domain = $mail->getDomain() ? $mail->getDomain()->getDomain() : '';
            
            $entityManager->remove($mail);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('删除邮箱用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('删除邮箱用户 ' . $email . '@' . $domain);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '邮箱用户已成功删除');
        }
        
        return $this->redirectToRoute('app_mail_user_index');
    }
    
    #[Route('/batch-create', name: 'app_mail_user_batch_create', methods: ['POST'])]
    public function batchCreate(Request $request, EntityManagerInterface $entityManager, DomainRepository $domainRepository, PasswordHashService $passwordHashService): Response
    {
        // 修改PHP脚本执行时间限制
        ini_set('max_execution_time', 120); // 增加到300秒
        
        $domainId = $request->request->get('domain_id');
        $prefix = $request->request->get('prefix', '');
        $length = (int)$request->request->get('name_length', 8);
        $count = (int)$request->request->get('count', 1);
        $password = $request->request->get('password');
        $useRandomPassword = $request->request->get('use_random_password', false);
        $quota = $request->request->get('quota', 0);
        
        // 限制最大创建数量为2000
        $count = min($count, 2000);
        
        // 获取域名实体
        $domain = $domainRepository->find($domainId);
        if (!$domain) {
            $this->addFlash('error', '所选域名不存在');
            return $this->redirectToRoute('app_mail_user_index');
        }
        
        // 用于生成随机用户名的字符集
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-';
        $successCount = 0;
        
        // 预先计算密码哈希（如果使用固定密码）
        $hashedPassword = null;
        if (!$useRandomPassword && !empty($password)) {
            $hashedPassword = $passwordHashService->hashPassword($password);
        }
        
        // 开始事务
        $entityManager->beginTransaction();
        
        try {
            // 更高效的批处理 - 减少数据库查询次数
            $batchSize = 100; // 每批次处理100个账户
            
            // 预生成所有随机用户名，避免每次单独查询已存在的邮箱
            $randomEmails = [];
            for ($i = 0; $i < $count * 2; $i++) { // 生成两倍数量以应对重复情况
                $randomString = '';
                for ($j = 0; $j < $length; $j++) {
                    $randomString .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $randomEmails[] = $prefix . $randomString;
                
                if (count($randomEmails) >= $count * 2) {
                    break;
                }
            }
            
            // 一次性查询所有已存在的邮箱
            $existingEmails = $entityManager->getRepository(Mail::class)
                ->createQueryBuilder('m')
                ->select('m.email')
                ->where('m.email IN (:emails)')
                ->setParameter('emails', $randomEmails)
                ->getQuery()
                ->getArrayResult();
            
            // 转换为简单的关联数组以便快速查找
            $existingEmailsMap = [];
            foreach ($existingEmails as $emailData) {
                $existingEmailsMap[$emailData['email']] = true;
            }
            
            // 开始创建邮箱
            for ($i = 0; $successCount < $count && $i < count($randomEmails); $i++) {
                $email = $randomEmails[$i];
                $fullEmail = $email . '@' . $domain->getDomain();
                
                // 检查邮箱是否已存在
                if (isset($existingEmailsMap[$fullEmail])) {
                    continue; // 跳过已存在的邮箱
                }
                
                $mail = new Mail();
                $mail->setEmail($fullEmail);
                
                // 设置密码（随机或指定）
                if ($useRandomPassword) {
                    // 随机生成8位密码
                    $randomPassword = '';
                    for ($j = 0; $j < 8; $j++) {
                        $randomPassword .= $chars[random_int(0, strlen($chars) - 1)];
                    }
                    $mail->setPassword($passwordHashService->hashPassword($randomPassword));
                } else {
                    $mail->setPassword($hashedPassword ?? $passwordHashService->hashPassword($password));
                }
                
                $mail->setDomain($domain);
                if ($quota) {
                    $mail->setQuota((int)$quota);
                }
                
                $entityManager->persist($mail);
                $successCount++;
                
                // 每批次处理完成后，刷新实体管理器
                if ($successCount % $batchSize === 0) {
                    $entityManager->flush();
                    $entityManager->clear(); // 清理内存
                    $domain = $domainRepository->find($domainId);
                }
            }
            
            // 最后一次flush确保所有更改都被保存
            $entityManager->flush();
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('批量添加邮箱用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails("批量创建了 {$successCount} 个邮箱用户");
            
            $entityManager->persist($log);
            $entityManager->flush();
            $entityManager->commit();
            
            $this->addFlash('success', "成功批量创建了 {$successCount} 个邮箱用户");
        } catch (\Exception $e) {
            $entityManager->rollback();
            $this->addFlash('error', '批量创建用户过程中出错: ' . $e->getMessage());
        } finally {
            // 恢复默认执行时间限制
            ini_set('max_execution_time', 30);
        }
        
        return $this->redirectToRoute('app_mail_user_index');
    }
    
    /**
     * 生成随机密码
     */
    private function generateRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-+=';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    #[Route('/batch-delete', name: 'app_mail_user_batch_delete', methods: ['POST'])]
    public function batchDelete(Request $request, EntityManagerInterface $entityManager, MailRepository $mailRepository): Response
    {
        $searchTerm = $request->request->get('search', '');
        $domainId = $request->request->get('domain_id', 0);
        $status = $request->request->get('status', '');
        
        if (empty($searchTerm) && empty($domainId) && empty($status)) {
            $this->addFlash('error', '请至少选择一个筛选条件');
            return $this->redirectToRoute('app_mail_user_index');
        }
        
        // 构建删除查询
        $queryBuilder = $mailRepository->findForDeleteQueryBuilder();
        
        // 应用筛选条件
        if (!empty($searchTerm)) {
            $queryBuilder->andWhere('m.email LIKE :email')
                         ->setParameter('email', '%' . $searchTerm . '%');
        }
        
        if (!empty($domainId)) {
            $queryBuilder->andWhere('m.domain = :domainId')
                         ->setParameter('domainId', $domainId);
        }
        
        if (!empty($status)) {
            $queryBuilder->andWhere('m.status = :status')
                         ->setParameter('status', $status);
        }
        
        // 开始事务
        $entityManager->beginTransaction();
        
        try {
            // 查询要删除的邮箱以便记录日志
            $emails = $queryBuilder->select('m.email, d.domain')
                                   ->join('m.domain', 'd')
                                   ->getQuery()
                                   ->getResult();
            
            $count = count($emails);
            
            if ($count === 0) {
                $this->addFlash('warning', '没有符合条件的邮箱用户');
                return $this->redirectToRoute('app_mail_user_index');
            }
            
            // 执行批量删除
            $deleteQueryBuilder = $mailRepository->findForDeleteQueryBuilder();
            
            // 应用相同的筛选条件进行删除
            if (!empty($searchTerm)) {
                $deleteQueryBuilder->andWhere('m.email LIKE :email')
                                  ->setParameter('email', '%' . $searchTerm . '%');
            }
            
            if (!empty($domainId)) {
                $deleteQueryBuilder->andWhere('m.domain = :domainId')
                                  ->setParameter('domainId', $domainId);
            }
            
            if (!empty($status)) {
                $deleteQueryBuilder->andWhere('m.status = :status')
                                  ->setParameter('status', $status);
            }
            
            // 创建删除查询并执行
            $deleteQueryBuilder->delete()
                              ->getQuery()
                              ->execute();
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('批量删除邮箱用户');
            $log->setIpAddress($request->getClientIp());
            
            // 记录删除详情，包括条件
            $details = "批量删除了 {$count} 个邮箱用户。";
            $details .= "筛选条件: ";
            if (!empty($searchTerm)) $details .= "邮箱包含 '{$searchTerm}' ";
            if (!empty($domainId)) $details .= "域名ID {$domainId} ";
            if (!empty($status)) $details .= "状态为 '{$status}' ";
            
            $log->setDetails($details);
            
            $entityManager->persist($log);
            $entityManager->flush();
            $entityManager->commit();
            
            $this->addFlash('success', "成功批量删除了 {$count} 个邮箱用户");
        } catch (\Exception $e) {
            $entityManager->rollback();
            $this->addFlash('error', '批量删除用户过程中出错: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_mail_user_index');
    }
    
    #[Route('/batch-create-direct', name: 'app_mail_user_batch_create_direct', methods: ['POST'])]
    public function batchCreateDirect(Request $request, EntityManagerInterface $entityManager, DomainRepository $domainRepository, PasswordHashService $passwordHashService): Response
    {
        // 修改PHP脚本执行时间限制
        ini_set('max_execution_time', 900); // 增加到900秒
        ini_set('memory_limit', '1024M'); // 增加内存限制到1GB
        
        $domainId = $request->request->get('domain_id');
        $prefix = $request->request->get('prefix', '');
        $length = (int)$request->request->get('name_length', 8);
        $count = (int)$request->request->get('count', 1);
        $password = $request->request->get('password');
        $useRandomPassword = $request->request->get('use_random_password', false);
        $quota = (int)$request->request->get('quota', 0);
        
        // 限制最大创建数量为5000
        $count = min($count, 5000);
        
        // 获取域名实体
        $domain = $domainRepository->find($domainId);
        if (!$domain) {
            $this->addFlash('error', '所选域名不存在');
            return $this->redirectToRoute('app_mail_user_index');
        }
        
        // 用于生成随机用户名的字符集
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-';
        $charsLength = strlen($chars) - 1;
        
        // 性能优化：预先计算密码哈希并创建哈希池（这是最大的性能瓶颈）
        $hashedPassword = null;
        $passwordPool = [];
        
        if (!$useRandomPassword && !empty($password)) {
            // 使用固定密码 - 只需计算一次哈希
            $hashedPassword = $passwordHashService->hashPassword($password);
        } else if ($useRandomPassword) {
            // 使用随机密码 - 预先生成一个密码哈希池
            // 根据测试数据，密码哈希生成是主要瓶颈，因此我们只预生成10个不同的哈希
            for ($i = 0; $i < 10; $i++) {
                $passwordPool[] = $passwordHashService->hashPassword('pwd_' . $i);
            }
        }
        
        // 开始事务
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        
        try {
            // 当前时间
            $now = new \DateTimeImmutable();
            $formattedDate = $now->format('Y-m-d H:i:s');
            
            $batchSize = 400; // 减小批处理大小以提高响应速度
            $insertValues = [];
            $insertParams = [];
            $successCount = 0;
            $generatedCount = 0;
            $existingEmailsMap = [];
            
            // 直接使用一个大循环，生成和插入
            while ($successCount < $count && $generatedCount < $count * 3) {
                $generatedCount++;
                
                // 高效生成随机邮箱
                $randomString = '';
                for ($j = 0; $j < $length; $j++) {
                    $randomString .= $chars[mt_rand(0, $charsLength)];
                }
                $email = $prefix . $randomString;
                
                // 跳过已生成的相同邮箱
                if (isset($existingEmailsMap[$email])) {
                    continue;
                }
                
                $existingEmailsMap[$email] = true;
                
                // 设置密码 - 主要性能优化点
                if ($useRandomPassword) {
                    // 从预生成的哈希池中随机选取，而不是每次生成新密码哈希
                    $currentPassword = $passwordPool[mt_rand(0, count($passwordPool) - 1)];
                } else {
                    $currentPassword = $hashedPassword;
                }
                
                // 添加到插入值列表
                $fullEmail = $email . '@' . $domain->getDomain();
                $insertValues[] = "(?, ?, ?, ?, ?, ?)";
                $insertParams[] = $fullEmail;                 // email
                $insertParams[] = $currentPassword;       // password
                $insertParams[] = $domainId;              // domain_id
                $insertParams[] = $formattedDate;         // created_at
                $insertParams[] = '活跃';                  // status
                $insertParams[] = $quota;                 // quota
                
                $successCount++;
                
                // 当达到批处理大小或已达到目标数量时执行批量插入
                if (count($insertValues) >= $batchSize || $successCount >= $count) {
                    // 检查这批邮箱是否已存在
                    $emailsToCheck = [];
                    for ($i = 0; $i < count($insertValues); $i++) {
                        $emailsToCheck[] = $insertParams[$i * 6];
                    }
                    
                    // 一次性检查所有邮箱
                    $existingDbEmails = [];
                    if (!empty($emailsToCheck)) {
                        // 分批次检查，避免参数过多
                        $checkBatchSize = 100;
                        for ($i = 0; $i < count($emailsToCheck); $i += $checkBatchSize) {
                            $batch = array_slice($emailsToCheck, $i, $checkBatchSize);
                            $placeholders = implode(',', array_fill(0, count($batch), '?'));
                            $checkSql = "SELECT email FROM virtual_users WHERE email IN ($placeholders)";
                            $checkStmt = $connection->prepare($checkSql);
                            
                            foreach ($batch as $index => $email) {
                                $checkStmt->bindValue($index + 1, $email);
                            }
                            
                            $checkResult = $checkStmt->executeQuery();
                            foreach ($checkResult->fetchAllAssociative() as $row) {
                                $existingDbEmails[$row['email']] = true;
                            }
                        }
                    }
                    
                    // 过滤掉已存在的邮箱
                    if (!empty($existingDbEmails)) {
                        $filteredValues = [];
                        $filteredParams = [];
                        
                        for ($i = 0; $i < count($insertValues); $i++) {
                            $email = $insertParams[$i * 6];
                            $fullEmail = $email . '@' . $domain->getDomain();
                            if (!isset($existingDbEmails[$fullEmail])) {
                                $filteredValues[] = $insertValues[$i];
                                for ($j = 0; $j < 6; $j++) {
                                    $filteredParams[] = $insertParams[$i * 6 + $j];
                                }
                            } else {
                                $successCount--; // 减少成功计数
                            }
                        }
                        
                        $insertValues = $filteredValues;
                        $insertParams = $filteredParams;
                    }
                    
                    // 执行批量插入
                    if (!empty($insertValues)) {
                        $sql = "INSERT INTO virtual_users (email, password, domain_id, created_at, status, quota) VALUES " . implode(', ', $insertValues);
                        $stmt = $connection->prepare($sql);
                        
                        // 绑定参数
                        foreach ($insertParams as $index => $param) {
                            $stmt->bindValue($index + 1, $param);
                        }
                        
                        $stmt->executeStatement();
                    }
                    
                    $insertValues = [];
                    $insertParams = [];
                }
            }
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('批量添加邮箱用户(直接SQL)');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails("批量创建了 {$successCount} 个邮箱用户");
            
            $entityManager->persist($log);
            $entityManager->flush();
            $connection->commit();
            
            $this->addFlash('success', "成功批量创建了 {$successCount} 个邮箱用户");
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->addFlash('error', '批量创建用户过程中出错: ' . $e->getMessage());
        } finally {
            // 恢复默认执行时间和内存限制
            ini_set('max_execution_time', 30);
            ini_set('memory_limit', '128M');
        }
        
        return $this->redirectToRoute('app_mail_user_index');
    }
    
    /**
     * 执行批量插入SQL
     */
    private function executeBatchInsert($connection, array $insertValues, array $insertParams): void
    {
        if (empty($insertValues)) {
            return;
        }
        
        $sql = "INSERT INTO virtual_users (email, password, domain_id, created_at, status, quota) VALUES " . implode(', ', $insertValues);
        $stmt = $connection->prepare($sql);
        
        // 绑定参数
        foreach ($insertParams as $index => $param) {
            $stmt->bindValue($index + 1, $param);
        }
        
        $stmt->executeStatement();
    }
    
    /**
     * 更快的随机密码生成方法，避免使用random_int
     */
    private function generateFastRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-+=';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        
        return $password;
    }

    #[Route('/export-csv', name: 'app_mail_user_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request, MailRepository $mailRepository, EntityManagerInterface $entityManager): Response
    {
        $searchTerm = $request->query->get('search', '');
        $domainFilter = $request->query->get('domain', '');
        $statusFilter = $request->query->get('status', '');
        $includePasswords = $request->query->get('include_passwords', false);
        $startDate = $request->query->get('start_date', '');
        $endDate = $request->query->get('end_date', '');
        
        // 构建查询
        $qb = $mailRepository->findAllQueryBuilder();
        
        // 应用筛选条件
        if (!empty($searchTerm)) {
            $qb->andWhere('m.email LIKE :searchTerm')
               ->setParameter('searchTerm', '%' . $searchTerm . '%');
        }
        
        if (!empty($domainFilter) && $domainFilter !== '所有域名') {
            $qb->innerJoin('m.domain', 'd')
               ->andWhere('d.domain = :domainFilter')
               ->setParameter('domainFilter', $domainFilter);
        }
        
        if (!empty($statusFilter)) {
            $qb->andWhere('m.status = :statusFilter')
               ->setParameter('statusFilter', $statusFilter);
        }
        
        // 添加时间范围筛选
        if (!empty($startDate)) {
            $startDateTime = new \DateTimeImmutable($startDate . ' 00:00:00');
            $qb->andWhere('m.createdAt >= :startDate')
               ->setParameter('startDate', $startDateTime);
        }
        
        if (!empty($endDate)) {
            $endDateTime = new \DateTimeImmutable($endDate . ' 23:59:59');
            $qb->andWhere('m.createdAt <= :endDate')
               ->setParameter('endDate', $endDateTime);
        }
        
        // 执行查询
        $users = $qb->getQuery()->getResult();
        
        // 生成CSV内容
        $csvContent = "ID,用户名,域名,创建时间";
        if ($includePasswords) {
            $csvContent .= ",密码哈希";
        }
        $csvContent .= "\n";
        
        foreach ($users as $user) {
            $csvContent .= $user->getId() . ",";
            $csvContent .= $user->getEmail() . ",";
            $csvContent .= $user->getDomain()->getDomain() . ",";
            $csvContent .= $user->getCreatedAt()->format('Y-m-d H:i:s') . ",";
            if ($includePasswords) {
                $csvContent .= "," . $user->getPassword();
            }
            $csvContent .= "\n";
        }
        
        // 记录系统日志
        $log = new SystemLog();
        $log->setOperatorName($this->getUser()->getUserIdentifier());
        $log->setOperationType('导出邮箱用户CSV');
        $log->setIpAddress($request->getClientIp());
        
        // 添加时间范围到日志详情
        $logDetails = "导出了 " . count($users) . " 个邮箱用户的数据";
        if (!empty($startDate) || !empty($endDate)) {
            $logDetails .= "，时间范围：";
            if (!empty($startDate)) $logDetails .= "从 {$startDate} ";
            if (!empty($endDate)) $logDetails .= "到 {$endDate}";
        }
        
        $log->setDetails($logDetails);
        $entityManager->persist($log);
        $entityManager->flush();
        
        // 设置响应头
        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="mail_users_' . date('Ymd_His') . '.csv"');
        
        return $response;
    }

    #[Route('/{id}/api-tokens', name: 'app_mail_user_tokens', methods: ['GET'])]
    public function viewApiTokens(
        Mail $mail, 
        ApiTokenRepository $apiTokenRepository
    ): Response
    {
        // 获取用户的所有令牌
        $tokens = $apiTokenRepository->findByMail($mail);
        
        return $this->render('mail_user/api_tokens.html.twig', [
            'mail' => $mail,
            'tokens' => $tokens
        ]);
    }
    
    #[Route('/{id}/generate-api-token', name: 'app_mail_user_generate_token', methods: ['POST'])]
    public function generateApiToken(
        Request $request, 
        Mail $mail, 
        EntityManagerInterface $entityManager,
        ImapService $imapService,
        ApiTokenRepository $apiTokenRepository
    ): Response
    {
        // 检查用户是否活跃
        if ($mail->getStatus() !== '活跃') {
            $this->addFlash('error', '只能为活跃用户生成API令牌');
            return $this->redirectToRoute('app_mail_user_index');
        }
        
        // 生成新的令牌
        $token = new ApiToken();
        $token->setMail($mail);
        $token->setToken($imapService->generateApiToken($mail));
        
        // 设置过期时间（默认30天）
        $expiresInDays = $request->request->getInt('expires_in', 30);
        if ($expiresInDays > 0) {
            $token->setExpiresAt(new \DateTimeImmutable('+' . $expiresInDays . ' days'));
        }
        
        $apiTokenRepository->save($token);
        
        // 记录系统日志
        $log = new SystemLog();
        $log->setOperatorName($this->getUser()->getUserIdentifier());
        $log->setOperationType('生成API令牌');
        $log->setIpAddress($request->getClientIp());
        $log->setDetails('为邮箱用户 ' . $mail->getFullEmail() . ' 生成API令牌');
        
        $entityManager->persist($log);
        $entityManager->flush();
        
        $this->addFlash('success', '已成功生成API令牌：' . $token->getToken());
        return $this->redirectToRoute('app_mail_user_tokens', ['id' => $mail->getId()]);
    }
    
    #[Route('/api-token/{id}/delete', name: 'app_api_token_delete', methods: ['POST'])]
    public function deleteApiToken(
        Request $request,
        ApiToken $token,
        ApiTokenRepository $apiTokenRepository,
        EntityManagerInterface $entityManager
    ): Response
    {
        if ($this->isCsrfTokenValid('delete-token'.$token->getId(), $request->request->get('_token'))) {
            $mail = $token->getMail();
            
            $apiTokenRepository->remove($token);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('删除API令牌');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('删除邮箱用户 ' . $mail->getFullEmail() . ' 的API令牌');
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', 'API令牌已成功删除');
        }
        
        return $this->redirectToRoute('app_mail_user_tokens', ['id' => $token->getMail()->getId()]);
    }
} 