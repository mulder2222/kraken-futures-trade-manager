<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $configDir = $this->getProjectDir().'/config';

        $loader->load($configDir.'/packages/*.yaml', 'glob');
        $loader->load($configDir.'/packages/'.$this->environment.'/*.yaml', 'glob');
        $loader->load($configDir.'/services.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
