<?php

namespace eventiva\craftchatagent\controllers;

use Craft;
use craft\web\Controller;
use eventiva\craftchatagent\Chatagent;

class DashboardController extends Controller
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
     * GET chatbot  →  Dashboard Übersicht
     */
    public function actionIndex(): \yii\web\Response
    {
        $request = Craft::$app->getRequest();
        $to   = $request->getQueryParam('to',   date('Y-m-d'));
        $from = $request->getQueryParam('from', date('Y-m-d', strtotime('-6 days')));

        $chatStats       = Chatagent::$instance->getLogsService()->getStatsForDateRange($from, $to);
        $vectorStats     = Chatagent::$instance->getVectorService()->getStats();
        $fileStats       = Chatagent::$instance->getVectorService()->getFileStats();
        $suggestionStats = Chatagent::$instance->getLogsService()->getSuggestionStats($from, $to);

        // Flatten daily data into ordered chart arrays
        $chartLabels   = [];
        $chartSessions = [];
        $chartMessages = [];
        foreach ($chatStats['dailySessions'] as $date => $count) {
            $parts           = explode('-', $date);
            $chartLabels[]   = $parts[2] . '.' . $parts[1] . '.';
            $chartSessions[] = $count;
            $chartMessages[] = $chatStats['dailyMessages'][$date] ?? 0;
        }

        $settings = Chatagent::$instance->getChatService()->getSettings();

        return $this->renderTemplate('chatbot/cp/dashboard/index', [
            'title'         => 'Dashboard',
            'chatStats'     => $chatStats,
            'vectorStats'   => $vectorStats,
            'fileStats'     => $fileStats,
            'chartLabels'   => $chartLabels,
            'chartSessions' => $chartSessions,
            'chartMessages' => $chartMessages,
            'from'            => $from,
            'to'              => $to,
            'enableRatings'   => !empty($settings['enableRatings']),
            'suggestionStats' => $suggestionStats,
        ]);
    }

    /**
     * GET chatbot/dashboard/stats  →  JSON für AJAX-Refresh
     */
    public function actionStats(): \yii\web\Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $from = $request->getParam('from', date('Y-m-d', strtotime('-6 days')));
        $to   = $request->getParam('to',   date('Y-m-d'));

        $chatStats       = Chatagent::$instance->getLogsService()->getStatsForDateRange($from, $to);
        $vectorStats     = Chatagent::$instance->getVectorService()->getStats();
        $fileStats       = Chatagent::$instance->getVectorService()->getFileStats();
        $suggestionStats = Chatagent::$instance->getLogsService()->getSuggestionStats($from, $to);

        return $this->asJson([
            'success'         => true,
            'chatStats'       => $chatStats,
            'vectorStats'     => $vectorStats,
            'fileStats'       => $fileStats,
            'suggestionStats' => $suggestionStats,
        ]);
    }
}
