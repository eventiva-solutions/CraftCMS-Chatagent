<?php

namespace eventiva\craftchatagent\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property string $sessionId
 * @property int $siteId
 * @property string $ipAddress
 * @property string $userAgent
 * @property string $pageUrl
 * @property int $messageCount
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ChatSessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chatbot_sessions}}';
    }

    public function getMessages(): \yii\db\ActiveQuery
    {
        return $this->hasMany(ChatMessageRecord::class, ['sessionId' => 'id']);
    }
}
