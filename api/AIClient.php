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

        if ($provider === 'auto') {
            $provider = self::detectProvider($this->baseUrl, $model);
        }
        $isStreaming = is_callable($streamCallback);

        if ($provider === 'anthropic') {
            return $this->callAnthropic($model, $messages, $temperature, $max_tokens, $anthropicVersion, $timeout, $isStreaming, $streamCallback);
        }
        return $this->callOpenAI($model, $messages, $temperature, $max_tokens, $max_tokens_param, $timeout, $isStreaming, $streamCallback);
    }

    /**
     * Detect provider from URL and model name
     *
     * @param string $apiUrl API URL
     * @param string $model Model name
     * @return string Provider type ('openai' or 'anthropic')
     */
    public static function detectProvider($apiUrl, $model) {
        if (stripos($apiUrl, 'anthropic.com') !== false ||
            stripos($model, 'claude') === 0 ||
            preg_match('#/v1/messages$#', $apiUrl)) {
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
     * Call OpenAI Responses API
     */
    private function callOpenAI($model, $messages, $temperature, $max_tokens, $max_tokens_param, $timeout, $isStreaming, $streamCallback) {
        $url = $this->baseUrl;
        if (!preg_match('#/responses$#', $url)) {
            $url = rtrim($url, '/') . '/responses';
        }

        // Extract system messages as instructions and convert rest to input
        list($instructions, $input) = $this->prepareResponsesApiPayload($messages);

        $payload = array(
            'model' => $model,
            'input' => $input,
            'temperature' => $temperature,
            $max_tokens_param => $max_tokens,
        );
        if ($instructions !== '') $payload['instructions'] = $instructions;
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

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, $streamCallback, $provider) {
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

                    if ($provider === 'anthropic') {
                        // Anthropic: content_block_delta event
                        if (isset($chunk['type']) && $chunk['type'] === 'content_block_delta' && isset($chunk['delta']['text'])) {
                            call_user_func($streamCallback, $chunk['delta']['text']);
                        }
                    } else {
                        // OpenAI Responses API: response.output_text.delta event
                        if (isset($chunk['type']) && $chunk['type'] === 'response.output_text.delta' && isset($chunk['delta'])) {
                            call_user_func($streamCallback, $chunk['delta']);
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
        return $this->parseOpenAIResponse($json);
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
     * Parse OpenAI Responses API response
     */
    private function parseOpenAIResponse($json) {
        // Responses API: output_text helper property
        if (isset($json['output_text']))
            return (string)$json['output_text'];

        // Responses API: output array with message content
        if (isset($json['output']) && is_array($json['output'])) {
            $parts = array();
            foreach ($json['output'] as $item) {
                if (($item['type'] ?? '') === 'message' && isset($item['content'])) {
                    foreach ($item['content'] as $block) {
                        if (($block['type'] ?? '') === 'output_text' && isset($block['text'])) {
                            $parts[] = (string)$block['text'];
                        }
                    }
                }
            }
            if ($parts) return trim(implode("\n", $parts));
        }

        return '';
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
                // Transform content to Anthropic format if it's an array
                $anthropicContent = $this->formatContentForAnthropic($content);
                $claudeMsgs[] = array('role' => $role, 'content' => $anthropicContent);
            }
        }

        return array(trim(implode("\n\n", $systemParts)), $claudeMsgs);
    }

    /**
     * Format content block for Anthropic API
     *
     * @param mixed $content String or array content
     * @return mixed Formatted content for Anthropic
     */
    private function formatContentForAnthropic($content) {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return $content;
        }

        $formatted = array();
        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $formatted[] = array(
                    'type' => 'text',
                    'text' => $block['text'] ?? ''
                );
            } elseif ($type === 'image' && isset($block['source'])) {
                // Images: already in correct Anthropic format
                $formatted[] = $block;
            } elseif ($type === 'file' && isset($block['data'])) {
                // Files: convert to Anthropic 'document' format
                $mimeType = $block['mime_type'] ?? 'application/octet-stream';
                $formatted[] = $this->formatDocumentForAnthropic($block['data'], $mimeType);
            }
        }

        return $formatted ?: $content;
    }

    /**
     * Format document for Anthropic API based on MIME type
     * Text files use 'text' source type, binary files use 'base64'
     *
     * @param string $base64Data Base64 encoded file data
     * @param string $mimeType MIME type of the file
     * @return array Formatted document block
     */
    private function formatDocumentForAnthropic($base64Data, $mimeType) {
        // Text-based files should be sent as plain text
        $textMimeTypes = array(
            'text/plain',
            'text/csv',
            'text/html',
            'text/markdown',
            'application/json',
            'application/xml',
            'text/xml',
        );

        if (in_array($mimeType, $textMimeTypes)) {
            // Decode base64 to plain text for text-based files
            $plainText = base64_decode($base64Data);
            return array(
                'type' => 'document',
                'source' => array(
                    'type' => 'text',
                    'media_type' => 'text/plain',
                    'data' => $plainText
                )
            );
        }

        // Binary files (PDF, etc.) use base64 encoding
        return array(
            'type' => 'document',
            'source' => array(
                'type' => 'base64',
                'media_type' => $mimeType,
                'data' => $base64Data
            )
        );
    }

    /**
     * Prepare payload for OpenAI Responses API
     * Extracts system messages as instructions and formats conversation as input
     *
     * @param array $messages Internal message format
     * @return array [instructions, input]
     */
    private function prepareResponsesApiPayload($messages) {
        $systemParts = array();
        $inputMessages = array();

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'];

            // System messages become instructions
            if ($role === 'system') {
                if (is_string($content) && strlen(trim($content))) {
                    $systemParts[] = trim($content);
                }
                continue;
            }

            // Convert content to Responses API format
            $inputContent = $this->formatContentForResponsesApi($content);

            if ($inputContent !== null) {
                $inputMessages[] = array(
                    'role' => $role,
                    'content' => $inputContent
                );
            }
        }

        $instructions = trim(implode("\n\n", $systemParts));
        return array($instructions, $inputMessages);
    }

    /**
     * Format content block for Responses API
     *
     * @param mixed $content String or array content
     * @return mixed Formatted content for Responses API
     */
    private function formatContentForResponsesApi($content) {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return null;
        }

        $formatted = array();
        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $formatted[] = array(
                    'type' => 'input_text',
                    'text' => $block['text'] ?? ''
                );
            } elseif ($type === 'image' && isset($block['source'])) {
                $mediaType = $block['source']['media_type'] ?? 'image/jpeg';
                $base64Data = $block['source']['data'] ?? '';
                $formatted[] = array(
                    'type' => 'input_image',
                    'image_url' => "data:{$mediaType};base64,{$base64Data}"
                );
            } elseif ($type === 'file' && isset($block['data'])) {
                $formatted[] = array(
                    'type' => 'input_file',
                    'filename' => $block['filename'] ?? 'document',
                    'file_data' => "data:{$block['mime_type']};base64,{$block['data']}"
                );
            }
        }

        return $formatted ?: null;
    }
}
