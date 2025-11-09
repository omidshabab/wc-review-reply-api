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

// Initialize the plugin
new WC_Review_Reply_API();