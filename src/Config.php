<?php
/*********************************************************************
 * AI Response Generator Plugin - Config
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.forms.php');

class AIResponseGeneratorPluginConfig extends PluginConfig {

    /**
     * Returns configuration form options
     *
     * @return array Form configuration options
     */
    function getFormOptions() {
        return array(
            'title' => __('AI Response Generator Settings'),
            'instructions' => __('Configure the connection to your OpenAI-compatible or Anthropic Claude server.'),
        );
    }

    /**
     * Returns configuration form fields
     *
     * @return array Configuration form fields
     */
    function getFields() {
        $fields = array();

        $fields['api_url'] = new TextboxField(array(
            'label' => __('API URL'),
            'required' => true,
            'hint' => __('Base URL to your API endpoint. Examples: OpenAI-compatible -> https://api.openai.com/v1/chat/completions, Anthropic -> https://api.anthropic.com/v1/messages'),
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

        // Anthropic API version (only used when provider=anthropic)
        $fields['anthropic_version'] = new TextboxField(array(
            'label' => __('Anthropic Version'),
            'required' => false,
            'hint' => __('Anthropic API version header (Anthropic-Version). Default: 2023-06-01'),
            'configuration' => array('size' => 20, 'length' => 32, 'placeholder' => '2023-06-01'),
        ));

        $fields['max_tokens_param'] = new TextboxField(array(
            'label' => __('Max Tokens Parameter Name'),
            'required' => false,
            'hint' => __('Parameter name for max tokens (e.g. max_tokens or max_completion_tokens). For Anthropic this is always max_tokens. Default: max_tokens'),
            'configuration' => array('size' => 40, 'length' => 64),
        ));

        $fields['max_tokens'] = new TextboxField(array(
            'label' => __('Max Tokens'),
            'required' => false,
            'hint' => __('Maximum number of tokens for the AI response (default: 512).'),
            'configuration' => array('size' => 10, 'length' => 10, 'placeholder' => '512'),
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

        $fields['temperature'] = new TextboxField(array(
            'label' => __('Temperature'),
            'required' => false,
            'hint' => __('Temperature for the AI model (e.g. 1 for default, 0.2 for more deterministic). Some models only support 1.'),
            'configuration' => array('size' => 10, 'length' => 10, 'placeholder' => '1'),
        ));

        // Timeout for the API request in seconds (default: 60)
        $fields['timeout'] = new TextboxField(array(
            'label' => __('Timeout (seconds)'),
            'required' => false,
            'hint' => __('Timeout for the API request in seconds (default: 60).'),
            'configuration' => array('size' => 10, 'length' => 10, 'placeholder' => '60'),
        ));

        $fields['max_thread_entries'] = new TextboxField(array(
            'label' => __('Max Thread Entries'),
            'required' => false,
            'hint' => __('Maximum number of ticket messages to include in the AI context (default: 20). Lower values reduce API costs but may miss context.'),
            'configuration' => array('size' => 10, 'length' => 10, 'placeholder' => '20'),
        ));

        $fields['show_instructions_popup'] = new BooleanField(array(
            'label' => __('Show Instructions Popup'),
            'default' => true,
            'configuration' => array(
                'desc' => __('When enabled, users can provide additional context before generating a response (e.g., "Offer customer a refund").')
            )
        ));

        $fields['enable_streaming'] = new BooleanField(array(
            'label' => __('Enable Streaming Responses'),
            'default' => false,
            'configuration' => array(
                'desc' => __('When enabled, AI responses stream in real-time (typewriter effect) instead of appearing all at once. Works with both OpenAI and Anthropic.')
            )
        ));

        return $fields;
    }
}
