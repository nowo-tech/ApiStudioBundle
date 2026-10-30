<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function method_exists;
use function str_starts_with;
use function substr;

/**
 * Every setter of the Doctrine entities returns the entity and the matching getter / isser reads the value back.
 */
final class EntityAccessorsTest extends TestCase
{
    /**
     * @return iterable<string, array{object}>
     */
    public static function entities(): iterable
    {
        yield 'workspace' => [new ApiWorkspace('Shop', 'shop')];
        yield 'service' => [new ApiService('Orders', 'orders')];
        yield 'endpoint' => [new ApiEndpoint('List', 'list')];
        yield 'translation' => [new ApiEndpointTranslation('en')];
        yield 'environment' => [new ApiEnvironment('Dev', 'dev')];
        yield 'variable' => [new ApiEnvironmentVariable('base_url', 'https://example.test')];
        yield 'request example' => [new ApiRequestExample('Default')];
        yield 'response example' => [new ApiResponseExample('OK', 201)];
        yield 'history' => [new ApiRequestHistory()];
    }

    #[DataProvider('entities')]
    public function testSettersRoundTrip(object $entity): void
    {
        $checked = 0;
        foreach ((new ReflectionClass($entity))->getMethods(ReflectionMethod::IS_PUBLIC) as $setter) {
            if (!str_starts_with($setter->getName(), 'set') || $setter->getNumberOfParameters() !== 1) {
                continue;
            }

            $type = $setter->getParameters()[0]->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            $value = self::sample($type->getName(), $setter->getName());

            $property = substr($setter->getName(), 3);
            $getter   = 'get' . $property;
            if (!method_exists($entity, $getter)) {
                $getter = 'is' . $property;
            }
            if (!method_exists($entity, $getter)) {
                $getter = 'is' . substr($property, 2); // setIsDefault() / isDefault()
            }

            self::assertSame($entity, $setter->invoke($entity, $value), $setter->getName() . ' is fluent');
            self::assertSame($value, $entity->{$getter}(), $getter . ' returns the value set by ' . $setter->getName());

            if ($type->allowsNull()) {
                $setter->invoke($entity, null);
                self::assertNull($entity->{$getter}());
            }
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
        self::assertNull((new ReflectionMethod($entity, 'getId'))->invoke($entity), 'unsaved entities have no id');
    }

    public function testTimestampsAndDefaults(): void
    {
        $workspace = new ApiWorkspace('Shop', 'shop');
        self::assertLessThanOrEqual(new DateTimeImmutable(), $workspace->getCreatedAt());
        self::assertGreaterThanOrEqual($workspace->getCreatedAt(), $workspace->getUpdatedAt());
        self::assertTrue($workspace->isEnabled());

        $service = new ApiService('Orders', 'orders');
        self::assertSame('{{base_url}}', $service->getBaseUrl());
        self::assertSame(ApiProtocol::Rest, $service->getProtocol());
        self::assertSame(AuthType::None, $service->getAuthType());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $service->getCreatedAt());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $service->getUpdatedAt());

