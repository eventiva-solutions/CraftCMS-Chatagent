<?php

namespace eventiva\craftchatagent\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $sessionId
 * @property string $role
 * @property string $message
 * @property string|null $metadata
 * @property int|null $responseTimeMs
 * @property string|null $rating
 * @property float|null $confidenceScore
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ChatMessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_messages}}';
    }

    public function getSession(): \yii\db\ActiveQuery
    {
        return $this->hasOne(ChatSessionRecord::class, ['id' => 'sessionId']);
    }
}
