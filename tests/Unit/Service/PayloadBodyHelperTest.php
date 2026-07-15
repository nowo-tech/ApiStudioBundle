<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Service\PayloadBodyHelper;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

final class PayloadBodyHelperTest extends TestCase
{
    private PayloadBodyHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new PayloadBodyHelper();
    }

    public function testResolveBodyFormat(): void
    {
        self::assertSame('xml', $this->helper->resolveBodyFormat('soap'));
        self::assertSame('xml', $this->helper->resolveBodyFormat('rest', 'text/xml'));
        self::assertSame('json', $this->helper->resolveBodyFormat('rest', 'application/json'));
    }

    public function testValidateAndFormatJson(): void
    {
        $body       = '{"name":"demo","token":{{access_token}}}';
        $validation = $this->helper->validateJson($body);
        self::assertTrue($validation['valid']);
        self::assertTrue($validation['with_variables']);

        $formatted = $this->helper->formatJson($body);
        self::assertStringContainsString('"token": "{{access_token}}"', $formatted);
        self::assertStringContainsString('"name": "demo"', $formatted);
    }

    public function testInvalidJson(): void
    {
        $validation = $this->helper->validateJson('{"broken":');
        self::assertFalse($validation['valid']);
    }

    public function testValidateAndFormatXml(): void
    {
        $body       = '<root><token>{{access_token}}</token></root>';
        $validation = $this->helper->validateXml($body);
        self::assertTrue($validation['valid']);
        self::assertTrue($validation['with_variables']);

        $formatted = $this->helper->formatXml($body);
        self::assertStringContainsString('{{access_token}}', $formatted);
        self::assertStringContainsString('<token>', $formatted);
    }

    public function testInvalidXml(): void
    {
        $validation = $this->helper->validateXml('<root><unclosed>');
        self::assertFalse($validation['valid']);
    }

    public function testValidateAndFormatDelegatesByFormat(): void
    {
        self::assertTrue($this->helper->validate('{"ok":true}', 'json')['valid']);
        self::assertTrue($this->helper->validate('<root/>', 'xml')['valid']);
        self::assertStringContainsString('"ok"', $this->helper->format('{"ok":true}', 'json'));
    }

    public function testEmptyBodyValidationAndFormatting(): void
    {
        self::assertSame('empty', $this->helper->validateJson('   ')['message']);
        self::assertSame('', $this->helper->formatJson(''));
        self::assertSame('', $this->helper->formatXml(''));
    }

    public function testFormatXmlThrowsOnInvalidBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->helper->formatXml('<broken>');
    }
}
