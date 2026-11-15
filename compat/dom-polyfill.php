<?php

// SPDX-FileCopyrightText: 2026 Alexandre Alapetite
// SPDX-License-Identifier: BSD-3-Clause

// Polyfill PHP ≥ 8.4 `Dom\` classes approximated using legacy `DOM*` classes.

declare(strict_types=1);

namespace Dom;

const HTML_NO_DEFAULT_NS = 2147483648;

// Hide from PHPStan to avoid divergent signature shapes of the legacy aliases
if (!defined('__PHPSTAN_RUNNING__')):

// Aliasing internal classes requires PHP 8.3+
if (\PHP_VERSION_ID >= 80300) {
    // The polyfilled node classes
    class_alias(\DOMNode::class, 'Dom\Node', false);
    class_alias(\DOMElement::class, 'Dom\Element', false);
    class_alias(\DOMText::class, 'Dom\Text', false);
    class_alias(\DOMComment::class, 'Dom\Comment', false);
    class_alias(\DOMNodeList::class, 'Dom\NodeList', false);
    class_alias(\DOMDocument::class, 'Dom\Document', false);
}

/**
 * On the legacy path, the parser never puts elements in any namespace,
 * so rewrite queries written for the namespaced PHP 8.4 parser (e.g. `xh:body//xh:img`).
 */
final class XPath extends \DOMXPath
{
    /** @var array<string, true> */
    private $registeredPrefixes = [];

    /**
     * @param string $prefix
     * @param string $namespaceURI
     */
    public function registerNamespace($prefix, $namespaceURI): bool
    {
        $this->registeredPrefixes[$prefix] = true;
        return parent::registerNamespace($prefix, $namespaceURI);
    }

    private function translate(string $expression): string
    {
        if ($this->registeredPrefixes === []) {
            return $expression;
        }
        foreach (array_keys($this->registeredPrefixes) as $prefix) {
            // NCName-boundary safe:
            $translated = preg_replace('/(?<![\\w.\\-])' . preg_quote($prefix, '/') . ':/', '', $expression);
            if ($translated !== null) {
                $expression = $translated;
            }
        }
        return $expression;
    }

    /**
     * The native API throws upon an invalid expression, rather than returning `false`.
     *
     * @param string $expression
     * @param \DOMNode|null $contextNode
     */
    public function query($expression, $contextNode = null, $registerNodeNS = true): \DOMNodeList
    {
        $translated = $this->translate($expression);
        $result = @parent::query($translated, $contextNode, $registerNodeNS);
        if ($result === false) {
            throw new \Exception('Invalid XPath expression: ' . $translated);
        }
        return $result;
    }
}

/**
 * Maps `\Dom\HTMLDocument::createFromString()` onto `\DOMDocument::loadHTML()`.
 */
final class HTMLDocument extends \DOMDocument
{
    /**
     * @return static
     */
    public static function createFromString(string $source, ?int $options = null, ?string $overrideEncoding = null)
    {
        $document = new self();
        $options = ($options ?? 0) | LIBXML_NOERROR | LIBXML_NOWARNING;

        if ($source !== '' && !preg_match('#<(?:meta|\?xml)[^>]*?(?:charset|encoding)\\s*=#i', $source)) {
            // If the source itself declares an encoding, let the parser honour it, otherwise UTF-8:
            $source = '<?xml encoding="UTF-8">' . $source;
        }

        try {
            // `HTML_NO_DEFAULT_NS` is unknown to the legacy `loadHTML()`
            $document->loadHTML($source, $options & ~HTML_NO_DEFAULT_NS);
        } catch (\ValueError $e) {
            // the native API silently creates an empty document.
        }

        return $document;
    }

    /**
     * Normalise to `attr=""` for parity.
     * It also emits end tags for void elements (e.g. `</source>`).
     *
     * @return string|false
     */
    #[\ReturnTypeWillChange]
    public function saveHTML(?\DOMNode $node = null)
    {
        $html = parent::saveHTML($node);
        if ($html === false) {
            return false;
        }
        // Drop void-element end tags emitted for e.g. `<source>`:
        $html = (string) preg_replace('#(<(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)\b[^>]*?>)</\2>#i', '$1', $html);
        return (string) preg_replace_callback('#<[\w:-]+[^>]*?>#', static function (array $match): string {
            $bools = 'allowfullscreen|async|autofocus|autoplay|checked|controls|default|defer|disabled|formnovalidate|hidden|inert|ismap|itemscope|loop|multiple|muted|nomodule|novalidate|open|playsinline|readonly|required|reversed|selected';
            // Only scan outside quoted attribute values, which have no raw `"`:
            $parts = preg_split('#(="[^"]*")#', $match[0], -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                return $match[0];
            }
            foreach ($parts as $i => $part) {
                if ($i % 2 === 0) {
                    $replaced = preg_replace('#\s(' . $bools . ')(?=[\s/>])#i', ' $1=""', $part);
                    if ($replaced !== null) {
                        $parts[$i] = $replaced;
                    }
                }
            }
            return implode('', $parts);
        }, $html) ?: $html;
    }
}

endif;
