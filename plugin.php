<?php
return array(
    'id' =>             'ai-response-generator:osticket',
    'version' =>        '0.2.0',
    'name' =>           'AI Response Generator',
    'description' =>    'Adds an AI-powered "Generate Response" button to the agent ticket view with configurable API settings (OpenAI-compatible or Anthropic) and RAG.',
    'author' =>         'Mateusz Hajder',
    'ost_version' =>    MAJOR_VERSION,
    'plugin' =>         'src/AIResponsePlugin.php:AIResponseGeneratorPlugin',
    'include_path' =>   '',
    'url' =>            'https://github.com/mhajder/ai-response-generator',
);
