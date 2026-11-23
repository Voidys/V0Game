<?php

namespace App\Controller\Login;

use App\Content\ContentPages;
use App\Content\HtmlContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules page (/rules): the text is a CMS page (admin: pages), printed as sanitized HTML.
 */
final class RulesController extends AbstractController
{
    /**
     * Renders the rules CMS page in the request locale as sanitized HTML.
     */
    #[Route('/rules', name: 'login_rules', methods: ['GET'])]
    #[\App\Routing\PublicCache(300)]
    public function __invoke(Request $request, ContentPages $pages, HtmlContent $html, TranslatorInterface $translator): Response
    {
        $page = $pages->find('rules', $request->getLocale());

        return $this->render('login/rules.html.twig', [
            'page_title' => $page['title'],
            'rules' => new \Twig\Markup($page['content'], 'UTF-8'), // sanitized by ContentPages
            'rules_empty' => $html->isEmpty($page['content']),
            'rules_empty_text' => $translator->trans('rulesEmpty', [], 'public', $request->getLocale()),
        ]);
    }
}
