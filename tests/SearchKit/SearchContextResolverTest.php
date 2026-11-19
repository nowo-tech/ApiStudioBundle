<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit;

use Nowo\ApiStudioBundle\Event\ApiStudioSearchContextEvent;
use Nowo\ApiStudioBundle\Search\SearchContextResolver;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function random_int;

/**
 * Edges of the search context: no request, tenant keys, kept labels, a resolver without the studio's contexts.
 */
final class SearchContextResolverTest extends TestCase
{
    public function testWithoutARequestNothingIsSearchable(): void
    {
        $resolver = new SearchContextResolver(new RequestStack());
        self::assertNull($resolver->resolve(SearchKind::Workspace, 'ann', null));
        self::assertSame([], $resolver->rememberedLabels(SearchKind::Workspace, 'ann', null));
        $resolver->rememberLabels(SearchKind::Workspace, 'ann', null, ['a' => 'A']);
        self::assertFalse($resolver->scopes());
    }

    public function testTenantKeys(): void
    {
        self::assertSame('instance', SearchContextResolver::tenantKey(ApiStudioTenant::instance()));
        self::assertSame('org:acme', SearchContextResolver::tenantKey(new ApiStudioTenant('org', 'acme')));
        self::assertTrue(ApiStudioTenant::instance()->equals(SearchContextResolver::tenantOf('instance') ?? new ApiStudioTenant('x', 'y')));
        self::assertSame('org:a:b', SearchContextResolver::tenantOf('org:a:b')?->key());
        foreach ([7, 'org', 'Org:a', 'org:', 'org:a b'] as $key) {
            self::assertNull(SearchContextResolver::tenantOf($key), (string) $key);
        }
    }

    public function testKeptLabelsAndARunWithoutContexts(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/search'));
        $resolver = new SearchContextResolver($requests);
        $resolver->rememberLabels(SearchKind::Service, 'ann', 's1', ['project:a' => 'A']);
        self::assertSame(['project:a' => 'A'], $resolver->rememberedLabels(SearchKind::Service, 'ann', 's1'));
        self::assertSame([], $resolver->rememberedLabels(SearchKind::Service, 'bob', 's1'));
        $requests->getMainRequest()?->attributes->set(SearchContextResolver::LABELS_ATTRIBUTE . "\0services\0ann\0\0", [1 => 'x', 'ok' => 'y', 'bad' => 3]);
        self::assertSame(['ok' => 'y'], $resolver->rememberedLabels(SearchKind::Service, 'ann', null));

        // No tenant context or scope context (a host wiring the resolver alone): the defaults, run as is.
        $context = $resolver->resolve(SearchKind::Service, 'ann', null);
        self::assertInstanceOf(ApiStudioSearchContextEvent::class, $context);
        self::assertTrue($context->tenant()?->isInstance());
        $value = random_int(1, 1000);
        self::assertSame($value, $resolver->run($context, static fn (): int => $value));
        // Memoised on the request: the same object.
        self::assertSame($context, $resolver->resolve(SearchKind::Service, 'ann', null));
        // A scope id without scopes on: denied, memoised as denied.
        self::assertNull($resolver->resolve(SearchKind::Service, 'ann', 'project:a'));
        self::assertNull($resolver->resolve(SearchKind::Service, 'ann', 'project:a'));
    }
}
