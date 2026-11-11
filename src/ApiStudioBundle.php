<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioEntityManagerPass;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\ApiStudioSecurityPass;
use Nowo\ApiStudioBundle\DependencyInjection\Compiler\TwigPathsPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
        $container->addCompilerPass(new ApiStudioSecurityPass());
        $container->addCompilerPass(new TwigPathsPass());
        // nowo_api_studio.entity_manager (1.12): the entities and the tenant filter on that manager only.
        $container->addCompilerPass(new ApiStudioEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new ApiStudioExtension();
        }

        return $this->extension;
    }
}
