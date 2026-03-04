<?php

namespace eventiva\craftchatagent\services;

use Craft;
use craft\helpers\StringHelper;
use eventiva\craftchatagent\records\ChatMessageRecord;
use eventiva\craftchatagent\records\ChatSessionRecord;
use yii\base\Component;

class LogService extends Component
{
    /**
     * Find an existing session by sessionId or create a new one.
     */
    public function findOrCreateSession(string $sessionId, array $context = []): ChatSessionRecord
    {
        $record = ChatSessionRecord::findOne(['sessionId' => $sessionId]);

        if (!$record) {
            $record = new ChatSessionRecord();
            $record->uid = StringHelper::UUID();
            $record->sessionId = $sessionId;
            $record->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $record->ipAddress = $context['ipAddress'] ?? null;
            $record->userAgent = isset($context['userAgent']) ? substr($context['userAgent'], 0, 512) : null;
            $record->pageUrl = isset($context['pageUrl']) ? substr($context['pageUrl'], 0, 2048) : null;
            $record->messageCount = 0;
            $record->dateCreated = date('Y-m-d H:i:s');
            $record->dateUpdated = date('Y-m-d H:i:s');
            $record->save(false);
        }

        return $record;
    }

    /**
     * Log a message to a session.
     */
    public function logMessage(int $sessionDbId, string $role, string $message, array $meta = [], ?string $suggestion = null, ?float $confidenceScore = null): ChatMessageRecord
    {
        $record = new ChatMessageRecord();
        $record->uid = StringHelper::UUID();
        $record->sessionId = $sessionDbId;
        $record->role = $role;
        $record->message = $message;
        $record->metadata = !empty($meta) ? json_encode($meta) : null;
        $record->responseTimeMs = $meta['responseTimeMs'] ?? null;
        $record->suggestion = $suggestion;
        $record->confidenceScore = $confidenceScore;
        $record->dateCreated = date('Y-m-d H:i:s');
        $record->dateUpdated = date('Y-m-d H:i:s');
        $record->save(false);

        return $record;
    }

