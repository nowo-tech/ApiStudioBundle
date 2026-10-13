<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Soap;

use Nowo\ApiStudioBundle\Soap\SoapEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SoapEnvelopeTest extends TestCase
{
    #[DataProvider('envelopes')]
    public function testIsEnvelope(string $body, bool $expected): void
    {
        self::assertSame($expected, SoapEnvelope::isEnvelope($body));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function envelopes(): iterable
    {
        yield 'soap 1.1 with declaration' => ["<?xml version=\"1.0\"?>\n<soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\"><soap:Body/></soap:Envelope>", true];
        yield 'unprefixed with BOM and whitespace' => ["\xEF\xBB\xBF  \n<Envelope xmlns=\"http://www.w3.org/2003/05/soap-envelope\"><Body/></Envelope>", true];
        yield 'SOAP-ENV prefix' => ['<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"/>', true];
        yield 'json parameters' => ['{"value": "<Envelope>"}', false];
        yield 'empty' => ['', false];
        yield 'xml without envelope' => ['<Ping><value>1</value></Ping>', false];
        yield 'element with Envelope suffix' => ['<MyEnvelopeData/>', false];
    }

    public function testVersionAndFramingHeaders(): void
    {
        self::assertSame(SoapEnvelope::VERSION_1_1, SoapEnvelope::version('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"/>'));
        self::assertSame(SoapEnvelope::VERSION_1_2, SoapEnvelope::version('<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"/>'));

        self::assertSame(
            ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '"urn:a#B"'],
            SoapEnvelope::framingHeaders('urn:a#B', SoapEnvelope::VERSION_1_1),
        );
        self::assertSame(
            ['Content-Type' => 'application/soap+xml; charset=utf-8; action="urn:a#B"'],
            SoapEnvelope::framingHeaders('urn:a#B', SoapEnvelope::VERSION_1_2),
        );
    }

    #[DataProvider('endpointUrls')]
    public function testEndpointUrl(string $serviceUrl, string $expected): void
    {
        self::assertSame($expected, SoapEnvelope::endpointUrl($serviceUrl));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpointUrls(): iterable
    {
        yield 'upper WSDL' => ['https://example.com/svc.asmx?WSDL', 'https://example.com/svc.asmx'];
        yield 'lower wsdl' => ['https://example.com/svc.asmx?wsdl', 'https://example.com/svc.asmx'];
        yield 'singleWsdl' => ['https://example.com/svc.svc?singleWsdl', 'https://example.com/svc.svc'];
        yield 'wsdl with value' => ['https://example.com/svc?wsdl=wsdl0', 'https://example.com/svc'];
        yield 'fragment dropped' => ['https://example.com/svc?WSDL#x', 'https://example.com/svc'];
        yield 'plain endpoint' => ['https://example.com/soap', 'https://example.com/soap'];
        yield 'other query kept' => ['https://example.com/soap?tenant=1', 'https://example.com/soap?tenant=1'];
    }

    #[DataProvider('actions')]
    public function testOperationName(string $action, string $expected): void
    {
        self::assertSame($expected, SoapEnvelope::operationName($action));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function actions(): iterable
    {
        yield 'bare name' => ['Ping', 'Ping'];
        yield 'http uri' => ['http://tempuri.org/OVCServWeb/OVCCoordenadas/Consulta_RCCOOR', 'Consulta_RCCOOR'];
        yield 'urn with hash' => ['urn:ping#Ping', 'Ping'];
        yield 'urn' => ['urn:Ping', 'Ping'];
        yield 'quoted' => ['"urn:ping#Ping"', 'Ping'];
        yield 'trailing slash keeps value' => ['urn:ping/', 'urn:ping/'];
    }

    public function testSoap11FaultIsParsed(): void
    {
        $body = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault>'
            . '<faultcode>soap:Client</faultcode><faultstring>Bad &amp; wrong</faultstring><detail /></soap:Fault></soap:Body></soap:Envelope>';

        self::assertSame('SOAP Fault (soap:Client): Bad & wrong', SoapEnvelope::fault($body));
    }

    public function testSoap12FaultIsParsed(): void
    {
        $body = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"><env:Body><env:Fault>'
            . '<env:Code><env:Value>env:Sender</env:Value></env:Code>'
            . '<env:Reason><env:Text xml:lang="en"><![CDATA[Invalid <input>]]></env:Text></env:Reason>'
            . '</env:Fault></env:Body></env:Envelope>';

        self::assertSame('SOAP Fault (env:Sender): Invalid <input>', SoapEnvelope::fault($body));
    }

    public function testFaultWithoutDetailsAndNoFault(): void
    {
        self::assertSame('SOAP Fault', SoapEnvelope::fault('<s:Envelope><s:Body><s:Fault></s:Fault></s:Body></s:Envelope>'));
        self::assertSame('SOAP Fault', SoapEnvelope::fault('<s:Envelope><s:Body><s:Fault/></s:Body></s:Envelope>'));
        self::assertNull(SoapEnvelope::fault('<s:Envelope><s:Body><PingResponse>pong</PingResponse></s:Body></s:Envelope>'));
        self::assertNull(SoapEnvelope::fault('not xml'));
    }
}
