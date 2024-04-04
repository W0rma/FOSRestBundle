<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (class_exists(\Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle::class)) {
    $loader->load(__DIR__.'/../config/sensio_framework_extra.php');

    $config = [
        'request' => [
            'converters' => true,
        ],
    ];

    $container->loadFromExtension('sensio_framework_extra', $config);
}
