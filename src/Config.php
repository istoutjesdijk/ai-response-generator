<?php
/*********************************************************************
 * AI Response Generator Plugin - Config
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.forms.php');

class AIResponseGeneratorPluginConfig extends PluginConfig {

    function getFormOptions() {
        return array(
            'title' => __('AI Response Generator Settings'),
            'instructions' => __('Configure the connection to your OpenAI-compatible server.'),
        );
    }

    function getFields() {
        $fields = array();

        $fields['api_url'] = new TextboxField(array(
            'label' => __('API URL'),
            'required' => true,
            'hint' => __('Base URL to an OpenAI-compatible API endpoint, e.g. https://api.openai.com/v1/chat/completions or your local server.'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['api_key'] = new TextboxField(array(
            'label' => __('API Key'),
            'required' => false,
            'hint' => __('API key used for Authorization header.'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['model'] = new TextboxField(array(
            'label' => __('Model Name'),
            'required' => true,
            'hint' => __('Name of the AI model to use (e.g. gpt-5-nano-2025-08-07).'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['system_prompt'] = new TextareaField(array(
            'label' => __('AI System Prompt'),
            'required' => false,
            'hint' => __('Optional system instruction sent to the model to steer tone, structure, and policy.'),
            'configuration' => array(
                'rows' => 6,
                'html' => false,
                'placeholder' => __('You are a helpful support agent. Draft a concise, professional reply...'),
            ),
        ));

        $fields['response_template'] = new TextareaField(array(
            'label' => __('Response Template'),
            'required' => false,
            'hint' => __('Optional template applied to the AI result. Use {ai_text} to insert the generated text. Supported tokens: {ticket_number}, {ticket_subject}, {user_name}, {user_email}, {agent_name}.'),
            'configuration' => array(
                'rows' => 6,
                'html' => false,
                'placeholder' => "Hello {user_name},\n\n{ai_text}\n\nBest regards,\n{agent_name}",
            ),
        ));

        $fields['rag_content'] = new TextareaField(array(
            'label' => __('RAG Content'),
            'required' => false,
            'hint' => __('Paste or type additional context here. This content will be used to enrich AI responses.'),
            'configuration' => array(
                'rows' => 10,
                'html' => false,
                'placeholder' => __('Paste your RAG content here...'),
            ),
        ));

        return $fields;
    }
}
