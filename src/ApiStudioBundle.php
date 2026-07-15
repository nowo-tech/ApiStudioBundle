<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\TwigPathsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * API catalog, documentation, environments, and request testing for Symfony applications.
 */
final class ApiStudioBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoApiStudioBundle';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new ApiStudioExtension();
        }

        return $this->extension;
    }
}
