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
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isStaff()) return;
        if (!$ticket || !method_exists($ticket, 'getId')) return;
        if (!isset($_REQUEST['id']) && !isset($_REQUEST['number'])) return;

        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        foreach ($this->getInstanceButtonData($ticket) as $btn) {
            ?>
            <li>
                <a class="ai-generate-reply" href="#ai/generate"
                   data-ticket-id="<?php echo $btn['ticketId']; ?>"
                   data-instance-id="<?php echo $btn['instanceId']; ?>"
                   data-show-popup="<?php echo $btn['showPopup']; ?>"
                   data-enable-streaming="<?php echo $btn['enableStreaming']; ?>">
                    <i class="icon-magic"></i>
                    <?php echo Format::htmlchars($btn['title']); ?>
                </a>
            </li>
            <?php
        }
    }

    /**
     * Gets button data for all enabled instances
     *
     * @param Ticket $ticket Ticket object
     * @return array Array of button configuration data
     */
    private function getInstanceButtonData($ticket) {
        $configs = self::getAllConfigs();
        if (!$configs || !$ticket) return array();

        $ticket_id = (int)$ticket->getId();
        if (!$ticket_id) return array();

        $buttons = array();
        foreach ($configs as $iid => $cfg) {
            $inst = $cfg->getInstance();
            $buttons[] = array(
                'ticketId' => $ticket_id,
                'instanceId' => (int)$iid,
                'showPopup' => (bool)$cfg->get('show_instructions_popup') ? '1' : '0',
                'enableStreaming' => (bool)$cfg->get('enable_streaming') ? '1' : '0',
                'title' => sprintf(__('AI Response — %s'), $inst ? $inst->getName() : ('Instance '.$iid))
            );
        }
        return $buttons;
    }

    /**
     * Gets toolbar button data for JavaScript injection (wrapper for templates)
     */
    private function getToolbarButtonData($object) {
        if (!$object || !method_exists($object, 'getId')) return array();
        return $this->getInstanceButtonData($object);
    }

    /**
     * Checks if we're on a single ticket detail view (not a ticket list)
     *
     * @param object $object The object being viewed
     * @return bool True if on ticket detail page
     */
    private function isTicketDetailView($object) {
        // Must be a Ticket object with valid ID
        if (!$object || !($object instanceof Ticket) || !$object->getId())
            return false;

        // Must have ticket id or number in request (indicates detail view, not list)
        return isset($_REQUEST['id']) || isset($_REQUEST['number']);
    }

    /**
     * Signal handler: Includes JS/CSS assets on ticket view pages
     *
     * @param object $object Viewed object (e.g., Ticket)
     * @param array $data View data passed by reference
     */
    function onObjectView($object, &$data) {
        if (!$this->isTicketDetailView($object))
            return;

        // Include CSS/JS assets once
        static $assets_included = false;
        if (!$assets_included) {
            $assets_included = true;
            $base = ROOT_PATH . 'include/plugins/ai-response-generator/';
            $js = $base . 'assets/js/main.js?v=' . urlencode(GIT_VERSION);
            $css = $base . 'assets/css/style.css?v=' . urlencode(GIT_VERSION);
            echo sprintf('<link rel="stylesheet" type="text/css" href="%s"/>', $css);
            echo sprintf('<script type="text/javascript" src="%s"></script>', $js);
        }

        // Inline bootstrap for toolbar button injection (runs on every pjax load)
        ?>
    <script type="text/javascript">
    window.AIResponseGen = window.AIResponseGen || {};
    window.AIResponseGen.ajaxEndpoint = 'ajax.php/ai/response';

    // Inject prominent toolbar button for each enabled instance
    // IMPORTANT: Always refresh toolbar instances data on page load to fix pjax navigation issues
    window.AIResponseGen.toolbarInstances = <?php echo json_encode($this->getToolbarButtonData($object)); ?>;

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
                    'data-enable-streaming': inst.enableStreaming,
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
        $dispatcher->append(url_post('^/ai/response/stream$', array('AIAjaxController', 'generateStreaming')));
    }

    /**
     * Provides "Duplicate" options in the Add New Instance dropdown
     *
     * @return array|false Array of instance options or false if none
     */
    public function getNewInstanceOptions() {
        $options = array();
        foreach ($this->getInstances() as $i) {
            $options[] = array(
                'name' => sprintf(__('Duplicate "%s"'), $i->getName()),
                'href' => sprintf('plugins.php?id=%d&a=add-instance&from=%d',
                    $this->getId(), $i->getId()),
                'icon' => 'icon-copy',
            );
        }
        return $options ?: false;
    }

    /**
     * Returns default config values when duplicating an instance
     *
     * @param array $options GET parameters including 'from' instance ID
     * @return array Configuration values to pre-fill
     */
    public function getNewInstanceDefaults($options) {
        if (!empty($options['from'])) {
            if ($instance = $this->getInstance((int)$options['from'])) {
                $config = $instance->getConfiguration();
                unset($config['name']); // Force user to enter new name
                return $config;
            }
        }
        return array();
    }
}
