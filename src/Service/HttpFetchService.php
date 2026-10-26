<?php

namespace App\Service;

class HttpFetchService
{
    private array $responseHeaders = [];

    private static function getTimeout(): int
    {
        return (int)($_ENV['HTTP_FETCH_TIMEOUT'] ?? 15);
    }

    public function getContents(string $url, $context = null): string|false
    {
        if ($context === null) {
            $context = stream_context_create([
                'http' => ['timeout' => self::getTimeout()],
            ]);
        }
        http_clear_last_response_headers();
        $result = @file_get_contents($url, false, $context);
        $headers = http_get_last_response_headers();
        if ($headers !== null) {
            $this->responseHeaders = $headers;
        }
        return $result;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseStatusCode(): int
    {
        if (!empty($this->responseHeaders)) {
            preg_match('/\b\d{3}\b/', $this->responseHeaders[0], $matches);
            return isset($matches[0]) ? (int)$matches[0] : 0;
        }
        return 0;
    }

    public function loadXmlFile(string $url): \SimpleXMLElement|false
    {
        $context = stream_context_create([
            'http' => ['timeout' => self::getTimeout()],
        ]);
        libxml_set_streams_context($context);
        return @simplexml_load_file($url);
    }
}
