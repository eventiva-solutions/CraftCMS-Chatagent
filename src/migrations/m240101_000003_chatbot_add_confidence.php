<?php

namespace eventiva\craftchatagent\migrations;

use craft\db\Migration;

class m240101_000003_chatbot_add_confidence extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%chatbot_messages}}') &&
            !$this->db->getTableSchema('{{%chatbot_messages}}')->getColumn('confidenceScore')) {
            $this->addColumn(
                '{{%chatbot_messages}}',
                'confidenceScore',
                $this->float()->null()->after('rating')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%chatbot_messages}}') &&
            $this->db->getTableSchema('{{%chatbot_messages}}')->getColumn('confidenceScore')) {
            $this->dropColumn('{{%chatbot_messages}}', 'confidenceScore');
        }

        return true;
    }
}
