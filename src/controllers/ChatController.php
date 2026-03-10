<?php

namespace eventiva\craftchatagent\controllers;

use Craft;
use craft\web\Controller;
use eventiva\craftchatagent\Chatagent;
use eventiva\craftchatagent\records\ChatMessageRecord;

class ChatController extends Controller
{
    protected array|int|bool $allowAnonymous = ['message', 'rate'];

    // CSRF validation is disabled for these public API endpoints -
    // the token embedded in the widget HTML may expire after a session timeout.
    public $enableCsrfValidation = false;

    /**
     * POST /chatbot/message
     */
    public function actionMessage(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Chatagent::getInstance()->getChatService()->getSettings();

        if (!$settings['enabled']) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Chatbot is disabled.')]);
        }

        $request = Craft::$app->getRequest();

        $sessionId  = $request->getBodyParam('sessionId', '');
        $chatInput  = $request->getBodyParam('chatInput', '');
        $pageUrl    = $request->getBodyParam('pageUrl', '');
        $suggestion = $request->getBodyParam('suggestion', null);

        $sessionId = substr(strip_tags($sessionId), 0, 64);
        $chatInput = substr(strip_tags($chatInput), 0, 4000);

        if (empty($sessionId) || empty($chatInput)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Invalid input.')]);
        }

        $context = [
            'ipAddress'  => $request->getUserIP(),
            'userAgent'  => $request->getUserAgent(),
            'pageUrl'    => substr($pageUrl, 0, 2048),
            'suggestion' => $suggestion ? substr(strip_tags((string)$suggestion), 0, 500) : null,
        ];

        $result = Chatagent::getInstance()->getChatService()->processMessage($sessionId, $chatInput, $context);

        if (!$result['success']) {
            return $this->asJson(['success' => false, 'error' => $result['error'] ?? Craft::t('chatagent', 'An error occurred.')]);
        }

        return $this->asJson([[
            'output'    => $result['botResponse'],
            'debug'     => $result['debug'] ?? null,
            'messageId' => $result['botMessageId'] ?? null,
        ]]);
    }

    /**
     * POST /chatbot/rate
     */
    public function actionRate(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Chatagent::getInstance()->getChatService()->getSettings();
        if (empty($settings['enableRatings'])) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Ratings are disabled.')]);
        }

        $request   = Craft::$app->getRequest();
        $messageId = (int)$request->getBodyParam('messageId', 0);
        $sessionId = (string)$request->getBodyParam('sessionId', '');
        $rating    = (string)$request->getBodyParam('rating', '');

        if (!in_array($rating, ['up', 'down'], true) || $messageId <= 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Invalid parameters.')]);
        }

        $message = ChatMessageRecord::findOne([
            'id'   => $messageId,
            'role' => 'bot',
        ]);

        if (!$message) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Message not found.')]);
        }

        $session = $message->getSession()->one();
        if (!$session || $session->sessionId !== $sessionId) {
            return $this->asJson(['success' => false, 'error' => Craft::t('chatagent', 'Invalid session.')]);
        }

        $message->rating      = $rating;
        $message->dateUpdated = date('Y-m-d H:i:s');
        $message->save(false);

        return $this->asJson(['success' => true, 'rating' => $rating]);
    }
}
