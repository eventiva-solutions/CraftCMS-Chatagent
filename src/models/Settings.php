<?php

namespace eventiva\craftchatagent\models;

use Craft;
use craft\base\Model;

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

    // RAG / AI fields
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
            'companyName'       => Craft::t('chatagent', 'Company Name'),
            'logoText'          => Craft::t('chatagent', 'Logo Text'),
            'primaryColor'      => Craft::t('chatagent', 'Primary Color'),
            'initialMessage'    => Craft::t('chatagent', 'Initial Message'),
            'defaultTheme'      => Craft::t('chatagent', 'Default Theme'),
            'enabled'           => Craft::t('chatagent', 'Chatbot Enabled'),
            'logConversations'  => Craft::t('chatagent', 'Log Conversations'),
            'logRetentionDays'  => Craft::t('chatagent', 'Log Retention (days, 0 = unlimited)'),
            'openaiModel'       => Craft::t('chatagent', 'Chat Model'),
            'embeddingModel'    => Craft::t('chatagent', 'Embedding Model'),
            'trainingSections'  => Craft::t('chatagent', 'Training Sections'),
            'autoTrainOnSave'   => Craft::t('chatagent', 'Auto-Train on Entry Save'),
            'maxContextChunks'  => Craft::t('chatagent', 'Max. Context Chunks'),
            'minSimilarityScore' => Craft::t('chatagent', 'Min. Similarity Score'),
        ];
    }
}
