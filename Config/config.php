<?php

declare(strict_types=1);

return [
    'name'        => 'Builder',
    'description' => 'GrapesJS Builder with MJML support for Mautic',
    'version'     => '1.0.0',
    'author'      => 'Webmecanik',
    'routes'      => [
        'main'   => [
	        'grapesjsbuilder_builder' => [
                'path'       => '/grapesjsbuilder/{objectType}/{objectId}',
                'controller' => 'GrapesJsBuilderBundle:GrapesJs:builder',
            ],
        ],
        'public' => [],
        'api'    => [],
    ],
    'menu'        => [],
    'services'    => [
        'other'        => [
            // Provides access to configured API keys, settings, field mapping, etc
            'grapesjsbuilder.config'            => [
                'class'     => \MauticPlugin\GrapesJsBuilderBundle\Integration\Config::class,
                'arguments' => [
                    'mautic.integrations.helper',
                ],
            ],
        ],
        'sync'         => [],
        'integrations' => [
            // Basic definitions with name, display name and icon
            'grapesjsbuilder.integration'               => [
                'class' => \MauticPlugin\GrapesJsBuilderBundle\Integration\GrapesJsBuilderIntegration::class,
                'tags'  => [
                    'mautic.integration',
                    'mautic.basic_integration',
                ],
            ],
            // Provides the form types to use for the configuration UI
            'grapesjsbuilder.integration.configuration' => [
                'class'     => \MauticPlugin\GrapesJsBuilderBundle\Integration\Support\ConfigSupport::class,
                'tags'      => [
                    'mautic.config_integration',
                ],
            ],
        ],
	    'models'  => [
            'grapesjsbuilder.model' => [
                'class'     => \MauticPlugin\GrapesJsBuilderBundle\Model\GrapesJsBuilderModel::class,
                'arguments' => [
                    'request_stack',
                ],
            ],
        ],
	    'helpers' => [],
        'events'  => [
            'grapesjsbuilder.event.assets.subscriber'=> [
                'class'=> \MauticPlugin\GrapesJsBuilderBundle\EventSubscriber\AssetsSubscriber::class,
                'arguments' => [
                    'grapesjsbuilder.config',
                ],
            ],
            'grapesjsbuilder.event.email.subscriber'=> [
                'class'=> \MauticPlugin\GrapesJsBuilderBundle\EventSubscriber\EmailSubscriber::class,
                'arguments' => [
                    'grapesjsbuilder.config',
                    'grapesjsbuilder.model',
                ],
            ],
            'grapesjsbuilder.event.content.subscriber' => [
                'class'     => \MauticPlugin\GrapesJsBuilderBundle\EventSubscriber\InjectCustomContentSubscriber::class,
                'arguments' => [
                    'grapesjsbuilder.config',
                    'grapesjsbuilder.model',
                    'mautic.helper.templating',
                    'request_stack',
                ],
            ],
        ],
    ],
];