<?php
return array(
    'id' =>             'ai-response-generator:osticket',
    'version' =>        '0.1.0',
    'name' =>           'AI Response Generator',
    'description' =>    'Adds an AI-powered "Generate Response" button to the agent ticket view with configurable API settings and RAG.',
    'author' =>         'Mateusz Hajder',
    'ost_version' =>    MAJOR_VERSION,
    'plugin' =>         'src/AIResponsePlugin.php:AIResponseGeneratorPlugin',
    'include_path' =>   '',
);