        $endpoint = new ApiEndpoint('List', 'list');
        self::assertSame(HttpMethod::Get, $endpoint->getMethod());
        self::assertSame('/', $endpoint->getPath());
        self::assertSame('application/json', $endpoint->getContentType());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $endpoint->getCreatedAt());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $endpoint->getUpdatedAt());

        $environment = new ApiEnvironment('Dev', 'dev');
        self::assertFalse($environment->isDefault());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $environment->getCreatedAt());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $environment->getUpdatedAt());

        $history = new ApiRequestHistory();
        self::assertLessThanOrEqual(new DateTimeImmutable(), $history->getExecutedAt());
        self::assertSame(201, (new ApiResponseExample('Created', 201))->getStatusCode());
        self::assertSame(200, (new ApiResponseExample('OK'))->getStatusCode());
    }

    public function testVariableKeyIsNormalized(): void
    {
        $variable = new ApiEnvironmentVariable('{{ token }}');
        self::assertSame('', $variable->getValue());
        self::assertSame('token', $variable->getVariableKey());

        $variable->setVariableKey('{{other}}');
        self::assertSame('other', $variable->getVariableKey());
    }

    public function testWorkspaceServicesAndEnvironments(): void
    {
        $workspace = new ApiWorkspace('Shop', 'shop');
        $service   = new ApiService('Orders', 'orders');
        $env       = new ApiEnvironment('Dev', 'dev');

        $workspace->addService($service)->addService($service);
        $workspace->addEnvironment($env)->addEnvironment($env);
        self::assertCount(1, $workspace->getServices());
        self::assertCount(1, $workspace->getEnvironments());
        self::assertSame($workspace, $service->getWorkspace());
        self::assertSame($workspace, $env->getWorkspace());

        $workspace->removeService($service)->removeEnvironment($env);
        self::assertCount(0, $workspace->getServices());
        self::assertCount(0, $workspace->getEnvironments());
        self::assertNull($service->getWorkspace());
        self::assertNull($env->getWorkspace());

        // Removing an element that belongs elsewhere leaves its owner alone.
        $other = new ApiWorkspace('Other', 'other');
        $other->addService($service);
        $workspace->removeService($service);
        self::assertSame($other, $service->getWorkspace());
    }

    public function testServiceEndpoints(): void
    {
        $service  = new ApiService('Orders', 'orders');
        $endpoint = new ApiEndpoint('List', 'list');

        $service->addEndpoint($endpoint)->addEndpoint($endpoint);
        self::assertCount(1, $service->getEndpoints());
        self::assertSame($service, $endpoint->getService());

        $service->removeEndpoint($endpoint);
        self::assertCount(0, $service->getEndpoints());
        self::assertNull($endpoint->getService());
    }

    public function testEndpointChildren(): void
    {
        $endpoint    = new ApiEndpoint('List', 'list');
        $translation = (new ApiEndpointTranslation('es'))->setTitle('Listar');
        $request     = new ApiRequestExample('Default');
        $response    = new ApiResponseExample('OK');

        $endpoint->addTranslation($translation)->addTranslation($translation);
        $endpoint->addRequestExample($request)->addRequestExample($request);
        $endpoint->addResponseExample($response)->addResponseExample($response);

        self::assertCount(1, $endpoint->getTranslations());
        self::assertSame($translation, $endpoint->getTranslation('es'));
        self::assertNull($endpoint->getTranslation('fr'));
        self::assertSame($endpoint, $translation->getEndpoint());
        self::assertCount(1, $endpoint->getRequestExamples());
        self::assertSame($endpoint, $request->getEndpoint());
        self::assertCount(1, $endpoint->getResponseExamples());
        self::assertSame($endpoint, $response->getEndpoint());

        $endpoint->removeTranslation($translation)->removeRequestExample($request)->removeResponseExample($response);
        self::assertCount(0, $endpoint->getTranslations());
        self::assertCount(0, $endpoint->getRequestExamples());
        self::assertCount(0, $endpoint->getResponseExamples());
        self::assertNull($translation->getEndpoint());
        self::assertNull($request->getEndpoint());
        self::assertNull($response->getEndpoint());
    }

    public function testEnvironmentVariables(): void
    {
        $env = new ApiEnvironment('Dev', 'dev');
        $a   = new ApiEnvironmentVariable('base_url', 'https://dev.example.test');
        $b   = new ApiEnvironmentVariable('token', 'REDACTED-TEST');

        $env->addVariable($a)->addVariable($b)->addVariable($a);
        self::assertCount(2, $env->getVariables());
        self::assertSame($env, $a->getEnvironment());
        self::assertSame(['base_url' => 'https://dev.example.test', 'token' => 'REDACTED-TEST'], $env->getVariableMap());

        $env->removeVariable($a);
        self::assertSame(['token' => 'REDACTED-TEST'], $env->getVariableMap());
        self::assertNull($a->getEnvironment());
    }

    private static function sample(string $type, string $setter): mixed
    {
        return match ($type) {
            'string'              => $setter === 'setVariableKey' ? 'api_key' : 'value-' . $setter,
            'int'                 => 42,
            'bool'                => $setter !== 'setEnabled',
            'array'               => ['Accept' => 'application/json'],
            ApiWorkspace::class   => new ApiWorkspace('W', 'w'),
            ApiService::class     => new ApiService('S', 's'),
            ApiEndpoint::class    => new ApiEndpoint('E', 'e'),
            ApiEnvironment::class => new ApiEnvironment('Env', 'env'),
            ApiProtocol::class    => ApiProtocol::Soap,
            AuthType::class       => AuthType::Bearer,
            HttpMethod::class     => HttpMethod::Post,
            default               => self::fail('No sample for ' . $type),
        };
    }
}
