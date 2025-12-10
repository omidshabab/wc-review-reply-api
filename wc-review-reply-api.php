<?php
/**
 * Plugin Name: WooCommerce Review Reply API
 * Plugin URI: https://github.com/omidshabab/wc-review-reply-api
 * Description: Simple REST API for responding to WooCommerce product reviews
 * Version: 1.0.1
 * Author: Omid Shabab
 * Author URI: https://omidshabab.com
 * License: GPL v2 or later
 * Text Domain: wc-review-reply-api
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declare compatibility with WooCommerce features (e.g., HPOS/custom order tables)
 * This prevents WooCommerce from showing the generic incompatibility notice.
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Plugin Conflict Checker
 * Safely checks for conflicts with other plugins during activation
 */
class WC_Review_Reply_API_Conflict_Checker {
    
    private $conflicts = array();
    private $plugin_file;
    
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        register_activation_hook($plugin_file, array($this, 'check_conflicts_on_activation'));
        add_action('admin_notices', array($this, 'display_conflict_notices'));
    }
    
    /**
     * Check for conflicts during plugin activation
     */
    public function check_conflicts_on_activation() {
        // Clear any previous conflicts
        delete_transient('wc_review_reply_api_conflicts');
        
        $this->conflicts = array();
        
        try {
            // Check if WooCommerce is active
            $this->check_woocommerce();
            
            // Check for REST API namespace conflicts
            $this->check_rest_api_namespace();
            
            // Check for conflicting plugins
            $this->check_conflicting_plugins();
            
            // Check PHP version
            $this->check_php_version();
            
            // Check WordPress version
            $this->check_wp_version();
            
            // If conflicts found, prevent activation
            if (!empty($this->conflicts)) {
                // Store conflicts in transient for display
                set_transient('wc_review_reply_api_conflicts', $this->conflicts, 300);
                
                // Deactivate this plugin safely
                deactivate_plugins(plugin_basename($this->plugin_file));
                
                // Prevent redirect after deactivation
                if (isset($_GET['activate'])) {
                    unset($_GET['activate']);
                }
                
                // Trigger WordPress error system
                add_action('admin_notices', array($this, 'activation_error_notice'));
                
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            // Log error but don't crash the site
            error_log('WC Review Reply API: Conflict check error - ' . $e->getMessage());
            
            // Set a generic error
            $this->conflicts[] = array(
                'type' => 'error',
                'message' => 'An error occurred during conflict checking. Please check error logs.'
            );
            
            set_transient('wc_review_reply_api_conflicts', $this->conflicts, 300);
            deactivate_plugins(plugin_basename($this->plugin_file));
            
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }
            
            return false;
        }
    }
    
    /**
     * Check if WooCommerce is installed and active
     */
    private function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            $this->conflicts[] = array(
                'type' => 'dependency',
                'plugin' => 'WooCommerce',
                'message' => 'WooCommerce is required for this plugin to work. Please install and activate WooCommerce first.'
            );
        } elseif (!function_exists('WC')) {
            $this->conflicts[] = array(
                'type' => 'dependency',
                'plugin' => 'WooCommerce',
                'message' => 'WooCommerce is active but not fully initialized. Please try deactivating and reactivating WooCommerce.'
            );
        }
    }
    
    /**
     * Check for REST API namespace conflicts
     */
    private function check_rest_api_namespace() {
        if (!function_exists('rest_get_server')) {
            return;
        }
        
        try {
            $server = rest_get_server();
            $routes = $server->get_routes();
            
            $namespace = 'wc-review-api/v1';
            $conflicting_routes = array();
            
            foreach ($routes as $route => $handlers) {
                // Check if any route starts with our namespace
                if (strpos($route, '/' . $namespace . '/') === 0 || $route === '/' . $namespace) {
                    // Check if it's registered by another plugin
                    foreach ($handlers as $handler) {
                        if (isset($handler['callback']) && is_array($handler['callback'])) {
                            $callback_class = is_object($handler['callback'][0]) ? get_class($handler['callback'][0]) : '';
                            
                            // If it's not our class, it's a conflict
                            if ($callback_class !== 'WC_Review_Reply_API' && !empty($callback_class)) {
                                $conflicting_routes[] = $route;
                            }
                        }
                    }
                }
            }
            
            if (!empty($conflicting_routes)) {
                $this->conflicts[] = array(
                    'type' => 'namespace',
                    'message' => sprintf(
                        'REST API namespace conflict detected. The route namespace "wc-review-api/v1" may already be in use by another plugin. Conflicting routes: %s',
                        implode(', ', array_slice($conflicting_routes, 0, 3))
                    )
                );
            }
        } catch (Exception $e) {
            // Silently fail - REST API might not be fully initialized yet
            error_log('WC Review Reply API: REST API check error - ' . $e->getMessage());
        }
    }
    
    /**
     * Check for known conflicting plugins
     */
    private function check_conflicting_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $active_plugins = get_option('active_plugins', array());
        $conflicting_plugins = array();
        
        // List of known conflicting plugins (can be extended)
        $known_conflicts = array(
            // REST API related
            'rest-api/rest-api.php' => array(
                'name' => 'REST API (Custom)',
                'message' => 'A custom REST API plugin may conflict with this plugin\'s REST endpoints.'
            ),
            // Review related plugins that might interfere
            'woocommerce-review-reminder/woocommerce-review-reminder.php' => array(
                'name' => 'WooCommerce Review Reminder',
                'message' => 'This plugin may interfere with review reply functionality.'
            ),
            // Disable REST API plugins
            'disable-json-api/disable-json-api.php' => array(
                'name' => 'Disable REST API',
                'message' => 'This plugin disables the REST API which is required for this plugin to function.'
            ),
            'disable-json-api/class-disable-json-api.php' => array(
                'name' => 'Disable JSON API',
                'message' => 'This plugin disables the REST API which is required for this plugin to function.'
            ),
        );
        
        // Check each active plugin
        foreach ($active_plugins as $plugin) {
            if (isset($known_conflicts[$plugin])) {
                $conflict_info = $known_conflicts[$plugin];
                $conflicting_plugins[] = array(
                    'plugin' => $conflict_info['name'],
                    'message' => $conflict_info['message']
                );
            }
        }
        
        // Check for plugins that disable REST API
        if (has_filter('rest_authentication_errors')) {
            $filters = $GLOBALS['wp_filter']['rest_authentication_errors'] ?? null;
            if ($filters && !empty($filters->callbacks)) {
                foreach ($filters->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        // Check if there's a filter that completely disables REST API
                        if (is_array($callback['function']) && is_object($callback['function'][0])) {
                            $class_name = get_class($callback['function'][0]);
                            // Some plugins disable REST API by returning WP_Error
                            // We can't detect all, but we can warn
                        }
                    }
                }
            }
        }
        
        foreach ($conflicting_plugins as $conflict) {
            $this->conflicts[] = array(
                'type' => 'plugin',
                'plugin' => $conflict['plugin'],
                'message' => $conflict['message']
            );
        }
    }
    
    /**
     * Check PHP version
     */
    private function check_php_version() {
        $required_php = '7.4';
        $current_php = PHP_VERSION;
        
        if (version_compare($current_php, $required_php, '<')) {
            $this->conflicts[] = array(
                'type' => 'php',
                'message' => sprintf(
                    'This plugin requires PHP %s or higher. Your server is running PHP %s. Please update PHP.',
                    $required_php,
                    $current_php
                )
            );
        }
    }
    
    /**
     * Check WordPress version
     */
    private function check_wp_version() {
        $required_wp = '5.0';
        $current_wp = get_bloginfo('version');
        
        if (version_compare($current_wp, $required_wp, '<')) {
            $this->conflicts[] = array(
                'type' => 'wordpress',
                'message' => sprintf(
                    'This plugin requires WordPress %s or higher. Your site is running WordPress %s. Please update WordPress.',
                    $required_wp,
                    $current_wp
                )
            );
        }
    }
    
    /**
     * Display activation error notice
     */
    public function activation_error_notice() {
        $conflicts = get_transient('wc_review_reply_api_conflicts');
        
        if (!$conflicts || empty($conflicts)) {
            return;
        }
        
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>WooCommerce Review Reply API could not be activated.</strong></p>';
        echo '<ul style="list-style-type: disc; margin-left: 20px;">';
        
        foreach ($conflicts as $conflict) {
            echo '<li>' . esc_html($conflict['message']) . '</li>';
        }
        
        echo '</ul>';
        echo '<p>Please resolve these issues and try activating the plugin again.</p>';
        echo '</div>';
        
        // Clear the transient after displaying
        delete_transient('wc_review_reply_api_conflicts');
    }
    
    /**
     * Display ongoing conflict notices (if needed for runtime checks)
     */
    public function display_conflict_notices() {
        // Can be used for runtime conflict checking if needed
    }
}

