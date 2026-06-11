<?php

declare(strict_types=1);

namespace App\Service\LLM\Tool;

use App\Enum\ToolName;
use App\Service\Http\Client;

/**
 * Завантажує сторінку за URL і повертає очищений вміст (без script/style),
 * обрізаний до ліміту, щоб не переповнити контекст LLM.
 */
final class FetchWebPageTool implements ToolInterface
{
    private const MAX_CONTENT_LENGTH = 20000;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private const STRIPPED_TAGS = ['script', 'style', 'noscript', 'svg', 'iframe', 'template'];

    public function __construct(private readonly Client $httpClient) {}

    public function getName(): ToolName
    {
        return ToolName::FETCH_WEB_PAGE;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->getName()->value,
                'description' => 'Fetch a web page by URL and return its cleaned content (title and visible text without scripts/styles). Use when the user shares a link or you need the content of a specific page.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Full URL of the page, e.g. https://example.com/article.',
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $url = isset($arguments['url']) && is_string($arguments['url']) ? trim($arguments['url']) : '';
        if ($url === '') {
            throw new \InvalidArgumentException('URL is required.');
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(sprintf('Invalid URL: %s', $url));
        }

        $html = $this->httpClient->get($url, [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'uk,en;q=0.8',
        ]);

        [$title, $content] = $this->extractReadableContent($html);

        $truncated = mb_strlen($content) > self::MAX_CONTENT_LENGTH;
        if ($truncated) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH);
        }

        return json_encode([
            'url' => $url,
            'title' => $title,
            'content' => $content,
            'truncated' => $truncated,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function extractReadableContent(string $html): array
    {
        if (trim($html) === '') {
            return [null, ''];
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            // Префікс примушує DOMDocument трактувати документ як UTF-8.
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        foreach (self::STRIPPED_TAGS as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // Live-колекція: видаляємо з кінця, щоб не зсувались індекси.
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        $title = null;
        $titleNode = $dom->getElementsByTagName('title')->item(0);
        if ($titleNode !== null) {
            $title = trim($titleNode->textContent);
            $title = $title !== '' ? $title : null;
        }

        $bodyNode = $dom->getElementsByTagName('body')->item(0);
        $text = $bodyNode !== null ? $bodyNode->textContent : $dom->textContent;

        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*(\n\s*)+/u', "\n\n", $text) ?? $text;

        return [$title, trim($text)];
    }
}
