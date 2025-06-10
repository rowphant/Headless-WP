<?php
/*
Plugin Name: Headless WP
Description: This plugin was created to optimize Wordpress for use with SPA frameworks like react, vue.js, angular, svelte etc.
Plugin URI: https://github.com/rowphant/WP-Headless
Version: 0.0.10
Requires at least: 6.0
License: MIT
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.0
Author: Robert Metzner
*/

// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

// Update Checker
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/rowphant/Headless-WP/',
	__FILE__,
	'headless-wp'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');

// Define the API base path for reuse in other PHP files of this plugin.
define('WPG_API_BASE_PATH', 'headless-wp/v1');

// Include classes
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-admin-fields.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-user-confirmation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-reset-password.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-user-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-api-user-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-api-user-registration.php';

// Include user groups classes
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-base.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-profile.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-rest-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-invitations.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-requests.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-members.php';
require_once plugin_dir_path(__FILE__) . 'includes/user-groups/class-user-groups-admins.php';


// Initialisierung der Klassen
function run_wp_headless_gateway() {
    $hwp_options = new HWP_Options();
    $hwp_user_register = new HWP_User_Register();
    $hwp_user_confirmation = new HWP_User_Confirmation();
    $hwp_reset_password = new HWP_Reset_Password();
    $hwp_user_image = new HWP_User_Image();
    $hwp_api_user_image = new HWP_Api_User_Image();
    
    // User Groups
    $hwp_user_groups_admin = new HWP_User_Groups_Admin();
    $hwp_user_groups_profile = new HWP_User_Groups_Profile();
    $hwp_user_groups_rest_api = new HWP_User_Groups_REST_API();

    $hwp_user_groups_invitations = new HWP_User_Groups_Invitations();
    $hwp_user_groups_requests = new HWP_User_Groups_Requests();
    $hwp_user_groups_members = new HWP_User_Groups_Members();
    $hwp_user_groups_admin = new HWP_User_Groups_Admins();
}

add_action('plugins_loaded', 'run_wp_headless_gateway');