<?php

namespace eventiva\craftchatagent\services;

use Craft;
use eventiva\craftchatagent\Chatagent;
use yii\base\Component;

class EmbeddingService extends Component
{
    /**
     * Generate an embedding vector for the given text via OpenAI API.
     *
     * @return float[] 1536-element vector (text-embedding-3-small)
     */
    public function embed(string $text): array
    {
        $settings = Chatagent::getInstance()->getChatService()->getSettings();
        $apiKey   = $settings['openaiApiKey'] ?? '';
        $model    = $settings['embeddingModel'] ?? 'text-embedding-3-small';

        if (empty($apiKey)) {
            throw new \RuntimeException('OpenAI API Key ist nicht konfiguriert.');
        }

        $payload = json_encode([
            'input' => $text,
            'model' => $model,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/embeddings');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
            throw new \RuntimeException('cURL-Fehler beim Embedding-Aufruf: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Craft::error("OpenAI Embeddings HTTP {$httpCode}: {$response}", __METHOD__);
            throw new \RuntimeException("OpenAI API antwortete mit HTTP {$httpCode}.");
        }

        $data = json_decode($response, true);
        $embedding = $data['data'][0]['embedding'] ?? null;

        if (!is_array($embedding)) {
            Craft::error("Unerwartete OpenAI Embedding-Antwort: {$response}", __METHOD__);
            throw new \RuntimeException('Unerwartete Antwort von OpenAI Embeddings API.');
        }

        return $embedding;
    }

    /**
     * Split text into overlapping word-based chunks.
     *
     * @return string[]
     */
    public function chunkText(string $text, int $chunkSize = 400, int $overlap = 50): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return [];
        }

        $chunks = [];
        $total  = count($words);
        $step   = max(1, $chunkSize - $overlap);

        for ($start = 0; $start < $total; $start += $step) {
            $slice   = array_slice($words, $start, $chunkSize);
            $chunks[] = implode(' ', $slice);
            if ($start + $chunkSize >= $total) {
                break;
            }
        }

        return $chunks;
    }
}
