<?php

// SPDX-FileCopyrightText: 2004-2023 Ryan Parman, Sam Sneddon, Ryan McCue
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace SimplePie\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\Misc;
use SimplePie\Registry;
use SimplePie\Sanitize;

class SanitizeTest extends TestCase
{
    public function testNamespacedClassExists(): void
    {
        self::assertTrue(class_exists('SimplePie\Sanitize'));
    }

    public function testClassExists(): void
    {
        self::assertTrue(class_exists('SimplePie_Sanitize'));
    }

    public function testSanitize(): void
    {
        $sanitize = new Sanitize();

        self::assertSame(
            <<<HTML
&lt;head&gt; &amp; &lt;body&gt; /\ ' === ' &amp; " === ". Sbohem bez šátečku! Тут был Лёха.
HTML
            ,
            $sanitize->sanitize(
                <<<HTML
&#60;head&#62; &amp; &lt;body&gt; /\ ' === &apos; &#38; " === &quot;. Sbohem bez šátečku! Тут был Лёха.<script>alert('XSS')</script>
HTML
                ,
                SIMPLEPIE_CONSTRUCT_MAYBE_HTML
            ),
            'XML input (with corresponding xml entities) should be cleaned and converted to utf-8 escaped HTML'
        );
    }

    /**
     * @return array<array{string, string}>
     */
    public static function sanitizeURLDataProvider(): array
    {
        return [
            'simple absolute valid a href, resolved' => [
                '<a href="/path/to/doc">link</a>',
                '<a href="http://example.com/path/to/doc">link</a>'
            ],
            'image valid fully qualified src, no change expected' => [
                '<img src="http://2.example.com/image.jpg">',
                '<img src="http://2.example.com/image.jpg">'
            ],
            'image valid relative src, resolved' => [
                '<img src="image.jpg">',
                '<img src="http://example.com/image.jpg">'
            ],
            'image valid absolute src, resolved' => [
                '<img src="/image.jpg">',
                '<img src="http://example.com/image.jpg">'
            ],
            'audio relative src, resolved, fixed' => [
                '<audio src="a.mp3" />',
                '<audio src="http://example.com/a.mp3" preload="none"></audio>'
            ],
            'audio absolute source src path, resolved, fixed' => [
                '<audio><source src="/a/b.wav" /></audio>',
                '<audio preload="none"><source src="http://example.com/a/b.wav"></audio>'
            ],
            'audio with alternative source src absolute paths, resolved, fixed' => [
                '<audio><source src="a/b.wav" /><source src="/c/d.mp3" /></audio>',
                '<audio preload="none"><source src="http://example.com/a/b.wav"><source src="http://example.com/c/d.mp3"></audio>'
            ],
            'video src relative, resolved, fixed' => [
                '<video src="./b.mpeg" />',
                '<video src="http://example.com/b.mpeg" preload="none"></video>'
            ],
            'video with alternative source src, resolved, fixed' => [
                '<video><source src="a/b.mpeg" /><source src="/c/../d.mov"></video>',
                '<video preload="none"><source src="http://example.com/a/b.mpeg"><source src="http://example.com/d.mov"></video>'
            ],
        ];
    }

    /**
     * @dataProvider sanitizeURLDataProvider
     */
    public function testSanitizeURLResolution(string $given, string $expected): void
    {
        $sanitize = new Sanitize();

        $registry = new Registry();
        $sanitize->set_registry($registry);

        $base = 'http://example.com/';

        self::assertSame($expected, $sanitize->sanitize($given, SIMPLEPIE_CONSTRUCT_HTML, $base));
    }

    /**
     * @param string|string[] $expected Expected output (string or array of valid alternatives)
     * @param string[] $disallowedSchemes List of schemes (protocols) to disallow
     * @dataProvider disallowedUriSchemesProvider
     */
    public function testDisallowedUriSchemes(
        string $input,
        $expected,
        array $disallowedSchemes = ['javascript']
    ): void {
        $sanitize = new Sanitize();
        $sanitize->disallow_uri_schemes($disallowedSchemes);
        $sanitize->strip_htmltags = [];

        $sanitize->set_registry(new Registry());
        $base = 'http://example.com/';
        $result = $sanitize->sanitize($input, \SimplePie\SimplePie::CONSTRUCT_HTML, $base);

        if (is_array($expected)) {
            self::assertTrue(in_array($result, $expected, true), 'Result matches one of the expected values');
        } else {
            self::assertSame($expected, $result);
        }
    }

