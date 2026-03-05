<?php

namespace eventiva\craftchatagent\jobs;

use Craft;
use craft\queue\BaseJob;
use eventiva\craftchatagent\Chatagent;

class CrawlJob extends BaseJob
{
    public int    $urlId;
    public string $url = '';

    public function execute($queue): void
    {
        $urlDoc = Chatagent::getInstance()->getVectorService()->getCrawlUrl($this->urlId);

        if (!$urlDoc) {
            Craft::warning("CrawlJob: URL #{$this->urlId} nicht gefunden – Job wird übersprungen.", __METHOD__);
            return;
        }

        try {
            $result = Chatagent::getInstance()->getCrawlService()->crawlAndIndex($this->urlId, $urlDoc['url']);
            Craft::info(
                "CrawlJob: URL #{$this->urlId} ({$urlDoc['url']}) – " .
                ($result['success'] ? $result['chunkCount'] . ' Chunks indexiert.' : 'Fehler: ' . $result['error']),
                __METHOD__
            );
        } catch (\Throwable $e) {
            Craft::error("CrawlJob: URL #{$this->urlId} fehlgeschlagen: " . $e->getMessage(), __METHOD__);
            Chatagent::getInstance()->getVectorService()->updateCrawlUrlStatus(
                $this->urlId, 'error', 0, '', $e->getMessage()
            );
            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Chatbot Crawl: ' . ($this->url ?: "URL #{$this->urlId}");
    }
}
