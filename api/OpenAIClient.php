<?php
/*********************************************************************
 * Simple OpenAI-compatible client
 * Supports OpenAI Chat Completions compatible APIs.
 *********************************************************************/

require_once(__DIR__ . '/../src/Constants.php');

class OpenAIClient {
    private $baseUrl;
    private $apiKey;

    /**
     * Constructor
     *
     * @param string $baseUrl Base API URL
     * @param string|null $apiKey Optional API key for authentication
     */
    function __construct($baseUrl, $apiKey=null) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Generates AI response using OpenAI or Anthropic API
     *
     * @param string $model Model name to use (e.g., 'gpt-4', 'claude-3-opus')
     * @param array $messages Array of message objects with 'role' and 'content' keys
     * @param float|null $temperature Temperature for response generation (0.0-2.0)
     * @param int|null $max_tokens Maximum tokens to generate
     * @param string|null $max_tokens_param Parameter name for max tokens (API-specific)
     * @param int|null $timeout Request timeout in seconds
     * @param string $provider Provider type: 'openai', 'anthropic', or 'auto' for auto-detection
     * @param string|null $anthropicVersion Anthropic API version header value
     * @return string Generated response text
     * @throws Exception On API errors or network failures
     */
    function generateResponse($model, array $messages, $temperature = null, $max_tokens = null, $max_tokens_param = null, $timeout = null, $provider = 'auto', $anthropicVersion = null) {
        // Apply defaults
        $temperature = $temperature ?? AIResponseGeneratorConstants::DEFAULT_TEMPERATURE;
        $max_tokens = $max_tokens ?? AIResponseGeneratorConstants::DEFAULT_MAX_TOKENS;
        $max_tokens_param = $max_tokens_param ?? AIResponseGeneratorConstants::DEFAULT_MAX_TOKENS_PARAM;
        $timeout = $timeout ?? AIResponseGeneratorConstants::DEFAULT_TIMEOUT;
        $anthropicVersion = $anthropicVersion ?? AIResponseGeneratorConstants::DEFAULT_ANTHROPIC_VERSION;
        // Auto-detect provider if requested
        if ($provider === 'auto') {
            if (stripos($this->baseUrl, 'anthropic.com') !== false || stripos($model, 'claude') === 0 || preg_match('#/v1/messages$#', $this->baseUrl)) {
                $provider = 'anthropic';
            } else {
                $provider = 'openai';
            }
        }

        if ($provider === 'anthropic') {
            // Anthropic Claude messages API
            $url = $this->baseUrl;
            // Normalize to one of:
            // - https://host/v1/messages
            // - https://host/.../messages (already full path)
            if (preg_match('#/v1/messages/?$#', $url)) {
                // already correct
            } elseif (preg_match('#/v1/?$#', $url)) {
                $url = rtrim($url, '/') . '/messages';
            } elseif (preg_match('#/messages/?$#', $url)) {
                // already ends with messages
            } else {
                $url = rtrim($url, '/') . '/v1/messages';
            }

            // Extract system content(s) and strip from message list (Claude expects separate 'system')
            $systemParts = array();
            $claudeMsgs = array();
            foreach ($messages as $m) {
                $role = $m['role'] ?? 'user';
                $content = is_array($m['content']) ? json_encode($m['content']) : (string)$m['content'];
                if ($role === 'system') {
                    if (strlen(trim($content))) $systemParts[] = $content;
                    continue;
                }
                // Keep only user/assistant roles for Anthropic
                if ($role === 'user' || $role === 'assistant') {
                    $claudeMsgs[] = array('role' => $role, 'content' => $content);
                }
            }
            $system = trim(implode("\n\n", $systemParts));

            $payload = array(
                'model' => $model,
                'messages' => $claudeMsgs,
                'temperature' => $temperature,
                // Anthropic always expects 'max_tokens'
                'max_tokens' => (int)$max_tokens,
            );
            if ($system !== '') $payload['system'] = $system;

            $headers = array('Content-Type: application/json');
            if ($this->apiKey)
                $headers[] = 'x-api-key: ' . $this->apiKey;
            // Required version header
            $headers[] = 'anthropic-version: ' . $anthropicVersion;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new Exception('cURL error: ' . $err);
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $json = JsonDataParser::decode($resp, true);
            if ($code >= AIResponseGeneratorConstants::HTTP_ERROR_THRESHOLD)
                throw new Exception('API error: HTTP ' . $code . ' (Endpoint: ' . (parse_url($url, PHP_URL_PATH) ?: '') . ') ' . ($json['error']['message'] ?? $resp));

            // Anthropic messages response shape
            if (isset($json['content']) && is_array($json['content'])) {
                // Concatenate all text blocks in order
                $parts = array();
                foreach ($json['content'] as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                        $parts[] = (string)$block['text'];
                    }
                }
                if ($parts) return trim(implode("\n", $parts));
            }
            if (isset($json['content']) && is_string($json['content']))
                return trim((string)$json['content']);

            // Fallback: no text content returned
            return '';
        }

        // Default: OpenAI-compatible Chat Completions
        // Detect whether the given base URL points to a specific endpoint
        // If it appears to be the bare API root, append /chat/completions
        $url = $this->baseUrl;
        if (!preg_match('#/chat/(?:completions|complete)$#', $url)) {
            $url .= '/chat/completions';
        }

        $payload = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        );
        // Add the correct parameter name for max tokens
        $payload[$max_tokens_param ?: 'max_tokens'] = $max_tokens;

        $headers = array('Content-Type: application/json');
        if ($this->apiKey)
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); // configurable timeout
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL error: ' . $err);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = JsonDataParser::decode($resp, true);
        if ($code >= AIResponseGeneratorConstants::HTTP_ERROR_THRESHOLD)
            throw new Exception('API error: HTTP ' . $code . ' (Endpoint: ' . (parse_url($url, PHP_URL_PATH) ?: '') . ') ' . ($json['error']['message'] ?? $resp));

        // OpenAI-style response
        if (isset($json['choices'][0]['message']['content']))
            return (string) $json['choices'][0]['message']['content'];
        if (isset($json['choices'][0]['text']))
            return (string) $json['choices'][0]['text'];

        // Some compatible servers may use 'output'
        if (isset($json['output']))
            return (string) $json['output'];

        // Fallback: return the whole body, best-effort
        return is_string($resp) ? $resp : '';
    }
}
