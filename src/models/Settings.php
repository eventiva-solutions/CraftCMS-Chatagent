<?php

namespace eventiva\craftchatagent\models;

use yii\base\Model;

class Settings extends Model
{
    public string $companyName = 'ABC Company';
    public string $logoText = 'ABC';
    public string $primaryColor = '#7C3AED';
    public string $initialMessage = 'Hallo, wie kann ich Ihnen heute helfen?';
    public string $defaultTheme = 'light';
    public string $systemPrompt = '';
    public string $openaiApiKey = '';
    public bool $enabled = true;
    public bool $logConversations = true;
    public int $logRetentionDays = 90;

    // RAG / KI fields
    public string $openaiModel = 'gpt-4o-mini';
    public string $embeddingModel = 'text-embedding-3-small';
    public array $trainingSections = [];
    public bool $autoTrainOnSave = false;
    public int $maxContextChunks = 5;
    public float $minSimilarityScore = 0.65;

    public function rules(): array
    {
        return [
            [['companyName', 'logoText', 'primaryColor', 'initialMessage', 'defaultTheme', 'systemPrompt', 'openaiApiKey', 'openaiModel', 'embeddingModel'], 'string'],
            [['enabled', 'logConversations', 'autoTrainOnSave'], 'boolean'],
            [['logRetentionDays', 'maxContextChunks'], 'integer', 'min' => 0],
            [['minSimilarityScore'], 'number', 'min' => 0, 'max' => 1],
            [['trainingSections'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'companyName' => 'Firmenname',
            'logoText' => 'Logo-Text',
            'primaryColor' => 'Primärfarbe',
            'initialMessage' => 'Startnachricht',
            'defaultTheme' => 'Standard-Theme',
            'enabled' => 'Chatbot aktiviert',
            'logConversations' => 'Gespräche protokollieren',
            'logRetentionDays' => 'Log-Aufbewahrung (Tage, 0 = unbegrenzt)',
            'openaiModel' => 'Chat-Modell',
            'embeddingModel' => 'Embedding-Modell',
            'trainingSections' => 'Trainings-Sections',
            'autoTrainOnSave' => 'Auto-Training bei Entry-Speicherung',
            'maxContextChunks' => 'Max. Kontext-Chunks',
            'minSimilarityScore' => 'Mindest-Ähnlichkeit',
        ];
    }
}
