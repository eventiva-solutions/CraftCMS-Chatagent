<?php

namespace eventiva\craftchatagent\assetbundles;

use craft\web\AssetBundle;

class ChatbotAssetBundle extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../web';

        $this->js = [
            'js/chatbot-widget.js',
        ];

        $this->css = [
            'css/chatbot-widget.css',
        ];

        parent::init();
    }
}
