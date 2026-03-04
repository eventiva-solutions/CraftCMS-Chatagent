<?php

namespace eventiva\craftchatagent\controllers;

use Craft;
use craft\web\Controller;
use eventiva\craftchatagent\Chatagent;

class LogsController extends Controller
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
     * GET chatbot
     * Sessions overview with pagination.
     */
    public function actionIndex(): \yii\web\Response
    {
        $request = Craft::$app->getRequest();
        $page       = (int)$request->getQueryParam('page', 1);
        $search     = $request->getQueryParam('search', '');
        $rating     = $request->getQueryParam('rating', '');
        $confidence = $request->getQueryParam('confidence', '');

        $logsService   = Chatagent::$instance->getLogsService();
        $settings      = Chatagent::$instance->getChatService()->getSettings();
        $enableRatings = !empty($settings['enableRatings']);

        $result = $logsService->getSessions($page, 25, $search, $enableRatings ? $rating : '', $confidence);

        $sessionIds  = array_map(fn($s) => $s->id, $result['sessions']);
        $ratings     = $enableRatings ? $logsService->getSessionRatings($sessionIds) : [];
        $confidences = $logsService->getSessionMinConfidences($sessionIds);

        return $this->renderTemplate('chatbot/cp/logs/index', array_merge($result, [
            'search'        => $search,
            'rating'        => $rating,
            'confidence'    => $confidence,
            'ratings'       => $ratings,
            'confidences'   => $confidences,
            'enableRatings' => $enableRatings,
            'title'         => 'Chatbot Logs',
        ]));
    }

    /**
     * GET chatbot/logs/<id>
     * Single session conversation view.
     */
    public function actionSession(int $id): \yii\web\Response
    {
        $logsService = Chatagent::$instance->getLogsService();
        $session = $logsService->getSessionById($id);

        if (!$session) {
            throw new \yii\web\NotFoundHttpException('Session nicht gefunden.');
        }

        $messages = $logsService->getSessionMessages($id);

        // Load message IDs already used as Q&A training source (cross-DB lookup)
        $qaSourceMsgIds = Chatagent::$instance->getVectorService()->getQaSourceMsgIds();

        return $this->renderTemplate('chatbot/cp/logs/_session', [
            'session'        => $session,
            'messages'       => $messages,
            'qaSourceMsgIds' => $qaSourceMsgIds,
            'title'          => 'Session #' . $id,
        ]);
    }

    /**
     * POST chatbot/logs/delete
     * Delete a session.
     */
    public function actionDelete(): \yii\web\Response
    {
        $this->requirePostRequest();

        $id = (int)Craft::$app->getRequest()->getBodyParam('id');

        if (Chatagent::$instance->getLogsService()->deleteSession($id)) {
            Craft::$app->getSession()->setNotice('Session gelöscht.');
        } else {
            Craft::$app->getSession()->setError('Session konnte nicht gelöscht werden.');
        }

        return $this->redirect('chatbot/logs');
    }

    /**
     * GET chatbot/settings
     * Settings form.
     */
    public function actionSettings(): \yii\web\Response
    {
        $settings = Chatagent::$instance->getChatService()->getSettings();

        $logoAsset = null;
        if (!empty($settings['logoAssetId'])) {
            $logoAsset = Craft::$app->getAssets()->getAssetById((int)$settings['logoAssetId']);
        }

        $allSections = \craft\records\Section::find()->orderBy(['name' => SORT_ASC])->all();

        return $this->renderTemplate('chatbot/cp/settings', [
            'settings'    => $settings,
            'logoAsset'   => $logoAsset,
            'allSections' => $allSections,
            'title'       => 'Chatbot Einstellungen',
        ]);
    }

    /**
     * POST chatbot/settings/save
     * Save settings.
     */
    public function actionSaveSettings(): \yii\web\Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $enabled             = $request->getBodyParam('enabled');
        $logConversations    = $request->getBodyParam('logConversations');
        $autoTrainOnSave     = $request->getBodyParam('autoTrainOnSave');
        $enableRatings       = $request->getBodyParam('enableRatings');
        $suggestionsEnabled  = $request->getBodyParam('suggestionsEnabled');

        // elementSelectField sends IDs as array: logoAssetId[] = [123]
        $logoAssetIdRaw = $request->getBodyParam('logoAssetId');
        $logoAssetId = 0;
        if (is_array($logoAssetIdRaw) && !empty($logoAssetIdRaw)) {
            $logoAssetId = (int)$logoAssetIdRaw[0];
        } elseif ($logoAssetIdRaw) {
            $logoAssetId = (int)$logoAssetIdRaw;
        }

        // trainingSections comes as array of handles
        $trainingSections = $request->getBodyParam('trainingSections', []);
        if (!is_array($trainingSections)) {
            $trainingSections = [];
        }

        // suggestions: textarea with one suggestion per line
        $suggestionsRaw = $request->getBodyParam('suggestions', '');
        $suggestions = array_values(array_filter(array_map('trim', explode("\n", (string)$suggestionsRaw))));

        $settings = [
            'companyName'        => $request->getBodyParam('companyName', ''),
            'logoText'           => $request->getBodyParam('logoText', ''),
            'logoAssetId'        => $logoAssetId,
            'primaryColor'       => $request->getBodyParam('primaryColor', '#7C3AED'),
            'logoBgColor'        => $request->getBodyParam('logoBgColor', '#7C3AED'),
            'initialMessage'     => $request->getBodyParam('initialMessage', ''),
            'defaultTheme'       => $request->getBodyParam('defaultTheme', 'light'),
            'enabled'            => ($enabled === '1' || $enabled === 1 || $enabled === true),
            'logConversations'   => ($logConversations === '1' || $logConversations === 1 || $logConversations === true),
            'logRetentionDays'   => (int)$request->getBodyParam('logRetentionDays', 90),
            'systemPrompt'       => $request->getBodyParam('systemPrompt', ''),
            'openaiApiKey'       => $request->getBodyParam('openaiApiKey', ''),
            'openaiModel'        => $request->getBodyParam('openaiModel', 'gpt-4o-mini'),
            'embeddingModel'     => $request->getBodyParam('embeddingModel', 'text-embedding-3-small'),
            'trainingSections'   => $trainingSections,
            'autoTrainOnSave'    => ($autoTrainOnSave === '1' || $autoTrainOnSave === 1 || $autoTrainOnSave === true),
            'enableRatings'      => ($enableRatings === '1' || $enableRatings === 1 || $enableRatings === true),
            'suggestionsEnabled' => ($suggestionsEnabled === '1' || $suggestionsEnabled === 1 || $suggestionsEnabled === true),
            'suggestions'        => $suggestions,
            'maxContextChunks'   => (int)$request->getBodyParam('maxContextChunks', 5),
            'minSimilarityScore' => (float)$request->getBodyParam('minSimilarityScore', 0.65),
        ];

        if (Chatagent::$instance->getChatService()->saveSettings($settings)) {
            Craft::$app->getSession()->setNotice('Einstellungen gespeichert.');
        } else {
            Craft::$app->getSession()->setError('Fehler beim Speichern.');
        }

        return $this->redirect('chatagent/settings');
    }
}
