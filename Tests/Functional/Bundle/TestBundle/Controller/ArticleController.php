<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\Tests\Functional\Bundle\TestBundle\Controller;

use FOS\RestBundle\Controller\AbstractFOSRestController;
use Symfony\Component\HttpFoundation\Request;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\View;

class ArticleController extends AbstractFOSRestController
{
    /**
     * @Post("/articles.{_format}", name="post_articles")
     *
     * @View()
     */
    #[Post(path: '/articles.{_format}', name: 'post_articles')]
    #[View]
    public function cpostAction(Request $request)
    {
        return $this->routeRedirectView('test_redirect_endpoint', ['name' => $request->request->get('name')]);
    }

    /**
     * @Get("/articles.{_format}", name="get_articles", defaults={"_format": "html"})
     *
     * @View()
     */
    #[Get(path: '/articles.{_format}', name: 'get_articles', defaults: ['_format' => 'html'])]
    #[View]
    public function cgetAction()
    {
        return $this->view();
    }

    /**
     * @Get("/articles/{id}.{_format}", name="get_article", defaults={"_format": "html"})
     *
     * @View()
     */
    #[Get(path: '/articles/{id}.{_format}', name: 'get_article', defaults: ['_format' => 'html'])]
    #[View]
    public function cgetSingleAction(int $id)
    {
        return ['id' => $id];
    }
}
