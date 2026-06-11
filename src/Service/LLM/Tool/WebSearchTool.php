<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\Http\Client;

/**
 * Пошук в інтернеті через DuckDuckGo (HTML-версія, без API-ключів).
 * Повертає список результатів: title, url, snippet.
 */
final class WebSearchTool implements ToolInterface
{
    private const SEARCH_URL = 'https://html.duckduckgo.com/html/';

    private const DEFAULT_MAX_RESULTS = 5;

    private const MAX_RESULTS_LIMIT = 10;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct(private readonly Client $httpClient) {}

    public function getName(): ToolName
    {
        return ToolName::WEB_SEARCH;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Search the web and return a list of results (title, url, snippet). Use for fresh information, news, facts you are not sure about. To read a full page from the results, call fetch_web_page with its url.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query.',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Number of results to return, 1-10. Defaults to 5.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $query = isset($arguments['query']) && is_string($arguments['query']) ? trim($arguments['query']) : '';
        if ($query === '') {
            throw new \InvalidArgumentException('Query is required.');
        }

        $maxResults = self::DEFAULT_MAX_RESULTS;
        if (isset($arguments['max_results']) && is_numeric($arguments['max_results'])) {
            $maxResults = max(1, min(self::MAX_RESULTS_LIMIT, (int) $arguments['max_results']));
        }

        $url = self::SEARCH_URL . '?' . http_build_query(['q' => $query]);
        $html = $this->httpClient->get($url, [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'uk,en;q=0.8',
        ]);

        $results = $this->parseResults($html, $maxResults);

        return json_encode([
            'query' => $query,
            'results' => $results,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array{title: string, url: string, snippet: ?string}>
     */
    private function parseResults(string $html, int $maxResults): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]');
        if ($links === false) {
            return [];
        }

        $results = [];
        foreach ($links as $link) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $title = trim($link->textContent);
            $href = $this->normalizeResultUrl($link->getAttribute('href'));
            if ($title === '' || $href === null) {
                continue;
            }

            $results[] = [
                'title' => $title,
                'url' => $href,
                'snippet' => $this->findSnippetForLink($xpath, $link),
            ];
        }

        return $results;
    }

    /**
     * DuckDuckGo загортає посилання у редірект /l/?uddg={url} — розгортаємо до прямого URL.
     */
    private function normalizeResultUrl(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }

        $queryString = parse_url($href, PHP_URL_QUERY);
        if (is_string($queryString) && str_contains($href, '/l/')) {
            parse_str($queryString, $params);
            if (isset($params['uddg']) && is_string($params['uddg']) && $params['uddg'] !== '') {
                $href = $params['uddg'];
            }
        }

        return filter_var($href, FILTER_VALIDATE_URL) !== false ? $href : null;
    }

    private function findSnippetForLink(\DOMXPath $xpath, \DOMElement $link): ?string
    {
        $snippetNodes = $xpath->query(
            'ancestor::div[contains(concat(" ", normalize-space(@class), " "), " result ")][1]'
            . '//*[contains(concat(" ", normalize-space(@class), " "), " result__snippet ")]',
            $link,
        );
        if ($snippetNodes === false || $snippetNodes->length === 0) {
            return null;
        }

        $snippet = trim($snippetNodes->item(0)?->textContent ?? '');

        return $snippet !== '' ? $snippet : null;
    }
}
