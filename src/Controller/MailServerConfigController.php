<?php

namespace App\Controller;

use App\Entity\MailServerConfig;
use App\Entity\SystemLog;
use App\Repository\MailServerConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/mail-server-config')]
class MailServerConfigController extends AbstractController
{
    #[Route('/', name: 'app_mail_server_config_index')]
    public function index(MailServerConfigRepository $configRepository): Response
    {
        return $this->render('mail_server_config/index.html.twig', [
            'configs' => $configRepository->findBy([], ['domainPattern' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_mail_server_config_new', methods: ['GET', 'POST'])]
    public function new(Request $request, MailServerConfigRepository $configRepository, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $config = new MailServerConfig();
            $config->setDomainPattern($request->request->get('domain_pattern'))
                ->setHost($request->request->get('host'))
                ->setPort((int)$request->request->get('port'))
                ->setEncryption($request->request->get('encryption'))
                ->setValidateCert($request->request->getBoolean('validate_cert'))
                ->setUseFullEmail($request->request->getBoolean('use_full_email'))
                ->setIsActive($request->request->getBoolean('is_active', true))
                ->setDescription($request->request->get('description'));
            
            $configRepository->save($config);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('添加邮箱服务器配置');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('添加了域名模式为 ' . $config->getDomainPattern() . ' 的邮箱服务器配置');
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '邮箱服务器配置已成功添加');
            return $this->redirectToRoute('app_mail_server_config_index');
        }
        
        return $this->render('mail_server_config/new.html.twig');
    }

    #[Route('/{id}/edit', name: 'app_mail_server_config_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, MailServerConfig $config, MailServerConfigRepository $configRepository, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $oldPattern = $config->getDomainPattern();
            $isActive = $request->request->getBoolean('is_active', true);
            
            $config->setDomainPattern($request->request->get('domain_pattern'))
                ->setHost($request->request->get('host'))
                ->setPort((int)$request->request->get('port'))
                ->setEncryption($request->request->get('encryption'))
                ->setValidateCert($request->request->getBoolean('validate_cert'))
                ->setUseFullEmail($request->request->getBoolean('use_full_email'))
                ->setIsActive($isActive)
                ->setDescription($request->request->get('description'));
            
            $configRepository->save($config);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('编辑邮箱服务器配置');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('编辑了域名模式从 ' . $oldPattern . ' 到 ' . $config->getDomainPattern() . ' 的邮箱服务器配置，状态设置为: ' . ($isActive ? '活跃' : '禁用'));
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '邮箱服务器配置已成功更新。活跃状态: ' . ($isActive ? '活跃' : '禁用'));
            return $this->redirectToRoute('app_mail_server_config_index');
        }
        
        return $this->render('mail_server_config/edit.html.twig', [
            'config' => $config,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_mail_server_config_delete', methods: ['POST'])]
    public function delete(Request $request, MailServerConfig $config, MailServerConfigRepository $configRepository, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$config->getId(), $request->request->get('_token'))) {
            $pattern = $config->getDomainPattern();
            
            $configRepository->remove($config);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('删除邮箱服务器配置');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('删除了域名模式为 ' . $pattern . ' 的邮箱服务器配置');
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '邮箱服务器配置已成功删除');
        }
        
        return $this->redirectToRoute('app_mail_server_config_index');
    }
    
    #[Route('/init-default', name: 'app_mail_server_config_init_default', methods: ['POST'])]
    public function initDefault(Request $request, MailServerConfigRepository $configRepository, EntityManagerInterface $entityManager): Response
    {
        // 检查CSRF令牌
        if (!$this->isCsrfTokenValid('init_default', $request->request->get('_token'))) {
            $this->addFlash('error', 'CSRF令牌无效');
            return $this->redirectToRoute('app_mail_server_config_index');
        }
        
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
        $entityManager->beginTransaction();
        
        try {
            foreach ($defaultConfigs as $configData) {
                // 检查是否已存在相同域名模式的配置
                $existingConfig = $configRepository->findOneBy(['domainPattern' => $configData['domain_pattern']]);
                
                if (!$existingConfig) {
                    $config = new MailServerConfig();
                    $config->setDomainPattern($configData['domain_pattern'])
                        ->setHost($configData['host'])
                        ->setPort($configData['port'])
                        ->setEncryption($configData['encryption'])
                        ->setValidateCert($configData['validate_cert'])
                        ->setUseFullEmail($configData['use_full_email'])
                        ->setDescription($configData['description']);
                    
                    $configRepository->save($config, false);
                    $addedCount++;
                }
            }
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('初始化默认邮箱服务器配置');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('初始化了 ' . $addedCount . ' 个默认邮箱服务器配置');
            
            $entityManager->persist($log);
            $entityManager->flush();
            $entityManager->commit();
            
            $this->addFlash('success', '成功初始化了 ' . $addedCount . ' 个默认邮箱服务器配置');
        } catch (\Exception $e) {
            $entityManager->rollback();
            $this->addFlash('error', '初始化默认配置失败: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_mail_server_config_index');
    }
} 