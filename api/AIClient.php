<?php
/*********************************************************************
 * AI API Client
 * Supports OpenAI, Anthropic Claude, and OpenAI-compatible APIs.
 *********************************************************************/

require_once(__DIR__ . '/../src/Constants.php');

class AIClient {
    private $baseUrl;
    private $apiKey;

    function __construct($baseUrl, $apiKey = null) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Generates AI response with optional streaming support
     */
    function generateResponse($model, array $messages, $temperature = null, $max_tokens = null, $max_tokens_param = null, $timeout = null, $provider = 'auto', $anthropicVersion = null, $streamCallback = null) {
        $C = 'AIResponseGeneratorConstants';
        $temperature = $temperature ?? $C::DEFAULT_TEMPERATURE;
        $max_tokens = $max_tokens ?? $C::DEFAULT_MAX_TOKENS;
        $max_tokens_param = $max_tokens_param ?? $C::DEFAULT_MAX_TOKENS_PARAM;
        $timeout = $timeout ?? $C::DEFAULT_TIMEOUT;
        $anthropicVersion = $anthropicVersion ?? $C::DEFAULT_ANTHROPIC_VERSION;

        $provider = $this->detectProvider($provider, $model);
        $isStreaming = is_callable($streamCallback);

        if ($provider === 'anthropic') {
            return $this->callAnthropic($model, $messages, $temperature, $max_tokens, $anthropicVersion, $timeout, $isStreaming, $streamCallback);
        }
        return $this->callOpenAI($model, $messages, $temperature, $max_tokens, $max_tokens_param, $timeout, $isStreaming, $streamCallback);
    }

    /**
     * Detect provider from URL and model name
     */
    private function detectProvider($provider, $model) {
        if ($provider !== 'auto') return $provider;

        if (stripos($this->baseUrl, 'anthropic.com') !== false ||
            stripos($model, 'claude') === 0 ||
            preg_match('#/v1/messages$#', $this->baseUrl)) {
            return 'anthropic';
        }
        return 'openai';
    }

    /**
     * Call Anthropic Claude API
     */
    private function callAnthropic($model, $messages, $temperature, $max_tokens, $anthropicVersion, $timeout, $isStreaming, $streamCallback) {
        $url = $this->normalizeAnthropicUrl();

        // Extract system messages and convert to Claude format
        list($system, $claudeMsgs) = $this->prepareAnthropicMessages($messages);

        $payload = array(
            'model' => $model,
            'messages' => $claudeMsgs,
            'temperature' => $temperature,
            'max_tokens' => (int)$max_tokens,
        );
        if ($system !== '') $payload['system'] = $system;
        if ($isStreaming) $payload['stream'] = true;

        $headers = array('Content-Type: application/json', 'anthropic-version: ' . $anthropicVersion);
        if ($this->apiKey) $headers[] = 'x-api-key: ' . $this->apiKey;

        return $this->executeRequest($url, $payload, $headers, $timeout, $isStreaming, $streamCallback, 'anthropic');
    }

    /**
     * Call OpenAI-compatible API
     */
    private function callOpenAI($model, $messages, $temperature, $max_tokens, $max_tokens_param, $timeout, $isStreaming, $streamCallback) {
        $url = $this->baseUrl;
        if (!preg_match('#/chat/(?:completions|complete)$#', $url)) {
            $url .= '/chat/completions';
        }

        $payload = array(
            'model' => $model,
            'messages' => $this->transformMessagesForOpenAI($messages),
            'temperature' => $temperature,
            $max_tokens_param => $max_tokens,
        );
        if ($isStreaming) $payload['stream'] = true;

        $headers = array('Content-Type: application/json');
        if ($this->apiKey) $headers[] = 'Authorization: Bearer ' . $this->apiKey;

        return $this->executeRequest($url, $payload, $headers, $timeout, $isStreaming, $streamCallback, 'openai');
    }

