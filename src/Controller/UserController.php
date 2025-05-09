<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\SystemLog;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/user')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index')]
    public function index(Request $request, UserRepository $userRepository, DomainRepository $domainRepository): Response
    {
        $searchTerm = $request->query->get('search', '');
        $domainFilter = $request->query->get('domain', '所有域名');
        
        $domains = $domainRepository->findAll();
        
        if (!empty($searchTerm)) {
            $users = $userRepository->findByEmail($searchTerm);
        } elseif ($domainFilter !== '所有域名') {
            $users = $userRepository->findByDomain($domainFilter);
        } else {
            $users = $userRepository->findAllSorted();
        }
        
        return $this->render('user/index.html.twig', [
            'users' => $users,
            'domains' => $domains,
            'search_term' => $searchTerm,
            'domain_filter' => $domainFilter,
        ]);
    }
    
    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, DomainRepository $domainRepository): Response
    {
        $domains = $domainRepository->findAll();
        
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $username = $request->request->get('username');
            $password = $request->request->get('password');
            $fullName = $request->request->get('fullName');
            $roles = $request->request->all('roles');
            
            // 检查邮箱是否已存在
            $existingEmail = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingEmail) {
                $this->addFlash('error', '邮箱地址已被使用');
                return $this->redirectToRoute('app_user_index');
            }
            
            $user = new User();
            $user->setUsername($username);
            $user->setEmail($email);
            $user->setFullName($fullName);
            
            // 设置角色，如果没有选择角色则默认为普通用户
            if (in_array('ROLE_ADMIN', $roles)) {
                $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }
            
            // 哈希密码
            $hashedPassword = $passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);
            
            $entityManager->persist($user);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('添加用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('创建新用户 ' . $email);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '用户已成功添加');
            return $this->redirectToRoute('app_user_index');
        }
        
        // 如果请求是GET，我们现在不渲染模板，而是重定向回列表页
        return $this->redirectToRoute('app_user_index');
    }
    
    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $username = $request->request->get('username');
            $password = $request->request->get('password');
            $fullName = $request->request->get('fullName');
            $roles = $request->request->all('roles');
            
            // 检查邮箱是否已存在（除了当前编辑的用户）
            $existingEmail = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existingEmail && $existingEmail->getId() !== $user->getId()) {
                $this->addFlash('error', '邮箱地址已被使用');
                return $this->redirectToRoute('app_user_index');
            }
            
            $oldEmail = $user->getEmail();
            $user->setEmail($email);
            $user->setUsername($username);
            $user->setFullName($fullName);
            
            // 设置角色，如果没有选择角色则默认为普通用户
            if (in_array('ROLE_ADMIN', $roles)) {
                $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }
            
            // 如果提供了新密码，则更新密码
            if (!empty($password)) {
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);
            }
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('编辑用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('编辑用户从 ' . $oldEmail . ' 到 ' . $email);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '用户已成功更新');
            return $this->redirectToRoute('app_user_index');
        }
        
        // 如果请求是GET，我们现在不渲染模板，而是重定向回列表页
        return $this->redirectToRoute('app_user_index');
    }
    
    #[Route('/{id}/delete', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager, DomainRepository $domainRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $email = $user->getEmail();
            $domain = $user->getDomain();
            
            $entityManager->remove($user);
            
            // 更新域名用户计数
            $domainRepository->updateUserCount($domain, -1);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('删除用户');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('删除用户 ' . $email);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '用户已成功删除');
        }
        
        return $this->redirectToRoute('app_user_index');
    }
    
    #[Route('/{id}/reset-password', name: 'app_user_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, User $user, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if ($this->isCsrfTokenValid('reset'.$user->getId(), $request->request->get('_token'))) {
            $newPassword = $request->request->get('password');
            
            // 设置新密码
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('重置密码');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('重置用户密码 ' . $user->getEmail());
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '密码已成功重置');
        }
        
        return $this->redirectToRoute('app_user_index');
    }
} 