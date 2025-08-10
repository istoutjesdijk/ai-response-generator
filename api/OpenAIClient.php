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
    function generateResponse($model, array $messages, $temperature = 0.2, $max_tokens = 512) {
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
            'max_tokens' => $max_tokens,
        );

        $headers = array('Content-Type: application/json');
        if ($this->apiKey)
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
            throw new Exception('API error: HTTP ' . $code . ' ' . ($json['error']['message'] ?? $resp));

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
