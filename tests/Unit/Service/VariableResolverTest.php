<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Service\VariableResolver;
use PHPUnit\Framework\TestCase;

final class VariableResolverTest extends TestCase
{
    private VariableResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new VariableResolver();
    }

    public function testResolvesSingleVariable(): void
    {
        $result = $this->resolver->resolve('https://{{base_url}}/posts', [
            'base_url' => 'api.example.com',
        ]);

        self::assertSame('https://api.example.com/posts', $result);
    }

    public function testLeavesUnknownPlaceholders(): void
    {
        $result = $this->resolver->resolve('{{unknown}}', []);

        self::assertSame('{{unknown}}', $result);
    }

    public function testResolveMap(): void
    {
        $result = $this->resolver->resolveMap(
            ['Authorization' => 'Bearer {{token}}'],
            ['token' => 'abc'],
        );

        self::assertSame(['Authorization' => 'Bearer abc'], $result);
    }

    public function testResolveEmptyTemplate(): void
    {
        self::assertSame('', $this->resolver->resolve('', ['host' => 'example.com']));
    }
}
