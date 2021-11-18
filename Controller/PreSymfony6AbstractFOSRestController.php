<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\Controller;

use FOS\RestBundle\View\ViewHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * @internal
 */
abstract class PreSymfony6AbstractFOSRestController extends AbstractController
{
    use ControllerTrait;

    /**
     * @return array
     */
    public static function getSubscribedServices()
    {
        $subscribedServices = parent::getSubscribedServices();
        $subscribedServices['fos_rest.view_handler'] = ViewHandlerInterface::class;

        return $subscribedServices;
    }
}
