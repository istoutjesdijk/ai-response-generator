<?php
return array(
    'id' =>             'ai-response-generator:osticket',
    'version' =>        '0.3.0',
    'name' =>           'AI Response Generator',
    'description' =>    'AI-powered response generation with vision support, streaming, and multi-provider compatibility (OpenAI, Anthropic). Generate intelligent replies with image analysis, RAG, and configurable templates.',
    'author' =>         'Mateusz Hajder (original), Ide Stoutjesdijk (enhanced fork)',
    'ost_version' =>    MAJOR_VERSION,
    'plugin' =>         'src/AIResponsePlugin.php:AIResponseGeneratorPlugin',
    'include_path' =>   '',
    'url' =>            'https://github.com/istoutjesdijk/ai-response-generator',
);
