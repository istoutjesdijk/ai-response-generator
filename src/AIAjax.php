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
            $client = new OpenAIClient($api_url, $api_key);
            $reply = $client->generateResponse($model, $messages);
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
