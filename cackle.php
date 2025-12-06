<?php
/**
 * Plugin Name: Cackle
 * Plugin URI: https://cackle.me
 * Description: This plugin allows your website's audience communicate through social networks like Facebook, Vkontakte, Twitter, and other providers.
 * Version: 4.50
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Author: Cackle
 * Author URI: https://cackle.me
 * Text Domain: cackle
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access when running outside of WordPress.
}

const CACKLE_VERSION = '4.50';

require_once __DIR__ . '/cackle_api.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/channel.php';
require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/sync_handler.php';
require_once __DIR__ . '/cackle_activate.php';

$cackle_api = new CackleAPI();

function cackle_manage() {
    include __DIR__ . '/manage.php';
}

function cackle_admin() {
    include __DIR__ . '/cackle_admin.php';
}

class Cackle_Plugin {
    public function __construct() {
        add_filter('comments_template', array($this, 'override_comments_template'), 1000);
        add_filter('comments_number', array($this, 'filter_comment_number'));
        add_action('admin_menu', array($this, 'register_moderation_menu'), 1);
        add_action('admin_menu', array($this, 'register_settings_menu'), 1);
    }

    public function register_moderation_menu() {
        global $submenu;
        unset($submenu['edit-comments.php'][0]);
        add_submenu_page(
            'edit-comments.php',
            'Cackle',
            __('Cackle moderate', 'cackle'),
            'moderate_comments',
            'cackle',
            'cackle_manage'
        );
        $submenu['edit-comments.php'][0] = $submenu['edit-comments.php'][1];
        unset($submenu['edit-comments.php'][1]);
    }

    public function register_settings_menu() {
        add_submenu_page(
            'edit-comments.php',
            'Cackle settings',
            __('Cackle settings', 'cackle'),
            'manage_options',
            'cackle_settings',
            'cackle_admin'
        );
    }

    public function filter_comment_number($comment_text) {
        global $post;
        $post_identifier = $this->identifier_for_post($post);

        // Strip markup (WordPress adds screen-reader spans) before exposing the count.
        $plain_text = wp_strip_all_tags($comment_text, true);

        return sprintf(
            '<span class="cackle-postid" id="c%s">%s</span>',
            esc_attr($post_identifier),
            esc_html($plain_text)
        );
    }

    public function identifier_for_post($post) {
        return ($post instanceof WP_Post) ? $post->ID : 0;
    }

    public function override_comments_template($template = null) {
        if (!cackle_enabled()) {
            // Fall back to the theme template when the plugin is not configured.
            return $template;
        }

        SyncHandler::init();

        return __DIR__ . '/comment-template.php';
    }
}

function cackle_bootstrap() {
    new Cackle_Plugin();
}
add_action('plugins_loaded', 'cackle_bootstrap');

/**
 * Enqueue admin assets only on Cackle admin screens to avoid loading outdated CDN files globally.
 */
function cackle_admin_enqueue_assets($hook) {
    // Load assets for any Cackle-specific admin screen; hooks vary per parent slug.
    if (strpos($hook, 'cackle') === false) {
        return;
    }

    $angular_version = '1.8.3';
    $material_version = '1.2.5';

    wp_enqueue_script('cackle-angular', 'https://cdn.jsdelivr.net/npm/angular@1.8.3/angular.min.js', array(), $angular_version, true);
    wp_enqueue_script('cackle-angular-animate', 'https://cdn.jsdelivr.net/npm/angular@1.8.3/angular-animate.min.js', array('cackle-angular'), $angular_version, true);
    wp_enqueue_script('cackle-angular-aria', 'https://cdn.jsdelivr.net/npm/angular@1.8.3/angular-aria.min.js', array('cackle-angular'), $angular_version, true);
    wp_enqueue_script('cackle-angular-messages', 'https://cdn.jsdelivr.net/npm/angular@1.8.3/angular-messages.min.js', array('cackle-angular'), $angular_version, true);
    wp_enqueue_script('cackle-angular-sanitize', 'https://cdn.jsdelivr.net/npm/angular@1.8.3/angular-sanitize.min.js', array('cackle-angular'), $angular_version, true);

    wp_enqueue_style('cackle-angular-material', 'https://cdn.jsdelivr.net/npm/angular-material@1.2.5/angular-material.min.css', array(), $material_version);
    wp_enqueue_script(
        'cackle-angular-material',
        'https://cdn.jsdelivr.net/npm/angular-material@1.2.5/angular-material.min.js',
        array('cackle-angular', 'cackle-angular-animate', 'cackle-angular-aria', 'cackle-angular-messages', 'cackle-angular-sanitize'),
        $material_version,
        true
    );

    // The admin bundle relies on Angular + Angular Material and is localized in cackle_admin.php.
    wp_enqueue_script(
        'cackle-admin-app',
        plugins_url('cackle_min_v1.js', __FILE__),
        array('cackle-angular-material', 'cackle-angular-sanitize'),
        CACKLE_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'cackle_admin_enqueue_assets');

function lang_init() {
    load_plugin_textdomain('cackle', false, basename(__DIR__) . '/languages');
}
add_action('plugins_loaded', 'lang_init');
function cackle_output_footer_comment_js() {
    if (!cackle_enabled()) {
        return;
    }

    require_once __DIR__ . '/counter.php';
    CackleCounter::init();
}
add_action('wp_footer', 'cackle_output_footer_comment_js');

function cackle_request_handler() {
    require_once __DIR__ . '/request_handler.php';
    cackle_handle_request();
}
add_action('wp_ajax_cackle_handle_request', 'cackle_request_handler');

register_activation_hook(__FILE__, 'cackle_activate');
