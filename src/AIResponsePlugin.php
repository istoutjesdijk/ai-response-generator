<?php
/*********************************************************************
 * AI Response Generator Plugin
 *
 * Adds a "Generate Response" button to the agent ticket view which
 * calls an OpenAI-compatible API using settings configured in the admin UI.
 *********************************************************************/

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(__DIR__ . '/Config.php');

class AIResponseGeneratorPlugin extends Plugin {
    var $config_class = 'AIResponseGeneratorPluginConfig';

    // Cache of the last-loaded active instance config (for ajax controller)
    private static $active_config = null;
    // Cache of all enabled instance configs by instance id
    private static $configs = array();

    /**
     * Bootstrap the plugin and register signal handlers
     */
    function bootstrap() {
        // Register signals
        // 1) Add menu item into the ticket "More" menu
        Signal::connect('ticket.view.more', array($this, 'onTicketViewMore'), 'Ticket');
        // 2) Include JS/CSS on ticket view page
        Signal::connect('object.view', array($this, 'onObjectView'), 'Ticket');
        // 3) Extend SCP AJAX dispatcher with our endpoint
        Signal::connect('ajax.scp', array($this, 'onAjaxScp'));

        // Cache this instance's config for use by Ajax controller
        // (Only runs for enabled instances)
        $cfg = $this->getConfig();
        if ($cfg) {
            self::$active_config = $cfg;
            $inst = $cfg->getInstance();
            if ($inst && $inst->getId()) {
                self::$configs[$inst->getId()] = $cfg;
            }
        }
    }

    /**
     * Gets the active plugin configuration
     *
     * @return PluginConfig|null Active configuration instance
     */
    public static function getActiveConfig() {
        return self::$active_config;
    }

    /**
     * Gets all enabled plugin instance configurations
     *
     * @return array Array of configurations indexed by instance ID
     */
    public static function getAllConfigs() {
        return self::$configs;
    }

    /**
     * Signal handler: Injects menu item in ticket "More" dropdown
     *
     * @param Ticket $ticket Ticket object
     * @param array $data Menu data passed by reference
     */
    function onTicketViewMore($ticket, &$data) {
                // Only staff with reply permission should see the button
                global $thisstaff;
                if (!$thisstaff || !$thisstaff->isStaff()) return;
                if (!$ticket || !method_exists($ticket, 'getId')) return;

                // Deduplicate: only render one button per instance per request
                static $rendered = array();
                $configs = self::getAllConfigs();
                if (!$configs) return;
                foreach ($configs as $iid => $cfg) {
                        if (isset($rendered[$iid])) continue; // Already rendered for this instance
                        $rendered[$iid] = true;
                        $inst = $cfg->getInstance();
                        $name = $inst ? $inst->getName() : ('Instance '.$iid);
                        // BooleanField returns true/false or 1/0
                        $showPopup = (bool)$cfg->get('show_instructions_popup');
                        ?>
                        <li>
                            <a class="ai-generate-reply" href="#ai/generate"
                                 data-ticket-id="<?php echo (int)$ticket->getId(); ?>"
                                 data-instance-id="<?php echo (int)$iid; ?>"
                                 data-show-popup="<?php echo $showPopup ? '1' : '0'; ?>">
                                <i class="icon-magic"></i>
                                <?php echo __('AI Response'); ?> — <?php echo Format::htmlchars($name); ?>
                            </a>
                        </li>
                        <?php
                }
    }