    /**
     * Execute HTTP request with optional streaming
     */
    private function executeRequest($url, $payload, $headers, $timeout, $isStreaming, $streamCallback, $provider) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => !$isStreaming,
        ));

        if ($isStreaming) {
            $this->setupStreamingHandler($ch, $streamCallback, $provider);
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL error: ' . $err);
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($isStreaming) {
            if ($code >= AIResponseGeneratorConstants::HTTP_ERROR_THRESHOLD)
                throw new Exception('API error: HTTP ' . $code);
            return '';
        }

        return $this->parseResponse($resp, $code, $url, $provider);
    }

    /**
     * Setup streaming handler for cURL
     */
    private function setupStreamingHandler($ch, $streamCallback, $provider) {
        $buffer = '';
        $contentPath = $provider === 'anthropic'
            ? array('delta', 'text')
            : array('choices', 0, 'delta', 'content');

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, $streamCallback, $provider, $contentPath) {
            $buffer .= $data;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, 'event:') === 0) continue;

                if (strpos($line, 'data: ') === 0) {
                    $json_str = substr($line, 6);
                    if ($json_str === '[DONE]') continue;

                    $chunk = JsonDataParser::decode($json_str, true);

                    // Anthropic: check for content_block_delta type
                    if ($provider === 'anthropic') {
                        if (isset($chunk['type']) && $chunk['type'] === 'content_block_delta' && isset($chunk['delta']['text'])) {
                            call_user_func($streamCallback, $chunk['delta']['text']);
                        }
                    } else {
                        // OpenAI format
                        if (isset($chunk['choices'][0]['delta']['content'])) {
                            call_user_func($streamCallback, $chunk['choices'][0]['delta']['content']);
                        }
                    }
                }
            }
            return strlen($data);
        });
    }

    /**
     * Parse API response
     */
    private function parseResponse($resp, $code, $url, $provider) {
        $json = JsonDataParser::decode($resp, true);

        if ($code >= AIResponseGeneratorConstants::HTTP_ERROR_THRESHOLD) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $msg = $json['error']['message'] ?? $resp;
            throw new Exception("API error: HTTP {$code} (Endpoint: {$path}) {$msg}");
        }

        if ($provider === 'anthropic') {
            return $this->parseAnthropicResponse($json);
        }
        return $this->parseOpenAIResponse($json, $resp);
    }

    /**
     * Parse Anthropic response
     */
    private function parseAnthropicResponse($json) {
        if (isset($json['content']) && is_array($json['content'])) {
            $parts = array();
            foreach ($json['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $parts[] = (string)$block['text'];
                }
            }
            if ($parts) return trim(implode("\n", $parts));
        }
        if (isset($json['content']) && is_string($json['content'])) {
            return trim((string)$json['content']);
        }
        return '';
    }

    /**
     * Parse OpenAI response
     */
    private function parseOpenAIResponse($json, $resp) {
        if (isset($json['choices'][0]['message']['content']))
            return (string)$json['choices'][0]['message']['content'];
        if (isset($json['choices'][0]['text']))
            return (string)$json['choices'][0]['text'];
        if (isset($json['output']))
            return (string)$json['output'];
        return is_string($resp) ? $resp : '';
    }

    /**
     * Normalize Anthropic API URL
     */
    private function normalizeAnthropicUrl() {
        $url = $this->baseUrl;
        if (preg_match('#/v1/messages/?$#', $url)) return $url;
        if (preg_match('#/v1/?$#', $url)) return rtrim($url, '/') . '/messages';
        if (preg_match('#/messages/?$#', $url)) return $url;
        return rtrim($url, '/') . '/v1/messages';
    }

    /**
     * Prepare messages for Anthropic format
     */
    private function prepareAnthropicMessages($messages) {
        $systemParts = array();
        $claudeMsgs = array();

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'];

            if ($role === 'system') {
                if (strlen(trim((string)$content))) $systemParts[] = (string)$content;
                continue;
            }

            if ($role === 'user' || $role === 'assistant') {
                $claudeMsgs[] = array('role' => $role, 'content' => $content);
            }
        }

        return array(trim(implode("\n\n", $systemParts)), $claudeMsgs);
    }

    /**
     * Transform messages from internal format to OpenAI format
     */
    private function transformMessagesForOpenAI($messages) {
        $transformed = array();

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'];

            if (is_string($content)) {
                $transformed[] = array('role' => $role, 'content' => $content);
                continue;
            }

            if (is_array($content)) {
                $openaiContent = array();
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'text') {
                        $openaiContent[] = array('type' => 'text', 'text' => $block['text'] ?? '');
                    } elseif ($type === 'image' && isset($block['source'])) {
                        $mediaType = $block['source']['media_type'] ?? 'image/jpeg';
                        $base64Data = $block['source']['data'] ?? '';
                        $openaiContent[] = array(
                            'type' => 'image_url',
                            'image_url' => array('url' => "data:{$mediaType};base64,{$base64Data}")
                        );
                    }
                }
                $transformed[] = array('role' => $role, 'content' => $openaiContent);
            }
        }

        return $transformed;
    }
}
