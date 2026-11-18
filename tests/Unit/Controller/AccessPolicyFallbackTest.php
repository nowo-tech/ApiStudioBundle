<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use LogicException;
use Nowo\ApiStudioBundle\Controller\AuthorizesTrait;
use Nowo\ApiStudioBundle\EventSubscriber\ApiStudioAccessSubscriber;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

use function array_keys;
use function array_pop;
use function basename;
use function class_exists;
use function class_uses;
use function file_get_contents;
use function glob;
use function in_array;
use function is_string;
use function str_contains;

/**
 * 1.16 (audit SY-L2): the authorization and the 403 / 404 policy fail closed — never an everything-granted default —
 * and every controller that uses them subscribes them.
 */
final class AccessPolicyFallbackTest extends TestCase
{
    public function testWithoutTheServicesTheTraitThrows(): void
    {
        $controller = new class extends AbstractController {
            use AuthorizesTrait;

            public function policy(): ApiStudioAccessPolicy
            {
                return $this->accessPolicy();
            }

            public function decisions(): ApiStudioAuthorization
            {
                return $this->authorization();
            }
        };
        $controller->setContainer(new Container());

        foreach (['policy' => ApiStudioAccessPolicy::class, 'decisions' => ApiStudioAuthorization::class] as $method => $service) {
            try {
                $controller->{$method}();
                self::fail($method . ' must fail closed.');
            } catch (LogicException $e) {
                self::assertStringContainsString('must subscribe ' . $service, $e->getMessage());
            }
        }
    }

    public function testEveryControllerUsingTheTraitSubscribesWhatItCalls(): void
    {
        $checked = 0;
        foreach (glob(__DIR__ . '/../../../src/Controller/*.php') ?: [] as $file) {
            $class = 'Nowo\\ApiStudioBundle\\Controller\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if (!in_array(AuthorizesTrait::class, self::traitsOf($class), true)) {
                continue;
            }
            self::assertTrue($reflection->hasMethod('getSubscribedServices'), $class);
            /** @var array<string, string> $services */
            $services = $class::getSubscribedServices();
            $source   = (string) file_get_contents($file) . self::traitSources($class);
            self::assertContains(ApiStudioAuthorization::class, array_keys($services), $class . ' subscribes ApiStudioAuthorization');
            if (str_contains($source, '$this->accessPolicy()')) {
                self::assertContains(ApiStudioAccessPolicy::class, array_keys($services), $class . ' subscribes ApiStudioAccessPolicy');
            }
            ++$checked;
        }
        self::assertGreaterThanOrEqual(10, $checked, 'the controllers were found');
    }

    public function testTheStaticRouteHelperStillMatchesTheBundlePrefix(): void
    {
        self::assertTrue(ApiStudioAccessSubscriber::isApiStudioRoute(new Request(attributes: ['_route' => 'nowo_api_studio_dashboard'])));
        self::assertFalse(ApiStudioAccessSubscriber::isApiStudioRoute(new Request(attributes: ['_route' => 'app_share'])));
    }

    /**
     * @param class-string $class
     *
     * @return list<class-string>
     */
    private static function traitsOf(string $class): array
    {
        $traits = [];
        $queue  = [$class];
        while ($queue !== []) {
            $current = (string) array_pop($queue);
            foreach (class_uses($current) ?: [] as $trait) {
                if (!in_array($trait, $traits, true)) {
                    $traits[] = $trait;
                    $queue[]  = $trait;
                }
            }
        }

        return $traits;
    }

    /**
     * @param class-string $class
     */
    private static function traitSources(string $class): string
    {
        $source = '';
        foreach (self::traitsOf($class) as $trait) {
            $file = (new ReflectionClass($trait))->getFileName();
            if (is_string($file) && $trait !== AuthorizesTrait::class) {
                $source .= (string) file_get_contents($file);
            }
        }

        return $source;
    }
}
