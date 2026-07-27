<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\ApiStudioBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Twig\Loader\FilesystemLoader;

use function dirname;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleViewsPathToNativeLoader(): void
    {
        $loader    = new Definition(FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', $loader);

        (new TwigPathsPass())->process($container);

        $calls = $loader->getMethodCalls();
        self::assertSame('addPath', $calls[0][0]);
        self::assertSame('NowoApiStudioBundle', $calls[0][1][1]);
    }

    public function testPrependsApplicationOverridePathWhenPresent(): void
    {
        $projectDir   = sys_get_temp_dir() . '/api-studio-twig-' . uniqid('', true);
        $overridePath = $projectDir . '/templates/bundles/NowoApiStudioBundle';
        mkdir($overridePath, 0777, true);

        $loader    = new Definition(FilesystemLoader::class);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setDefinition('twig.loader.native', $loader);

        (new TwigPathsPass())->process($container);

        self::assertSame('prependPath', $loader->getMethodCalls()[0][0]);

        rmdir($overridePath);
        rmdir(dirname($overridePath));
        rmdir(dirname($overridePath, 2));
        rmdir($projectDir);
    }

    public function testNoOpWhenTwigLoaderMissing(): void
    {
        $container = new ContainerBuilder();
        (new TwigPathsPass())->process($container);
        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }
}
