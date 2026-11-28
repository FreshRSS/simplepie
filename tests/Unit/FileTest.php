<?php

// SPDX-FileCopyrightText: 2004-2023 Ryan Parman, Sam Sneddon, Ryan McCue
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace SimplePie\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\File;
use SimplePie\HTTP\Response;
use SimplePie\Tests\Fixtures\FileMock;
use SimplePie\Tests\Fixtures\FileProxyResolveMock;

class FileTest extends TestCase
{
    public function testNamespacedClassExists(): void
    {
        self::assertTrue(class_exists('SimplePie\File'));
    }

    public function testClassExists(): void
    {
        self::assertTrue(class_exists('SimplePie_File'));
    }

    public function testFileExtendsResponse(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(Response::class, new FileMock(''));
    }

    /**
     * @return array<array{File}>
     */
    public static function getResponseData(): iterable
    {
        yield [new FileMock('http://example.com/feed')];
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetRequestedUriReturnsString(File $response): void
    {
        self::assertSame(
            'http://example.com/feed',
            $response->get_final_requested_uri()
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetStatusCodeReturnsInt(File $response): void
    {
        self::assertSame(
            200,
            $response->get_status_code()
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetHeadersReturnsArray(File $response): void
    {
        self::assertSame(
            ['content-type' => ['application/atom+xml']],
            $response->get_headers()
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testHasHeadersReturnsTrue(File $response): void
    {
        self::assertTrue($response->has_header('Content-Type'));
    }

    /**
     * @dataProvider getResponseData
     */
    public function testHasHeadersReturnsFalse(File $response): void
    {
        self::assertFalse($response->has_header('X-Custom-Header'));
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetHeaderReturnsArray(File $response): void
    {
        self::assertSame(
            ['application/atom+xml'],
            $response->get_header('CONTENT-TYPE')
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetHeaderReturnsEmptyArray(File $response): void
    {
        self::assertSame(
            [],
            $response->get_header('X-Custom-Header')
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetHeaderLineReturnsString(File $response): void
    {
        self::assertSame(
            'application/atom+xml',
            $response->get_header_line('content-Type')
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetHeaderLineReturnsEmptyString(File $response): void
    {
        self::assertSame(
            '',
            $response->get_header_line('X-Custom-Header')
        );
    }

    /**
     * @dataProvider getResponseData
     */
    public function testGetBodyContentReturnsString(File $response): void
    {
        self::assertSame(
            '<?xml version="1.0" encoding="utf-8"?><feed xmlns="http://www.w3.org/2005/Atom" />',
            $response->get_body_content()
        );
    }

    /** @return iterable<string, array{string, int, ?int, string}> */
    public static function getProxyResolveData(): iterable
    {
        yield 'Adds the documented default port' => [
            'proxy.example.com',
            CURLPROXY_SOCKS5,
            null,
            'socks5://proxy.example.com:1080',
        ];
        yield 'Adds the documented default HTTPS port' => [
            'proxy.example.com',
            CURLPROXY_HTTPS,
            null,
            'https://proxy.example.com:443',
        ];
        yield 'Uses CURLOPT_PROXYPORT and drops it for the checks' => [
            'proxy.example.com',
            CURLPROXY_HTTP,
            3128,
            'http://proxy.example.com:3128',
        ];
        yield 'Keeps an explicit port and credentials' => [
            'user:pass@proxy.example.com:3128',
            CURLPROXY_HTTP,
            null,
            'http://user:pass@proxy.example.com:3128',
        ];
        yield 'Keeps an explicit IPv6 port' => [
            '[::1]:3128',
            CURLPROXY_HTTP,
            null,
            'http://[::1]:3128',
        ];
    }

    /**
     * A scheme must go to `CURLOPT_PROXYTYPE`, and an explicit port must be present,
     * because the SSRF check needs to agree with what cURL will connect to.
     * `CURLOPT_PROXYPORT` is folded into the proxy URL.
     *
     * @dataProvider getProxyResolveData
     */
    public function testProxyIsNormalizedBeforeAllowlistCheck(string $proxy, int $proxyType, ?int $proxyPort, string $expectedUrl): void
    {
        if (!function_exists('curl_exec')) {
            self::markTestSkipped('requires cURL');
        }

        $file = FileProxyResolveMock::create($proxy, $proxyType, $proxyPort);

        self::assertSame($expectedUrl, $file->resolvedUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function getAmbiguousProxyData(): iterable
    {
        yield 'Scheme in the proxy URL' => ['http://proxy.example.com:3128'];
        yield 'Path' => ['localhost:9050/path/to.sock'];
        yield 'Query delimiter' => ['127.0.0.1?:6379'];
        yield 'Fragment delimiter' => ['8.8.8.8#@127.0.0.1'];
        yield 'Backslash' => ['proxy.example.com\\8.8.8.8'];
        yield 'Whitespace' => ['proxy.example .com'];
    }

    /**
     * Ambiguous proxy values are rejected instead of being (mis)parsed,
     * so that the SSRF check cannot be tricked into a different address than the one of cURL.
     *
     * @dataProvider getAmbiguousProxyData
     */
    public function testAmbiguousProxyIsRejected(string $proxy): void
    {
        if (!function_exists('curl_exec')) {
            self::markTestSkipped('requires cURL');
        }

        $file = FileProxyResolveMock::create($proxy, CURLPROXY_HTTP);

        self::assertFalse($file->success);
        self::assertNull($file->resolvedUrl);
    }
}
