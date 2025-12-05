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
     * Validates CSRF token for POST requests
     *
     * @return void Returns error response if validation fails
     */
    private function validateCSRF() {
        global $ost;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            return;

        $token = $_POST['__CSRFToken__'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? null;
        if (!$ost || !$ost->getCSRF() || !$ost->getCSRF()->validateToken($token)) {
            Http::response(403, $this->encode(array('ok' => false, 'error' => __('CSRF token validation failed'))));
        }
    }

    /**
     * Logs an error message to osTicket's system log
     *
     * @param string $message Error message to log
     * @param bool $alert Whether to send admin alert (default: false)
     * @return void
     */
    private function logError($message, $alert = false) {
        global $ost;

        if ($ost) {
            $ost->logError('AI Response Generator', $message, $alert);
        }
    }

    /**
     * Generates AI response with streaming for a ticket
     *
     * @return void Streams SSE events directly to the client
     */
    function generateStreaming() {
        global $thisstaff;
        $this->staffOnly();
        $this->validateCSRF();

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

        // Get configuration values (defaults are handled by PluginConfig)
        $api_url          = rtrim($cfg->get('api_url'), '/');
        $api_key          = $cfg->get('api_key');
        $model            = $cfg->get('model');
        $max_tokens_param = $cfg->get('max_tokens_param');
        $temperature      = floatval($cfg->get('temperature'));
        $max_tokens       = intval($cfg->get('max_tokens'));
        $timeout          = intval($cfg->get('timeout'));
        $max_thread_entries = intval($cfg->get('max_thread_entries'));

        if (!$api_url || !$model)
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('Missing API URL or model'))));

        // Build messages array (same as generate method)
        $messages = array();
        $system = trim((string)$cfg->get('system_prompt')) ?: "You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.";
        // Replace template variables in system prompt
        $system = $this->replaceTemplateVars($system, $ticket, $thisstaff);
        $messages[] = array('role' => 'system', 'content' => $system);

        $extra_instructions = trim((string)($_POST['extra_instructions'] ?? ''));
        // Limit extra instructions length to prevent abuse
        if (strlen($extra_instructions) > AIResponseGeneratorConstants::MAX_EXTRA_INSTRUCTIONS_LENGTH) {
            $extra_instructions = substr($extra_instructions, 0, AIResponseGeneratorConstants::MAX_EXTRA_INSTRUCTIONS_LENGTH);
        }
        if ($extra_instructions) {
            $messages[] = array('role' => 'system', 'content' => "Special instructions for this response: " . $extra_instructions);
        }

        // Check if vision support is enabled
        $visionEnabled = (bool)$cfg->get('enable_vision');
        $provider = $this->detectProvider($api_url, $model);
        $providerImageLimit = $this->getProviderImageLimit($provider);

        // Get max images from config
        $maxImages = min(intval($cfg->get('max_images')), $providerImageLimit);

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
                $fullResponse = $this->replaceTemplateVars($tpl, $ticket, $thisstaff, array('ai_text' => $fullResponse));
            }

            // Send final event with complete response
            $sendEvent('done', array('text' => $fullResponse));

        } catch (Throwable $t) {
            $this->logError($t->getMessage());
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
        $this->validateCSRF();

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

        // Get configuration values (defaults are handled by PluginConfig)
        $api_url          = rtrim($cfg->get('api_url'), '/');
        $api_key          = $cfg->get('api_key');
        $model            = $cfg->get('model');
        $max_tokens_param = $cfg->get('max_tokens_param');
        $temperature      = floatval($cfg->get('temperature'));
        $max_tokens       = intval($cfg->get('max_tokens'));
        $timeout          = intval($cfg->get('timeout'));
        $max_thread_entries = intval($cfg->get('max_thread_entries'));

        if (!$api_url || !$model)
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('Missing API URL or model'))));

        // Build messages array starting with system prompt
        $messages = array();

        // Append instruction for the model (from config or default)
        $system = trim((string)$cfg->get('system_prompt')) ?: "You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.";
        // Replace template variables in system prompt
        $system = $this->replaceTemplateVars($system, $ticket, $thisstaff);
        $messages[] = array('role' => 'system', 'content' => $system);

        // Add extra instructions as system message (meta-instruction for the AI)
        $extra_instructions = trim((string)($_POST['extra_instructions'] ?? ''));
        // Limit extra instructions length to prevent abuse
        if (strlen($extra_instructions) > AIResponseGeneratorConstants::MAX_EXTRA_INSTRUCTIONS_LENGTH) {
            $extra_instructions = substr($extra_instructions, 0, AIResponseGeneratorConstants::MAX_EXTRA_INSTRUCTIONS_LENGTH);
        }
        if ($extra_instructions) {
            $messages[] = array('role' => 'system', 'content' => "Special instructions for this response: " . $extra_instructions);
        }

        // Check if vision support is enabled
        $visionEnabled = (bool)$cfg->get('enable_vision');
        $provider = $this->detectProvider($api_url, $model);
        $providerImageLimit = $this->getProviderImageLimit($provider);

        // Get max images from config
        $maxImages = min(intval($cfg->get('max_images')), $providerImageLimit);

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
                $reply = $this->replaceTemplateVars($tpl, $ticket, $thisstaff, array('ai_text' => $reply));
            }

            return $this->encode(array('ok' => true, 'text' => $reply));
        }
        catch (Throwable $t) {
            $this->logError($t->getMessage());
            return $this->encode(array('ok' => false, 'error' => $t->getMessage()));
        }
    }

    /**
     * Replaces template variables using osTicket's native VariableReplacer
     *
     * Supports all osTicket template variables like %{ticket.number}, %{ticket.user.name}, etc.
     * Also supports custom variables: %{date}, %{time}, %{datetime}, %{day}, %{ai_text}
     *
     * @param string $template Template string with %{...} placeholders
     * @param Ticket $ticket Ticket instance
     * @param Staff|null $staff Staff member (agent) instance
     * @param array $extraVars Additional variables to replace (e.g., ['ai_text' => $response])
     * @return string Expanded template with replaced placeholders
     */
    private function replaceTemplateVars($template, Ticket $ticket, $staff=null, $extraVars=array()) {
        global $ost;

        // Add date/time variables
        $now = new DateTime();

        // Build context - all variables passed to osTicket's VariableReplacer
        $vars = array(
            'ticket'   => $ticket,
            'staff'    => $staff,
            'date'     => $now->format('Y-m-d'),
            'time'     => $now->format('H:i'),
            'datetime' => $now->format('Y-m-d H:i'),
            'day'      => $now->format('l'),
        );

        // Merge any extra vars (like ai_text)
        $vars = array_merge($vars, $extraVars);

        // Use osTicket's native template variable replacement
        return $ost->replaceTemplateVariables($template, $vars);
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
