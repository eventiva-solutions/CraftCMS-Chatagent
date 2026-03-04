<?php

namespace eventiva\craftchatagent\migrations;

use craft\db\Migration;

class m000000_000000_chatbot_install extends Migration
{
    public function safeUp(): bool
    {
        // Create chatbot_sessions table
        if (!$this->db->tableExists('{{%chatbot_sessions}}')) {
            $this->createTable('{{%chatbot_sessions}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid()->notNull(),
                'sessionId' => $this->string(64)->notNull()->unique(),
                'siteId' => $this->integer()->notNull()->defaultValue(1),
                'ipAddress' => $this->string(45)->null(),
                'userAgent' => $this->string(512)->null(),
                'pageUrl' => $this->string(2048)->null(),
                'messageCount' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%chatbot_sessions}}', ['sessionId'], true);
            $this->createIndex(null, '{{%chatbot_sessions}}', ['siteId']);
            $this->createIndex(null, '{{%chatbot_sessions}}', ['dateCreated']);
        }

        // Create chatbot_messages table
        if (!$this->db->tableExists('{{%chatbot_messages}}')) {
            $this->createTable('{{%chatbot_messages}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid()->notNull(),
                'sessionId' => $this->integer()->notNull(),
                'role' => $this->enum('role', ['user', 'bot'])->notNull(),
                'message' => $this->mediumText()->notNull(),
                'metadata' => $this->text()->null(),
                'responseTimeMs' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%chatbot_messages}}', ['sessionId']);
            $this->createIndex(null, '{{%chatbot_messages}}', ['role']);
            $this->addForeignKey(
                null,
                '{{%chatbot_messages}}', 'sessionId',
                '{{%chatbot_sessions}}', 'id',
                'CASCADE', 'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%chatbot_messages}}');
        $this->dropTableIfExists('{{%chatbot_sessions}}');

        return true;
    }
}
