<?php

// SPDX-FileCopyrightText: 2026 FreshRSS <https://freshrss.github.io/FreshRSS/>
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SimplePie\SimplePie;

/**
 * Comment-stripping tests for `SimplePie::clean_hash()`, in particular against
 * a quadratic scan from each unclosed `<!--` (ReDoS, GHSA-8pj8-6xgr-8r8f)
 */
class CleanHashTest extends TestCase
{
    protected function cleanHash(string $rss): string
    {
        $method = new ReflectionMethod(SimplePie::class, 'clean_hash');
        if (PHP_VERSION_ID < 80100) {
            // ReflectionMethod::setAccessible() is required before PHP 8.1, and deprecated since PHP 8.5.
            $method->setAccessible(true);
        }
        $hash = $method->invoke(new SimplePie(), $rss);
        return is_string($hash) ? $hash : '';
    }

    /**
     * @dataProvider commentsIgnoredByHashProvider
     */
    public function testCommentsAreIgnored(string $rss, string $expected): void
    {
        self::assertSame(sha1($expected), $this->cleanHash($rss));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function commentsIgnoredByHashProvider(): array
    {
        return [
            'no comment' => ['<rss><title>a</title></rss>', '<rss><title>a</title></rss>'],
            'comment' => ['<rss><!-- 1 --><title>a</title><!----></rss>', '<rss><title>a</title></rss>'],
            'multi-line comment' => ["<rss><!--\n1\n--><title>a</title></rss>", '<rss><title>a</title></rss>'],
            'comment start in comment' => ['<rss><!-- 1 <!-- 2 --><title>a</title></rss>', '<rss><title>a</title></rss>'],
            'unclosed comment in CDATA' => ['<rss><title><![CDATA[a<!-- 1 ]]></title></rss>', '<rss><title><![CDATA[a]]></title></rss>'],
            'conditional comments' => [
                '<!--[if gt IE 8]><!--><html class="no-js"><!--<![endif]--><!--[if !IE]><!---><p>a</p><!--<![endif]-->',
                '<html class="no-js"><p>a</p>',
            ],
            'unclosed comment' => ['<rss><title>a</title><!-- 1 </rss>', '<rss><title>a</title><!-- 1 </rss>'],
            'too long comment' => [
                '<rss><!--' . str_repeat('1', 8193) . '--><title>a</title></rss>',
                '<rss><!--' . str_repeat('1', 8193) . '--><title>a</title></rss>',
            ],
            // Each unclosed comment start must not be scanned again from the previous ones
            'many unclosed comments' => ['<rss><title><![CDATA[a' . str_repeat('<!--', 100000) . ']]></title></rss>', '<rss><title><![CDATA[a]]></title></rss>'],
        ];
    }
}
