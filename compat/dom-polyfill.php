<?php

// SPDX-FileCopyrightText: 2026 Alexandre Alapetite
// SPDX-License-Identifier: BSD-3-Clause

// Polyfill PHP ≥ 8.4 `Dom\` classes approximated using legacy `DOM*` classes.

declare(strict_types=1);

namespace Dom;

const HTML_NO_DEFAULT_NS = 2147483648;

// The polyfilled node classes
class_alias(\DOMNode::class, 'Dom\Node', false);
class_alias(\DOMElement::class, 'Dom\Element', false);
class_alias(\DOMText::class, 'Dom\Text', false);
class_alias(\DOMComment::class, 'Dom\Comment', false);
class_alias(\DOMNodeList::class, 'Dom\NodeList', false);
class_alias(\DOMDocument::class, 'Dom\Document', false);

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
    public function registerNamespace($prefix, $namespaceURI)
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
     * @return \DOMNodeList|false
     */
    public function query($expression, $contextNode = null, $registerNodeNS = true)
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
            $document->loadHTML($source, $options);
        } catch (\ValueError $e) {
            // the native API silently creates an empty document.
        }

        return $document;
    }
}
