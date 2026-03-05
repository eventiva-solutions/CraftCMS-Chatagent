<?php

namespace eventiva\craftchatagent\services;

use Craft;
use eventiva\craftchatagent\Chatagent;
use yii\base\Component;

class ChatService extends Component
{
    private const SETTINGS_KEY = 'chatbot_settings';

    /**
     * Get settings from cache or defaults.
     */
    public function getSettings(): array
    {
        $defaults = [
            'companyName'        => 'AMR Eventtechnik',
            'logoText'           => 'AMR',
            'logoAssetId'        => 0,
            'primaryColor'       => '#7C3AED',
            'logoBgColor'        => '#7C3AED',
            'initialMessage'     => 'Hey, wie kann ich Ihnen helfen?',
            'defaultTheme'       => 'light',
            'enabled'            => true,
            'logConversations'   => true,
            'logRetentionDays'   => 90,
            'systemPrompt'       => '',
            'openaiApiKey'       => '',
            'openaiModel'        => 'gpt-4o-mini',
            'embeddingModel'     => 'text-embedding-3-small',
            'trainingSections'   => [],
            'autoTrainOnSave'    => false,
            'maxContextChunks'   => 5,
            'minSimilarityScore' => 0.4,
            'enableRatings'      => true,
            'suggestionsEnabled' => true,
            'suggestions'        => [],
        ];

        $saved = Craft::$app->getCache()->get(self::SETTINGS_KEY);

        if (!$saved) {
            return $defaults;
        }

        return array_merge($defaults, $saved);
    }

    /**
     * Save settings to cache.
     */
    public function saveSettings(array $settings): bool
    {
        return Craft::$app->getCache()->set(self::SETTINGS_KEY, $settings, 0);
    }

    /**
     * Process an incoming chat message via RAG pipeline.
     */
    public function processMessage(string $sessionId, string $chatInput, array $context = []): array
    {
        $settings = $this->getSettings();

        if (empty($settings['openaiApiKey'])) {
            return ['success' => false, 'error' => 'OpenAI API Key ist nicht konfiguriert.'];
        }

        $logService = Chatagent::getInstance()->getLogsService();

        // Find or create DB session
        $sessionRecord = null;
        if ($settings['logConversations']) {
            $sessionRecord = $logService->findOrCreateSession($sessionId, $context);
            $suggestion = isset($context['suggestion']) && $context['suggestion'] !== '' ? $context['suggestion'] : null;
            $logService->logMessage($sessionRecord->id, 'user', $chatInput, [], $suggestion);
        }

        $startTime = microtime(true);

        try {
            // 1. Embed the user question
            $embeddingService = Chatagent::getInstance()->getEmbeddingService();
            $queryEmbedding   = $embeddingService->embed($chatInput);

            // 2. Find similar chunks
            $vectorService = Chatagent::getInstance()->getVectorService();
            $similarChunks = $vectorService->searchSimilar(
                $queryEmbedding,
                (int)($settings['maxContextChunks'] ?? 5),
                (float)($settings['minSimilarityScore'] ?? 0.3),
                true // debug: log scores to craft log
            );

            // 3. Build context from chunks
            $contextText = '';
            if (!empty($similarChunks)) {
                $contextParts = [];
                foreach ($similarChunks as $chunk) {
                    $meta   = $chunk['metadata'] ?? [];
                    $source = '';
                    $url    = $meta['url'] ?? '';
                    $title  = $meta['entryTitle'] ?? $meta['title'] ?? '';
                    if ($url && $title) {
                        $source = "\n[Quelle: {$title} – {$url}]";
                    } elseif ($url) {
                        $source = "\n[URL: {$url}]";
                    } elseif ($title) {
                        $source = "\n[Quelle: {$title}]";
                    } elseif (!empty($meta['filename'])) {
                        $source = "\n[Datei: {$meta['filename']}]";
                    }
                    $contextParts[] = $chunk['chunk_text'] . $source;
                }
                $contextText = implode("\n\n---\n\n", $contextParts);
            }

            // 4. Build system prompt
            $baseSystemPrompt = $settings['systemPrompt'] ?: 'Du bist ein freundlicher Assistent für AMR Eventtechnik. Beantworte Fragen auf Deutsch.';
            $systemPrompt = $baseSystemPrompt;
            if ($contextText !== '') {
                $systemPrompt .= "\n\nNutze die folgenden Informationen aus der Wissensdatenbank, um die Frage des Nutzers zu beantworten:\n\n" . $contextText;
            }

            // 5. Build messages array (with recent session history)
            $messages = [['role' => 'system', 'content' => $systemPrompt]];

            if ($settings['logConversations'] && $sessionRecord !== null) {
                $history = $logService->getSessionMessages($sessionRecord->id);
                // Exclude the just-logged user message (last entry), take previous 10
                $historyMessages = array_slice($history, 0, -1);
                $historyMessages = array_slice($historyMessages, -10);
                foreach ($historyMessages as $msg) {
                    $role = $msg->role === 'bot' ? 'assistant' : 'user';
                    $messages[] = ['role' => $role, 'content' => $msg->message];
                }
            }

            $messages[] = ['role' => 'user', 'content' => $chatInput];

            // 6. Call OpenAI Chat Completions API
            $botResponse = $this->callOpenAiChat($settings['openaiApiKey'], $settings['openaiModel'] ?? 'gpt-4o-mini', $messages);

        } catch (\Throwable $e) {
            Craft::error('ChatService RAG pipeline error: ' . $e->getMessage(), __METHOD__);
            $botResponse = 'Es tut mir leid, es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut.';
        }

        $responseTimeMs = (int)((microtime(true) - $startTime) * 1000);

        // Confidence: top similarity score, or null if no chunks matched
        $confidenceScore = !empty($similarChunks) ? round((float)$similarChunks[0]['score'], 4) : null;

        // 7. Log bot response + increment counter
        $botMessageId = null;
        if ($settings['logConversations'] && $sessionRecord !== null) {
            $botMsg = $logService->logMessage($sessionRecord->id, 'bot', $botResponse, ['responseTimeMs' => $responseTimeMs], null, $confidenceScore);
            $logService->incrementMessageCount($sessionRecord->id);
            $botMessageId = $botMsg->id;
        }

        return ['success' => true, 'botResponse' => $botResponse, 'botMessageId' => $botMessageId, 'debug' => [
            'messages'   => $messages ?? [],
            'chunks'     => $similarChunks ?? [],
            'allScores'  => $vectorService->lastDebugScores ?? [],
            'minScore'   => (float)($settings['minSimilarityScore'] ?? 0.3),
        ]];
    }

    /**
     * Call OpenAI Chat Completions API via cURL.
     */
    private function callOpenAiChat(string $apiKey, string $model, array $messages): string
    {
        $payload = json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.7,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('cURL-Fehler beim OpenAI-Aufruf: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Craft::error("OpenAI Chat HTTP {$httpCode}: {$response}", __METHOD__);
            throw new \RuntimeException("OpenAI API antwortete mit HTTP {$httpCode}.");
        }

        $data    = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            Craft::error("Unerwartete OpenAI Chat-Antwort: {$response}", __METHOD__);
            throw new \RuntimeException('Unerwartete Antwort von OpenAI Chat API.');
        }

        return $content;
    }
}
