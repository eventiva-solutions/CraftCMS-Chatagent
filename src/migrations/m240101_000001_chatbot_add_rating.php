<?php

namespace eventiva\craftchatagent\migrations;

use craft\db\Migration;

class m240101_000001_chatbot_add_rating extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%chatbot_messages}}') &&
            !$this->db->getTableSchema('{{%chatbot_messages}}')->getColumn('rating')) {
            $this->addColumn(
                '{{%chatbot_messages}}',
                'rating',
                $this->string(4)->null()->after('responseTimeMs')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%chatbot_messages}}') &&
            $this->db->getTableSchema('{{%chatbot_messages}}')->getColumn('rating')) {
            $this->dropColumn('{{%chatbot_messages}}', 'rating');
        }

        return true;
    }
}
