<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        
        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // 获取登录错误信息
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // 上次输入的用户名
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    // #[Route('/register', name: 'app_register')]
    // public function register(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    // {
    //     if ($this->getUser()) {
    //         return $this->redirectToRoute('app_dashboard');
    //     }

    //     if ($request->isMethod('POST')) {
    //         $username = $request->request->get('username');
    //         $email = $request->request->get('email');
    //         $domain = $request->request->get('domain');
    //         $password = $request->request->get('password');
    //         $confirmPassword = $request->request->get('confirm_password');

    //         // 检查用户名是否已存在
    //         $existingUser = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
    //         if ($existingUser) {
    //             $this->addFlash('error', '用户名已存在');
    //             return $this->redirectToRoute('app_register');
    //         }

    //         // 检查邮箱是否已存在
    //         $existingEmail = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    //         if ($existingEmail) {
    //             $this->addFlash('error', '邮箱地址已被使用');
    //             return $this->redirectToRoute('app_register');
    //         }

    //         // 检查密码是否匹配
    //         if ($password !== $confirmPassword) {
    //             $this->addFlash('error', '两次输入的密码不一致');
    //             return $this->redirectToRoute('app_register');
    //         }

    //         // 创建新用户
    //         $user = new User();
    //         $user->setUsername($username);
    //         $user->setEmail($email);
    //         $user->setDomain($domain);
    //         $user->setRoles(['ROLE_ADMIN']);

    //         // 哈希密码
    //         $hashedPassword = $passwordHasher->hashPassword($user, $password);
    //         $user->setPassword($hashedPassword);

    //         $entityManager->persist($user);
    //         $entityManager->flush();

    //         $this->addFlash('success', '注册成功，请登录');
    //         return $this->redirectToRoute('app_login');
    //     }

    //     return $this->render('security/register.html.twig');
    // }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // 此方法可以为空，由 security.yaml 中的 logout 配置处理
        throw new \LogicException('这个方法永远不会被执行，因为会被 logout 配置拦截。');
    }
} 