// Initialize conflict checker
$wc_review_reply_api_conflict_checker = new WC_Review_Reply_API_Conflict_Checker(__FILE__);

/**
 * Check if plugin should be initialized
 * Prevents initialization if conflicts exist
 */
function wc_review_reply_api_can_initialize() {
    // Only initialize if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        return false;
    }
    
    // Check if plugin was deactivated due to conflicts
    $conflicts = get_transient('wc_review_reply_api_conflicts');
    if ($conflicts && !empty($conflicts)) {
        return false;
    }
    
    return true;
}

class WC_Review_Reply_API {
    
    private $namespace = 'wc-review-api/v1';
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        // POST endpoint to reply to a review
        register_rest_route($this->namespace, '/reviews/(?P<id>\d+)/reply', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_reply'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'description' => 'Review (comment) ID',
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
                'content' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Reply content',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'author_name' => array(
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Author name (defaults to current user)',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'author_email' => array(
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Author email (defaults to current user)',
                    'sanitize_callback' => 'sanitize_email',
                ),
            ),
        ));
        
        // GET endpoint to retrieve review replies
        register_rest_route($this->namespace, '/reviews/(?P<id>\d+)/replies', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_replies'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'description' => 'Review (comment) ID',
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));
        
        // DELETE endpoint to remove a reply
        register_rest_route($this->namespace, '/replies/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_reply'),
            'permission_callback' => array($this, 'check_permission'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'description' => 'Reply (comment) ID',
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ),
            ),
        ));

        // Debug endpoint to test if plugin is working
        register_rest_route($this->namespace, '/test', array(
            'methods' => 'GET',
            'callback' => function() {
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => 'WooCommerce Review Reply API is working!',
                    'version' => '1.0.1'
                ));
            },
            'permission_callback' => '__return_true',
        ));
    }
    
    public function check_permission() {
        return current_user_can('moderate_comments') || current_user_can('manage_woocommerce');
    }
    
    public function create_reply($request) {
        $review_id = $request->get_param('id');
        $content = $request->get_param('content');
        
        // Verify the review exists
        $review = get_comment($review_id);
        if (!$review) {
            return new WP_Error(
                'invalid_review',
                'Review not found with ID: ' . $review_id,
                array('status' => 404)
            );
        }

        // Check if it's a product review (WooCommerce stores reviews as regular comments on product post type)
        $post = get_post($review->comment_post_ID);
        if (!$post || $post->post_type !== 'product') {
            return new WP_Error(
                'invalid_review',
                'This comment is not a product review',
                array('status' => 400)
            );
        }
        
        // Get current user info or use provided info
        $current_user = wp_get_current_user();
        $author_name = $request->get_param('author_name') ?: $current_user->display_name;
        $author_email = $request->get_param('author_email') ?: $current_user->user_email;
        
        // Create the reply
        $reply_data = array(
            'comment_post_ID' => $review->comment_post_ID,
            'comment_author' => $author_name,
            'comment_author_email' => $author_email,
            'comment_content' => $content,
            'comment_parent' => $review_id,
            'comment_type' => 'comment', // WooCommerce uses 'comment' type
            'comment_approved' => 1,
            'user_id' => $current_user->ID,
        );
        
        $reply_id = wp_insert_comment($reply_data);
        
        if (!$reply_id) {
            return new WP_Error(
                'reply_failed',
                'Failed to create reply',
                array('status' => 500)
            );
        }
        
        $reply = get_comment($reply_id);
        
        return rest_ensure_response(array(
            'success' => true,
            'reply_id' => $reply_id,
            'reply' => $this->format_reply($reply),
        ));
    }
    
    public function get_replies($request) {
        $review_id = $request->get_param('id');
        
        // Verify the review exists
        $review = get_comment($review_id);
        if (!$review) {
            return new WP_Error(
                'invalid_review',
                'Review not found with ID: ' . $review_id,
                array('status' => 404)
            );
        }
        
        // Get all replies to this review
        $replies = get_comments(array(
            'parent' => $review_id,
            'status' => 'approve',
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
        ));
        
        $formatted_replies = array_map(array($this, 'format_reply'), $replies);
        
        return rest_ensure_response(array(
            'success' => true,
            'review_id' => $review_id,
            'count' => count($formatted_replies),
            'replies' => $formatted_replies,
        ));
    }
    
    public function delete_reply($request) {
        $reply_id = $request->get_param('id');
        
        $reply = get_comment($reply_id);
        if (!$reply) {
            return new WP_Error(
                'invalid_reply',
                'Reply not found with ID: ' . $reply_id,
                array('status' => 404)
            );
        }

        if ($reply->comment_parent == 0) {
            return new WP_Error(
                'invalid_reply',
                'This is not a reply, it is a top-level review',
                array('status' => 400)
            );
        }
        
        $deleted = wp_delete_comment($reply_id, true);
        
        if (!$deleted) {
            return new WP_Error(
                'delete_failed',
                'Failed to delete reply',
                array('status' => 500)
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Reply deleted successfully',
            'reply_id' => $reply_id,
        ));
    }
    
    private function format_reply($reply) {
        return array(
            'id' => (int) $reply->comment_ID,
            'parent_id' => (int) $reply->comment_parent,
            'product_id' => (int) $reply->comment_post_ID,
            'author' => $reply->comment_author,
            'author_email' => $reply->comment_author_email,
            'content' => $reply->comment_content,
            'date' => $reply->comment_date,
            'date_gmt' => $reply->comment_date_gmt,
            'status' => $reply->comment_approved,
            'link' => get_comment_link($reply->comment_ID),
        );
    }
}

// Initialize the plugin only if there are no conflicts
if (wc_review_reply_api_can_initialize()) {
    new WC_Review_Reply_API();
}