<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class BatteryincludedBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.yaml');

        // the demo shop renders twig templates
        if (isset($builder->getParameter('kernel.bundles')['TwigBundle'])) {
            $container->import(__DIR__ . '/../config/services_demo.yaml');
        }
    }
}
