<?php

/*
Plugin Name: WP-OAuth2
Plugin URI: http://github.com/donbowman/wp-oauth2
Description: A wordpress plugin that allows OAUTH2 login against any of a list of providers (github, google, ...) without using an external SaaS service.
Version: 0.0.1
Author: Don Bowman
Author URI: https://blog.donbowman.ca
License: GPL2
*/

// start the user session for persisting user/login state during ajax, header redirect, and cross domain calls:
if (!isset($_SESSION)) {
    session_start();
}

include 'wp-oauth2-client.php';

// plugin class:
Class WPOA2 {

    // ==============
    // INITIALIZATION
    // ==============

    // set a version that we can use for performing plugin updates, this should always match the plugin version:
    const PLUGIN_VERSION = "0.0.1";

    // singleton class pattern:
    protected static $instance = NULL;
    public static function get_instance() {
	NULL === self::$instance and self::$instance = new self;
	return self::$instance;
    }

    // define the settings used by this plugin; this array will be used for registering settings, applying default values, and deleting them during uninstall:
    private $settings = array(
	'wpoa2_show_login_messages' => 0,                          // 0, 1
	'wpoa2_login_redirect' => 'last_page',                     // home_page, last_page, specific_page, admin_dashboard, profile_page, custom_url
	'wpoa2_login_redirect_page' => 0,                          // any whole number (wordpress page id)
	'wpoa2_login_redirect_url' => '',                          // any string (url)
	'wpoa2_logout_redirect' => 'home_page',                    // home_page, last_page, specific_page, admin_dashboard, profile_page, custom_url, default_handling
	'wpoa2_logout_redirect_page' => 0,                         // any whole number (wordpress page id)
	'wpoa2_logout_redirect_url' => '',                         // any string (url)
	'wpoa2_logout_inactive_users' => 0,                        // any whole number (minutes)
	'wpoa2_hide_wordpress_login_form' => 0,                    // 0, 1
	'wpoa2_logo_links_to_site' => 0,                           // 0, 1
	'wpoa2_logo_image' => '',                                  // any string (image url)
	'wpoa2_bg_image' => '',                                    // any string (image url)
	'wpoa2_login_form_show_login_screen' => 'Login Screen',    // any string (name of a custom login form shortcode design)
	'wpoa2_login_form_show_profile_page' => 'Profile Page',    // any string (name of a custom login form shortcode design)
	'wpoa2_login_form_show_comments_section' => 'None',        // any string (name of a custom login form shortcode design)
	'wpoa2_login_form_designs' => array(                       // array of shortcode designs to be included by default; same array signature as the shortcode function uses
		'Login Screen' => array(
			'layout' => 'buttons-column',
			'align' => 'center',
			'show_login' => 'conditional',
			'show_logout' => 'conditional',
			'button_prefix' => 'Log in with',
			'logged_out_title' => 'Please log in:',
			'logged_in_title' => 'You are already logged in.',
			'logging_in_title' => 'Logging in...',
			'logging_out_title' => 'Logging out...',
			'style' => '',
			'class' => '',
			),
		'Profile Page' => array(
			'layout' => 'buttons-row',
			'align' => 'left',
			'show_login' => 'always',
			'show_logout' => 'never',
			'button_prefix' => 'Link',
			'logged_out_title' => 'Select a provider:',
			'logged_in_title' => 'Select a provider:',
			'logging_in_title' => 'Authenticating...',
			'logging_out_title' => 'Logging out...',
			'style' => '',
			'class' => '',
			),
		),
	'wpoa2_suppress_welcome_email' => 0,                    // 0, 1
	'wpoa2_new_user_role' => 'subscriber',                  // role
	'wpoa2_google_api_enabled' => 0,                        // 0, 1
	'wpoa2_google_api_id' => '',                            // any string
	'wpoa2_google_api_secret' => '',                        // any string
	'wpoa2_linkedin_api_enabled' => 0,                      // 0, 1
	'wpoa2_linkedin_api_id' => '',                          // any string
	'wpoa2_linkedin_api_secret' => '',                      // any string
	'wpoa2_github_api_enabled' => 0,                        // 0, 1
	'wpoa2_github_api_id' => '',                            // any string
	'wpoa2_github_api_secret' => '',                        // any string

	'wpoa2_restore_default_settings' => 0,                  // 0, 1
	'wpoa2_delete_settings_on_uninstall' => 0,              // 0, 1
    );

    // when the plugin class gets created, fire the initialization:
    function __construct() {
	// hook activation and deactivation for the plugin:
	register_activation_hook(__FILE__, array($this, 'wpoa2_activate'));
	register_deactivation_hook(__FILE__, array($this, 'wpoa2_deactivate'));
	// hook load event to handle any plugin updates:
	add_action('plugins_loaded', array($this, 'wpoa2_update'));
	// hook init event to handle plugin initialization:
	add_action('init', array($this, 'init'));
    }

    // a wrapper for wordpress' get_option(), this basically feeds get_option()
    // the setting's correct default value as specified at the top of this file:
    function wpoa2_option($name) {
        $val = get_option($name, $this->settings[$name]);
	return $val;
    }

    // do something during plugin activation:
    function wpoa2_activate() {
    }

    // do something during plugin deactivation:
    function wpoa2_deactivate() {
    }

    // do something during plugin update:
    function wpoa2_update() {
	$plugin_version = WPOA2::PLUGIN_VERSION;
	$installed_version = get_option("wpoa2_plugin_version");
	if (!$installed_version || $installed_version <= 0 || $installed_version != $plugin_version) {
	    // version mismatch, run the update logic...
	    // add any missing options and set a default (usable) value:
	    $this->wpoa2_add_missing_settings();
	    // set the new version so we don't trigger the update again:
	    update_option("wpoa2_plugin_version", $plugin_version);
        }
    }

    // adds any missing settings and their default values:
    function wpoa2_add_missing_settings() {
	foreach($this->settings as $setting_name => $default_value) {
            // call add_option() which ensures that we only add NEW options that don't exist:
            if (is_array($this->settings[$setting_name])) {
                $default_value = json_encode($default_value);
            }
            $added = add_option($setting_name, $default_value);
	}
    }

    // restores the default plugin settings:
    function wpoa2_restore_default_settings() {
	foreach($this->settings as $setting_name => $default_value) {
            // call update_option() which ensures that we update the setting's value:
            if (is_array($this->settings[$setting_name])) {
                $default_value = json_encode($default_value);
            }
            update_option($setting_name, $default_value);
	}
	add_action('admin_notices', array($this, 'wpoa2_restore_default_settings_notice'));
    }

    // indicate to the admin that the plugin has been updated:
    function wpoa2_restore_default_settings_notice() {
	$settings_link = "<a href='options-general.php?page=WP-OAuth.php'>Settings Page</a>"; // CASE SeNsItIvE filename!
	?>
	<div class="updated">
		<p>The default settings have been restored. You may review the <?php echo $settings_link ?>.</p>
	</div>
	<?php
    }

    // initialize the plugin's functionality by hooking into wordpress:
    function init() {
	// restore default settings if necessary; this might get toggled by the admin or forced by a new version of the plugin:
	if (get_option("wpoa2_restore_default_settings")) {$this->wpoa2_restore_default_settings();}
	// hook the query_vars and template_redirect so we can stay within the wordpress context no matter what (avoids having to use wp-load.php)
	add_filter('query_vars', array($this, 'wpoa2_qvar_triggers'));
	add_action('template_redirect', array($this, 'wpoa2_qvar_handlers'));
	// hook scripts and styles for frontend pages:
	add_action('wp_enqueue_scripts', array($this, 'wpoa2_init_frontend_scripts_styles'));
	// hook scripts and styles for backend pages:
	add_action('admin_enqueue_scripts', array($this, 'wpoa2_init_backend_scripts_styles'));
	add_action('admin_menu', array($this, 'wpoa2_settings_page'));
	add_action('admin_init', array($this, 'wpoa2_register_settings'));
	$plugin = plugin_basename(__FILE__);
	add_filter("plugin_action_links_$plugin", array($this, 'wpoa2_settings_link'));
	// hook scripts and styles for login page:
	add_action('login_enqueue_scripts', array($this, 'wpoa2_init_login_scripts_styles'));
	if (get_option('wpoa2_logo_links_to_site') == true) {add_filter('login_headerurl', array($this, 'wpoa2_logo_link'));}
	add_filter('login_message', array($this, 'wpoa2_customize_login_screen'));
	// hooks used globally:
	add_filter('comment_form_defaults', array($this, 'wpoa2_customize_comment_form_fields'));
	//add_action('comment_form_top', array($this, 'wpoa2_customize_comment_form'));
	add_action('wp_logout', array($this, 'wpoa2_end_logout'));
	add_action('wp_ajax_wpoa2_logout', array($this, 'wpoa2_logout_user'));
	add_action('wp_ajax_wpoa2_unlink_account', array($this, 'wpoa2_unlink_account'));
	add_action('wp_ajax_nopriv_wpoa2_unlink_account', array($this, 'wpoa2_unlink_account'));
	add_shortcode('wpoa2_login_form', array($this, 'wpoa2_login_form'));
	// push login messages into the DOM if the setting is enabled:
	if (get_option('wpoa2_show_login_messages') !== false) {
		add_action('wp_footer', array($this, 'wpoa2_push_login_messages'));
		add_filter('admin_footer', array($this, 'wpoa2_push_login_messages'));
		add_filter('login_footer', array($this, 'wpoa2_push_login_messages'));
	}
    }

    // init scripts and styles for use on FRONTEND PAGES:
    function wpoa2_init_frontend_scripts_styles() {

	// here we "localize" php variables, making them available as a js variable in the browser:
	$wpoa2_cvars = array(
	    // basic info:
	    'ajaxurl' => admin_url('admin-ajax.php'),
	    'template_directory' => get_bloginfo('template_directory'),
	    'stylesheet_directory' => get_bloginfo('stylesheet_directory'),
	    'plugins_url' => plugins_url(),
	    'plugin_dir_url' => plugin_dir_url(__FILE__),
	    'url' => get_bloginfo('url'),
	    'logout_url' => wp_logout_url(),
	    // other:
	    'show_login_messages' => get_option('wpoa2_show_login_messages'),
	    'logout_inactive_users' => get_option('wpoa2_logout_inactive_users'),
	    'logged_in' => is_user_logged_in(),
	);
        wp_enqueue_script('wpoa2-cvars', plugins_url('/cvars.js', __FILE__));
	wp_localize_script('wpoa2-cvars', 'wpoa2_cvars', $wpoa2_cvars);

	// we always need jquery:
	wp_enqueue_script('jquery');
	// load the core plugin scripts/styles:
	wp_enqueue_script('wpoa2-script', plugin_dir_url( __FILE__ ) . 'wp-oauth2.js', array());
	wp_enqueue_style('wpoa2-style', plugin_dir_url( __FILE__ ) . 'wp-oauth2.css', array());
	wp_enqueue_style('wpoa2-fonts-style', plugin_dir_url( __FILE__ ) . 'fonts/style.css', array());
    }

    // init scripts and styles for use on BACKEND PAGES:
    function wpoa2_init_backend_scripts_styles() {

	// here we "localize" php variables, making them available as a js variable in the browser:
	$wpoa2_cvars = array(
	    // basic info:
	    'ajaxurl' => admin_url('admin-ajax.php'),
	    'template_directory' => get_bloginfo('template_directory'),
	    'stylesheet_directory' => get_bloginfo('stylesheet_directory'),
	    'plugins_url' => plugins_url(),
	    'plugin_dir_url' => plugin_dir_url(__FILE__),
	    'url' => get_bloginfo('url'),
	    'show_login_messages' => get_option('wpoa2_show_login_messages'),
	    'logout_inactive_users' => get_option('wpoa2_logout_inactive_users'),
	    'logged_in' => is_user_logged_in(),
	);
        wp_enqueue_script('wpoa2-cvars', plugins_url('/cvars.js', __FILE__));
	wp_localize_script('wpoa2-cvars', 'wpoa2_cvars', $wpoa2_cvars);

	// we always need jquery:
	wp_enqueue_script('jquery');
	// load the core plugin scripts/styles:
	wp_enqueue_script('wpoa2-script', plugin_dir_url( __FILE__ ) . 'wp-oauth2.js', array());
	wp_enqueue_style('wpoa2-style', plugin_dir_url( __FILE__ ) . 'wp-oauth2.css', array());
	wp_enqueue_style('wpoa2-fonts-style', plugin_dir_url( __FILE__ ) . 'fonts/style.css', array());
	// load the default wordpress media screen:
	wp_enqueue_media();
    }

    // init scripts and styles for use on the LOGIN PAGE:
    function wpoa2_init_login_scripts_styles() {
	if (isset($_SESSION['WPOA2']['RESULT'])) {
		$login_message = $_SESSION['WPOA2']['RESULT'];
	} else {
		$login_message = '';
	}
	// here we "localize" php variables, making them available as a js variable in the browser:
	$wpoa2_cvars = array(
	    // basic info:
	    'ajaxurl' => admin_url('admin-ajax.php'),
	    'template_directory' => get_bloginfo('template_directory'),
	    'stylesheet_directory' => get_bloginfo('stylesheet_directory'),
	    'plugins_url' => plugins_url(),
	    'plugin_dir_url' => plugin_dir_url(__FILE__),
	    'url' => get_bloginfo('url'),
	    // login specific:
	    'hide_login_form' => get_option('wpoa2_hide_wordpress_login_form'),
	    'logo_image' => get_option('wpoa2_logo_image'),
	    'bg_image' => get_option('wpoa2_bg_image'),
	    'login_message' => $login_message,
	    'show_login_messages' => get_option('wpoa2_show_login_messages'),
	    'logout_inactive_users' => get_option('wpoa2_logout_inactive_users'),
	    'logged_in' => is_user_logged_in(),
	);
        wp_enqueue_script('wpoa2-cvars', plugins_url('/cvars.js', __FILE__));
	wp_localize_script('wpoa2-cvars', 'wpoa2_cvars', $wpoa2_cvars);
	// we always need jquery:
	wp_enqueue_script('jquery');
	// load the core plugin scripts/styles:
	wp_enqueue_script('wpoa2-script', plugin_dir_url( __FILE__ ) . 'wp-oauth2.js', array());
	wp_enqueue_style('wpoa2-style', plugin_dir_url( __FILE__ ) . 'wp-oauth2.css', array());
	wp_enqueue_style('wpoa2-fonts-style', plugin_dir_url( __FILE__ ) . 'fonts/style.css', array());
    }

    // add a settings link to the plugins page:
    function wpoa2_settings_link($links) {
	$settings_link = "<a href='options-general.php?page=WP-OAuth2'>Settings</a>"; // CASE SeNsItIvE filename!
	array_unshift($links, $settings_link);
	return $links;
    }

    // ===============
    // GENERIC HELPERS
    // ===============

    // adds basic http auth to a given url string:
    function wpoa2_add_basic_auth($url, $username, $password) {
	$url = str_replace("https://", "", $url);
	$url = "https://" . $username . ":" . $password . "@" . $url;
	return $url;
    }

    // ===================
    // LOGIN FLOW HANDLING
    // ===================

    // define the querystring variables that should trigger an action:
    function wpoa2_qvar_triggers($vars) {
	$vars[] = 'connect';
	$vars[] = 'code';
	$vars[] = 'error_description';
	$vars[] = 'error_message';
	return $vars;
    }

    // handle the querystring triggers:
    function wpoa2_qvar_handlers() {
	if (get_query_var('connect')) {
	    $provider = get_query_var('connect');
	    $this->wpoa2_include_connector($provider);
	}
	elseif (get_query_var('code')) {
	    $provider = $_SESSION['WPOA2']['PROVIDER'];
	    $this->wpoa2_include_connector($provider);
	}
	elseif (get_query_var('error_description') || get_query_var('error_message')) {
	    $provider = $_SESSION['WPOA2']['PROVIDER'];
	    $this->wpoa2_include_connector($provider);
	}
    }

    // load the provider script that is being requested by the user or being called back after authentication:
    function wpoa2_include_connector($provider) {
	// normalize the provider name (no caps, no spaces):
	$provider = strtolower($provider);
	$provider = str_replace(" ", "", $provider);
	$provider = str_replace(".", "", $provider);
	// include the provider script:
	include 'login-' . $provider . '.php';
    }

    // =======================
    // LOGIN / LOGOUT HANDLING
    // =======================

    // login (or register and login) a wordpress user based on their oauth identity:
    function wpoa2_login_user($oauth_identity) {
	// store the user info in the user session so we can grab it later if we need to register the user:
	$_SESSION["WPOA2"]["USER_ID"] = $oauth_identity["id"];
        $user = get_user_by('email', $oauth_identity['email']);
        $created = false;
        if (!$user) {
            // Create!
            // 'ID' => $oauth_identity['id'],
            $user = array(
                    'user_login' => $oauth_identity['email'],
                    'user_nicename' => $oauth_identity['name'],
                    'user_email' => $oauth_identity['email'],
                    'user_pass' => '',
                    'role' => $this->wpoa2_option('wpoa2_new_user_role')
            );
            $user['ID'] = wp_insert_user($user);
            $user = get_user_by('ID', $user['ID']);
            $created = true;
        }
        if ($user) {
            wp_set_current_user($user->ID, $user->user_login);
            wp_set_auth_cookie($user->ID);
            do_action('wp_login', $user->user_login, $user);
            if ($created) {
                wp_redirect('/wp-admin/profile.php');
            }
            $this->wpoa2_end_login("Logged in successfully!");
        }
	// we shouldn't be here, but just in case...
	$this->wpoa2_end_login("Sorry, we couldn't log you in. The login flow terminated in an unexpected way. Please notify the admin or try again later.");
    }

    // ends the login request by clearing the login state and redirecting the user to the desired page:
    function wpoa2_end_login($msg) {
	$last_url = $_SESSION["WPOA2"]["LAST_URL"];
        $last_url = strtok($last_url, "?");

	unset($_SESSION["WPOA2"]["LAST_URL"]);
	$_SESSION["WPOA2"]["RESULT"] = $msg;
	$this->wpoa2_clear_login_state();
	$redirect_method = get_option("wpoa2_login_redirect");
	$redirect_url = "";
	switch ($redirect_method) {
	    case "home_page":
		$redirect_url = site_url();
		break;
	    case "last_page":
		$redirect_url = $last_url;
		break;
	    case "specific_page":
		$redirect_url = get_permalink(get_option('wpoa2_login_redirect_page'));
		break;
	    case "admin_dashboard":
		$redirect_url = admin_url();
		break;
	    case "user_profile":
		$redirect_url = get_edit_user_link();
		break;
	    case "custom_url":
		$redirect_url = get_option('wpoa2_login_redirect_url');
		break;
	}
	//header("Location: " . $redirect_url);
	wp_redirect($redirect_url);
	die();
    }

    // logout the wordpress user:
    // TODO: this is usually called from a custom logout button, but we could
    // have the button call /wp-logout.php?action=logout for more consistency...
    function wpoa2_logout_user() {
	// logout the user:
	$user = null;           // nullify the user
	session_destroy();      // destroy the php user session
	wp_logout();            // logout the wordpress user...this gets hooked and diverted to wpoa2_end_logout() for final handling
    }

    // ends the logout request by redirecting the user to the desired page:
    function wpoa2_end_logout() {
	$_SESSION["WPOA2"]["RESULT"] = 'Logged out successfully.';
	if (is_user_logged_in()) {
	    // user is logged in and trying to logout...get their Last Page:
	    $last_url = $_SERVER['HTTP_REFERER'];
	}
	else {
	    // user is NOT logged in and trying to logout...get their Last Page minus the querystring so we don't trigger the logout confirmation:
	    $last_url = strtok($_SERVER['HTTP_REFERER'], "?");
	}
	unset($_SESSION["WPOA2"]["LAST_URL"]);
	$this->wpoa2_clear_login_state();
	$redirect_method = get_option("wpoa2_logout_redirect");
	$redirect_url = "";
	switch ($redirect_method) {
	    case "default_handling":
		return false;
	    case "home_page":
		$redirect_url = site_url();
		break;
	    case "last_page":
		$redirect_url = $last_url;
		break;
	    case "specific_page":
		$redirect_url = get_permalink(get_option('wpoa2_logout_redirect_page'));
		break;
	    case "admin_dashboard":
		$redirect_url = admin_url();
		break;
	    case "user_profile":
		$redirect_url = get_edit_user_link();
		break;
	    case "custom_url":
		$redirect_url = get_option('wpoa2_logout_redirect_url');
		break;
	}
	//header("Location: " . $redirect_url);
	wp_safe_redirect($redirect_url);
	die();
    }

    // pushes login messages into the dom where they can be extracted by javascript:
    function wpoa2_push_login_messages() {
	if (isset($_SESSION['WPOA2']['RESULT'])) {
		$result = $_SESSION['WPOA2']['RESULT'];
		echo "<div id='wpoa2-result'>" . $result . "</div>";
	}
	$_SESSION['WPOA2']['RESULT'] = '';
    }

    // clears the login state:
    function wpoa2_clear_login_state() {
	unset($_SESSION["WPOA2"]["USER_ID"]);
	unset($_SESSION["WPOA2"]["USER_EMAIL"]);
	unset($_SESSION["WPOA2"]["ACCESS_TOKEN"]);
	unset($_SESSION["WPOA2"]["EXPIRES_IN"]);
	unset($_SESSION["WPOA2"]["EXPIRES_AT"]);
	//unset($_SESSION["WPOA2"]["LAST_URL"]);
    }

    // ===================================
    // DEFAULT LOGIN SCREEN CUSTOMIZATIONS
    // ===================================

    // force the login screen logo to point to the site instead of wordpress.org:
    function wpoa2_logo_link() {
	return get_bloginfo('url');
    }

    // show a custom login form on the default login screen:
    function wpoa2_customize_login_screen() {
	$html = "";
	$design = get_option('wpoa2_login_form_show_login_screen');
	if ($design != "None") {
	    // TODO: we need to use $settings defaults here, not hard-coded defaults...
	    $html .= $this->wpoa2_login_form_content($design, 'none', 'buttons-column', 'Connect with', 'center', 'conditional', 'conditional', 'Please login:', 'You are already logged in.', 'Logging in...', 'Logging out...');
	}
	echo $html;
    }

    // ===================================
    // DEFAULT COMMENT FORM CUSTOMIZATIONS
    // ===================================

    // show a custom login form at the top of the default comment form:
    function wpoa2_customize_comment_form_fields($fields) {
	$html = "";
	$design = get_option('wpoa2_login_form_show_comments_section');
	if ($design != "None") {
	    // TODO: we need to use $settings defaults here, not hard-coded defaults...
	    $html .= $this->wpoa2_login_form_content($design, 'none', 'buttons-column', 'Connect with', 'center', 'conditional', 'conditional', 'Please login:', 'You are already logged in.', 'Logging in...', 'Logging out...');
	    $fields['logged_in_as'] = $html;
	}
	return $fields;
    }

    // show a custom login form at the top of the default comment form:
    function wpoa2_customize_comment_form() {
	$html = "";
	$design = get_option('wpoa2_login_form_show_comments_section');
	if ($design != "None") {
	    // TODO: we need to use $settings defaults here, not hard-coded defaults...
	    $html .= $this->wpoa2_login_form_content($design, 'none', 'buttons-column', 'Connect with', 'center', 'conditional', 'conditional', 'Please login:', 'You are already logged in.', 'Logging in...', 'Logging out...');
	}
	echo $html;
    }

    // =========================
    // LOGIN / LOGOUT COMPONENTS
    // =========================

    // shortcode which allows adding the wpoa2 login form to any post or page:
    function wpoa2_login_form( $atts ){
	$a = shortcode_atts( array(
	    'design' => '',
	    'button_prefix' => '',
	    'layout' => 'links-column',
	    'align' => 'left',
	    'show_login' => 'conditional',
	    'show_logout' => 'conditional',
	    'logged_out_title' => 'Please login:',
	    'logged_in_title' => 'You are already logged in.',
	    'logging_in_title' => 'Logging in...',
	    'logging_out_title' => 'Logging out...',
	    'style' => '',
	    'class' => '',
	), $atts );
	// convert attribute strings to proper data types:
	//$a['show_login'] = filter_var($a['show_login'], FILTER_VALIDATE_BOOLEAN);
	//$a['show_logout'] = filter_var($a['show_logout'], FILTER_VALIDATE_BOOLEAN);
	// get the shortcode content:
	$html = $this->wpoa2_login_form_content($a['design'], $a['layout'], $a['button_prefix'], $a['align'], $a['show_login'], $a['show_logout'], $a['logged_out_title'], $a['logged_in_title'], $a['logging_in_title'], $a['logging_out_title'], $a['style'], $a['class']);
	return $html;
    }

    // gets the content to be used for displaying the login/logout form:
    function wpoa2_login_form_content($design = '',
                                      $layout = 'links-column',
                                      $button_prefix = '',
                                      $align = 'left',
                                      $show_login = 'conditional',
                                      $show_logout = 'conditional',
                                      $logged_out_title = 'Please login:',
                                      $logged_in_title = 'You are already logged in.',
                                      $logging_in_title = 'Logging in...',
                                      $logging_out_title = 'Logging out...',
                                      $style = '',
                                      $class = '') {
        // even though wpoa2_login_form() will pass a default, we might call
        // this function from another method so it's important to re-specify
        // the default values. If a design was specified and that design
        // exists, load the shortcode attributes from that design:
	if ($design != '' && WPOA2::wpoa2_login_form_design_exists($design)) {
            $a = WPOA2::wpoa2_get_login_form_design($design);
            $layout = $a['layout'];
            $button_prefix = $a['button_prefix'];
            $align = $a['align'];
            $show_login = $a['show_login'];
            $show_logout = $a['show_logout'];
            $logged_out_title = $a['logged_out_title'];
            $logged_in_title = $a['logged_in_title'];
            $logging_in_title = $a['logging_in_title'];
            $logging_out_title = $a['logging_out_title'];
            $style = $a['style'];
            $class = $a['class'];
	}
	// build the shortcode markup:
	$html = "";
	$html .= "<div class='wpoa2-login-form wpoa2-layout-$layout wpoa2-layout-align-$align $class' style='$style' data-logging-in-title='$logging_in_title' data-logging-out-title='$logging_out_title'>";
	$html .= "<nav>";
	if (is_user_logged_in()) {
            if ($logged_in_title) {
                $html .= "<p id='wpoa2-title'>" . $logged_in_title . "</p>";
            }
            if ($show_login == 'always') {
                $html .= $this->wpoa2_login_buttons($button_prefix);
            }
            if ($show_logout == 'always' || $show_logout == 'conditional') {
                $html .= "<a class='wpoa2-logout-button' href='" . wp_logout_url() . "' title='Logout'>Logout</a>";
            }
        }
        else {
            if ($logged_out_title) {
                $html .= "<p id='wpoa2-title'>" . $logged_out_title . "</p>";
            }
            if ($show_login == 'always' || $show_login == 'conditional') {
                $html .= $this->wpoa2_login_buttons($button_prefix);
            }
            if ($show_logout == 'always') {
                $html .= "<a class='wpoa2-logout-button' href='" . wp_logout_url() . "' title='Logout'>Logout</a>";
            }
        }
        $html .= "</nav>";
        $html .= "</div>";
        return $html;
    }

    // generate and return the login buttons, depending on available providers:
    function wpoa2_login_buttons($button_prefix) {
	// generate the atts once (cache them), so we can use it for all buttons without computing them each time:
	$site_url = get_bloginfo('url');
	if( force_ssl_admin() ) { $site_url = set_url_scheme( $site_url, 'https' ); }
	$redirect_to = array_key_exists('redirect_to', $_GET) ? urlencode($_GET['redirect_to']) : NULL;
	if ($redirect_to) {
            $redirect_to = "&redirect_to=" . $redirect_to;
        }
	// get shortcode atts that determine how we should build these buttons:
	$atts = array(
            'site_url' => $site_url,
            'redirect_to' => $redirect_to,
            'button_prefix' => $button_prefix,
	);
	// generate the login buttons for available providers:
        // TODO: don't hard-code the buttons/providers here, we want to be able
        // to add more providers without having to update this function...
	$html = "";
	$html .= $this->wpoa2_login_button("google", "Google", $atts);
	$html .= $this->wpoa2_login_button("linkedin", "LinkedIn", $atts);
	$html .= $this->wpoa2_login_button("github", "GitHub", $atts);
	$html .= $this->wpoa2_login_button("reddit", "Reddit", $atts);
	$html .= $this->wpoa2_login_button("instagram", "Instagram", $atts);
	$html .= $this->wpoa2_login_button("oauth_server" , get_option( 'wpoa2_oauth_server_api_button_text' ), $atts );
	if ($html == '') {
            $html .= 'Sorry, no login providers have been enabled.';
	}
	return $html;
    }

    // generates and returns a login button for a specific provider:
    function wpoa2_login_button($provider, $display_name, $atts) {
        $html = "";
        if (get_option("wpoa2_" . $provider . "_api_enabled")) {
            $html .= "<a id='wpoa2-login-" . $provider . "' class='wpoa2-login-button' href='" . $atts['site_url'] . "?connect=" . $provider . $atts['redirect_to'] . "'>";

            $html .= "<span class=socicon-" . $provider . "> -- $provider </span>";
            $html .= "</a>";
        }
        return $html;
    }

    // output the custom login form design selector:
    function wpoa2_login_form_designs_selector($id = '', $master = false) {
        $html = "";
        $designs_json = get_option('wpoa2_login_form_designs');
        $designs_array = json_decode($designs_json);
        $name = str_replace('-', '_', $id);
        $html .= "<select id='" . $id . "' name='" . $name . "'>";
        if ($master == true) {
            foreach($designs_array as $key => $val) {
                $html .= "<option value=''>" . $key . "</option>";
            }
            $html .= "</select>";
            $html .= "<input type='hidden' id='wpoa2-login-form-designs' name='wpoa2_login_form_designs' value='" . $designs_json . "'>";
        }
        else {
            $html .= "<option value='None'>" . 'None' . "</option>";
            foreach($designs_array as $key => $val) {
                $html .= "<option value='" . $key . "' " . selected(get_option($name), $key, false) . ">" . $key . "</option>";
            }
            $html .= "</select>";
        }
        return $html;
    }

    // returns a saved login form design as a shortcode atts string or array for direct use via the shortcode
    function wpoa2_get_login_form_design($design_name, $as_string = false) {
	$designs_json = get_option('wpoa2_login_form_designs');
	$designs_array = json_decode($designs_json, true);
	foreach($designs_array as $key => $val) {
            if ($design_name == $key) {
                $found = $val;
                break;
            }
	}
	$atts;
	//echo print_r($found);
	if ($found) {
            if ($as_string) {
                $atts = json_encode($found);
            }
            else {
                $atts = $found;
            }
	}
	return $atts;
    }

    function wpoa2_login_form_design_exists($design_name) {
	$designs_json = get_option('wpoa2_login_form_designs');
	$designs_array = json_decode($designs_json, true);
	foreach($designs_array as $key => $val) {
            if ($design_name == $key) {
                $found = $val;
                break;
            }
	}
	if ($found) {
            return true;
	}
	else {
            return false;
	}
    }

    // ====================
    // PLUGIN SETTINGS PAGE
    // ====================

    // registers all settings that have been defined at the top of the plugin:
    function wpoa2_register_settings() {
        foreach ($this->settings as $setting_name => $default_value) {
            register_setting('wpoa2_settings', $setting_name);
        }
    }

    // add the main settings page:
    function wpoa2_settings_page() {
        add_options_page( 'WP-OAuth2 Options', 'WP-OAuth2', 'manage_options', 'WP-OAuth2', array($this, 'wpoa2_settings_page_content') );
    }

    // render the main settings page content:
    function wpoa2_settings_page_content() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
        }
        $blog_url = rtrim(site_url(), "/") . "/";
        include 'wp-oauth2-settings.php';
    }
} // END OF WPOA2 CLASS

// instantiate the plugin class ONCE and maintain a single instance (singleton):
WPOA2::get_instance();
?>
