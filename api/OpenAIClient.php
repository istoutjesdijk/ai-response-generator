<?php
/*********************************************************************
 * Simple OpenAI-compatible client
 * Supports OpenAI Chat Completions compatible APIs.
 *********************************************************************/

class OpenAIClient {
    private $baseUrl;
    private $apiKey;

    function __construct($baseUrl, $apiKey=null) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * $messages: array of [role => 'system'|'user'|'assistant', content => '...']
     * Returns string reply content
     */
    // $timeout: timeout in seconds for the API request (default: 60)
    // $provider: 'openai' | 'anthropic' | 'auto'
    // $anthropicVersion: version string for Anthropic-Version header (default '2023-06-01')
    function generateResponse($model, array $messages, $temperature = 0.2, $max_tokens = 512, $max_tokens_param = 'max_tokens', $timeout = 60, $provider = 'auto', $anthropicVersion = '2023-06-01') {
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
            if ($code >= 400)
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
        if ($code >= 400)
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
