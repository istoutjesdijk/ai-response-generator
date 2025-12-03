<?php
/*********************************************************************
 * AI Response Generator Ajax Controller
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.thread.php');
require_once(__DIR__ . '/../api/AIClient.php');
require_once(__DIR__ . '/Constants.php');

class AIAjaxController extends AjaxController {

    /**
     * Generates AI response with streaming for a ticket
     *
     * @return void Streams SSE events directly to the client
     */
    function generateStreaming() {
        global $thisstaff;
        $this->staffOnly();

        $ticket_id = (int) ($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticket_id || !($ticket = Ticket::lookup($ticket_id)))
            Http::response(404, $this->encode(array('ok' => false, 'error' => __('Unknown ticket'))));

        // Permission check: must be able to reply
        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY))
            Http::response(403, $this->encode(array('ok' => false, 'error' => __('Access denied'))));

        // Load plugin config
        $cfg = null;
        $iid = (int)($_POST['instance_id'] ?? $_GET['instance_id'] ?? 0);
        if ($iid) {
            $all = AIResponseGeneratorPlugin::getAllConfigs();
            if (isset($all[$iid]))
                $cfg = $all[$iid];
        }
        if (!$cfg)
            $cfg = AIResponseGeneratorPlugin::getActiveConfig();
        if (!$cfg)
            Http::response(500, $this->encode(array('ok' => false, 'error' => __('Plugin not configured'))));

        // Build configuration parameters and validate BEFORE setting SSE headers
        $api_url = rtrim($cfg->get('api_url'), '/');
        $api_key = $cfg->get('api_key');
        $model   = $cfg->get('model');
        $max_tokens_param = trim((string)$cfg->get('max_tokens_param')) ?: 'max_tokens';

        $temperature = $cfg->get('temperature');
        if ($temperature === null || $temperature === '' || !is_numeric($temperature)) {
            $temperature = AIResponseGeneratorConstants::DEFAULT_TEMPERATURE;
        } else {
            $temperature = floatval($temperature);
        }

        $max_tokens = $cfg->get('max_tokens');
        if ($max_tokens === null || $max_tokens === '' || !is_numeric($max_tokens) || $max_tokens < 1) {
            $max_tokens = AIResponseGeneratorConstants::DEFAULT_MAX_TOKENS;
        } else {
            $max_tokens = intval($max_tokens);
        }

        $timeout = $cfg->get('timeout');
        if ($timeout === null || $timeout === '' || !is_numeric($timeout) || $timeout < 1) {
            $timeout = AIResponseGeneratorConstants::DEFAULT_TIMEOUT;
        } else {
            $timeout = intval($timeout);
        }

        if (!$api_url || !$model)
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('Missing API URL or model'))));

        $max_thread_entries = $cfg->get('max_thread_entries');
        if ($max_thread_entries === null || $max_thread_entries === '' || !is_numeric($max_thread_entries) || $max_thread_entries < 1) {
            $max_thread_entries = AIResponseGeneratorConstants::MAX_THREAD_ENTRIES;
        } else {
            $max_thread_entries = intval($max_thread_entries);
        }

        // Build messages array (same as generate method)
        $messages = array();
        $system = trim((string)$cfg->get('system_prompt')) ?: "You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.";
        $messages[] = array('role' => 'system', 'content' => $system);

        $extra_instructions = trim((string)($_POST['extra_instructions'] ?? $_GET['extra_instructions'] ?? ''));
        if ($extra_instructions) {
            $messages[] = array('role' => 'system', 'content' => "Special instructions for this response: " . $extra_instructions);
        }

        // Check if vision support is enabled
        $visionEnabled = (bool)$cfg->get('enable_vision');
        $provider = $this->detectProvider($api_url, $model);
        $providerImageLimit = $this->getProviderImageLimit($provider);

        // Get max images from config
        $maxImages = $cfg->get('max_images');
        if ($maxImages === null || $maxImages === '' || !is_numeric($maxImages) || $maxImages < 0) {
            $maxImages = AIResponseGeneratorConstants::DEFAULT_MAX_IMAGES;
        } else {
            $maxImages = intval($maxImages);
        }
        // Respect provider limits
        $maxImages = min($maxImages, $providerImageLimit);

        $thread = $ticket->getThread();
        if ($thread) {
            // Use osTicket's QuerySet methods for efficient database query
            // Clone to avoid modifying cached entries, order by most recent, limit to configured amount
            $entries = clone $thread->getEntries();
            $entries->order_by('-created')->limit($max_thread_entries);

            // Reverse to get chronological order (oldest to newest)
            $entryList = array_reverse(iterator_to_array($entries));

            $imageCount = 0;

            foreach ($entryList as $E) {
                $type = $E->getType();
                $body = ThreadEntryBody::clean($E->getBody());
                $who  = $E->getPoster();
                $who  = is_object($who) ? $who->getName() : 'User';

                // Map to role for AI context
                $role = ($type == 'M') ? 'user' : 'assistant';

                // Format content based on entry type
                if ($type === 'N') {
                    // Internal notes: always include name with prefix
                    $textContent = sprintf('[Internal Note - %s] %s', $who, $body);
                } elseif ($type === 'M' && $who === 'User') {
                    // Customer message with fallback name: just the body (role says it's user)
                    $textContent = $body;
                } else {
                    // Customer with real name or Agent response: include name
                    $textContent = sprintf('%s: %s', $who, $body);
                }

                // Process images if vision is enabled
                $images = array();
                if ($visionEnabled && $maxImages > 0) {
                    $images = $this->processImageAttachments($E, $cfg, $imageCount, $maxImages);
                }

                // If there are images, use content array format; otherwise use simple string
                if (!empty($images)) {
                    $content = array();
                    // Add text first
                    $content[] = array('type' => 'text', 'text' => $textContent);
                    // Add images
                    foreach ($images as $img) {
                        $content[] = array(
                            'type' => 'image',
                            'source' => array(
                                'type' => 'base64',
                                'media_type' => $img['type'],
                                'data' => $img['data']
                            )
                        );
                    }
                    $messages[] = array('role' => $role, 'content' => $content);
                } else {
                    // No images, use simple string format
                    $messages[] = array('role' => $role, 'content' => $textContent);
                }
            }
        }

        $rag_text = $this->loadRagDocuments($cfg);
        if ($rag_text)
            $messages[] = array('role' => 'system', 'content' => "Additional knowledge base context:\n".$rag_text);

        // Validation
        if (stripos($model, 'gpt-5-nano') !== false && $temperature != 1) {
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('This model only supports temperature=1.'))));
        }

        // All validations passed - NOW set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        // Disable output buffering
        while (ob_get_level()) ob_end_clean();

        // Helper to send SSE event
        $sendEvent = function($event, $data) {
            echo "event: {$event}\n";
            echo "data: " . json_encode($data) . "\n\n";
            // Ensure immediate output
            if (ob_get_level()) ob_flush();
            flush();
            // Force flush for some servers
            if (function_exists('fastcgi_finish_request')) {
                // Don't use this - it would close the connection
                // fastcgi_finish_request();
            }
        };

        try {
            $client = new AIClient($api_url, $api_key);
            $provider = 'auto';
            $anthVersion = trim((string)$cfg->get('anthropic_version')) ?: AIResponseGeneratorConstants::DEFAULT_ANTHROPIC_VERSION;

            // Collect full response for template expansion
            $fullResponse = '';

            // Stream response with callback
            $client->generateResponse($model, $messages, $temperature, $max_tokens, $max_tokens_param, $timeout, $provider, $anthVersion, function($chunk) use ($sendEvent, &$fullResponse) {
                $fullResponse .= $chunk;
                $sendEvent('chunk', array('text' => $chunk));
            });

            // Apply response template if provided
            $tpl = trim((string)$cfg->get('response_template'));
            if ($tpl) {
                global $thisstaff;
                $tpl = $this->expandTemplate($tpl, $ticket, $fullResponse, $thisstaff);
                $fullResponse = $tpl;
            }

            // Send final event with complete response
            $sendEvent('done', array('text' => $fullResponse));

        } catch (Throwable $t) {
            $sendEvent('error', array('message' => $t->getMessage()));
        }
    }

    /**
     * Generates AI response for a ticket
     *
     * @return string JSON encoded response
     */
    function generate() {
        global $thisstaff;
        $this->staffOnly();

        $ticket_id = (int) ($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticket_id || !($ticket = Ticket::lookup($ticket_id)))
            Http::response(404, $this->encode(array('ok' => false, 'error' => __('Unknown ticket'))));

        // Permission check: must be able to reply
        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY))
            Http::response(403, $this->encode(array('ok' => false, 'error' => __('Access denied'))));

        // Load plugin config from active instance
        // Support per-instance selection via instance_id
        $cfg = null;
        $iid = (int)($_POST['instance_id'] ?? $_GET['instance_id'] ?? 0);
        if ($iid) {
            $all = AIResponseGeneratorPlugin::getAllConfigs();
            if (isset($all[$iid]))
                $cfg = $all[$iid];
        }
        if (!$cfg)
            $cfg = AIResponseGeneratorPlugin::getActiveConfig();
        if (!$cfg)
            Http::response(500, $this->encode(array('ok' => false, 'error' => __('Plugin not configured'))));


    $api_url = rtrim($cfg->get('api_url'), '/');
    $api_key = $cfg->get('api_key');
    $model   = $cfg->get('model');
    $max_tokens_param = trim((string)$cfg->get('max_tokens_param')) ?: 'max_tokens';

    $temperature = $cfg->get('temperature');
    if ($temperature === null || $temperature === '' || !is_numeric($temperature)) {
        $temperature = AIResponseGeneratorConstants::DEFAULT_TEMPERATURE;
    } else {
        $temperature = floatval($temperature);
    }

    // Read max_tokens from config, default if not set or invalid
    $max_tokens = $cfg->get('max_tokens');
    if ($max_tokens === null || $max_tokens === '' || !is_numeric($max_tokens) || $max_tokens < 1) {
        $max_tokens = AIResponseGeneratorConstants::DEFAULT_MAX_TOKENS;
    } else {
        $max_tokens = intval($max_tokens);
    }

    // Read timeout from config, default if not set or invalid
    $timeout = $cfg->get('timeout');
    if ($timeout === null || $timeout === '' || !is_numeric($timeout) || $timeout < 1) {
        $timeout = AIResponseGeneratorConstants::DEFAULT_TIMEOUT;
    } else {
        $timeout = intval($timeout);
    }

        if (!$api_url || !$model)
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('Missing API URL or model'))));

        // Read max_thread_entries from config, default if not set or invalid
        $max_thread_entries = $cfg->get('max_thread_entries');
        if ($max_thread_entries === null || $max_thread_entries === '' || !is_numeric($max_thread_entries) || $max_thread_entries < 1) {
            $max_thread_entries = AIResponseGeneratorConstants::MAX_THREAD_ENTRIES;
        } else {
            $max_thread_entries = intval($max_thread_entries);
        }

        // Build messages array starting with system prompt
        $messages = array();

        // Append instruction for the model (from config or default)
        $system = trim((string)$cfg->get('system_prompt')) ?: "You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.";
        $messages[] = array('role' => 'system', 'content' => $system);

        // Add extra instructions as system message (meta-instruction for the AI)
        $extra_instructions = trim((string)($_POST['extra_instructions'] ?? $_GET['extra_instructions'] ?? ''));
        if ($extra_instructions) {
            $messages[] = array('role' => 'system', 'content' => "Special instructions for this response: " . $extra_instructions);
        }

        // Check if vision support is enabled
        $visionEnabled = (bool)$cfg->get('enable_vision');
        $provider = $this->detectProvider($api_url, $model);
        $providerImageLimit = $this->getProviderImageLimit($provider);

        // Get max images from config
        $maxImages = $cfg->get('max_images');
        if ($maxImages === null || $maxImages === '' || !is_numeric($maxImages) || $maxImages < 0) {
            $maxImages = AIResponseGeneratorConstants::DEFAULT_MAX_IMAGES;
        } else {
            $maxImages = intval($maxImages);
        }
        // Respect provider limits
        $maxImages = min($maxImages, $providerImageLimit);

        // Build thread context using latest thread entries
        $thread = $ticket->getThread();
        if ($thread) {
            // Use osTicket's QuerySet methods for efficient database query
            // Clone to avoid modifying cached entries, order by most recent, limit to configured amount
            $entries = clone $thread->getEntries();
            $entries->order_by('-created')->limit($max_thread_entries);

            // Reverse to get chronological order (oldest to newest)
            $entryList = array_reverse(iterator_to_array($entries));

            $imageCount = 0;

            foreach ($entryList as $E) {
                $type = $E->getType();
                $body = ThreadEntryBody::clean($E->getBody());
                $who  = $E->getPoster();
                $who  = is_object($who) ? $who->getName() : 'User';

                // Map to role for AI context
                $role = ($type == 'M') ? 'user' : 'assistant';

                // Format content based on entry type
                if ($type === 'N') {
                    // Internal notes: always include name with prefix
                    $textContent = sprintf('[Internal Note - %s] %s', $who, $body);
                } elseif ($type === 'M' && $who === 'User') {
                    // Customer message with fallback name: just the body (role says it's user)
                    $textContent = $body;
                } else {
                    // Customer with real name or Agent response: include name
                    $textContent = sprintf('%s: %s', $who, $body);
                }

                // Process images if vision is enabled
                $images = array();
                if ($visionEnabled && $maxImages > 0) {
                    $images = $this->processImageAttachments($E, $cfg, $imageCount, $maxImages);
                }

                // If there are images, use content array format; otherwise use simple string
                if (!empty($images)) {
                    $content = array();
                    // Add text first
                    $content[] = array('type' => 'text', 'text' => $textContent);
                    // Add images
                    foreach ($images as $img) {
                        $content[] = array(
                            'type' => 'image',
                            'source' => array(
                                'type' => 'base64',
                                'media_type' => $img['type'],
                                'data' => $img['data']
                            )
                        );
                    }
                    $messages[] = array('role' => $role, 'content' => $content);
                } else {
                    // No images, use simple string format
                    $messages[] = array('role' => $role, 'content' => $textContent);
                }
            }
        }

        // Load RAG documents content (if any) - last
        $rag_text = $this->loadRagDocuments($cfg);
        if ($rag_text)
            $messages[] = array('role' => 'system', 'content' => "Additional knowledge base context:\n".$rag_text);

        try {
            // Validation: some models only support temperature=1
            if (stripos($model, 'gpt-5-nano') !== false && $temperature != 1) {
                throw new Exception(__('This model only supports temperature=1.'));
            }
            $client = new AIClient($api_url, $api_key);
            // Let the client auto-detect the provider
            $provider = 'auto';
            $anthVersion = trim((string)$cfg->get('anthropic_version')) ?: AIResponseGeneratorConstants::DEFAULT_ANTHROPIC_VERSION;
            // Pass the configurable timeout and provider info to the client
            $reply = $client->generateResponse($model, $messages, $temperature, $max_tokens, $max_tokens_param, $timeout, $provider, $anthVersion);
            if (!$reply)
                throw new Exception(__('Empty response from model'));

            // Apply response template if provided
            $tpl = trim((string)$cfg->get('response_template'));
            if ($tpl) {
                global $thisstaff;
                $tpl = $this->expandTemplate($tpl, $ticket, $reply, $thisstaff);
                $reply = $tpl;
            }

            return $this->encode(array('ok' => true, 'text' => $reply));
        }
        catch (Throwable $t) {
            return $this->encode(array('ok' => false, 'error' => $t->getMessage()));
        }
    }

    /**
     * Loads and processes RAG (Retrieval-Augmented Generation) content from config
     *
     * @param PluginConfig $cfg Plugin configuration instance
     * @return string RAG content, truncated if necessary
     */
    private function loadRagDocuments($cfg) {
        $rag = trim((string)$cfg->get('rag_content'));
        if (!$rag) return '';
        // Limit RAG content length to prevent excessive prompt sizes
        if (strlen($rag) > AIResponseGeneratorConstants::MAX_RAG_CONTENT_LENGTH) {
            $rag = substr($rag, 0, AIResponseGeneratorConstants::MAX_RAG_CONTENT_LENGTH) . "\n... (truncated)";
        }
        return $rag;
    }

    /**
     * Expands template with ticket and AI response data
     *
     * @param string $template Template string with placeholders
     * @param Ticket $ticket Ticket instance
     * @param string $aiText Generated AI response text
     * @param Staff|null $staff Staff member (agent) instance
     * @return string Expanded template with replaced placeholders
     */
    private function expandTemplate($template, Ticket $ticket, $aiText, $staff=null) {
        $user = $ticket->getOwner();
        $agentName = '';
        if ($staff && is_object($staff)) {
            // Prefer display name, fallback to name
            $agentName = method_exists($staff, 'getName') ? (string)$staff->getName() : '';
        }
        $replacements = array(
            '{ai_text}' => (string)$aiText,
            '{ticket_number}' => (string)$ticket->getNumber(),
            '{ticket_subject}' => (string)$ticket->getSubject(),
            '{user_name}' => $user ? (string)$user->getName() : '',
            '{user_email}' => $user ? (string)$user->getEmail() : '',
            '{agent_name}' => $agentName,
        );
        return strtr($template, $replacements);
    }

    /**
     * Processes image attachments from a thread entry for vision-capable AI models
     *
     * @param ThreadEntry $entry Thread entry to process attachments from
     * @param PluginConfig $cfg Plugin configuration
     * @param int &$imageCount Current image count (modified by reference)
     * @param int $maxImages Maximum images allowed
     * @return array Array of image data arrays with 'type', 'data', and 'name' keys
     */
    private function processImageAttachments($entry, $cfg, &$imageCount, $maxImages) {
        $images = array();

        // Check if we've reached the limit
        if ($imageCount >= $maxImages) {
            return $images;
        }

        // Get configuration
        $includeInline = (bool)$cfg->get('include_inline_images');
        $maxSizeMB = $cfg->get('max_image_size_mb');
        if ($maxSizeMB === null || $maxSizeMB === '' || !is_numeric($maxSizeMB) || $maxSizeMB < 0) {
            $maxSizeMB = AIResponseGeneratorConstants::DEFAULT_MAX_IMAGE_SIZE_MB;
        } else {
            $maxSizeMB = floatval($maxSizeMB);
        }
        $maxSizeBytes = $maxSizeMB * 1048576; // Convert MB to bytes

        // Get attachments from entry
        $attachments = $entry->getAttachments();
        if (!$attachments) {
            return $images;
        }

        foreach ($attachments as $attachment) {
            // Stop if we've reached the limit
            if ($imageCount >= $maxImages) {
                break;
            }

            // Skip inline images if configured
            if ($attachment->inline && !$includeInline) {
                continue;
            }

            $file = $attachment->getFile();
            if (!$file) {
                continue;
            }

            // Check MIME type - only process images
            $mimeType = $file->getType();
            if (!in_array($mimeType, AIResponseGeneratorConstants::SUPPORTED_IMAGE_TYPES)) {
                continue;
            }

            // Check file size
            $fileSize = $file->getSize();
            if ($fileSize > $maxSizeBytes) {
                continue; // Skip images that are too large
            }

            // Get image data and encode to base64
            try {
                $imageData = $file->getData();
                if (!$imageData) {
                    continue;
                }

                $base64Data = base64_encode($imageData);

                $images[] = array(
                    'type' => $mimeType,
                    'data' => $base64Data,
                    'name' => $attachment->getFilename(),
                    'size' => $fileSize
                );

                $imageCount++;
            } catch (Exception $e) {
                // Skip this attachment if there's an error reading it
                continue;
            }
        }

        return $images;
    }

    /**
     * Determines the maximum number of images allowed for the given provider
     *
     * @param string $provider Provider type ('openai' or 'anthropic')
     * @return int Maximum number of images allowed
     */
    private function getProviderImageLimit($provider) {
        if ($provider === 'anthropic') {
            return AIResponseGeneratorConstants::ANTHROPIC_MAX_IMAGES;
        }
        // Default to OpenAI limit (also used for 'openai' and 'auto')
        return AIResponseGeneratorConstants::OPENAI_MAX_IMAGES;
    }

    /**
     * Detects the AI provider from URL and model name
     *
     * @param string $apiUrl API URL
     * @param string $model Model name
     * @return string Provider type ('openai' or 'anthropic')
     */
    private function detectProvider($apiUrl, $model) {
        if (stripos($apiUrl, 'anthropic.com') !== false ||
            stripos($model, 'claude') === 0 ||
            preg_match('#/v1/messages$#', $apiUrl)) {
            return 'anthropic';
        }
        return 'openai';
    }
}
