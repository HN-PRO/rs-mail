<?php

namespace App\Controller;

use App\Entity\Domain;
use App\Entity\SystemLog;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/domain')]
class DomainController extends AbstractController
{
    #[Route('/', name: 'app_domain_index')]
    public function index(Request $request, DomainRepository $domainRepository): Response
    {
        $searchTerm = $request->query->get('search', '');
        
        if (!empty($searchTerm)) {
            $domains = $domainRepository->findByName($searchTerm);
        } else {
            $domains = $domainRepository->findAllSorted();
        }
        
        return $this->render('domain/index.html.twig', [
            'domains' => $domains,
            'search_term' => $searchTerm,
        ]);
    }
    
    #[Route('/new', name: 'app_domain_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $domainName = $request->request->get('domain');
            
            // 检查域名是否已存在
            $existingDomain = $entityManager->getRepository(Domain::class)->findOneBy(['domain' => $domainName]);
            if ($existingDomain) {
                $this->addFlash('error', '域名已存在');
                return $this->redirectToRoute('app_domain_index');
            }
            
            $domain = new Domain();
            $domain->setDomain($domainName);
            
            $entityManager->persist($domain);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('添加域名');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('添加新域名 ' . $domainName);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '域名已成功添加');
            return $this->redirectToRoute('app_domain_index');
        }
        
        // 如果请求是GET，我们现在不渲染模板，而是重定向回列表页
        return $this->redirectToRoute('app_domain_index');
    }
    
    #[Route('/{id}/edit', name: 'app_domain_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Domain $domain, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $domainName = $request->request->get('domain');
            
            // 检查域名是否已存在（除了当前编辑的域名）
            $existingDomain = $entityManager->getRepository(Domain::class)->findOneBy(['domain' => $domainName]);
            if ($existingDomain && $existingDomain->getId() !== $domain->getId()) {
                $this->addFlash('error', '域名已存在');
                return $this->redirectToRoute('app_domain_index');
            }
            
            $oldName = $domain->getDomain();
            $domain->setDomain($domainName);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('编辑域名');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('编辑域名从 ' . $oldName . ' 到 ' . $domainName);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '域名已成功更新');
            return $this->redirectToRoute('app_domain_index');
        }
        
        // 如果请求是GET，我们现在不渲染模板，而是重定向回列表页
        return $this->redirectToRoute('app_domain_index');
    }
    
    #[Route('/{id}/delete', name: 'app_domain_delete', methods: ['POST'])]
    public function delete(Request $request, Domain $domain, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$domain->getId(), $request->request->get('_token'))) {
            $domainName = $domain->getDomain();
            
            $entityManager->remove($domain);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('删除域名');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('删除域名 ' . $domainName);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '域名已成功删除');
        }
        
        return $this->redirectToRoute('app_domain_index');
    }
} 