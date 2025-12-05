<?php
/*********************************************************************
 * AI Response Generator Plugin - Config
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.forms.php');
require_once(__DIR__ . '/Constants.php');

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
     * Uses osTicket's native 'default' attribute for field defaults.
     * These defaults are automatically applied by PluginConfig::__construct()
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

        $fields['api_key'] = new PasswordField(array(
            'label' => __('API Key'),
            'required' => false,
            'hint' => __('API key used for Authorization header. Stored encrypted.'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['model'] = new TextboxField(array(
            'label' => __('Model Name'),
            'required' => true,
            'hint' => __('Name of the AI model to use (e.g. gpt-4o, claude-3-sonnet).'),
            'configuration' => array('size' => 80, 'length' => 255),
        ));

        $fields['anthropic_version'] = new TextboxField(array(
            'label' => __('Anthropic Version'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_ANTHROPIC_VERSION,
            'hint' => __('Anthropic API version header (only for Claude models).'),
            'configuration' => array('size' => 20, 'length' => 32),
        ));

        $fields['max_tokens_param'] = new TextboxField(array(
            'label' => __('Max Tokens Parameter Name'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_MAX_TOKENS_PARAM,
            'hint' => __('Parameter name for max tokens (e.g. max_tokens or max_completion_tokens).'),
            'configuration' => array('size' => 40, 'length' => 64),
        ));

        $fields['max_tokens'] = new TextboxField(array(
            'label' => __('Max Tokens'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_MAX_TOKENS,
            'hint' => __('Maximum number of tokens for the AI response.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['system_prompt'] = new TextareaField(array(
            'label' => __('AI System Prompt'),
            'required' => false,
            'hint' => __('System instruction for the AI. Supports osTicket variables like %{ticket.number}, %{ticket.subject}, %{ticket.user.name}, %{ticket.user.email}, %{ticket.dept}, %{ticket.status}, %{ticket.priority}, %{ticket.create_date}, %{staff.name}. Also: %{date}, %{time}, %{datetime}, %{day}'),
            'configuration' => array(
                'rows' => 6,
                'html' => false,
                'placeholder' => __('You are a support agent for %{ticket.dept}. Today is %{date}. Draft a professional reply for %{ticket.user.name}...'),
            ),
        ));

        $fields['response_template'] = new TextareaField(array(
            'label' => __('Response Template'),
            'required' => false,
            'hint' => __('Template applied to the AI result. Use %{ai_text} for the generated text. Supports same variables as system prompt.'),
            'configuration' => array(
                'rows' => 6,
                'html' => false,
                'placeholder' => "Hello %{ticket.user.name},\n\n%{ai_text}\n\nBest regards,\n%{staff.name}",
            ),
        ));

        $fields['temperature'] = new TextboxField(array(
            'label' => __('Temperature'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_TEMPERATURE,
            'hint' => __('Temperature for the AI model (0.0-2.0). Lower = more deterministic.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['timeout'] = new TextboxField(array(
            'label' => __('Timeout (seconds)'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_TIMEOUT,
            'hint' => __('Timeout for the API request in seconds.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['max_thread_entries'] = new TextboxField(array(
            'label' => __('Max Thread Entries'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::MAX_THREAD_ENTRIES,
            'hint' => __('Maximum number of ticket messages to include in the AI context.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['show_instructions_popup'] = new BooleanField(array(
            'label' => __('Show Instructions Popup'),
            'default' => true,
            'configuration' => array(
                'desc' => __('Allow agents to provide additional context before generating a response.')
            )
        ));

        $fields['enable_streaming'] = new BooleanField(array(
            'label' => __('Enable Streaming Responses'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Stream AI responses in real-time (typewriter effect).')
            )
        ));

        $fields['enable_vision'] = new BooleanField(array(
            'label' => __('Enable Vision Support'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Send image attachments to vision-capable AI models. Increases API costs.')
            )
        ));

        $fields['max_images'] = new TextboxField(array(
            'label' => __('Max Images per Request'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_MAX_IMAGES,
            'hint' => __('Maximum images per AI request. OpenAI: max 10, Anthropic: max 100.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['max_image_size_mb'] = new TextboxField(array(
            'label' => __('Max Image Size (MB)'),
            'required' => false,
            'default' => AIResponseGeneratorConstants::DEFAULT_MAX_IMAGE_SIZE_MB,
            'hint' => __('Maximum size per image in megabytes. Larger images are skipped.'),
            'configuration' => array('size' => 10, 'length' => 10),
        ));

        $fields['include_inline_images'] = new BooleanField(array(
            'label' => __('Include Inline Images'),
            'default' => false,
            'configuration' => array(
                'desc' => __('Include embedded images (signatures, logos). Usually disabled.')
            )
        ));

        return $fields;
    }
}
