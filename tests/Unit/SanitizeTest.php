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
     * Test HTML5 tag closing compliance.
     */
    public function testSanitizeHtml5TagClosingCompliance(): void
    {
        if (!class_exists('Dom\HTMLDocument') || get_parent_class('Dom\HTMLDocument') === \DOMDocument::class) {
            self::markTestSkipped('Requires the HTML5 parser of a native `Dom\HTMLDocument` (PHP 8.4+)');
        }
        $sanitize = new Sanitize();

        self::assertSame(
            <<<'HTML'
            <ul>
                <li><div></div></li>
                <li>Item</li>
            </ul>
            HTML,
            $sanitize->sanitize(
                <<<'HTML'
                <ul>
                    <li><div></li>
                    <li>Item</li>
                </ul>
                HTML,
                SIMPLEPIE_CONSTRUCT_MAYBE_HTML
            )
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

        $actual = $sanitize->sanitize($given, SIMPLEPIE_CONSTRUCT_HTML, $base);

        // Remove newlines for PHP 7.2 compatibility
        $expected = str_replace(["\n", "\r"], '', $expected);
        $actual = str_replace(["\n", "\r"], '', $actual);

        self::assertSame($expected, $actual);
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

        yield 'data scheme in href' => [
            '<a href="data:text/html,<script>alert(1)</script>">Click me</a>',
            '<a href="unsafe:data:text/html,%3Cscript%3Ealert(1)%3C/script%3E">Click me</a>',
            ['javascript', 'data'],
        ];

        yield 'data scheme in iframe src' => [
            '<iframe src="data:text/html,x"></iframe>',
            '<iframe src="unsafe:data:text/html,x" sandbox="allow-scripts allow-same-origin"></iframe>',
            ['javascript', 'data'],
        ];

        yield 'data scheme case insensitive' => [
            '<a href="DATA:text/html,x">Click me</a>',
            '<a href="unsafe:data:text/html,x">Click me</a>',
            ['javascript', 'data'],
        ];

        yield 'data image kept on img src' => [
            '<img src="data:image/png;base64,iVBORw0KGgo=">',
            '<img src="data:image/png;base64,iVBORw0KGgo=">',
            ['javascript', 'data'],
        ];

        yield 'data non-image blocked on img src' => [
            '<img src="data:text/html,x">',
            '<img src="unsafe:data:text/html,x">',
            ['javascript', 'data'],
        ];

        yield 'data image blocked on href' => [
            '<a href="data:image/png;base64,iVBORw0KGgo=">Click me</a>',
            '<a href="unsafe:data:image/png;base64,iVBORw0KGgo=">Click me</a>',
            ['javascript', 'data'],
        ];

        yield 'data scheme unaffected when not disallowed' => [
            '<a href="data:text/html,x">Click me</a>',
            '<a href="data:text/html,x">Click me</a>',
            ['javascript'],
        ];
    }

    /**
     * @dataProvider disallowedElementChildOrderProvider
     */
    public function testDisallowedElementPreservesChildOrder(string $input, string $expected): void
    {
        $sanitize = new Sanitize();
        $sanitize->allowed_html_elements_with_attributes([
            'p' => [],
        ]);

        $sanitize->set_registry(new Registry());
        $result = $sanitize->sanitize($input, \SimplePie\SimplePie::CONSTRUCT_HTML, 'http://example.com/');

        self::assertSame($expected, preg_replace('/\R/', '', $result));
    }

    /** @return iterable<string, array{string, string}> */
    public static function disallowedElementChildOrderProvider(): iterable
    {
        yield 'disallowed wrapper preserves paragraph order' => [
            '<span><p>First</p><p>Second</p><p>Third</p></span>',
            '<p>First</p><p>Second</p><p>Third</p>',
        ];

        yield 'disallowed wrapper between allowed elements preserves order' => [
            '<p>Before</p><span><p>First</p><p>Second</p></span><p>After</p>',
            '<p>Before</p><p>First</p><p>Second</p><p>After</p>',
        ];

        yield 'nested disallowed elements preserve order' => [
            '<span><i><p>First</p><p>Second</p></i></span>',
            '<p>First</p><p>Second</p>',
        ];

        yield 'mixed text and elements inside disallowed wrapper' => [
            '<span>text1<p>Middle</p>text2</span>',
            'text1<p>Middle</p>text2',
        ];

        yield 'multiple disallowed wrappers preserve global order' => [
            '<p>One</p><span><p>Two</p><p>Three</p></span><em><p>Four</p><p>Five</p></em><p>Six</p>',
            '<p>One</p><p>Two</p><p>Three</p><p>Four</p><p>Five</p><p>Six</p>',
        ];

        yield 'disallowed wrapper at end with no next sibling' => [
            '<p>First</p><p>Second</p><span><p>Third</p><p>Fourth</p></span>',
            '<p>First</p><p>Second</p><p>Third</p><p>Fourth</p>',
        ];
    }

    /**
     * @return array<array{string}>
     */
    public static function rawTextElementDataProvider(): array
    {
        return [
            'noembed' => ['noembed'],
            'noframes' => ['noframes'],
            'textarea' => ['textarea'],
            'title' => ['title'],
            'xmp' => ['xmp'],
        ];
    }

    /**
     * Browsers read the content of e.g. `<xmp>` as text ending at the first `</xmp>`,
     * while libxml2 builds child elements there, and writes `<!--...-->` and `&{...}`
     * found in their attribute values without escaping `<` and `>`.
     *
     * With the HTML5 parser (PHP 8.4+), the content of `<title>` is RCDATA ending at
     * the first `</title>` even inside an attribute value: the escaped head of the
     * payload stays as the text of `<title>`, and the markup which the parser builds
     * beyond it — a `<form>` for this payload — is removed by the default strip list.
     *
     * @dataProvider rawTextElementDataProvider
     */
    public function testMarkupInRawTextElementKeepsOnlyText(string $tag): void
    {
        $sanitize = new Sanitize();
        $sanitize->set_registry(new Registry());
        $base = 'http://example.com/';

        foreach (['<!--%s-->', '&amp;{%s}'] as $unescapedByLibxml) {
            $title = sprintf($unescapedByLibxml, '</' . $tag . '><form method=post>');
            $actual = $sanitize->sanitize(
                '<' . $tag . '><a title="' . $title . '">Hello</a> <b>world</b></' . $tag . '>',
                SIMPLEPIE_CONSTRUCT_HTML,
                $base
            );

            // The attribute value must not end the raw text element early in a browser and become live markup
            self::assertStringNotContainsString('<form', $actual);
            // Only the text may remain in a raw text element
            self::assertStringContainsString('Hello', $actual);
            self::assertStringContainsString('world', $actual);
            // Whatever a consumer ingests when re-parsing the output must not be executable either
            self::assertNoExecutableMarkupIngested($actual);
        }
    }

    /**
     * Same as testMarkupInRawTextElementKeepsOnlyText(), but with an element the default
     * strip list removes (`<iframe>`), provided nothing is stripped at all.
     *
     * Regardless of what libxml2 makes of the payload — attribute glue on older
     * versions, live markup beyond an early-terminated raw text element on newer
     * ones — the sanitized output must not ingest anything executable.
     */
    public function testMarkupInRawTextElementKeepsOnlyTextWhenNothingIsStripped(): void
    {
        $sanitize = new Sanitize();
        $sanitize->set_registry(new Registry());
        $sanitize->strip_htmltags(false);
        $base = 'http://example.com/';

        $title = sprintf('<!--%s-->', '</iframe><img src=x onerror=alert(1)>');
        $actual = $sanitize->sanitize(
            '<iframe><a title="' . $title . '">Hello</a></iframe>',
            SIMPLEPIE_CONSTRUCT_HTML,
            $base
        );

        // Event handlers must not survive anywhere in the output
        self::assertStringNotContainsString('onerror', $actual);
        // The regular text of the raw text element must remain
        self::assertStringContainsString('Hello', $actual);
        // What a consumer ingests when re-parsing the output must not be executable either
        self::assertNoExecutableMarkupIngested($actual);
    }

    /**
     * Same as testMarkupInRawTextElementKeepsOnlyTextWhenNothingIsStripped(), but with a
     * `<noscript>` element: the modern HTML5 parser (`Dom\HTMLDocument`, PHP 8.4+) still
     * parses its content as markup — RAWTEXT handling for `<noscript>` only applies to
     * browser-side parsing with scripting enabled — and `saveHtml()` serializes text
     * children of `<noscript>` literally, leaving markup characters in attribute values
     * unescaped. A browser re-parsing the output with scripting enabled would terminate
     * the element early on markup embedded in an attribute value.
     */
    public function testMarkupInRawTextElementKeepsOnlyTextInNoscript(): void
    {
        $sanitize = new Sanitize();
        $sanitize->set_registry(new Registry());
        $sanitize->strip_htmltags(false);
        $base = 'http://example.com/';

        $title = sprintf('<!--%s-->', '</noscript><img src=x onerror=alert(1)>');
        $actual = $sanitize->sanitize(
            '<noscript><a title="' . $title . '">Hello</a></noscript>',
            SIMPLEPIE_CONSTRUCT_HTML,
            $base
        );

        // Event handlers must not survive anywhere in the output
        self::assertStringNotContainsString('onerror', $actual);
        // The regular text of the raw text element must remain
        self::assertStringContainsString('Hello', $actual);
        // What a consumer ingests when re-parsing the output must not be executable either
        self::assertNoExecutableMarkupIngested($actual);
    }

    /**
     * Same as testMarkupInRawTextElementKeepsOnlyText(), but with `<plaintext>`,
     * which is special among the raw text elements: per HTML5, a browser parses
     * everything after its start tag as text until the end of the document, and
     * never recognizes `</plaintext>`.
     *
     * The legacy libxml2 parser builds child elements in it, which this sanitizer
     * flattens to text. With the HTML5 parser (PHP 8.4+), the whole payload stays
     * a single literal text node — serialized verbatim, so that the start tag of
     * a disallowed element may remain visible in the output. This cannot become
     * live markup when re-parsed: whatever a browser ingests stays text until the
     * end of the document, hence no `assertStringNotContainsString('<form', …)
     * here, unlike the other raw text element tests.
     */
    public function testMarkupInRawTextElementKeepsOnlyTextInPlaintext(): void
    {
        $sanitize = new Sanitize();
        $sanitize->set_registry(new Registry());
        $base = 'http://example.com/';

        $title = sprintf('<!--%s-->', '</plaintext><form method=post>');
        $actual = $sanitize->sanitize(
            '<plaintext><a title="' . $title . '">Hello</a> <b>world</b></plaintext>',
            SIMPLEPIE_CONSTRUCT_HTML,
            $base
        );

        // The regular text of the raw text element must remain
        self::assertStringContainsString('Hello', $actual);
        self::assertStringContainsString('world', $actual);
        // Whatever a consumer ingests when re-parsing the output must not be executable either
        self::assertNoExecutableMarkupIngested($actual);
    }

    /**
     * Asserts that re-parsing the HTML ingests no executable markup:
     * no script elements and no event-handler attributes, and no markup
     * characters embedded in attribute values, which older libxml2 would
     * write unescaped into the serialized output.
     */
    private static function assertNoExecutableMarkupIngested(string $html): void
    {
        // Unlike the legacy API, parsing never fails (no recovery needed).
        // The HTML5 parser makes this a browser-fidelity ingest check on PHP 8.4+,
        // while the `Dom\` polyfill maps it onto the legacy libxml2 parser below 8.4.
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $xpath = new \Dom\XPath($document);
        $xpath->registerNamespace('xh', 'http://www.w3.org/1999/xhtml');
        $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');
        $xpath->registerNamespace('mml', 'http://www.w3.org/1998/Math/MathML');
        $handlers = $xpath->query('//@*[starts-with(name(), "on")]');
        $scripts = $xpath->query('//*[self::xh:script or self::svg:script or self::mml:script]');
        $markupInAttributes = $xpath->query('//@*[contains(., "<")]');
        self::assertNotFalse($handlers);
        self::assertNotFalse($scripts);
        self::assertNotFalse($markupInAttributes);
        self::assertCount(0, $handlers);
        self::assertCount(0, $scripts);
        self::assertCount(0, $markupInAttributes);
    }
}
