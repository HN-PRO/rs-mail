<?php

namespace App\Controller;

use App\Entity\Mail;
use App\Repository\MailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/mail')]
class MailController extends AbstractController
{
    #[Route('/', name: 'app_mail_dashboard')]
    public function dashboard(MailRepository $mailRepository): Response
    {
        $pendingCount = count($mailRepository->findByStatus('pending'));
        $sentCount = count($mailRepository->findByStatus('sent'));
        $failedCount = count($mailRepository->findByStatus('failed'));
        
        $recentMails = $mailRepository->findAllSorted();
        
        return $this->render('mail/dashboard.html.twig', [
            'pending_count' => $pendingCount,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'recent_mails' => array_slice($recentMails, 0, 5),
        ]);
    }

    #[Route('/list', name: 'app_mail_list')]
    public function index(Request $request, MailRepository $mailRepository): Response
    {
        $status = $request->query->get('status');
        $sender = $request->query->get('sender');
        $recipient = $request->query->get('recipient');
        $subject = $request->query->get('subject');
        
        if ($status) {
            $mails = $mailRepository->findByStatus($status);
        } elseif ($sender) {
            $mails = $mailRepository->findBySender($sender);
        } elseif ($recipient) {
            $mails = $mailRepository->findByRecipient($recipient);
        } elseif ($subject) {
            $mails = $mailRepository->findBySubject($subject);
        } else {
            $mails = $mailRepository->findAllSorted();
        }
        
        return $this->render('mail/index.html.twig', [
            'mails' => $mails,
            'status' => $status,
            'sender' => $sender,
            'recipient' => $recipient,
            'subject' => $subject,
        ]);
    }
    
    #[Route('/new', name: 'app_mail_new')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $mail = new Mail();
            $mail->setSender($request->request->get('sender'));
            $mail->setRecipient($request->request->get('recipient'));
            $mail->setSubject($request->request->get('subject'));
            $mail->setContent($request->request->get('content'));
            
            $entityManager->persist($mail);
            $entityManager->flush();
            
            $this->addFlash('success', '邮件已成功创建');
            
            return $this->redirectToRoute('app_mail_list');
        }
        
        return $this->render('mail/new.html.twig');
    }
    
    #[Route('/{id}', name: 'app_mail_show', methods: ['GET'])]
    public function show(Mail $mail): Response
    {
        return $this->render('mail/show.html.twig', [
            'mail' => $mail,
        ]);
    }
    
    #[Route('/{id}/edit', name: 'app_mail_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Mail $mail, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $mail->setSender($request->request->get('sender'));
            $mail->setRecipient($request->request->get('recipient'));
            $mail->setSubject($request->request->get('subject'));
            $mail->setContent($request->request->get('content'));
            $mail->setStatus($request->request->get('status'));
            
            $entityManager->flush();
            
            $this->addFlash('success', '邮件已成功更新');
            
            return $this->redirectToRoute('app_mail_list');
        }
        
        return $this->render('mail/edit.html.twig', [
            'mail' => $mail,
        ]);
    }
    
    #[Route('/{id}/delete', name: 'app_mail_delete', methods: ['POST'])]
    public function delete(Request $request, Mail $mail, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$mail->getId(), $request->request->get('_token'))) {
            $entityManager->remove($mail);
            $entityManager->flush();
            
            $this->addFlash('success', '邮件已成功删除');
        }
        
        return $this->redirectToRoute('app_mail_list');
    }
} 