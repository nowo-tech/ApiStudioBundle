<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\HostSecretResolver;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestHostSecretSource;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class HostSecretResolverTest extends TestCase
{
    public function testWithoutASourceTheVariablesAreUntouched(): void
    {
        $resolver          = new HostSecretResolver();
        [$env, $workspace] = $this->environment();
        $map               = $env->getVariableMap();

        self::assertFalse($resolver->isEnabled());
        self::assertSame([], $resolver->referenceKeys($env, []));
        $resolution = $resolver->resolve($env, $workspace, $map, [], 'u:alice');
        self::assertSame($map, $resolution->variables);
        self::assertSame([], $resolution->values);
        self::assertSame([], $resolution->unresolvedKeys);
    }

    public function testResolvesStoredReferencesOfSecretVariablesOnly(): void
    {
        $source            = $this->source(['vault/payments' => self::value('one'), 'vault/other' => self::value('two')]);
        [$env, $workspace] = $this->environment();
        $map               = $env->getVariableMap();

        $resolver = new HostSecretResolver($source);
        self::assertSame(['token', 'overridden'], $resolver->referenceKeys($env, []));
        self::assertSame(['token'], $resolver->referenceKeys($env, ['overridden']));
        $resolution = $resolver->resolve($env, $workspace, $map, ['overridden'], 'u:alice', 'https://api.example.test');

        self::assertSame(self::value('one'), $resolution->variables['token']);
        self::assertSame('{{secret:vault/other}}', $resolution->variables['notSecret'], 'a non-secret variable is a plain value');
        self::assertSame('{{secret:vault/other}}', $resolution->variables['overridden'], 'a console override is never resolved');
        self::assertSame('plain-secret', $resolution->variables['plain']);
        self::assertSame([self::value('one')], $resolution->values);
        self::assertSame([], $resolution->unresolvedKeys);

        self::assertCount(1, $source->calls);
        $ref = $source->calls[0];
        self::assertSame(['vault', 'payments', 'token', 42, 9, 'org', 'acme', 'u:alice', 'https://api.example.test'], [$ref->source, $ref->id, $ref->variableKey, $ref->workspaceId, $ref->environmentId, $ref->tenantType, $ref->tenantId, $ref->actorId, $ref->targetOrigin]);
    }

    public function testUnknownDeniedFailingOrShortSecretsAreUnresolvedAndNeverLogged(): void
    {
        foreach ([
            'not_found_or_denied'   => [],
            'too_short'             => ['vault/payments' => 'abc'],
            RuntimeException::class => null,
        ] as $reason => $secrets) {
            $source = $this->source($secrets ?? []);
            if ($secrets === null) {
                $source->throw = new RuntimeException('store said ' . self::value('leak'));
            }
            $logger            = new InMemoryLogger();
            [$env, $workspace] = $this->environment();

            $resolution = (new HostSecretResolver($source, $logger))->resolve($env, $workspace, $env->getVariableMap(), ['overridden'], null);

            self::assertArrayNotHasKey('token', $resolution->variables, $reason . ': the reference is never sent as a value');
            self::assertSame(['token'], $resolution->unresolvedKeys, $reason);
            self::assertSame([], $resolution->values, $reason);
            self::assertCount(1, $logger->records, $reason);
            self::assertSame($reason, $logger->records[0]['context']['reason']);
            self::assertSame('token', $logger->records[0]['context']['variable']);
            self::assertStringNotContainsString(self::value('leak'), $logger->text());
            self::assertStringNotContainsString('payments', $logger->text(), 'the secret id is not logged');
        }
    }

    public function testATraversingIdFailsClosedWithoutAskingTheHost(): void
    {
        // QE-B-L6: `..` would escape an adapter's per-workspace prefix.
        $source            = $this->source(['vault/../other-team/key' => self::value('other')]);
        $logger            = new InMemoryLogger();
        [$env, $workspace] = $this->environment();
        $env->addVariable((new ApiEnvironmentVariable('escape', '{{secret:vault/../other-team/key}}'))->setSecret(true));

        $resolution = (new HostSecretResolver($source, $logger))->resolve($env, $workspace, $env->getVariableMap(), ['overridden', 'token'], null);

        self::assertSame(['escape'], $resolution->unresolvedKeys);
        self::assertArrayNotHasKey('escape', $resolution->variables);
        self::assertSame([], $source->calls);
        self::assertSame('InvalidArgumentException', $logger->records[0]['context']['reason']);
    }

    public function testValuesUnderEightBytesAreRefused(): void
    {
        // QE-B-L5.
        [$env, $workspace] = $this->environment();
        $resolution        = (new HostSecretResolver($this->source(['vault/payments' => '1234567'])))->resolve($env, $workspace, $env->getVariableMap(), ['overridden'], null);
        self::assertSame(['token'], $resolution->unresolvedKeys);

        $resolution = (new HostSecretResolver($this->source(['vault/payments' => '12345678'])))->resolve($env, $workspace, $env->getVariableMap(), ['overridden'], null);
        self::assertSame('12345678', $resolution->variables['token']);
    }

    public function testNothingIsMemoisedBetweenCalls(): void
    {
        $source            = $this->source(['u:alice|vault/payments' => self::value('alice'), 'u:bob|vault/payments' => self::value('bob')]);
        $resolver          = new HostSecretResolver($source);
        [$env, $workspace] = $this->environment();

        foreach (['alice', 'bob', 'alice', 'carol'] as $user) {
            $resolution = $resolver->resolve($env, $workspace, $env->getVariableMap(), ['overridden'], 'u:' . $user);
            if ($user === 'carol') {
                self::assertSame(['token'], $resolution->unresolvedKeys);
            } else {
                self::assertSame(self::value($user), $resolution->variables['token']);
            }
        }
        self::assertCount(4, $source->calls);
    }

    private static function value(string $suffix): string
    {
        return 'hv-REDACTED-TEST-' . $suffix;
    }

    /**
     * @param array<string, string> $secrets
     */
    private function source(array $secrets): TestHostSecretSource
    {
        $source          = new TestHostSecretSource();
        $source->secrets = $secrets;

        return $source;
    }

    /**
     * @return array{ApiEnvironment, ApiWorkspace}
     */
    private function environment(): array
    {
        $workspace = (new ApiWorkspace('Payments', 'payments'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 42);
        $env = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);
        (new ReflectionProperty(ApiEnvironment::class, 'id'))->setValue($env, 9);
        $env->addVariable((new ApiEnvironmentVariable('token', '{{secret:vault/payments}}'))->setSecret(true));
        $env->addVariable(new ApiEnvironmentVariable('notSecret', '{{secret:vault/other}}'));
        $env->addVariable((new ApiEnvironmentVariable('overridden', '{{secret:vault/other}}'))->setSecret(true));
        $env->addVariable((new ApiEnvironmentVariable('plain', 'plain-secret'))->setSecret(true));
        $unreadable = (new ApiEnvironmentVariable('broken', '{{secret:vault/other}}'))->setSecret(true);
        $unreadable->markUnreadable();
        $env->addVariable($unreadable);

        return [$env, $workspace];
    }
}