    /**
     * Gets toolbar button data for JavaScript injection
     *
     * @param object $object Viewed object (e.g., Ticket)
     * @return array Array of button configuration data for each instance
     */
    private function getToolbarButtonData($object) {
        // Verify this is a ticket
        if (!$object || !method_exists($object, 'getId')) return array();

        $ticket_id = (int)$object->getId();
        if (!$ticket_id) return array();

        // Get all enabled instance configs
        $configs = self::getAllConfigs();
        if (!$configs) return array();

        $buttons = array();
        foreach ($configs as $iid => $cfg) {
            $inst = $cfg->getInstance();
            $name = $inst ? $inst->getName() : ('Instance '.$iid);
            $showPopup = (bool)$cfg->get('show_instructions_popup');

            $buttons[] = array(
                'ticketId' => $ticket_id,
                'instanceId' => (int)$iid,
                'showPopup' => $showPopup ? '1' : '0',
                'title' => sprintf(__('AI Response — %s'), $name)
            );
        }

        return $buttons;
    }

    /**
     * Signal handler: Includes JS/CSS assets on ticket view pages
     *
     * @param object $object Viewed object (e.g., Ticket)
     * @param array $data View data passed by reference
     */
    function onObjectView($object, &$data) {
    // Prevent duplicate inclusion of assets
    static $included = false;
    if ($included) return;
    $included = true;
    // Emit asset links. Attempt static files, plus a small inline bootstrap
    $base = ROOT_PATH . 'include/plugins/ai-response-generator/';
    $js = $base . 'assets/js/main.js?v=' . urlencode(GIT_VERSION);
    $css = $base . 'assets/css/style.css?v=' . urlencode(GIT_VERSION);
    echo sprintf('<link rel="stylesheet" type="text/css" href="%s"/>', $css);
    echo sprintf('<script type="text/javascript" src="%s"></script>', $js);

    // Inline bootstrap for route and toolbar button injection
    ?>
    <script type="text/javascript">
    window.AIResponseGen = window.AIResponseGen || {};
    window.AIResponseGen.ajaxEndpoint = 'ajax.php/ai/response';

    // Inject prominent toolbar button for each enabled instance
    window.AIResponseGen.toolbarInstances = window.AIResponseGen.toolbarInstances || <?php echo json_encode($this->getToolbarButtonData($object)); ?>;

    (function() {
        function injectToolbarButtons() {
            // Find the toolbar
            var $toolbar = $('.sticky.bar .pull-right.flush-right');
            if (!$toolbar.length) return;

            // Get instances data
            var instances = window.AIResponseGen.toolbarInstances || [];
            if (!instances.length) return;

            // Create buttons for each instance
            instances.forEach(function(inst) {
                // Check if button already exists
                var btnId = 'ai-response-toolbar-btn-' + inst.instanceId;
                if ($('#' + btnId).length) return;

                // Create the button HTML
                var $btn = $('<a/>', {
                    id: btnId,
                    class: 'action-button pull-right ai-generate-reply',
                    href: '#ai/generate',
                    'data-ticket-id': inst.ticketId,
                    'data-instance-id': inst.instanceId,
                    'data-show-popup': inst.showPopup,
                    'data-placement': 'bottom',
                    'data-toggle': 'tooltip',
                    title: inst.title
                }).append($('<i/>', {
                    class: 'icon-magic'
                }));

                // Insert before the More dropdown (first element in toolbar)
                $toolbar.prepend($btn);

                // Initialize tooltip if available
                if (typeof $btn.tooltip === 'function') {
                    $btn.tooltip();
                }
            });
        }

        // Try to inject immediately
        $(document).ready(injectToolbarButtons);

        // Also try after a short delay (for dynamic content)
        setTimeout(injectToolbarButtons, 500);

        // Watch for pjax page loads (osTicket uses pjax for navigation)
        $(document).on('pjax:end', injectToolbarButtons);
    })();
    </script>
    <?php
    }

    /**
     * Signal handler: Extends AJAX dispatcher with plugin routes
     *
     * @param AjaxDispatcher $dispatcher AJAX dispatcher instance
     */
    function onAjaxScp($dispatcher) {
        require_once(__DIR__ . '/AIAjax.php');
        $dispatcher->append(url_post('^/ai/response$', array('AIAjaxController', 'generate')));
    }
}
