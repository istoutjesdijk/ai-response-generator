<?php
/*********************************************************************
 * AI Response Generator Ajax Controller
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.ajax.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.thread.php');
require_once(__DIR__ . '/../api/OpenAIClient.php');

class AIAjaxController extends AjaxController {

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
        $temperature = 1;
    } else {
        $temperature = floatval($temperature);
    }

    // Read max_tokens from config, default to 512 if not set or invalid
    $max_tokens = $cfg->get('max_tokens');
    if ($max_tokens === null || $max_tokens === '' || !is_numeric($max_tokens) || $max_tokens < 1) {
        $max_tokens = 512;
    } else {
        $max_tokens = intval($max_tokens);
    }

    // Read timeout from config, default to 60 seconds if not set or invalid
    $timeout = $cfg->get('timeout');
    if ($timeout === null || $timeout === '' || !is_numeric($timeout) || $timeout < 1) {
        $timeout = 60;
    } else {
        $timeout = intval($timeout);
    }

        if (!$api_url || !$model)
            Http::response(400, $this->encode(array('ok' => false, 'error' => __('Missing API URL or model'))));

        // Build prompt using latest thread entries
        $thread = $ticket->getThread();
        $entries = $thread ? $thread->getEntries() : array();
        $messages = array();
        $count = 0;
        foreach ($entries as $E) {
            // Cap to recent context to avoid huge prompts
            if ($count++ > 20) break;
            $type = $E->getType();
            $body = ThreadEntryBody::clean($E->getBody());
            $who  = $E->getPoster();
            $who  = is_object($who) ? $who->getName() : 'User';
            $role = ($type == 'M') ? 'user' : 'assistant';
            $messages[] = array('role' => $role, 'content' => sprintf('[%s] %s', $who, $body));
        }

        // Append instruction for the model (from config or default)
        $system = trim((string)$cfg->get('system_prompt')) ?: "You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.";
        array_unshift($messages, array('role' => 'system', 'content' => $system));

        // Load RAG documents content (if any)
        $rag_text = $this->loadRagDocuments($cfg);
        if ($rag_text)
            $messages[] = array('role' => 'system', 'content' => "Additional knowledge base context:\n".$rag_text);

        try {
            // Validatie: sommige modellen ondersteunen alleen temperature=1
            if (stripos($model, 'gpt-5-nano') !== false && $temperature != 1) {
                throw new Exception(__('This model only supports temperature=1.'));
            }
            $client = new OpenAIClient($api_url, $api_key);
            // Provider selection and Anthropic version (from config)
            $provider = $cfg->get('provider') ?: 'auto';
            // Extra safety: auto-detect Anthropic by URL or model even if UI value didn't persist
            if ($provider !== 'anthropic') {
                if (stripos($api_url, 'anthropic.com') !== false || preg_match('#/v1/messages/?$#', $api_url) || stripos($model, 'claude') === 0) {
                    $provider = 'anthropic';
                }
            }
            $anthVersion = trim((string)$cfg->get('anthropic_version')) ?: '2023-06-01';
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

    private function loadRagDocuments($cfg) {
        $rag = trim((string)$cfg->get('rag_content'));
        if (!$rag) return '';
        // Optionally limit to 20,000 chars
        $limit_chars = 20000;
        if (strlen($rag) > $limit_chars) {
            $rag = substr($rag, 0, $limit_chars) . "\n... (truncated)";
        }
        return $rag;
    }

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
}
