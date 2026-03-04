<?php

namespace eventiva\craftchatagent\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use eventiva\craftchatagent\Chatagent;
use eventiva\craftchatagent\jobs\CrawlJob;
use eventiva\craftchatagent\jobs\TrainingJob;

class TrainingController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin();
        return true;
    }

    /**
     * GET chatbot/training
     */
    public function actionIndex(): \yii\web\Response
    {
        $vectorService = Chatagent::$instance->getVectorService();
        $stats         = $vectorService->getStats();
        $entries       = $vectorService->getEntrySummary();
        $allSections   = \craft\records\Section::find()->orderBy(['name' => SORT_ASC])->all();
        $fileStats     = $vectorService->getFileStats();
        $files         = $vectorService->getFileDocuments();
        $crawlStats    = $vectorService->getCrawlStats();
        $crawlUrls     = $vectorService->getCrawlUrls();

        $qaStats = $vectorService->getQaStats();
        $qaPairs = $vectorService->getQaPairs();

        return $this->renderTemplate('chatbot/cp/training/index', [
            'title'       => 'Training',
            'stats'       => $stats,
            'entries'     => $entries,
            'allSections' => $allSections,
            'fileStats'   => $fileStats,
            'files'       => $files,
            'crawlStats'  => $crawlStats,
            'crawlUrls'   => $crawlUrls,
            'qaStats'     => $qaStats,
            'qaPairs'     => $qaPairs,
        ]);
    }

    /**
     * GET chatbot/training/entry/<id>
     */
    public function actionEntry(int $id): \yii\web\Response
    {
        $vectorService = Chatagent::$instance->getVectorService();
        $chunks        = $vectorService->getEntryChunks($id);

        if (empty($chunks)) {
            throw new \yii\web\NotFoundHttpException('Keine Chunks für diese Entry gefunden.');
        }

        $meta    = $chunks[0]['metadata'] ?? [];
        $title   = $meta['entryTitle'] ?? ('Entry #' . $id);
        $url     = $meta['url'] ?? '';
        $db      = $this->getEntrySectionFromDb($id);
        $section = $db['section'] ?? '';

        return $this->renderTemplate('chatbot/cp/training/_entry', [
            'title'    => $title,
            'entryId'  => $id,
            'entryUrl' => $url,
            'section'  => $section,
            'chunks'   => $chunks,
        ]);
    }

    /**
     * GET chatbot/training/url/<id>
     */
    public function actionUrlDetail(int $id): \yii\web\Response
    {
        $vectorService = Chatagent::$instance->getVectorService();
        $urlDoc        = $vectorService->getCrawlUrl($id);

        if (!$urlDoc) {
            throw new \yii\web\NotFoundHttpException('URL nicht gefunden.');
        }

        $chunks = $vectorService->getUrlChunks($id);

        return $this->renderTemplate('chatbot/cp/training/_url', [
            'title'   => $urlDoc['title'] ?: $urlDoc['url'],
            'urlDoc'  => $urlDoc,
            'chunks'  => $chunks,
        ]);
    }

    /**
     * POST chatbot/training/train  (AJAX)
     * Pushes one TrainingJob per Entry into the Craft queue.
     */
    public function actionTrain(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Chatagent::$instance->getChatService()->getSettings();
        $sections = $settings['trainingSections'] ?? [];

        if (empty($sections)) {
            return $this->asJson(['success' => false, 'error' => 'Keine Sections konfiguriert. Bitte zuerst unter Einstellungen → Training Sections auswählen.']);
        }

        $jobCount = 0;
        $errors   = [];

        foreach ($sections as $handle) {
            $entries = Entry::find()
                ->section($handle)
                ->status('live')
                ->limit(null)
                ->ids();

            foreach ($entries as $entryId) {
                Craft::$app->queue->push(new TrainingJob([
                    'entryId'       => $entryId,
                    'sectionHandle' => $handle,
                ]));
                $jobCount++;
            }

            if (empty($entries)) {
                $errors[] = "Section '{$handle}': keine publizierten Entries gefunden.";
            }
        }

        return $this->asJson([
            'success'  => true,
            'jobCount' => $jobCount,
            'errors'   => $errors,
            'message'  => "{$jobCount} Jobs in die Queue eingereiht. Fortschritt unter Dienstprogramme → Warteschlange.",
        ]);
    }

    /**
     * POST chatbot/training/train-entry  (AJAX)
     * Pushes a single TrainingJob into the queue.
     */
    public function actionTrainEntry(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entryId = (int)Craft::$app->getRequest()->getBodyParam('entryId');
        if (!$entryId) {
            return $this->asJson(['success' => false, 'error' => 'entryId fehlt.']);
        }

        $entry = Entry::find()->id($entryId)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['success' => false, 'error' => 'Entry nicht gefunden.']);
        }

        Craft::$app->queue->push(new TrainingJob([
            'entryId'       => $entryId,
            'sectionHandle' => $entry->getSection()->handle ?? '',
        ]));

        return $this->asJson([
            'success' => true,
            'message' => "Training-Job für \"{$entry->title}\" in die Queue eingereiht.",
        ]);
    }

    /**
     * POST chatbot/training/clear-index  (AJAX)
     */
    public function actionClearIndex(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $deleted = Chatagent::$instance->getVectorService()->clearAll();
        return $this->asJson(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * POST chatbot/training/delete-entry  (AJAX)
     */
    public function actionDeleteEntry(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entryId = (int)Craft::$app->getRequest()->getBodyParam('entryId');
        if (!$entryId) {
            return $this->asJson(['success' => false, 'error' => 'entryId fehlt.']);
        }

        Chatagent::$instance->getVectorService()->deleteByEntry($entryId);
        return $this->asJson(['success' => true]);
    }

    /**
     * GET chatbot/training/status  (AJAX polling)
     */
    public function actionStatus(): \yii\web\Response
    {
        $this->requireAcceptsJson();
        $stats    = Chatagent::$instance->getVectorService()->getStats();
        $queueSize = Craft::$app->queue->getTotalJobs();
        return $this->asJson(['success' => true, 'stats' => $stats, 'queueSize' => $queueSize]);
    }

    /**
     * POST chatbot/training/upload-file  (AJAX, multipart)
     */
    public function actionUploadFile(): \yii\web\Response
    {
        $this->requirePostRequest();

        $file = \yii\web\UploadedFile::getInstanceByName('file');

        if (!$file) {
            return $this->asJson(['success' => false, 'error' => 'Keine Datei ausgewählt.']);
        }

        $ext = strtolower($file->extension);
        if (!in_array($ext, ['txt', 'md'], true)) {
            return $this->asJson(['success' => false, 'error' => 'Ungültiger Dateityp. Nur .txt und .md erlaubt.']);
        }

        if ($file->size > 5 * 1024 * 1024) {
            return $this->asJson(['success' => false, 'error' => 'Datei zu groß. Maximal 5 MB erlaubt.']);
        }

        $content = file_get_contents($file->tempName);
        if ($content === false) {
            return $this->asJson(['success' => false, 'error' => 'Datei konnte nicht gelesen werden.']);
        }

        $vectorService   = Chatagent::$instance->getVectorService();
        $trainingService = Chatagent::$instance->getTrainingService();

        $fileId     = $vectorService->storeFileDocument($file->name, $ext, (int)$file->size, $content);
        $chunkCount = $trainingService->trainFileContent($fileId, $content, $file->name);

        return $this->asJson([
            'success'    => true,
            'fileId'     => $fileId,
            'filename'   => $file->name,
            'chunkCount' => $chunkCount,
            'message'    => "\"{$file->name}\" wurde mit {$chunkCount} Chunks indexiert.",
        ]);
    }

    /**
     * POST chatbot/training/delete-file  (AJAX)
     */
    public function actionDeleteFile(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $fileId = (int)Craft::$app->getRequest()->getBodyParam('fileId');
        if (!$fileId) {
            return $this->asJson(['success' => false, 'error' => 'fileId fehlt.']);
        }

        Chatagent::$instance->getVectorService()->deleteFileDocument($fileId);
        return $this->asJson(['success' => true]);
    }

    /**
     * POST chatbot/training/retrain-file  (AJAX)
     */
    public function actionRetrainFile(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $fileId = (int)Craft::$app->getRequest()->getBodyParam('fileId');
        if (!$fileId) {
            return $this->asJson(['success' => false, 'error' => 'fileId fehlt.']);
        }

        $vectorService = Chatagent::$instance->getVectorService();
        $fileDoc       = $vectorService->getFileDocument($fileId);

        if (!$fileDoc) {
            return $this->asJson(['success' => false, 'error' => 'Datei nicht gefunden.']);
        }

        $content    = $vectorService->getFileContent($fileId);
        $chunkCount = Chatagent::$instance->getTrainingService()->trainFileContent($fileId, $content, $fileDoc['original_name']);

        return $this->asJson([
            'success'    => true,
            'chunkCount' => $chunkCount,
            'message'    => "\"{$fileDoc['original_name']}\" neu trainiert: {$chunkCount} Chunks.",
        ]);
    }

    // ── URL Crawl Actions ──────────────────────────────────────────────

    /**
     * POST chatbot/training/add-urls  (AJAX)
     * Accepts a newline-separated list of URLs and adds them to the crawl list.
     */
    public function actionAddUrls(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $raw  = Craft::$app->getRequest()->getBodyParam('urls', '');
        $urls = array_values(array_unique(array_filter(array_map('trim', explode("\n", (string)$raw)))));

        if (empty($urls)) {
            return $this->asJson(['success' => false, 'error' => 'Keine URLs angegeben.']);
        }

        $added = Chatagent::$instance->getVectorService()->addCrawlUrls($urls);

        return $this->asJson([
            'success' => true,
            'added'   => $added,
            'message' => "{$added} neue URL(s) zur Liste hinzugefügt.",
        ]);
    }

    /**
     * POST chatbot/training/clear-urls  (AJAX)
     * Deletes all crawl URLs and their chunks in one operation.
     */
    public function actionClearUrls(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $count = Chatagent::$instance->getVectorService()->clearAllUrls();

        return $this->asJson(['success' => true, 'deleted' => $count]);
    }

    /**
     * POST chatbot/training/delete-url  (AJAX)
     */
    public function actionDeleteUrl(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $urlId = (int)Craft::$app->getRequest()->getBodyParam('urlId');
        if (!$urlId) {
            return $this->asJson(['success' => false, 'error' => 'urlId fehlt.']);
        }

        Chatagent::$instance->getVectorService()->deleteCrawlUrl($urlId);
        return $this->asJson(['success' => true]);
    }

    /**
     * POST chatbot/training/crawl-url  (AJAX)
     * Pushes a single URL into the Craft queue for crawling.
     */
    public function actionCrawlUrl(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $urlId = (int)Craft::$app->getRequest()->getBodyParam('urlId');
        if (!$urlId) {
            return $this->asJson(['success' => false, 'error' => 'urlId fehlt.']);
        }

        $urlDoc = Chatagent::$instance->getVectorService()->getCrawlUrl($urlId);
        if (!$urlDoc) {
            return $this->asJson(['success' => false, 'error' => 'URL nicht gefunden.']);
        }

        Craft::$app->queue->push(new CrawlJob([
            'urlId' => $urlId,
            'url'   => $urlDoc['url'],
        ]));

        return $this->asJson(['success' => true, 'queued' => true, 'urlId' => $urlId]);
    }

    /**
     * POST chatbot/training/crawl-all  (AJAX)
     * Pushes all pending/error URLs into the Craft queue for crawling.
     */
    public function actionCrawlAll(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $urls   = Chatagent::$instance->getVectorService()->getCrawlUrls();
        $queued = 0;

        foreach ($urls as $urlDoc) {
            Craft::$app->queue->push(new CrawlJob([
                'urlId' => (int)$urlDoc['id'],
                'url'   => $urlDoc['url'],
            ]));
            $queued++;
        }

        return $this->asJson([
            'success' => true,
            'queued'  => $queued,
            'message' => "{$queued} URL(s) zur Warteschlange hinzugefügt.",
        ]);
    }

    /**
     * POST chatbot/training/import-sitemap  (AJAX)
     * Fetches a sitemap XML and adds all discovered URLs to the crawl list.
     */
    public function actionImportSitemap(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sitemapUrl = trim((string)Craft::$app->getRequest()->getBodyParam('sitemapUrl', ''));
        if (empty($sitemapUrl)) {
            return $this->asJson(['success' => false, 'error' => 'Keine Sitemap-URL angegeben.']);
        }

        $crawlService  = Chatagent::$instance->getCrawlService();
        $vectorService = Chatagent::$instance->getVectorService();

        $urls  = $crawlService->parseSitemap($sitemapUrl);
        if (empty($urls)) {
            return $this->asJson(['success' => false, 'error' => 'Keine URLs in der Sitemap gefunden. URL und Format prüfen.']);
        }

        $added = $vectorService->addCrawlUrls($urls);

        return $this->asJson([
            'success'  => true,
            'found'    => count($urls),
            'added'    => $added,
            'message'  => count($urls) . ' URLs gefunden, ' . $added . ' neu hinzugefügt.',
            'urls'     => array_values($vectorService->getCrawlUrls()),
        ]);
    }

    // ── Q&A Pair Actions ───────────────────────────────────────────────

    /**
     * POST chatbot/training/save-qa-pair  (AJAX)
     * Create or update a Q&A pair and embed it immediately.
     */
    public function actionSaveQaPair(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request     = Craft::$app->getRequest();
        $id          = (int)$request->getBodyParam('id', 0) ?: null;
        $question    = trim((string)$request->getBodyParam('question', ''));
        $answer      = trim((string)$request->getBodyParam('answer', ''));
        $source      = (string)$request->getBodyParam('source', 'manual');
        $sourceMsgId = (int)$request->getBodyParam('sourceMsgId', 0) ?: null;

        if (!$question || !$answer) {
            return $this->asJson(['success' => false, 'error' => 'Frage und Antwort sind erforderlich.']);
        }

        $vectorService   = Chatagent::$instance->getVectorService();
        $embeddingService = Chatagent::$instance->getEmbeddingService();

        $qaId = $vectorService->saveQaPair($id, $question, $answer, $source, $sourceMsgId);

        $combinedText = "Frage: {$question}\nAntwort: {$answer}";
        try {
            $embedding = $embeddingService->embed($combinedText);
            $vectorService->storeQaEmbedding($qaId, $embedding, $combinedText, $question);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => true,
                'qaId'    => $qaId,
                'warning' => 'Q&A gespeichert, aber Embedding fehlgeschlagen: ' . $e->getMessage(),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'qaId'    => $qaId,
            'message' => 'Q&A-Paar gespeichert und indexiert.',
        ]);
    }

    /**
     * POST chatbot/training/delete-qa-pair  (AJAX)
     */
    public function actionDeleteQaPair(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $qaId = (int)Craft::$app->getRequest()->getBodyParam('qaId');
        if (!$qaId) {
            return $this->asJson(['success' => false, 'error' => 'qaId fehlt.']);
        }

        Chatagent::$instance->getVectorService()->deleteQaPair($qaId);
        return $this->asJson(['success' => true]);
    }

    /**
     * POST chatbot/training/toggle-qa-pair  (AJAX)
     */
    public function actionToggleQaPair(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $qaId   = (int)Craft::$app->getRequest()->getBodyParam('qaId');
        $active = (bool)Craft::$app->getRequest()->getBodyParam('active', true);
        if (!$qaId) {
            return $this->asJson(['success' => false, 'error' => 'qaId fehlt.']);
        }

        Chatagent::$instance->getVectorService()->toggleQaPair($qaId, $active);
        return $this->asJson(['success' => true, 'active' => $active]);
    }

    private function getEntrySectionFromDb(int $entryId): array
    {
        $summary = Chatagent::$instance->getVectorService()->getEntrySummary();
        foreach ($summary as $row) {
            if ($row['entry_id'] === $entryId) {
                return $row;
            }
        }
        return [];
    }
}
