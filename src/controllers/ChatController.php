<?php

namespace eventiva\craftchatagent\controllers;

use Craft;
use craft\web\Controller;
use eventiva\craftchatagent\Chatagent;

class ChatController extends Controller
{
    protected array|int|bool $allowAnonymous = ['message', 'rate'];
    // CSRF ist für diese öffentlichen API-Endpoints nicht erforderlich –
    // das Token im eingebetteten Widget-HTML kann nach Session-Ablauf ungültig werden.
    public $enableCsrfValidation = false;

    /**
     * POST /chatbot/message
     * Proxy between frontend and n8n webhook.
     */
    public function actionMessage(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Chatagent::$instance->getChatService()->getSettings();

        if (!$settings['enabled']) {
            return $this->asJson(['success' => false, 'error' => 'Chatbot ist deaktiviert.']);
        }

        $request = Craft::$app->getRequest();

        $sessionId  = $request->getBodyParam('sessionId', '');
        $chatInput  = $request->getBodyParam('chatInput', '');
        $pageUrl    = $request->getBodyParam('pageUrl', '');
        $suggestion = $request->getBodyParam('suggestion', null);

        // Sanitize inputs
        $sessionId = substr(strip_tags($sessionId), 0, 64);
        $chatInput = substr(strip_tags($chatInput), 0, 4000);

        if (empty($sessionId) || empty($chatInput)) {
            return $this->asJson(['success' => false, 'error' => 'Ungültige Eingabe.']);
        }

        $context = [
            'ipAddress'  => $request->getUserIP(),
            'userAgent'  => $request->getUserAgent(),
            'pageUrl'    => substr($pageUrl, 0, 2048),
            'suggestion' => $suggestion ? substr(strip_tags((string)$suggestion), 0, 500) : null,
        ];

        $result = Chatagent::$instance->getChatService()->processMessage($sessionId, $chatInput, $context);

        if (!$result['success']) {
            return $this->asJson(['success' => false, 'error' => $result['error'] ?? 'Fehler.']);
        }

        // Return in the format the frontend expects: [{"output": "..."}]
        return $this->asJson([[
            'output'    => $result['botResponse'],
            'debug'     => $result['debug'] ?? null,
            'messageId' => $result['botMessageId'] ?? null,
        ]]);
    }

    /**
     * POST /chatbot/rate
     * Save a thumbs up/down rating for a bot message.
     */
    public function actionRate(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Chatagent::$instance->getChatService()->getSettings();
        if (empty($settings['enableRatings'])) {
            return $this->asJson(['success' => false, 'error' => 'Bewertungen sind deaktiviert.']);
        }

        $request   = Craft::$app->getRequest();
        $messageId = (int)$request->getBodyParam('messageId', 0);
        $sessionId = (string)$request->getBodyParam('sessionId', '');
        $rating    = (string)$request->getBodyParam('rating', '');

        if (!in_array($rating, ['up', 'down'], true) || $messageId <= 0) {
            return $this->asJson(['success' => false, 'error' => 'Ungültige Parameter.']);
        }

        $message = \eventiva\craftchatagent\records\ChatMessageRecord::findOne([
            'id'   => $messageId,
            'role' => 'bot',
        ]);

        if (!$message) {
            return $this->asJson(['success' => false, 'error' => 'Nachricht nicht gefunden.']);
        }

        // Verify the message belongs to the given session
        $session = $message->getSession()->one();
        if (!$session || $session->sessionId !== $sessionId) {
            return $this->asJson(['success' => false, 'error' => 'Session ungültig.']);
        }

        $message->rating      = $rating;
        $message->dateUpdated = date('Y-m-d H:i:s');
        $message->save(false);

        return $this->asJson(['success' => true, 'rating' => $rating]);
    }
}