    /**
     * Increment messageCount on a session.
     */
    public function incrementMessageCount(int $sessionDbId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%chatbot_sessions}}',
                [
                    'messageCount' => new \yii\db\Expression('messageCount + 1'),
                    'dateUpdated' => date('Y-m-d H:i:s'),
                ],
                ['id' => $sessionDbId]
            )
            ->execute();
    }

    /**
     * Get paginated sessions.
     */
    public function getSessions(int $page = 1, int $perPage = 25, string $search = '', string $rating = '', string $confidence = ''): array
    {
        $query = ChatSessionRecord::find()->orderBy(['dateCreated' => SORT_DESC]);

        if ($search !== '') {
            $query->andWhere(['like', 'sessionId', $search]);
        }

        if ($rating === 'up' || $rating === 'down') {
            $ratedSessionIds = (new \yii\db\Query())
                ->select(['sessionId'])
                ->from('{{%chatbot_messages}}')
                ->where(['rating' => $rating])
                ->distinct()
                ->column();

            if (empty($ratedSessionIds)) {
                return [
                    'sessions'    => [],
                    'total'       => 0,
                    'pages'       => 0,
                    'currentPage' => $page,
                ];
            }

            $query->andWhere(['id' => $ratedSessionIds]);
        }

        if ($confidence !== '') {
            $confQuery = (new \yii\db\Query())
                ->select(['sessionId'])
                ->from('{{%chatbot_messages}}')
                ->where(['role' => 'bot'])
                ->andWhere(['not', ['confidenceScore' => null]]);

            if ($confidence === 'low') {
                $confQuery->andWhere(['<', 'confidenceScore', 0.60]);
            } elseif ($confidence === 'medium') {
                $confQuery->andWhere(['between', 'confidenceScore', 0.60, 0.799]);
            } elseif ($confidence === 'high') {
                $confQuery->andWhere(['>=', 'confidenceScore', 0.80]);
            } elseif ($confidence === 'none') {
                // Sessions where at least one bot msg has no matching context
                $confQuery->andWhere(['confidenceScore' => null]);
                // Override: don't filter by score range, just null
                $confQuery = (new \yii\db\Query())
                    ->select(['sessionId'])
                    ->from('{{%chatbot_messages}}')
                    ->where(['role' => 'bot'])
                    ->andWhere(['confidenceScore' => null]);
            }

            $confSessionIds = $confQuery->distinct()->column();
            if (empty($confSessionIds)) {
                return [
                    'sessions'    => [],
                    'total'       => 0,
                    'pages'       => 0,
                    'currentPage' => $page,
                ];
            }
            $query->andWhere(['id' => $confSessionIds]);
        }

        $total = (int)$query->count();
        $offset = ($page - 1) * $perPage;
        $records = $query->offset($offset)->limit($perPage)->all();

        return [
            'sessions'    => $records,
            'total'       => $total,
            'pages'       => (int)ceil($total / $perPage),
            'currentPage' => $page,
        ];
    }

    /**
     * Get the minimum (worst) confidence score per session for a list of session IDs.
     * Returns: [sessionDbId => float|null]
     */
    public function getSessionMinConfidences(array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        $rows = (new \yii\db\Query())
            ->select(['sessionId', 'MIN(confidenceScore) as min_score'])
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionIds, 'role' => 'bot'])
            ->andWhere(['not', ['confidenceScore' => null]])
            ->groupBy(['sessionId'])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['sessionId']] = round((float)$row['min_score'], 4);
        }

        return $result;
    }

    /**
     * Get thumbs up/down counts grouped by session ID.
     * Returns: [sessionId => ['up' => N, 'down' => N]]
     */
    public function getSessionRatings(array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        $rows = (new \yii\db\Query())
            ->select(['sessionId', 'rating', 'COUNT(*) as cnt'])
            ->from('{{%chatbot_messages}}')
            ->where(['sessionId' => $sessionIds])
            ->andWhere(['not', ['rating' => null]])
            ->groupBy(['sessionId', 'rating'])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $sid = (int)$row['sessionId'];
            if (!isset($result[$sid])) {
                $result[$sid] = ['up' => 0, 'down' => 0];
            }
            $result[$sid][$row['rating']] = (int)$row['cnt'];
        }

        return $result;
    }

    /**
     * Get all messages for a session.
     */
    public function getSessionMessages(int $sessionId): array
    {
        return ChatMessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();
    }

    /**
     * Get a single session by ID.
     */
    public function getSessionById(int $id): ?ChatSessionRecord
    {
        return ChatSessionRecord::findOne($id);
    }

    /**
     * Delete a session (messages cascade via FK).
     */
    public function deleteSession(int $id): bool
    {
        $record = ChatSessionRecord::findOne($id);
        if ($record) {
            return (bool)$record->delete();
        }
        return false;
    }

    /**
     * Aggregate chat statistics for a date range (for Dashboard).
     */
    public function getStatsForDateRange(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to   . ' 23:59:59';

        $sessions = (int)(new \yii\db\Query())
            ->from('{{%chatbot_sessions}}')
            ->where(['between', 'dateCreated', $fromDt, $toDt])
            ->count();

        $messages = (int)(new \yii\db\Query())
            ->select('SUM(messageCount)')
            ->from('{{%chatbot_sessions}}')
            ->where(['between', 'dateCreated', $fromDt, $toDt])
            ->scalar();

        $avgResponseMs = (int)((new \yii\db\Query())
            ->from('{{%chatbot_messages}}')
            ->where(['between', 'dateCreated', $fromDt, $toDt])
            ->andWhere(['not', ['responseTimeMs' => null]])
            ->average('responseTimeMs') ?? 0);

        // Daily sessions + messages (both from chatbot_sessions via messageCount)
        $dailySessionRows = (new \yii\db\Query())
            ->select(['DATE(dateCreated) as day', 'COUNT(*) as cnt', 'SUM(messageCount) as msg_cnt'])
            ->from('{{%chatbot_sessions}}')
            ->where(['between', 'dateCreated', $fromDt, $toDt])
            ->groupBy(['day'])
            ->orderBy(['day' => SORT_ASC])
            ->all();

        // Build full date range filled with zeros
        $dailySessions = [];
        $dailyMessages = [];
        $current = new \DateTime($from);
        $end     = new \DateTime($to);
        while ($current <= $end) {
            $day = $current->format('Y-m-d');
            $dailySessions[$day] = 0;
            $dailyMessages[$day] = 0;
            $current->modify('+1 day');
        }

        foreach ($dailySessionRows as $row) {
            if (isset($dailySessions[$row['day']])) {
                $dailySessions[$row['day']] = (int)$row['cnt'];
                $dailyMessages[$row['day']] = (int)$row['msg_cnt'];
            }
        }

        $thumbsUp = (int)(new \yii\db\Query())
            ->from('{{%chatbot_messages}}')
            ->where(['between', 'dateCreated', $fromDt, $toDt])
            ->andWhere(['rating' => 'up'])
            ->count();

        $thumbsDown = (int)(new \yii\db\Query())
            ->from('{{%chatbot_messages}}')
            ->where(['between', 'dateCreated', $fromDt, $toDt])
            ->andWhere(['rating' => 'down'])
            ->count();

        return [
            'sessions'      => $sessions,
            'messages'      => $messages,
            'avgResponseMs' => $avgResponseMs,
            'dailySessions' => $dailySessions,
            'dailyMessages' => $dailyMessages,
            'thumbsUp'      => $thumbsUp,
            'thumbsDown'    => $thumbsDown,
        ];
    }

    /**
     * Get suggestion usage statistics for a date range.
     * Returns: [['suggestion' => '...', 'cnt' => N], ...]
     */
    public function getSuggestionStats(string $from, string $to): array
    {
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to   . ' 23:59:59';

        return (new \yii\db\Query())
            ->select(['suggestion', 'COUNT(*) as cnt'])
            ->from('{{%chatbot_messages}}')
            ->where(['role' => 'user'])
            ->andWhere(['not', ['suggestion' => null]])
            ->andWhere(['!=', 'suggestion', ''])
            ->andWhere(['between', 'dateCreated', $fromDt, $toDt])
            ->groupBy(['suggestion'])
            ->orderBy(['cnt' => SORT_DESC])
            ->all();
    }

    /**
     * Delete sessions older than $days days.
     */
    public function pruneOldSessions(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $ids = ChatSessionRecord::find()
            ->select(['id'])
            ->where(['<', 'dateCreated', $cutoff])
            ->column();

        if (empty($ids)) {
            return 0;
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($this->deleteSession((int)$id)) {
                $count++;
            }
        }

        return $count;
    }
}
