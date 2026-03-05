<?php

namespace eventiva\craftchatagent\services;

use Craft;
use eventiva\craftchatagent\Chatagent;
use yii\base\Component;

class CrawlService extends Component
{
    private const FETCH_TIMEOUT = 15; // seconds per URL

    /**
     * Fetch a URL via cURL and return raw HTML.
     * Returns null on error.
     */
    public function fetchUrl(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::FETCH_TIMEOUT);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ChatbotIndexer/1.0 (Craft CMS)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9']);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $response === false) {
            Craft::warning("CrawlService: cURL error fetching {$url}: {$curlError}", __METHOD__);
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 400) {
            Craft::warning("CrawlService: HTTP {$httpCode} for {$url}", __METHOD__);
            return null;
        }

        return $response;
    }

    /**
     * Extract title and clean text from HTML using DOMDocument.
     * Reliable for large pages – no regex backtracking issues.
     *
     * @return array{title: string, text: string}
     */
    public function extractTextFromHtml(string $html): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Ensure UTF-8 is declared so DOMDocument handles entities correctly
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Extract <title>
        $title = '';
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        // Remove elements that add noise
        foreach (['//script', '//style', '//noscript', '//iframe',
                  '//nav',    '//footer', '//header',  '//aside',
                  '//form',   '//svg',    '//button'] as $sel) {
            foreach (iterator_to_array($xpath->query($sel)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        // Prefer <main>, then <article>, then <body>
        $contentNode = null;
        foreach (['//main', '//article', '//body'] as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes->length > 0) {
                $contentNode = $nodes->item(0);
                break;
            }
        }

        if (!$contentNode) {
            return ['title' => $title, 'text' => ''];
        }

        $text = $this->extractNodeText($contentNode);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n[ \t]+/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return ['title' => $title, 'text' => $text];
    }

    /**
     * Recursively extract text from a DOM node,
     * inserting newlines at block-level elements.
     */
    private function extractNodeText(\DOMNode $node): string
    {
        static $blockTags = ['p','div','section','article','li','ul','ol',
                             'h1','h2','h3','h4','h5','h6','tr','td','th',
                             'br','blockquote','pre','figure','figcaption'];
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $tag      = strtolower($child->nodeName);
                $inner    = $this->extractNodeText($child);
                $text    .= in_array($tag, $blockTags, true)
                    ? "\n" . trim($inner) . "\n"
                    : $inner;
            }
        }
        return $text;
    }

    /**
     * Fetch and parse a sitemap XML, returning an array of URLs.
     * Handles both sitemap index files and regular sitemaps.
     * Recurses into nested sitemaps (max depth 2).
     */
    public function parseSitemap(string $sitemapUrl, int $depth = 0): array
    {
        if ($depth > 2) return [];

        $xml = $this->fetchUrl($sitemapUrl);
        if (!$xml) return [];

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) return [];

        $urls = [];

        // Sitemap index → recurse into child sitemaps
        foreach ($doc->sitemap as $sitemap) {
            $loc = trim((string)$sitemap->loc);
            if ($loc) {
                $subUrls = $this->parseSitemap($loc, $depth + 1);
                $urls    = array_merge($urls, $subUrls);
            }
        }

        // Regular sitemap → collect <loc> URLs
        foreach ($doc->url as $url) {
            $loc = trim((string)$url->loc);
            if ($loc) {
                $urls[] = $loc;
            }
        }

        return array_unique(array_values(array_filter($urls)));
    }

    /**
     * Crawl a URL, extract text, chunk, embed, and store in Vector DB.
     *
     * @return array{success: bool, chunkCount: int, title: string, error: string}
     */
    public function crawlAndIndex(int $urlId, string $url): array
    {
        $vectorService   = Chatagent::getInstance()->getVectorService();
        $embeddingService = Chatagent::getInstance()->getEmbeddingService();

        // Fetch HTML
        $html = $this->fetchUrl($url);
        if ($html === null) {
            $error = "URL konnte nicht abgerufen werden.";
            $vectorService->updateCrawlUrlStatus($urlId, 'error', 0, '', $error);
            return ['success' => false, 'chunkCount' => 0, 'title' => '', 'error' => $error];
        }

        // Extract text
        ['title' => $title, 'text' => $text] = $this->extractTextFromHtml($html);

        if (empty(trim($text))) {
            $error = "Kein nutzbarer Text gefunden.";
            $vectorService->updateCrawlUrlStatus($urlId, 'error', 0, $title, $error);
            return ['success' => false, 'chunkCount' => 0, 'title' => $title, 'error' => $error];
        }

        // Chunk + Embed
        $chunkTexts = $embeddingService->chunkText($text);
        if (empty($chunkTexts)) {
            $error = "Text zu kurz zum Chunken.";
            $vectorService->updateCrawlUrlStatus($urlId, 'error', 0, $title, $error);
            return ['success' => false, 'chunkCount' => 0, 'title' => $title, 'error' => $error];
        }

        $metadata = ['url' => $url, 'title' => $title, 'source' => 'url'];

        $chunks = [];
        foreach ($chunkTexts as $chunkText) {
            $embedding = $embeddingService->embed($chunkText);
            $chunks[]  = ['text' => $chunkText, 'embedding' => $embedding, 'metadata' => $metadata];
        }

        $vectorService->storeUrlChunks($urlId, $chunks);
        $vectorService->updateCrawlUrlStatus($urlId, 'indexed', count($chunks), $title);

        return ['success' => true, 'chunkCount' => count($chunks), 'title' => $title, 'error' => ''];
    }
}
