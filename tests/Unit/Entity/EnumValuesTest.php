<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Entity;

use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use PHPUnit\Framework\TestCase;

final class EnumValuesTest extends TestCase
{
    public function testValuesListTheBackingValuesInOrder(): void
    {
        self::assertSame(['rest', 'soap', 'graphql'], ApiProtocol::values());
        self::assertSame(['none', 'basic', 'bearer', 'api_key', 'custom'], AuthType::values());
        self::assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], HttpMethod::values());
    }
}
