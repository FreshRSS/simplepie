<?php

// SPDX-FileCopyrightText: 2004-2023 Ryan Parman, Sam Sneddon, Ryan McCue
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SanitizeSrcsetTest extends TestCase
{
    private function sanitize(string $html, string $base = 'https://example.com/'): string
    {
        $sanitize = new SimplePie_Sanitize();
        $sanitize->set_registry(new SimplePie_Registry());
        $sanitize->allowed_html_elements_with_attributes([
            'picture' => [],
            'source' => ['type', 'src', 'srcset', 'sizes', 'media', 'height', 'width'],
            'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height'],
        ]);

        return $sanitize->sanitize($html, SIMPLEPIE_CONSTRUCT_HTML, $base);
    }

    public function testPicksSmallestSrcsetWidthWhenSrcIsDataUri(): void
    {
        // `src` is a fallback for clients that can't honour `srcset`; the smallest
        // entry is the safest by bandwidth and never larger than the browser pick.
        $html = '<img srcset="https://example.com/s.jpg 80w, https://example.com/m.jpg 350w, https://example.com/l.jpg 2000w" '
            . 'src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="https://example.com/s.jpg"', $out);
        self::assertStringNotContainsString('data:image/gif', $out);
    }

    public function testRecognisesGifPlaceholderRegardlessOfLength(): void
    {
        // The R0lGODlh prefix is the de-facto universal 1x1 transparent GIF marker;
        // treat it as a placeholder even if padded past the 128-char threshold.
        $gif = 'data:image/gif;base64,R0lGODlh' . str_repeat('A', 200);
        $html = '<img src="' . $gif . '" srcset="https://example.com/real.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="https://example.com/real.jpg"', $out);
        self::assertStringNotContainsString('R0lGODlh', $out);
    }

    public function testRetainsSrcsetAttribute(): void
    {
        $html = '<img src="data:image/gif;base64,xxx" srcset="https://example.com/a.jpg 100w, https://example.com/b.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('srcset=', $out);
        self::assertStringContainsString('https://example.com/a.jpg 100w', $out);
        self::assertStringContainsString('https://example.com/b.jpg 500w', $out);
    }

    public function testRetainsSizesAttribute(): void
    {
        $html = '<img src="https://example.com/x.jpg" '
            . 'srcset="https://example.com/a.jpg 100w, https://example.com/b.jpg 500w" '
            . 'sizes="(max-width: 800px) 100vw, 800px" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('sizes="(max-width: 800px) 100vw, 800px"', $out);
    }

    public function testAbsolutisesRelativeSrcsetUrls(): void
    {
        $html = '<img src="" srcset="/img/a.jpg 100w, /img/b.jpg 500w" alt="x">';
        $out = $this->sanitize($html, 'https://example.com/articles/page');
        self::assertStringContainsString('https://example.com/img/a.jpg 100w', $out);
        self::assertStringContainsString('https://example.com/img/b.jpg 500w', $out);
        self::assertStringNotContainsString('srcset="/img/', $out);
    }

    public function testKeepsLegitimateImgSrc(): void
    {
        $html = '<img src="https://example.com/real.jpg" srcset="https://example.com/a.jpg 100w, https://example.com/b.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="https://example.com/real.jpg"', $out);
    }

    public function testFallsBackToLowestDensityWhenNoWidthEntries(): void
    {
        // No Nw entries: clients that only read src would keep the placeholder,
        // so fall back to the lowest-density entry instead.
        $html = '<img src="" srcset="https://example.com/b.jpg 2x, https://example.com/a.jpg 1x" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="https://example.com/a.jpg"', $out);
    }

    public function testPrefersWidthOverDensityForFallbackSrc(): void
    {
        $html = '<img src="" srcset="https://example.com/d.jpg 1x, https://example.com/w.jpg 300w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="https://example.com/w.jpg"', $out);
    }

    public function testAbsolutisesDensityOnlySrcsetUrls(): void
    {
        $html = '<img src="" srcset="/img/a.jpg 1x, /img/a@2x.jpg 2x" alt="x">';
        $out = $this->sanitize($html, 'https://example.com/page');
        self::assertStringContainsString('https://example.com/img/a.jpg 1x', $out);
        self::assertStringContainsString('https://example.com/img/a@2x.jpg 2x', $out);
    }

    public function testKeepsDensityEntriesWhenMixedWithWidthEntries(): void
    {
        $html = '<img src="data:image/gif;base64,xxx" '
            . 'srcset="https://example.com/a.jpg 100w, https://example.com/b.jpg 500w, https://example.com/c@2x.jpg 2x" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('https://example.com/a.jpg 100w', $out);
        self::assertStringContainsString('https://example.com/b.jpg 500w', $out);
        self::assertStringContainsString('https://example.com/c@2x.jpg 2x', $out);
    }

    public function testDoesNotDoubleEncodeAmpersandFromSrcset(): void
    {
        $html = '<img src="" srcset="https://example.com/img?w=80&amp;v=1 80w, https://example.com/img?w=500&amp;v=1 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringNotContainsString('&amp;amp;', $out);
    }

    public function testNoOpOnImgWithoutSrcset(): void
    {
        $html = '<img src="https://example.com/x.jpg" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="https://example.com/x.jpg"', $out);
    }

    public function testPreservesBareSrcsetEntryWithoutDescriptor(): void
    {
        $html = '<img src="data:image/gif;base64,xxx" srcset="https://example.com/a.jpg" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('srcset="https://example.com/a.jpg"', $out);
        self::assertStringNotContainsString('https://example.com/a.jpg 1x', $out);
    }

    public function testRewritesSourceSrcsetInPicture(): void
    {
        $html = '<picture>'
            . '<source srcset="/img/a.jpg 500w, /img/b.jpg 1500w" sizes="100vw">'
            . '<img src="/img/fallback.jpg" alt="x">'
            . '</picture>';
        $out = $this->sanitize($html, 'https://example.com/page');
        self::assertStringContainsString('<source ', $out);
        self::assertStringContainsString('https://example.com/img/a.jpg 500w', $out);
        self::assertStringContainsString('https://example.com/img/b.jpg 1500w', $out);
        self::assertStringContainsString('sizes="100vw"', $out);
        // <source> has no src attribute even if the img placeholder logic considered it.
        self::assertDoesNotMatchRegularExpression('/<source[^>]*\ssrc=/', $out);
    }

    public function testPreservesLongInlineBase64Src(): void
    {
        $big = 'data:image/png;base64,' . str_repeat('A', 300);
        $html = '<img src="' . $big . '" srcset="https://example.com/a.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="' . $big . '"', $out);
    }

    public function testPreservesMidSizedInlineBase64Src(): void
    {
        // ~150 chars: comfortably larger than typical 1x1 placeholders but small
        // enough that a loose threshold would have misclassified it.
        $mid = 'data:image/png;base64,' . str_repeat('A', 150);
        $html = '<img src="' . $mid . '" srcset="https://example.com/a.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('src="' . $mid . '"', $out);
    }

    public function testKeepsCommasInsideSrcsetUrls(): void
    {
        // Cloudinary-style transformation URLs contain unencoded commas;
        // splitting on every comma would corrupt them.
        $html = '<img src="" srcset="'
            . 'https://res.example.com/upload/w_300,c_scale/a.jpg 300w, '
            . 'https://res.example.com/upload/w_600,c_scale/a.jpg 600w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('https://res.example.com/upload/w_300,c_scale/a.jpg 300w', $out);
        self::assertStringContainsString('https://res.example.com/upload/w_600,c_scale/a.jpg 600w', $out);
        self::assertStringContainsString('src="https://res.example.com/upload/w_300,c_scale/a.jpg"', $out);
    }

    public function testParsesSrcsetEntriesSeparatedByCommaWithoutSpace(): void
    {
        $html = '<img src="" srcset="https://example.com/a.jpg 100w,https://example.com/b.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringContainsString('https://example.com/a.jpg 100w', $out);
        self::assertStringContainsString('https://example.com/b.jpg 500w', $out);
        self::assertStringContainsString('src="https://example.com/a.jpg"', $out);
    }

    public function testDropsDisallowedSchemesFromSrcset(): void
    {
        // The srcset rewrite runs after replace_urls, so a disallowed scheme must
        // not survive in srcset nor be written into the src fallback.
        $html = '<img src="" srcset="javascript:alert(1) 100w, https://example.com/a.jpg 500w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringContainsString('src="https://example.com/a.jpg"', $out);
    }

    public function testRemovesSrcsetWhenAllEntriesDisallowed(): void
    {
        $html = '<img src="https://example.com/x.jpg" srcset="javascript:alert(1) 100w" alt="x">';
        $out = $this->sanitize($html);
        self::assertStringNotContainsString('srcset', $out);
        self::assertStringContainsString('src="https://example.com/x.jpg"', $out);
    }
}
