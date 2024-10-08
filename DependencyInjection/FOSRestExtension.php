<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\DependencyInjection;

use FOS\RestBundle\ErrorRenderer\SerializerErrorRenderer;
use FOS\RestBundle\EventListener\ResponseStatusCodeListener;
use FOS\RestBundle\View\ViewHandler;
use Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\HostRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\IpsRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\Validator\Constraint;

/**
 * @internal
 */
class FOSRestExtension extends ConfigurableExtension
{
    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration($container->getParameter('kernel.debug'));
    }

    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('view.xml');
        $loader->load('request.xml');
        $loader->load('serializer.xml');

        foreach ($mergedConfig['service'] as $key => $service) {
            if ('validator' === $service && empty($mergedConfig['body_converter']['validate'])) {
                continue;
            }

            if (null !== $service) {
                if ('view_handler' === $key) {
                    $container->setAlias('fos_rest.'.$key, new Alias($service, true));
                } else {
                    $container->setAlias('fos_rest.'.$key, $service);
                }
            }
        }

        $this->loadForm($mergedConfig, $loader, $container);
        $this->loadException($mergedConfig, $loader, $container);
        $this->loadBodyConverter($mergedConfig, $loader, $container);
        $this->loadView($mergedConfig, $loader, $container);

        $this->loadBodyListener($mergedConfig, $loader, $container);
        $this->loadFormatListener($mergedConfig, $loader, $container);
        $this->loadVersioning($mergedConfig, $loader, $container);
        $this->loadParamFetcherListener($mergedConfig, $loader, $container);
        $this->loadAllowedMethodsListener($mergedConfig, $loader, $container);
        $this->loadZoneMatcherListener($mergedConfig, $loader, $container);

        // Needs RequestBodyParamConverter and View Handler loaded.
        $this->loadSerializer($mergedConfig, $container);
    }

    private function loadForm(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['disable_csrf_role'])) {
            $loader->load('forms.xml');

            $definition = $container->getDefinition('fos_rest.form.extension.csrf_disable');
            $definition->replaceArgument(1, $config['disable_csrf_role']);
            $definition->addTag('form.type_extension', ['extended_type' => FormType::class]);
        }
    }

    private function loadAllowedMethodsListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($this->isConfigEnabled($container, $config['allowed_methods_listener'])) {
            if (!empty($config['allowed_methods_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.allowed_methods_listener');
                $service->clearTag('kernel.event_listener');
            }

            $loader->load('allowed_methods_listener.xml');

            $container->getDefinition('fos_rest.allowed_methods_loader')->replaceArgument(1, $config['cache_dir']);
        }
    }

    private function loadBodyListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($this->isConfigEnabled($container, $config['body_listener'])) {
            $loader->load('body_listener.xml');

            $service = $container->getDefinition('fos_rest.body_listener');

            if (!empty($config['body_listener']['service'])) {
                $service->clearTag('kernel.event_listener');
            }

            $service->replaceArgument(1, $config['body_listener']['throw_exception_on_unsupported_content_type']);
            $service->addMethodCall('setDefaultFormat', [$config['body_listener']['default_format']]);

            $container->getDefinition('fos_rest.decoder_provider')->replaceArgument(1, $config['body_listener']['decoders']);

            $decoderServicesMap = [];

            foreach ($config['body_listener']['decoders'] as $id) {
                $decoderServicesMap[$id] = new Reference($id);
            }

            $decodersServiceLocator = ServiceLocatorTagPass::register($container, $decoderServicesMap);
            $container->getDefinition('fos_rest.decoder_provider')->replaceArgument(0, $decodersServiceLocator);

            $arrayNormalizer = $config['body_listener']['array_normalizer'];

            if (null !== $arrayNormalizer['service']) {
                $bodyListener = $container->getDefinition('fos_rest.body_listener');
                $bodyListener->addArgument(new Reference($arrayNormalizer['service']));
                $bodyListener->addArgument($arrayNormalizer['forms']);
            }
        }
    }

    private function loadFormatListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($this->isConfigEnabled($container, $config['format_listener']) && !empty($config['format_listener']['rules'])) {
            $loader->load('format_listener.xml');

            if (!empty($config['format_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.format_listener');
                $service->clearTag('kernel.event_listener');
            }

            $container->setParameter(
                'fos_rest.format_listener.rules',
                $config['format_listener']['rules']
            );
        }
    }

    private function loadVersioning(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($this->isConfigEnabled($container, $config['versioning'])) {
            $loader->load('versioning.xml');

            $versionListener = $container->getDefinition('fos_rest.versioning.listener');
            $versionListener->replaceArgument(1, $config['versioning']['default_version']);

            $resolvers = [];
            if ($this->isConfigEnabled($container, $config['versioning']['resolvers']['query'])) {
                $resolvers['query'] = $container->getDefinition('fos_rest.versioning.query_parameter_resolver');
                $resolvers['query']->replaceArgument(0, $config['versioning']['resolvers']['query']['parameter_name']);
            }
            if ($this->isConfigEnabled($container, $config['versioning']['resolvers']['custom_header'])) {
                $resolvers['custom_header'] = $container->getDefinition('fos_rest.versioning.header_resolver');
                $resolvers['custom_header']->replaceArgument(0, $config['versioning']['resolvers']['custom_header']['header_name']);
            }
            if ($this->isConfigEnabled($container, $config['versioning']['resolvers']['media_type'])) {
                $resolvers['media_type'] = $container->getDefinition('fos_rest.versioning.media_type_resolver');
                $resolvers['media_type']->replaceArgument(0, $config['versioning']['resolvers']['media_type']['regex']);
            }

            $chainResolver = $container->getDefinition('fos_rest.versioning.chain_resolver');
            foreach ($config['versioning']['guessing_order'] as $resolver) {
                if (isset($resolvers[$resolver])) {
                    $chainResolver->addMethodCall('addResolver', [$resolvers[$resolver]]);
                }
            }
        }
    }

    private function loadParamFetcherListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($this->isConfigEnabled($container, $config['param_fetcher_listener'])) {
            if (!class_exists(Constraint::class)) {
                throw new \LogicException('Enabling the fos_rest.param_fetcher_listener option when the Symfony Validator component is not installed is not supported. Try installing the symfony/validator package.');
            }

            $loader->load('param_fetcher_listener.xml');

            if (!empty($config['param_fetcher_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.param_fetcher_listener');
                $service->clearTag('kernel.event_listener');
            }

            if ($config['param_fetcher_listener']['force']) {
                $container->getDefinition('fos_rest.param_fetcher_listener')->replaceArgument(1, true);
            }
        }
    }

    private function loadBodyConverter(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->isConfigEnabled($container, $config['body_converter'])) {
            return;
        }

        if (!class_exists(SensioFrameworkExtraBundle::class)) {
            throw new LogicException('To use the request body param converter, the "sensio/framework-extra-bundle" package is required.');
        }

        $loader->load('request_body_param_converter.xml');

        if (!empty($config['body_converter']['validation_errors_argument'])) {
            $container->getDefinition('fos_rest.converter.request_body')->replaceArgument(4, $config['body_converter']['validation_errors_argument']);
        }
    }

    private function loadView(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['view']['jsonp_handler'])) {
            $handler = new ChildDefinition($config['service']['view_handler']);
            $handler->setPublic(true);

            $jsonpHandler = new Reference('fos_rest.view_handler.jsonp');
            $handler->addMethodCall('registerHandler', ['jsonp', [$jsonpHandler, 'createResponse']]);
            $container->setDefinition('fos_rest.view_handler', $handler);

            $container->getDefinition('fos_rest.view_handler.jsonp')->replaceArgument(0, $config['view']['jsonp_handler']['callback_param']);

            if (empty($config['view']['mime_types']['jsonp'])) {
                $config['view']['mime_types']['jsonp'] = $config['view']['jsonp_handler']['mime_type'];
            }
        }

        if ($this->isConfigEnabled($container, $config['view']['mime_types'])) {
            $loader->load('mime_type_listener.xml');

            if (!empty($config['mime_type_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.mime_type_listener');
                $service->clearTag('kernel.event_listener');
            }

            $container->getDefinition('fos_rest.mime_type_listener')->replaceArgument(0, $config['view']['mime_types']['formats']);
        }

        if ($this->isConfigEnabled($container, $config['view']['view_response_listener'])) {
            $loader->load('view_response_listener.xml');
            $service = $container->getDefinition('fos_rest.view_response_listener');

            if (!empty($config['view_response_listener']['service'])) {
                $service->clearTag('kernel.event_listener');
            }

            $service->replaceArgument(1, $config['view']['view_response_listener']['force']);
        }

        $formats = [];
        foreach ($config['view']['formats'] as $format => $enabled) {
            if ($enabled) {
                $formats[$format] = false;
            }
        }

        if (!is_numeric($config['view']['failed_validation'])) {
            $config['view']['failed_validation'] = constant(sprintf('%s::%s', Response::class, $config['view']['failed_validation']));
        }

        if (!is_numeric($config['view']['empty_content'])) {
            $config['view']['empty_content'] = constant(sprintf('%s::%s', Response::class, $config['view']['empty_content']));
        }

        $defaultViewHandler = $container->getDefinition('fos_rest.view_handler.default');
        $defaultViewHandler->setFactory([ViewHandler::class, 'create']);
        $defaultViewHandler->setArguments([
            new Reference('router'),
            new Reference('fos_rest.serializer'),
            new Reference('request_stack'),
            $formats,
            $config['view']['failed_validation'],
            $config['view']['empty_content'],
            $config['view']['serialize_null'],
        ]);
    }

    private function loadException(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($this->isConfigEnabled($container, $config['exception'])) {
            $loader->load('exception.xml');

            if ($config['exception']['map_exception_codes']) {
                $container->register('fos_rest.exception.response_status_code_listener', ResponseStatusCodeListener::class)
                    ->setArguments([
                        new Reference('fos_rest.exception.codes_map'),
                    ])
                    ->addTag('kernel.event_subscriber');
            }

            $container->getDefinition('fos_rest.exception.codes_map')
                ->replaceArgument(0, $config['exception']['codes']);
            $container->getDefinition('fos_rest.exception.messages_map')
                ->replaceArgument(0, $config['exception']['messages']);

            $container->getDefinition('fos_rest.serializer.flatten_exception_handler')
                ->replaceArgument(2, $config['exception']['debug']);
            $container->getDefinition('fos_rest.serializer.flatten_exception_handler')
                ->replaceArgument(3, 'rfc7807' === $config['exception']['flatten_exception_format']);
            $container->getDefinition('fos_rest.serializer.flatten_exception_normalizer')
                ->replaceArgument(2, $config['exception']['debug']);
            $container->getDefinition('fos_rest.serializer.flatten_exception_normalizer')
                ->replaceArgument(3, 'rfc7807' === $config['exception']['flatten_exception_format']);

            if ($config['exception']['serializer_error_renderer']) {
                $format = new Definition();
                $format->setFactory([SerializerErrorRenderer::class, 'getPreferredFormat']);
                $format->setArguments([
                    new Reference('request_stack'),
                ]);
                $debug = new Definition();
                $debug->setFactory([SerializerErrorRenderer::class, 'isDebug']);
                $debug->setArguments([
                    new Reference('request_stack'),
                    '%kernel.debug%',
                ]);
                $container->register('fos_rest.error_renderer.serializer', SerializerErrorRenderer::class)
                    ->setArguments([
                        new Reference('fos_rest.serializer'),
                        $format,
                        new Reference('error_renderer.html', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                        $debug,
                    ]);
                $container->setAlias('error_renderer', 'fos_rest.error_renderer.serializer');
            }
        }
    }

    private function loadSerializer(array $config, ContainerBuilder $container): void
    {
        $bodyConverter = $container->hasDefinition('fos_rest.converter.request_body') ? $container->getDefinition('fos_rest.converter.request_body') : null;
        $viewHandler = $container->getDefinition('fos_rest.view_handler.default');
        $options = [];

        if (!empty($config['serializer']['version'])) {
            if ($bodyConverter) {
                $bodyConverter->replaceArgument(2, $config['serializer']['version']);
            }
            $options['exclusionStrategyVersion'] = $config['serializer']['version'];
        }

        if (!empty($config['serializer']['groups'])) {
            if ($bodyConverter) {
                $bodyConverter->replaceArgument(1, $config['serializer']['groups']);
            }
            $options['exclusionStrategyGroups'] = $config['serializer']['groups'];
        }

        $options['serializeNullStrategy'] = $config['serializer']['serialize_null'];
        $viewHandler->addArgument($options);
    }

    private function loadZoneMatcherListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['zone'])) {
            $loader->load('zone_matcher_listener.xml');
            $zoneMatcherListener = $container->getDefinition('fos_rest.zone_matcher_listener');

            foreach ($config['zone'] as $zone) {
                $matcher = $this->createZoneRequestMatcher(
                    $container,
                    $zone['path'],
                    $zone['host'],
                    $zone['methods'],
                    $zone['ips']
                );

                $zoneMatcherListener->addMethodCall('addRequestMatcher', [$matcher]);
            }
        }
    }

    private function createZoneRequestMatcher(ContainerBuilder $container, ?string $path = null, ?string $host = null, array $methods = [], ?array $ips = null): Reference
    {
        if ($methods) {
            $methods = array_map('strtoupper', (array) $methods);
        }

        $serialized = serialize([$path, $host, $methods, $ips]);
        $id = 'fos_rest.zone_request_matcher.'.md5($serialized).sha1($serialized);

        // only add arguments that are necessary
        $arguments = [$path, $host, $methods, $ips];
        while (count($arguments) > 0 && !end($arguments)) {
            array_pop($arguments);
        }

        if (!class_exists(ChainRequestMatcher::class)) {
            $container->setDefinition($id, new Definition(RequestMatcher::class, $arguments));
        } else {
            $matchers = [];
            if (!is_null($path)) {
                $matchers[] = new Definition(PathRequestMatcher::class, [$path]);
            }
            if (!is_null($host)) {
                $matchers[] = new Definition(HostRequestMatcher::class, [$host]);
            }
            if (!is_null($methods)) {
                $matchers[] = new Definition(MethodRequestMatcher::class, [$methods]);
            }
            if (!is_null($ips)) {
                $matchers[] = new Definition(IpsRequestMatcher::class, [$ips]);
            }
            $container
                ->setDefinition($id, new Definition(ChainRequestMatcher::class))
                ->setArguments([$matchers]);
        }

        return new Reference($id);
    }
}
