<?php

namespace eventiva\craftchatagent\migrations;

use craft\db\Migration;

class m240101_000002_chatbot_add_suggestion extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%chatbot_messages}}') &&
            !$this->db->getTableSchema('{{%chatbot_messages}}')->getColumn('suggestion')) {
            $this->addColumn(
                '{{%chatbot_messages}}',
                'suggestion',
                $this->string(500)->null()->after('rating')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%chatbot_messages}}') &&
            $this->db->getTableSchema('{{%chatbot_messages}}')->getColumn('suggestion')) {
            $this->dropColumn('{{%chatbot_messages}}', 'suggestion');
        }

        return true;
    }
}
