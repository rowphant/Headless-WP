<?php
/*
Plugin Name: Headless WP
Description: This plugin was created to optimize Wordpress for use with SPA frameworks like react, vue.js, angular, svelte etc.
Plugin URI: https://github.com/rowphant/WP-Headless
Version: 0.0.1
Requires at least: 6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.0
Author: Robert Metzner
*/

// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

// Einbinden der Klassen
require_once plugin_dir_path(__FILE__) . 'includes/class-api-user-registration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-user-confirmation.php';

// Initialisierung der Klassen
function run_hwp() {
    $hwp_user_register = new HWP_User_Register();
    $hwp_user_confirmation = new HWP_User_Confirmation();
}

add_action('plugins_loaded', 'run_hwp');