    /**
     * @return iterable<array{string,string|string[],array<string>}>
     */
    public static function disallowedUriSchemesProvider(): iterable
    {
        yield 'javascript scheme in href' => [
            '<a href="javascript:alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with spaces in href' => [
            '<a href="  javascript:alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];
        yield 'javascript scheme in iframe src' => [
            '<iframe src="javascript:alert(\'XSS\')"></iframe>',
            '<iframe src="unsafe:javascript:alert(\'XSS\')" sandbox="allow-scripts allow-same-origin"></iframe>',
            ['javascript'],
        ];

        yield 'javascript scheme case insensitive' => [
            '<a href="JaVaScRiPt:alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
            true,
        ];

        yield 'javascript scheme url encoded' => [
            '<a href="%6A%61%76%61%73%63%72%69%70%74:alert(\'XSS\')">Click me</a>',
            '<a href="http://example.com/">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with scheme colon url encoded' => [
            '<a href="javascript%3Aalert(\'XSS\')">Click me</a>',
            '<a href="http://example.com/javascript%3Aalert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme encoded with numeric HTML entities' => [
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;:alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme encoded with hex entities' => [
            '<a href="&#x6a;&#x61;&#x76;&#x61;&#x73;&#x63;&#x72;&#x69;&#x70;&#x74;:alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with scheme colon as numeric HTML entity' => [
            '<a href="javascript&#58;alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with scheme colon as hex HTML entity' => [
            '<a href="javascript&#x3a;alert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with scheme colon as named HTML entity' => [
            '<a href="javascript&colon;alert(\'XSS\')">Click me</a>',
            [ // Two valid alternatives depending on the libxml version used for parsing:
                '<a href="http://example.com/javascript&amp;colon;alert(\'XSS\')">Click me</a>', // libxml < 2.14.0
                '<a href="unsafe:javascript:alert(\'XSS\')">Click me</a>', // libxml >= 2.14.0
            ],
            ['javascript'],
        ];

        yield 'javascript scheme double encoded with URL encoding inside numeric HTML entities' => [
            '<a href="&#37;&#54;&#65;&#37;&#54;&#49;&#37;&#55;&#54;&#37;&#54;&#49;&#37;&#55;&#51;&#37;&#54;&#51;&#37;&#55;&#50;&#37;&#54;&#57;&#37;&#55;&#48;&#37;&#55;&#52;:alert(\'XSS\')">Click me</a>',
            '<a href="http://example.com/">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with scheme colon double encoded with URL encoding inside numeric HTML entities' => [
            '<a href="javascript&#37;&#51;&#65;alert(\'XSS\')">Click me</a>',
            '<a href="http://example.com/javascript%3Aalert(\'XSS\')">Click me</a>',
            ['javascript'],
        ];

        yield 'javascript scheme with a double slash' => [
            '<a href="javascript://%0Aalert(\'XSS\')">Click me</a>',
            '<a href="unsafe:javascript://%0Aalert(\'xss\')">Click me</a>',
            ['javascript'],
        ];

        yield 'vbscript scheme blocked' => [
            '<a href="vbscript:msgbox(\'XSS\')">Click me</a>',
            '<a href="unsafe:vbscript:msgbox(\'XSS\')">Click me</a>',
            ['javascript', 'vbscript', 'data'],
        ];

        yield 'data scheme blocked' => [
            '<a href="data:text/html,<script>alert(\'XSS\')</script>">Click me</a>',
            '<a href="unsafe:data:text/html,%3Cscript%3Ealert(\'XSS\')%3C/script%3E">Click me</a>',
            ['javascript', 'vbscript', 'data'],
        ];

        yield 'safe http scheme unaffected' => [
            '<a href="http://example.com/page">HTTP link</a>',
            '<a href="http://example.com/page">HTTP link</a>',
            ['javascript'],
        ];

        yield 'safe http scheme with blanks unaffected' => [
            '<a href=" http://example.com/page">HTTP link</a>',
            '<a href="http://example.com/page">HTTP link</a>',
            ['javascript'],
        ];

        yield 'safe https scheme unaffected' => [
            '<a href="https://example.com/page">HTTPS link</a>',
            '<a href="https://example.com/page">HTTPS link</a>',
            ['javascript'],
        ];

        yield 'safe mailto scheme unaffected' => [
            '<a href="mailto:test@example.com">Email</a>',
            '<a href="mailto:test@example.com">Email</a>',
            ['javascript'],
        ];

        yield 'javascript scheme in form action' => [
            '<form action="javascript:alert(\'XSS\')"></form>',
            '<form action="unsafe:javascript:alert(\'XSS\')"></form>',
            ['javascript'],
        ];

        yield 'javascript scheme in blockquote cite' => [
            '<blockquote cite="javascript:alert(\'XSS\')">Quote</blockquote>',
            '<blockquote cite="unsafe:javascript:alert(\'XSS\')">Quote</blockquote>',
            ['javascript'],
        ];

        yield 'javascript scheme on mathml descendant href' => [
            '<math><maction href="javascript:alert(\'XSS\')">x</maction></math>',
            '<math><maction href="unsafe:javascript:alert(\'XSS\')">x</maction></math>',
            ['javascript'],
        ];

        yield 'javascript scheme on mathml root href' => [
            '<math href="javascript:alert(\'XSS\')"><mtext>x</mtext></math>',
            '<math href="unsafe:javascript:alert(\'XSS\')"><mtext>x</mtext></math>',
            ['javascript'],
        ];

        yield 'javascript scheme on svg descendant href' => [
            '<svg><a href="javascript:alert(\'XSS\')">x</a></svg>',
            '<svg><a href="unsafe:javascript:alert(\'XSS\')">x</a></svg>',
            ['javascript'],
        ];

        yield 'javascript scheme on svg descendant xlink:href' => [
            '<svg xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(\'XSS\')">x</a></svg>',
            '<svg xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="unsafe:javascript:alert(\'XSS\')">x</a></svg>',
            ['javascript'],
        ];

        yield 'safe scheme on svg descendant xlink:href unaffected' => [
            '<svg xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="https://example.com/page">x</a></svg>',
            '<svg xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="https://example.com/page">x</a></svg>',
            ['javascript'],
        ];
    }
}
