<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Service\ImportExport\PostmanAuthMapper;
use PHPUnit\Framework\TestCase;

final class PostmanAuthMapperTest extends TestCase
{
    public function testMapsV21ListsAndV20Maps(): void
    {
        self::assertSame(['type' => AuthType::Bearer, 'config' => ['token' => '{{t}}']], PostmanAuthMapper::map(['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{t}}', 'type' => 'string']]]));
        self::assertSame(['type' => AuthType::Basic, 'config' => ['username' => 'u', 'password' => 'p']], PostmanAuthMapper::map(['type' => 'basic', 'basic' => ['username' => 'u', 'password' => 'p']]));
        self::assertSame(['type' => AuthType::ApiKey, 'config' => ['header' => 'X-Key', 'value' => 'v']], PostmanAuthMapper::map(['type' => 'apikey', 'apikey' => [['key' => 'key', 'value' => 'X-Key'], ['key' => 'value', 'value' => 'v'], ['key' => 'in', 'value' => 'header']]]));
        self::assertSame(['type' => AuthType::None, 'config' => []], PostmanAuthMapper::map(['type' => 'noauth']));
    }

    public function testUnsupportedOrMissingAuthIsNotGuessed(): void
    {
        self::assertNull(PostmanAuthMapper::map(['type' => 'oauth2', 'oauth2' => []]));
        self::assertNull(PostmanAuthMapper::map(['type' => 'apikey', 'apikey' => [['key' => 'in', 'value' => 'query']]]));
        self::assertNull(PostmanAuthMapper::map(null));
        self::assertSame(['type' => AuthType::Bearer, 'config' => ['token' => '']], PostmanAuthMapper::map(['type' => 'bearer', 'bearer' => 'not-a-list']));
        self::assertTrue(PostmanAuthMapper::isPresent(['type' => 'oauth2']));
        self::assertFalse(PostmanAuthMapper::isPresent(['type' => '']));
        self::assertFalse(PostmanAuthMapper::isPresent('bearer'));
    }
}
