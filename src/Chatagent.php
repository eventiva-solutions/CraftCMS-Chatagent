<?php

namespace eventiva\craftchatagent;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use craft\web\View;
use eventiva\craftchatagent\jobs\TrainingJob;
use eventiva\craftchatagent\migrations\m000000_000000_chatbot_install;
use eventiva\craftchatagent\migrations\m240101_000001_chatbot_add_rating;
use eventiva\craftchatagent\migrations\m240101_000002_chatbot_add_suggestion;
use eventiva\craftchatagent\migrations\m240101_000003_chatbot_add_confidence;
use eventiva\craftchatagent\models\Settings;
use eventiva\craftchatagent\services\ChatService;
use eventiva\craftchatagent\services\CrawlService;
use eventiva\craftchatagent\services\EmbeddingService;
use eventiva\craftchatagent\services\LogService;
use eventiva\craftchatagent\services\TrainingService;
use eventiva\craftchatagent\services\VectorService;
use eventiva\craftchatagent\twigextensions\ChatbotTwigExtension;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Chatagent plugin
 *
 * @method static Chatagent getInstance()
 * @method Settings getSettings()
 * @author Eventiva <support@eventiva.io>
 * @copyright Eventiva
 * @license https://craftcms.github.io/license/ Craft License
 */
class Chatagent extends Plugin
{
    /**
     * @var mixed|object|null
     */
    public static mixed $instance;
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        self::$instance = $this;

        // Auto-Migration: Tabellen beim ersten Laden anlegen wenn noch nicht vorhanden.
        if (!Craft::$app->getDb()->tableExists('{{%chatbot_sessions}}')) {
            $migration = new m000000_000000_chatbot_install();
            ob_start();
            $migration->up();
            ob_end_clean();
            Craft::info('Chatbot: DB-Tabellen angelegt.', __METHOD__);
        }

        // Auto-Migration: Neue Spalten hinzufügen wenn noch nicht vorhanden.
        if (Craft::$app->getDb()->tableExists('{{%chatbot_messages}}')) {
            $schema = Craft::$app->getDb()->getTableSchema('{{%chatbot_messages}}', true);
            if ($schema && !$schema->getColumn('rating')) {
                $migration = new m240101_000001_chatbot_add_rating();
                ob_start(); $migration->up(); ob_end_clean();
                Craft::info('Chatbot: Rating-Spalte angelegt.', __METHOD__);
            }
            $schema = Craft::$app->getDb()->getTableSchema('{{%chatbot_messages}}', true);
            if ($schema && !$schema->getColumn('suggestion')) {
                $migration = new m240101_000002_chatbot_add_suggestion();
                ob_start(); $migration->up(); ob_end_clean();
                Craft::info('Chatbot: Suggestion-Spalte angelegt.', __METHOD__);
            }
            $schema = Craft::$app->getDb()->getTableSchema('{{%chatbot_messages}}', true);
            if ($schema && !$schema->getColumn('confidenceScore')) {
                $migration = new m240101_000003_chatbot_add_confidence();
                ob_start(); $migration->up(); ob_end_clean();
                Craft::info('Chatbot: ConfidenceScore-Spalte angelegt.', __METHOD__);
            }
        }

        $this->setComponents([
                                 'chat'      => ChatService::class,
                                 'logs'      => LogService::class,
                                 'vector'    => VectorService::class,
                                 'embedding' => EmbeddingService::class,
                                 'training'  => TrainingService::class,
                                 'crawl'     => CrawlService::class,
                             ]);

