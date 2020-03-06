<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\Request;

/**
 * Helper interface to validate query parameters from the active request.
 *
 * @author Alexander <iam.asm89@gmail.com>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
interface ParamFetcherInterface
{
    /**
     * @param callable $controller
     */
    public function setController($controller);

    /**
     * @param string $name
     * @param bool   $strict Whether a requirement mismatch should cause an exception
     */
    public function get($name, $strict = null);

    /**
     * @param bool $strict Whether a requirement mismatch should cause an exception
     *
     * @return array
     */
    public function all($strict = false);
}
