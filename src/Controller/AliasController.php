<?php

namespace App\Controller;

use App\Entity\Alias;
use App\Entity\SystemLog;
use App\Repository\AliasRepository;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/admin/alias')]
class AliasController extends AbstractController
{
    #[Route('/', name: 'app_alias_index')]
    public function index(Request $request, AliasRepository $aliasRepository, DomainRepository $domainRepository): Response
    {
        $searchTerm = $request->query->get('search', '');
        $domainFilter = $request->query->get('domain', '所有域名');
        
        $domains = $domainRepository->findAll();
        
        if (!empty($searchTerm)) {
            $aliases = $aliasRepository->findBySourceAddress($searchTerm);
        } elseif ($domainFilter !== '所有域名') {
            $aliases = $aliasRepository->findByDomain($domainFilter);
        } else {
            $aliases = $aliasRepository->findAllSorted();
        }
        
        return $this->render('alias/index.html.twig', [
            'aliases' => $aliases,
            'domains' => $domains,
            'search_term' => $searchTerm,
            'domain_filter' => $domainFilter,
        ]);
    }
    
    #[Route('/new', name: 'app_alias_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, DomainRepository $domainRepository): Response
    {
        $domains = $domainRepository->findAll();
        
        if ($request->isMethod('POST')) {
            $source = $request->request->get('source');
            $destination = $request->request->get('destination');
            $domainId = $request->request->get('domain_id');
            
            // 获取域名实体
            $domain = $domainRepository->find($domainId);
            if (!$domain) {
                $this->addFlash('error', '所选域名不存在');
                return $this->redirectToRoute('app_alias_index');
            }
            
            // 检查别名是否已存在
            $existingAlias = $entityManager->getRepository(Alias::class)->findOneBy(['source' => $source]);
            if ($existingAlias) {
                $this->addFlash('error', '源地址别名已存在');
                return $this->redirectToRoute('app_alias_index');
            }
            
            $alias = new Alias();
            $alias->setSource($source);
            $alias->setDestination($destination);
            $alias->setDomain($domain);
            
            $entityManager->persist($alias);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('添加别名');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('创建别名 ' . $source . ' -> ' . $destination);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '别名已成功添加');
            return $this->redirectToRoute('app_alias_index');
        }
        
        // 如果请求是GET，我们现在不渲染模板，而是重定向回列表页
        return $this->redirectToRoute('app_alias_index');
    }
    
    #[Route('/{id}/edit', name: 'app_alias_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Alias $alias, EntityManagerInterface $entityManager, DomainRepository $domainRepository): Response
    {
        $domains = $domainRepository->findAll();
        
        if ($request->isMethod('POST')) {
            $source = $request->request->get('source');
            $destination = $request->request->get('destination');
            $domainId = $request->request->get('domain_id');
            $status = $request->request->get('status');
            
            // 获取域名实体
            $domain = $domainRepository->find($domainId);
            if (!$domain) {
                $this->addFlash('error', '所选域名不存在');
                return $this->redirectToRoute('app_alias_index');
            }
            
            // 检查别名是否已存在（除了当前编辑的别名）
            $existingAlias = $entityManager->getRepository(Alias::class)->findOneBy(['source' => $source]);
            if ($existingAlias && $existingAlias->getId() !== $alias->getId()) {
                $this->addFlash('error', '源地址别名已存在');
                return $this->redirectToRoute('app_alias_index');
            }
            
            $oldSource = $alias->getSource();
            $oldDestination = $alias->getDestination();
            
            $alias->setSource($source);
            $alias->setDestination($destination);
            $alias->setDomain($domain);
            $alias->setStatus($status);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('编辑别名');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('编辑别名从 ' . $oldSource . ' -> ' . $oldDestination . ' 到 ' . $source . ' -> ' . $destination);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '别名已成功更新');
            return $this->redirectToRoute('app_alias_index');
        }
        
        // 如果请求是GET，我们现在不渲染模板，而是重定向回列表页
        return $this->redirectToRoute('app_alias_index', ['domains' => $domains, 'alias' => $alias]);
    }
    
    #[Route('/{id}/delete', name: 'app_alias_delete', methods: ['POST'])]
    public function delete(Request $request, Alias $alias, EntityManagerInterface $entityManager, DomainRepository $domainRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$alias->getId(), $request->request->get('_token'))) {
            $sourceAddress = $alias->getSourceAddress();
            $targetAddress = $alias->getTargetAddress();
            
            // 获取域名并更新别名计数
            $sourceDomain = substr(strrchr($sourceAddress, '@'), 1);
            $domainRepository->updateAliasCount($sourceDomain, -1);
            
            $entityManager->remove($alias);
            
            // 记录系统日志
            $log = new SystemLog();
            $log->setOperatorName($this->getUser()->getUserIdentifier());
            $log->setOperationType('删除别名');
            $log->setIpAddress($request->getClientIp());
            $log->setDetails('删除别名 ' . $sourceAddress . ' -> ' . $targetAddress);
            
            $entityManager->persist($log);
            $entityManager->flush();
            
            $this->addFlash('success', '别名已成功删除');
        }
        
        return $this->redirectToRoute('app_alias_index');
    }
} 