        // Register Twig extension (web requests only)
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getView()->registerTwigExtension(new ChatbotTwigExtension());
        }

        // Register CP template roots
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['chatbot'] = $this->getBasePath() . '/templates';
            }
        );

        // Register site template roots (for _widget.twig)
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['chatbot'] = $this->getBasePath() . '/templates';
            }
        );

        // Register CP URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['chatagent']                          = 'chatagent/dashboard/index';
                $event->rules['chatagent/dashboard/stats']          = 'chatagent/dashboard/stats';
                $event->rules['chatagent/logs']                     = 'chatagent/logs/index';
                $event->rules['chatagent/logs/<id:\d+>']            = 'chatagent/logs/session';
                $event->rules['chatagent/logs/delete']              = 'chatagent/logs/delete';
                $event->rules['chatagent/settings']                 = 'chatagent/logs/settings';
                $event->rules['chatagent/settings/save']            = 'chatagent/logs/save-settings';
                $event->rules['chatagent/training']                 = 'chatagent/training/index';
                $event->rules['chatagent/training/entry/<id:\d+>']  = 'chatagent/training/entry';
                $event->rules['chatagent/training/train']           = 'chatagent/training/train';
                $event->rules['chatagent/training/train-entry']     = 'chatagent/training/train-entry';
                $event->rules['chatagent/training/clear-index']     = 'chatagent/training/clear-index';
                $event->rules['chatagent/training/delete-entry']    = 'chatagent/training/delete-entry';
                $event->rules['chatagent/training/status']          = 'chatagent/training/status';
                $event->rules['chatagent/training/upload-file']     = 'chatagent/training/upload-file';
                $event->rules['chatagent/training/delete-file']     = 'chatagent/training/delete-file';
                $event->rules['chatagent/training/retrain-file']    = 'chatagent/training/retrain-file';
                $event->rules['chatagent/training/add-urls']        = 'chatagent/training/add-urls';
                $event->rules['chatagent/training/delete-url']      = 'chatagent/training/delete-url';
                $event->rules['chatagent/training/clear-urls']      = 'chatagent/training/clear-urls';
                $event->rules['chatagent/training/crawl-url']       = 'chatagent/training/crawl-url';
                $event->rules['chatagent/training/crawl-all']       = 'chatagent/training/crawl-all';
                $event->rules['chatagent/training/import-sitemap']  = 'chatagent/training/import-sitemap';
                $event->rules['chatagent/training/url/<id:\d+>']    = 'chatagent/training/url-detail';
                $event->rules['chatagent/training/save-qa-pair']   = 'chatagent/training/save-qa-pair';
                $event->rules['chatagent/training/delete-qa-pair'] = 'chatagent/training/delete-qa-pair';
                $event->rules['chatagent/training/toggle-qa-pair'] = 'chatagent/training/toggle-qa-pair';
            }
        );

        // Register site URL rules
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['chatbot/message'] = 'chatagent/chat/message';
                $event->rules['chatbot/rate']    = 'chatagent/chat/rate';
            }
        );

        // Register CP nav item
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                if (Craft::$app->getUser()->getIsAdmin()) {
                    $event->navItems[] = [
                        'url'    => 'chatagent',
                        'label'  => 'Chatbot',
                        'icon'   => __DIR__ . '/templates/icons/chatbot.svg',
                        'subnav' => [
                            'dashboard' => ['label' => 'Dashboard',      'url' => 'chatagent'],
                            'logs'      => ['label' => 'Gesprächslogs',  'url' => 'chatagent/logs'],
                            'training'  => ['label' => 'Training',       'url' => 'chatagent/training'],
                            'settings'  => ['label' => 'Einstellungen',  'url' => 'chatagent/settings'],
                        ],
                    ];
                }
            }
        );

        // Auto-Training on Entry save → push to queue
        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                $settings = self::$instance->getChatService()->getSettings();
                if (!empty($settings['autoTrainOnSave'])) {
                    /** @var Entry $entry */
                    $entry = $event->sender;
                    Craft::$app->queue->push(new TrainingJob([
                                                                 'entryId'       => $entry->id,
                                                                 'sectionHandle' => $entry->getSection()->handle ?? '',
                                                             ]));
                }
            }
        );

        Craft::info('Chatbot module loaded', __METHOD__);
    }

    public function getChatService(): ChatService
    {
        return $this->get('chat');
    }

    public function getLogsService(): LogService
    {
        return $this->get('logs');
    }

    public function getVectorService(): VectorService
    {
        return $this->get('vector');
    }

    public function getEmbeddingService(): EmbeddingService
    {
        return $this->get('embedding');
    }

    public function getTrainingService(): TrainingService
    {
        return $this->get('training');
    }

    public function getCrawlService(): CrawlService
    {
        return $this->get('crawl');
    }


    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('chatbot/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
    }
}
