<?php

namespace App\Controller;

use App\Entity\Mail;
use App\Repository\MailRepository;
use App\Service\ImapService;
use App\Service\PasswordHashService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ImapTestController extends AbstractController
{
    private $imapService;
    private $mailRepository;
    private $passwordHashService;

    public function __construct(
        ImapService $imapService,
        MailRepository $mailRepository,
        PasswordHashService $passwordHashService
    ) {
        $this->imapService = $imapService;
        $this->mailRepository = $mailRepository;
        $this->passwordHashService = $passwordHashService;
    }

    #[Route('/admin/imap-test', name: 'app_imap_test')]
    public function testImapConnection(Request $request): Response
    {
        $mailId = $request->query->get('mail_id');
        $password = $request->query->get('password');
        $result = null;
        $error = null;
        
        // 查找指定的邮箱用户
        $mail = null;
        if ($mailId) {
            $mail = $this->mailRepository->find($mailId);
        }
        
        // 尝试连接
        if ($mail && $password) {
            try {
                // 使用用户提供的原始密码进行IMAP连接测试
                $result = $this->imapService->testConnection($mail, $password);
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }
        
        return $this->render('imap/test.html.twig', [
            'mail' => $mail,
            'result' => $result,
            'error' => $error,
            'mail_users' => $this->mailRepository->findAll(),
        ]);
    }
} 