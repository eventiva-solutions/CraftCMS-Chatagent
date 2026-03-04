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
     * GET chatagent/training
     */
    public function actionIndex(): \yii\web\Response
    {
        $vectorService = Chatagent::getInstance()->getVectorService();

        return $this->renderTemplate('chatbot/cp/training/index', [
            'title'       => Craft::t('chatagent', 'Training'),
            'stats'       => $vectorService->getStats(),
            'entries'     => $vectorService->getEntrySummary(),
            'allSections' => \craft\records\Section::find()->orderBy(['name' => SORT_ASC])->all(),
            'fileStats'   => $vectorService->getFileStats(),
            'files'       => $vectorService->getFileDocuments(),
            'crawlStats'  => $vectorService->getCrawlStats(),
            'crawlUrls'   => $vectorService->getCrawlUrls(),
            'qaStats'     => $vectorService->getQaStats(),
            'qaPairs'     => $vectorService->getQaPairs(),
        ]);
    }

    /**
     * GET chatagent/training/entry/<id>
     */
    public function actionEntry(int $id): \yii\web\Response
    {
        $vectorService = Chatagent::getInstance()->getVectorService();
        $chunks        = $vectorService->getEntryChunks($id);

        if (empty($chunks)) {
            throw new \yii\web\NotFoundHttpException(Craft::t('chatagent', 'No chunks found for this entry.'));
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
     * GET chatagent/training/url/<id>
     */
    public function actionUrlDetail(int $id): \yii\web\Response
    {
        $vectorService = Chatagent::getInstance()->getVectorService();
        $urlDoc        = $vectorService->getCrawlUrl($id);

        if (!$urlDoc) {
            throw new \yii\web\NotFoundHttpException(Craft::t('chatagent', 'URL not found.'));
        }

        return $this->renderTemplate('chatbot/cp/training/_url', [
            'title'  => $urlDoc['title'] ?: $urlDoc['url'],
            'urlDoc' => $urlDoc,
            'chunks' => $vectorService->getUrlChunks($id),
        ]);
    }

    /**
     * POST chatagent/training/train  (AJAX)
     */
    public function actionTrain(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Chatagent::getInstance()->getChatService()->getSettings();
        $sections = $settings['trainingSections'] ?? [];

        if (empty($sections)) {
            return $this->asJson([
                'success' => false,
                'error'   => Craft::t('chatagent', 'No sections configured. Please select training sections under Settings first.'),
            ]);
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
                $errors[] = Craft::t('chatagent', "Section '{handle}': no published entries found.", ['handle' => $handle]);
            }
        }

        return $this->asJson([
            'success'  => true,
            'jobCount' => $jobCount,
            'errors'   => $errors,
            'message'  => Craft::t('chatagent', '{count} jobs queued. See Utilities → Queue for progress.', ['count' => $jobCount]),
        ]);
    }

    /**
     * POST chatagent/training/train-entry  (AJAX)
     */
    public function actionTrainEntry(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entryId = (int)Craft::$app->getRequest()->getBodyParam('entryId');
        if (!$entryId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'entryId is missing.')]);
        }

        $entry = Entry::find()->id($entryId)->status(null)->one();
        if (!$entry) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Entry not found.')]);
        }

        Craft::$app->queue->push(new TrainingJob([
            'entryId'       => $entryId,
            'sectionHandle' => $entry->getSection()->handle ?? '',
        ]));

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('chatagent', 'Training job for "{title}" queued.', ['title' => $entry->title]),
        ]);
    }

    /**
     * POST chatagent/training/clear-index  (AJAX)
     */
    public function actionClearIndex(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $deleted = Chatagent::getInstance()->getVectorService()->clearAll();

        return $this->asJson(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * POST chatagent/training/delete-entry  (AJAX)
     */
    public function actionDeleteEntry(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $entryId = (int)Craft::$app->getRequest()->getBodyParam('entryId');
        if (!$entryId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'entryId is missing.')]);
        }

        Chatagent::getInstance()->getVectorService()->deleteByEntry($entryId);

        return $this->asJson(['success' => true]);
    }

    /**
     * GET chatagent/training/status  (AJAX polling)
     */
    public function actionStatus(): \yii\web\Response
    {
        $this->requireAcceptsJson();

        return $this->asJson([
            'success'   => true,
            'stats'     => Chatagent::getInstance()->getVectorService()->getStats(),
            'queueSize' => Craft::$app->queue->getTotalJobs(),
        ]);
    }

    /**
     * POST chatagent/training/upload-file  (AJAX, multipart)
     */
    public function actionUploadFile(): \yii\web\Response
    {
        $this->requirePostRequest();

        $file = \yii\web\UploadedFile::getInstanceByName('file');

        if (!$file) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'No file selected.')]);
        }

        $ext = strtolower($file->extension);
        if (!in_array($ext, ['txt', 'md'], true)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Invalid file type. Only .txt and .md are allowed.')]);
        }

        if ($file->size > 5 * 1024 * 1024) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'File too large. Maximum 5 MB allowed.')]);
        }

        $content = file_get_contents($file->tempName);
        if ($content === false) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'File could not be read.')]);
        }

        $vectorService   = Chatagent::getInstance()->getVectorService();
        $trainingService = Chatagent::getInstance()->getTrainingService();

        $fileId     = $vectorService->storeFileDocument($file->name, $ext, (int)$file->size, $content);
        $chunkCount = $trainingService->trainFileContent($fileId, $content, $file->name);

        return $this->asJson([
            'success'    => true,
            'fileId'     => $fileId,
            'filename'   => $file->name,
            'chunkCount' => $chunkCount,
            'message'    => Craft::t('chatagent', '"{filename}" indexed with {count} chunks.', ['filename' => $file->name, 'count' => $chunkCount]),
        ]);
    }

    /**
     * POST chatagent/training/delete-file  (AJAX)
     */
    public function actionDeleteFile(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $fileId = (int)Craft::$app->getRequest()->getBodyParam('fileId');
        if (!$fileId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'fileId is missing.')]);
        }

        Chatagent::getInstance()->getVectorService()->deleteFileDocument($fileId);

        return $this->asJson(['success' => true]);
    }

    /**
     * POST chatagent/training/retrain-file  (AJAX)
     */
    public function actionRetrainFile(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $fileId = (int)Craft::$app->getRequest()->getBodyParam('fileId');
        if (!$fileId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'fileId is missing.')]);
        }

        $vectorService = Chatagent::getInstance()->getVectorService();
        $fileDoc       = $vectorService->getFileDocument($fileId);

        if (!$fileDoc) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'File not found.')]);
        }

        $content    = $vectorService->getFileContent($fileId);
        $chunkCount = Chatagent::getInstance()->getTrainingService()->trainFileContent($fileId, $content, $fileDoc['original_name']);

        return $this->asJson([
            'success'    => true,
            'chunkCount' => $chunkCount,
            'message'    => Craft::t('chatagent', '"{filename}" retrained: {count} chunks.', ['filename' => $fileDoc['original_name'], 'count' => $chunkCount]),
        ]);
    }

    // ── URL Crawl Actions ──────────────────────────────────────────────

    /**
     * POST chatagent/training/add-urls  (AJAX)
     */
    public function actionAddUrls(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $raw  = Craft::$app->getRequest()->getBodyParam('urls', '');
        $urls = array_values(array_unique(array_filter(array_map('trim', explode("\n", (string)$raw)))));

        if (empty($urls)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'No URLs provided.')]);
        }

        $added = Chatagent::getInstance()->getVectorService()->addCrawlUrls($urls);

        return $this->asJson([
            'success' => true,
            'added'   => $added,
            'message' => Craft::t('chatagent', '{count} new URL(s) added to the list.', ['count' => $added]),
        ]);
    }

    /**
     * POST chatagent/training/clear-urls  (AJAX)
     */
    public function actionClearUrls(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $count = Chatagent::getInstance()->getVectorService()->clearAllUrls();

        return $this->asJson(['success' => true, 'deleted' => $count]);
    }

    /**
     * POST chatagent/training/delete-url  (AJAX)
     */
    public function actionDeleteUrl(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $urlId = (int)Craft::$app->getRequest()->getBodyParam('urlId');
        if (!$urlId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'urlId is missing.')]);
        }

        Chatagent::getInstance()->getVectorService()->deleteCrawlUrl($urlId);

        return $this->asJson(['success' => true]);
    }

    /**
     * POST chatagent/training/crawl-url  (AJAX)
     */
    public function actionCrawlUrl(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $urlId = (int)Craft::$app->getRequest()->getBodyParam('urlId');
        if (!$urlId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'urlId is missing.')]);
        }

        $urlDoc = Chatagent::getInstance()->getVectorService()->getCrawlUrl($urlId);
        if (!$urlDoc) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'URL not found.')]);
        }

        Craft::$app->queue->push(new CrawlJob([
            'urlId' => $urlId,
            'url'   => $urlDoc['url'],
        ]));

        return $this->asJson(['success' => true, 'queued' => true, 'urlId' => $urlId]);
    }

    /**
     * POST chatagent/training/crawl-all  (AJAX)
     */
    public function actionCrawlAll(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $urls   = Chatagent::getInstance()->getVectorService()->getCrawlUrls();
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
            'message' => Craft::t('chatagent', '{count} URL(s) added to queue.', ['count' => $queued]),
        ]);
    }

    /**
     * POST chatagent/training/import-sitemap  (AJAX)
     */
    public function actionImportSitemap(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sitemapUrl = trim((string)Craft::$app->getRequest()->getBodyParam('sitemapUrl', ''));
        if (empty($sitemapUrl)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'No sitemap URL provided.')]);
        }

        $crawlService  = Chatagent::getInstance()->getCrawlService();
        $vectorService = Chatagent::getInstance()->getVectorService();

        $urls = $crawlService->parseSitemap($sitemapUrl);
        if (empty($urls)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'No URLs found in the sitemap. Check the URL and format.')]);
        }

        $added = $vectorService->addCrawlUrls($urls);
        $found = count($urls);

        return $this->asJson([
            'success' => true,
            'found'   => $found,
            'added'   => $added,
            'message' => Craft::t('chatagent', '{found} URLs found, {added} newly added.', ['found' => $found, 'added' => $added]),
            'urls'    => array_values($vectorService->getCrawlUrls()),
        ]);
    }

    // ── Q&A Pair Actions ───────────────────────────────────────────────

    /**
     * POST chatagent/training/save-qa-pair  (AJAX)
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
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Question and answer are required.')]);
        }

        $vectorService    = Chatagent::getInstance()->getVectorService();
        $embeddingService = Chatagent::getInstance()->getEmbeddingService();

        $qaId         = $vectorService->saveQaPair($id, $question, $answer, $source, $sourceMsgId);
        $combinedText = "Frage: {$question}\nAntwort: {$answer}";

        try {
            $embedding = $embeddingService->embed($combinedText);
            $vectorService->storeQaEmbedding($qaId, $embedding, $combinedText, $question);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => true,
                'qaId'    => $qaId,
                'warning' => Craft::t('chatagent', 'Q&A saved, but embedding failed: {error}', ['error' => $e->getMessage()]),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'qaId'    => $qaId,
            'message' => Craft::t('chatagent', 'Q&A pair saved and indexed.'),
        ]);
    }

    /**
     * POST chatagent/training/delete-qa-pair  (AJAX)
     */
    public function actionDeleteQaPair(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $qaId = (int)Craft::$app->getRequest()->getBodyParam('qaId');
        if (!$qaId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'qaId is missing.')]);
        }

        Chatagent::getInstance()->getVectorService()->deleteQaPair($qaId);

        return $this->asJson(['success' => true]);
    }

    /**
     * POST chatagent/training/toggle-qa-pair  (AJAX)
     */
    public function actionToggleQaPair(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $qaId   = (int)Craft::$app->getRequest()->getBodyParam('qaId');
        $active = (bool)Craft::$app->getRequest()->getBodyParam('active', true);

        if (!$qaId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'qaId is missing.')]);
        }

        Chatagent::getInstance()->getVectorService()->toggleQaPair($qaId, $active);

        return $this->asJson(['success' => true, 'active' => $active]);
    }

    private function getEntrySectionFromDb(int $entryId): array
    {
        $summary = Chatagent::getInstance()->getVectorService()->getEntrySummary();

        foreach ($summary as $row) {
            if ($row['entry_id'] === $entryId) {
                return $row;
            }
        }

        return [];
    }
}
