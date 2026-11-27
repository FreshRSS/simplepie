<?php

declare(strict_types=1);

namespace SimplePie\Tests\Fixtures;

use SimplePie\File;

/**
 * Performs a real `File` construction with proxy options, but stops just before
 * connecting, and records the proxy URL given to the SSRF check
 * (`get_curl_resolve_info()`), so that tests can assert the normalization contract.
 */
class FileProxyResolveMock extends File
{
    /** @var ?string */
    public $resolvedUrl;

    public static function create(string $proxy, int $proxyType, ?int $proxyPort = null): self
    {
        $curl_options = [
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => $proxyType,
        ];
        if ($proxyPort !== null) {
            $curl_options[CURLOPT_PROXYPORT] = $proxyPort;
        }

        return new self('http://example.com/feed', 10, 5, [], 'SimplePie tests', false, $curl_options);
    }

    /**
     * @return array<string>|null|false
     */
    protected function get_curl_resolve_info(string $url)
    {
        $this->resolvedUrl = $url;

        return null;
    }
}
