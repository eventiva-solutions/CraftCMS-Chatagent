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

/**
 * Chatagent plugin
 *
 * @method static Chatagent getInstance()
 * @method Settings getSettings()
 * @property-read ChatService $chat
 * @property-read LogService $logs
 * @property-read VectorService $vector
 * @property-read EmbeddingService $embedding
 * @property-read TrainingService $training
 * @property-read CrawlService $crawl
 * @author Eventiva <support@eventiva.io>
 * @copyright Eventiva
 * @license https://craftcms.github.io/license/ Craft License
 */
class Chatagent extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->runMigrationsIfNeeded();

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

        $this->registerTemplateRoots();
        $this->registerUrlRules();
        $this->registerCpNav();
        $this->registerEventHandlers();

        Craft::info('Chatbot plugin loaded.', __METHOD__);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = Craft::t('chatagent', 'Chatbot');
        $item['url']   = 'chatagent';
        $item['icon']  = __DIR__ . '/templates/icons/chatbot.svg';

        $item['subnav'] = [
            'dashboard' => ['label' => Craft::t('chatagent', 'Dashboard'),   'url' => 'chatagent'],
            'logs'      => ['label' => Craft::t('chatagent', 'Chat Logs'),   'url' => 'chatagent/logs'],
            'training'  => ['label' => Craft::t('chatagent', 'Training'),    'url' => 'chatagent/training'],
            'settings'  => ['label' => Craft::t('chatagent', 'Settings'),    'url' => 'chatagent/settings'],
        ];

        return $item;
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
            'plugin'   => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['chatbot'] = $this->getBasePath() . '/templates';
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['chatbot'] = $this->getBasePath() . '/templates';
            }
        );
    }

    private function registerUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'chatagent'                                   => 'chatagent/dashboard/index',
                    'chatagent/dashboard/stats'                   => 'chatagent/dashboard/stats',
                    'chatagent/logs'                              => 'chatagent/logs/index',
                    'chatagent/logs/<id:\d+>'                     => 'chatagent/logs/session',
                    'chatagent/logs/delete'                       => 'chatagent/logs/delete',
                    'chatagent/settings'                          => 'chatagent/logs/settings',
                    'chatagent/settings/save'                     => 'chatagent/logs/save-settings',
                    'chatagent/training'                          => 'chatagent/training/index',
                    'chatagent/training/entry/<id:\d+>'           => 'chatagent/training/entry',
                    'chatagent/training/train'                    => 'chatagent/training/train',
                    'chatagent/training/train-entry'              => 'chatagent/training/train-entry',
                    'chatagent/training/clear-index'              => 'chatagent/training/clear-index',
                    'chatagent/training/delete-entry'             => 'chatagent/training/delete-entry',
                    'chatagent/training/status'                   => 'chatagent/training/status',
                    'chatagent/training/upload-file'              => 'chatagent/training/upload-file',
                    'chatagent/training/delete-file'              => 'chatagent/training/delete-file',
                    'chatagent/training/retrain-file'             => 'chatagent/training/retrain-file',
                    'chatagent/training/add-urls'                 => 'chatagent/training/add-urls',
                    'chatagent/training/delete-url'               => 'chatagent/training/delete-url',
                    'chatagent/training/clear-urls'               => 'chatagent/training/clear-urls',
                    'chatagent/training/crawl-url'                => 'chatagent/training/crawl-url',
                    'chatagent/training/crawl-all'                => 'chatagent/training/crawl-all',
                    'chatagent/training/import-sitemap'           => 'chatagent/training/import-sitemap',
                    'chatagent/training/url/<id:\d+>'             => 'chatagent/training/url-detail',
                    'chatagent/training/save-qa-pair'             => 'chatagent/training/save-qa-pair',
                    'chatagent/training/delete-qa-pair'           => 'chatagent/training/delete-qa-pair',
                    'chatagent/training/toggle-qa-pair'           => 'chatagent/training/toggle-qa-pair',
                ]);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['chatbot/message'] = 'chatagent/chat/message';
                $event->rules['chatbot/rate']    = 'chatagent/chat/rate';
            }
        );
    }

    private function registerCpNav(): void
    {
        // Only needed for older Craft versions that don't use getCpNavItem()
        // getCpNavItem() is the preferred approach for hasCpSection = true plugins
    }

    private function registerEventHandlers(): void
    {
        // Auto-train entries on save
        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                $settings = Chatagent::getInstance()->getChatService()->getSettings();
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
    }

    /**
     * Runs any pending migrations that haven't been applied yet.
     * This handles cases where the plugin was installed without going through
     * Craft's normal plugin installation flow.
     */
    private function runMigrationsIfNeeded(): void
    {
        $db = Craft::$app->getDb();

        if (!$db->tableExists('{{%chatbot_sessions}}')) {
            $this->runMigration(new m000000_000000_chatbot_install());
            Craft::info('Chatbot: DB tables created.', __METHOD__);
        }

        if (!$db->tableExists('{{%chatbot_messages}}')) {
            return;
        }

        $schema = $db->getTableSchema('{{%chatbot_messages}}', true);
        if (!$schema) {
            return;
        }

        if (!$schema->getColumn('rating')) {
            $this->runMigration(new m240101_000001_chatbot_add_rating());
            Craft::info('Chatbot: Rating column added.', __METHOD__);
        }

        // Refresh schema after potential changes
        $schema = $db->getTableSchema('{{%chatbot_messages}}', true);

        if ($schema && !$schema->getColumn('suggestion')) {
            $this->runMigration(new m240101_000002_chatbot_add_suggestion());
            Craft::info('Chatbot: Suggestion column added.', __METHOD__);
        }

        $schema = $db->getTableSchema('{{%chatbot_messages}}', true);

        if ($schema && !$schema->getColumn('confidenceScore')) {
            $this->runMigration(new m240101_000003_chatbot_add_confidence());
            Craft::info('Chatbot: ConfidenceScore column added.', __METHOD__);
        }
    }

    private function runMigration(object $migration): void
    {
        ob_start();
        $migration->up();
        ob_end_clean();
    }
}
