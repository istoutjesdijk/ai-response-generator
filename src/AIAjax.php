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
     */
    private function validateCSRF() {
        global $ost;

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            return;

        $token = $_POST['__CSRFToken__'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? null;
        if (!$ost || !$ost->getCSRF() || !$ost->getCSRF()->validateToken($token))
            $this->exerr(403, __('CSRF token validation failed'));
    }

    /**
     * Validates request and returns ticket and config, or sends error response
     *
     * @return array [Ticket, PluginConfig, Staff]
     */
    private function validateAndGetContext() {
        global $thisstaff;
        $this->staffOnly();
        $this->validateCSRF();

        $ticket_id = (int) ($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
        if (!$ticket_id || !($ticket = Ticket::lookup($ticket_id)))
            $this->exerr(404, __('Unknown ticket'));

        $role = $ticket->getRole($thisstaff);
        if (!$role || !$role->hasPerm(Ticket::PERM_REPLY))
            $this->exerr(403, __('Access denied'));

        // Load plugin config (support per-instance selection)
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
            $this->exerr(500, __('Plugin not configured'));

        return array($ticket, $cfg, $thisstaff);
    }

    /**
     * Builds messages array from ticket thread for AI context
     *
     * @param Ticket $ticket
     * @param PluginConfig $cfg
     * @param Staff $staff
     * @return array Messages array for AI API
     */
    private function buildMessages($ticket, $cfg, $staff) {
        $C = 'AIResponseGeneratorConstants';
        $messages = array();

        // System prompt
        $system = trim((string)$cfg->get('system_prompt')) ?: $C::DEFAULT_SYSTEM_PROMPT;
        $system = $this->replaceTemplateVars($system, $ticket, $staff);
        $messages[] = array('role' => 'system', 'content' => $system);

        // Extra instructions
        $extra = trim((string)($_POST['extra_instructions'] ?? ''));
        if (strlen($extra) > $C::MAX_EXTRA_INSTRUCTIONS_LENGTH) {
            $extra = substr($extra, 0, $C::MAX_EXTRA_INSTRUCTIONS_LENGTH);
        }
        if ($extra) {
            $messages[] = array('role' => 'system', 'content' => "Special instructions for this response: " . $extra);
        }

        // Thread entries
        $visionEnabled = (bool)$cfg->get('enable_vision');
        $includeInternalNotes = (bool)$cfg->get('include_internal_notes');
        $provider = $this->detectProvider($cfg->get('api_url'), $cfg->get('model'));
        $maxImages = min($C::getInt($cfg, 'max_images'), $this->getProviderImageLimit($provider));
        $max_thread_entries = $C::getInt($cfg, 'max_thread_entries');

        $thread = $ticket->getThread();
        if (!$thread) return $messages;

        $entries = clone $thread->getEntries();
        $entries->order_by('-created')->limit($max_thread_entries);
        $entryList = array_reverse(iterator_to_array($entries));

        $imageCount = 0;
        foreach ($entryList as $E) {
            $type = $E->getType();

            if ($type === 'N' && !$includeInternalNotes) continue;

            $body = ThreadEntryBody::clean($E->getBody());
            $who = $E->getPoster();
            $who = is_object($who) ? $who->getName() : 'User';
            $role = ($type == 'M') ? 'user' : 'assistant';

            // Format content
            if ($type === 'N') {
                $textContent = sprintf('[Internal Note - %s] %s', $who, $body);
            } elseif ($type === 'M' && $who === 'User') {
                $textContent = $body;
            } else {
                $textContent = sprintf('%s: %s', $who, $body);
            }

            // Process images if vision enabled
            $images = ($visionEnabled && $maxImages > 0)
                ? $this->processImageAttachments($E, $cfg, $imageCount, $maxImages)
                : array();

            if (!empty($images)) {
                $content = array(array('type' => 'text', 'text' => $textContent));
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
                $messages[] = array('role' => $role, 'content' => $textContent);
            }
        }

        return $messages;
    }

    /**
     * Generates AI response with streaming
     */
    function generateStreaming() {
        list($ticket, $cfg, $staff) = $this->validateAndGetContext();

        $C = 'AIResponseGeneratorConstants';
        $api_url = rtrim($cfg->get('api_url'), '/');
        $model = $cfg->get('model');

        if (!$api_url || !$model)
            $this->exerr(400, __('Missing API URL or model'));

        $messages = $this->buildMessages($ticket, $cfg, $staff);

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level()) ob_end_clean();

        $sendEvent = function($event, $data) {
            echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        };

        try {
            $client = new AIClient($api_url, $cfg->get('api_key'));
            $anthVersion = $C::get($cfg, 'anthropic_version');

            $fullResponse = '';
            $client->generateResponse(
                $model, $messages,
                $C::getFloat($cfg, 'temperature'),
                $C::getInt($cfg, 'max_tokens'),
                $C::get($cfg, 'max_tokens_param'),
                $C::getInt($cfg, 'timeout'),
                'auto', $anthVersion,
                function($chunk) use ($sendEvent, &$fullResponse) {
                    $fullResponse .= $chunk;
                    $sendEvent('chunk', array('text' => $chunk));
                }
            );

            // Apply response template
            $tpl = trim((string)$cfg->get('response_template'));
            if ($tpl) {
                $fullResponse = $this->replaceTemplateVars($tpl, $ticket, $staff, array('ai_text' => $fullResponse));
            }

            $sendEvent('done', array('text' => $fullResponse));
        } catch (Throwable $t) {
            $this->logError('AI Response Generator', $t->getMessage());
            $sendEvent('error', array('message' => $t->getMessage()));
        }
    }

    /**
     * Generates AI response for a ticket (non-streaming)
     */
    function generate() {
        list($ticket, $cfg, $staff) = $this->validateAndGetContext();

        $C = 'AIResponseGeneratorConstants';
        $api_url = rtrim($cfg->get('api_url'), '/');
        $model = $cfg->get('model');

        if (!$api_url || !$model)
            $this->exerr(400, __('Missing API URL or model'));

        $messages = $this->buildMessages($ticket, $cfg, $staff);

        try {
            $client = new AIClient($api_url, $cfg->get('api_key'));
            $reply = $client->generateResponse(
                $model, $messages,
                $C::getFloat($cfg, 'temperature'),
                $C::getInt($cfg, 'max_tokens'),
                $C::get($cfg, 'max_tokens_param'),
                $C::getInt($cfg, 'timeout'),
                'auto',
                $C::get($cfg, 'anthropic_version')
            );

            if (!$reply)
                throw new Exception(__('Empty response from model'));

            // Apply response template
            $tpl = trim((string)$cfg->get('response_template'));
            if ($tpl) {
                $reply = $this->replaceTemplateVars($tpl, $ticket, $staff, array('ai_text' => $reply));
            }

            return $this->encode(array('ok' => true, 'text' => $reply));
        } catch (Throwable $t) {
            $this->logError('AI Response Generator', $t->getMessage());
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

        if ($imageCount >= $maxImages) {
            return $images;
        }

        $C = 'AIResponseGeneratorConstants';
        $includeInline = (bool)$cfg->get('include_inline_images');
        $maxSizeBytes = $C::getFloat($cfg, 'max_image_size_mb') * 1048576;

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
