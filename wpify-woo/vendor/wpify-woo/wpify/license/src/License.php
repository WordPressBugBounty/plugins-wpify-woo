<?php

namespace WpifyWooDeps\Wpify\License;

use WpifyWooDeps\Wpify\Asset\AssetFactory;
use WpifyWooDeps\Wpify\License\Api\LicenseApi;
/**
 * Class License
 *
 * @package WpifyWoo
 */
class License
{
    const PATH = __DIR__;
    private string $option_key;
    /**
     * Several instances may exist for one plugin (plugin + settings module), the
     * status hooks must still run once.
     */
    private static array $status_hooks = array();
    /**
     * @param string $plugin
     * @param bool   $enqueue_script
     * @param int    $network_id
     */
    public function __construct(private string $plugin, private $enqueue_script = \false, private $network_id = 0, bool $api_check = \false)
    {
        $this->option_key = sprintf('%s_license', $plugin);
        add_action('admin_init', array($this, 'save_activation_token'));
        add_action('admin_init', array($this, 'delete_activation_token'));
        add_action('init', array($this, 'load_textdomain'));
        if ($this->enqueue_script) {
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'), 1000);
        }
        if (empty(self::$status_hooks[$plugin])) {
            self::$status_hooks[$plugin] = \true;
            add_action('wpify_updates_metadata', array($this, 'save_status_from_update_metadata'), 10, 2);
            add_action('wp_ajax_' . $this->get_ajax_action(), array($this, 'ajax_save_status'));
        }
        if ($api_check) {
            add_action('init', [$this, 'schedule_licence_check']);
            add_action($this->get_api_check_hook(), [$this, 'license_check']);
        }
    }
    /**
     * Register license textdomain
     * @return void
     */
    function load_textdomain()
    {
        $mo_file = self::find_translation(dirname(__DIR__) . '/languages', 'wpify-license-', '.mo', determine_locale());
        if ($mo_file) {
            load_textdomain('wpify-license', $mo_file);
        }
    }
    /**
     * The script translations fall back to another locale of the same language too.
     *
     * @param string|false $file   Path WordPress looks for.
     * @param string       $handle Script handle.
     * @param string       $domain Text domain.
     *
     * @return string|false
     */
    public static function script_translation_fallback($file, $handle, $domain)
    {
        if ('wpify-license' !== $domain || $file && file_exists($file)) {
            return $file;
        }
        $fallback = self::find_translation(dirname(__DIR__) . '/languages', 'wpify-license-', '-' . $handle . '.json', determine_locale());
        return $fallback ?: $file;
    }
    /**
     * Translation file for the locale, or for another locale of the same language when there is none
     * (de_AT, de_CH and de_DE_formal use de_DE; the {language}_{LANGUAGE} variant is preferred).
     *
     * @param string $dir    Directory with the translations.
     * @param string $prefix File name before the locale, e.g. 'wpify-license-'.
     * @param string $suffix File name after the locale, e.g. '.mo'.
     * @param string $locale Locale.
     *
     * @return string Empty when no translation exists.
     */
    public static function find_translation(string $dir, string $prefix, string $suffix, string $locale): string
    {
        $file = $dir . '/' . $prefix . $locale . $suffix;
        if (file_exists($file)) {
            return $file;
        }
        $language = strtolower(strtok($locale, '_'));
        $preferred = $dir . '/' . $prefix . $language . '_' . strtoupper($language) . $suffix;
        if (file_exists($preferred)) {
            return $preferred;
        }
        $files = glob($dir . '/' . $prefix . $language . '_*' . $suffix);
        return $files ? $files[0] : '';
    }
    public function get_full_url()
    {
        $query_string = $_SERVER['QUERY_STRING'];
        parse_str($query_string, $query_params);
        $url = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        return add_query_arg($query_params, $url);
    }
    public function enqueue_scripts()
    {
        wp_dequeue_script('wpify-settings');
        $asset_factory = new AssetFactory();
        $asset_factory->wp_script(dirname(__DIR__) . '/build/settings.css', array('is_admin' => \true));
        $asset_factory->wp_script(dirname(__DIR__) . '/build/settings.js', array('handle' => 'wpify-woo-settings', 'is_admin' => \true, 'variables' => array('wpifyWooLicenseSettings' => array('publicPath' => dirname($this::PATH) . '/build/', 'activateUrl' => add_query_arg(array('license-action' => 'add', 'slug' => $this->plugin, 'domain' => $this->get_license_domain(), 'return_url' => urlencode(urlencode($this->get_full_url()))), $this->get_base_url()), 'deactivateUrl' => !$this->current_user_can_deactivate() ? '' : add_query_arg(array('license-action' => 'deactivate', 'slug' => $this->plugin, 'domain' => $this->get_license_domain(), 'uuid' => $this->get_license(), 'return_url' => urlencode(urlencode($this->get_full_url()))), $this->get_base_url()), 'activated' => $this->is_activated(), 'license' => $this->is_activated() && isset($this->is_activated()['license']) ? $this->is_activated()['license'] : '', 'license_details_url' => add_query_arg(array('domain' => rawurlencode($this->get_license_domain()), 'slug' => $this->plugin), sprintf($this->get_base_url() . 'wp-json/wpify/v1/license/%s', $this->get_license())), 'ajaxUrl' => admin_url('admin-ajax.php'), 'ajaxAction' => $this->get_ajax_action(), 'ajaxNonce' => wp_create_nonce($this->get_ajax_action()))), 'dependencies' => array('react', 'wp-components', 'wp-element', 'wp-hooks', 'wp-date', 'wp-i18n', 'wp-polyfill')));
        wp_set_script_translations('wpify-woo-settings', 'wpify-license', dirname(__DIR__) . '/languages');
        if (!has_filter('load_script_translation_file', array(self::class, 'script_translation_fallback'))) {
            add_filter('load_script_translation_file', array(self::class, 'script_translation_fallback'), 10, 3);
        }
        $this->enqueue_fallback_script();
    }
    /**
     * Custom Fields resolves the field component on DOMContentLoaded, when settings.js has either run or failed,
     * so this lower-priority component shows only when the server blocked settings.js (e.g. 403 on vendor folders);
     * otherwise Custom Fields falls back to a bare text input.
     */
    private function enqueue_fallback_script(): void
    {
        $handle = 'wpify-license-fallback';
        if (wp_script_is($handle, 'registered')) {
            return;
        }
        wp_register_script($handle, \false, array('wp-hooks', 'wp-element'), \false, \true);
        wp_add_inline_script($handle, sprintf('(function (hooks, el, text) {
	var Fallback = function () {
		var settings = window.wpifyWooLicenseSettings || {};
		return el.createElement("div", {className: "license-field not-active wpify-notice wpify-notice-error"},
			el.createElement("div", {className: "license-field__text"},
				el.createElement("h3", null, text.title),
				el.createElement("p", null, text.message),
				el.createElement("p", null, el.createElement("code", null, text.script))
			),
			!settings.activated && settings.activateUrl && el.createElement("a", {className: "components-button is-primary", href: settings.activateUrl}, text.activate)
		);
	};
	["wcf_field_license", "wpifycf_field_license"].forEach(function (filter) {
		hooks.addFilter(filter, "wpify-license/fallback", function () {
			return Fallback;
		}, 5);
	});
})(window.wp.hooks, window.wp.element, %s);', wp_json_encode(array('title' => __('The license field could not be loaded', 'wpify-license'), 'message' => __('The browser did not receive the license script. The server or a security plugin is probably blocking files of this plugin (HTTP 403). Please ask your hosting or administrator to allow this file:', 'wpify-license'), 'script' => plugins_url('build/settings.js', __DIR__), 'activate' => __('Activate domain', 'wpify-license')))));
        wp_enqueue_script($handle);
    }
    public function get_option_key()
    {
        return $this->option_key;
    }
    public function save_option_license($args)
    {
        $data = array('license' => $args['license'], 'slug' => $this->plugin);
        if (isset($args['valid'])) {
            $data['valid'] = $args['valid'];
        }
        $this->update_option_data($data);
    }
    private function update_option_data(array $data): void
    {
        if ($this->network_id) {
            update_network_option($this->network_id, $this->get_option_key(), $data);
        } else {
            update_option($this->get_option_key(), $data);
        }
    }
    /**
     * Get Base URL
     *
     * @return string
     */
    public function get_base_url(): string
    {
        return 'https://wpify.io/';
    }
    public function save_activation_token()
    {
        if (empty($_GET['slug']) || empty($_GET['activation-token']) || empty($_GET['wpify-license-action'])) {
            return;
        }
        if ($_GET['slug'] !== $this->plugin) {
            return;
        }
        if ($_GET['wpify-license-action'] !== 'add') {
            return;
        }
        if (!$this->current_user_can_manage()) {
            return;
        }
        $data = ['license' => sanitize_text_field(wp_unslash($_GET['activation-token'])), 'valid' => \true];
        $this->save_option_license($data);
        $this->invalidate_update_cache();
        wp_redirect(remove_query_arg(array('slug', 'activation-token', 'wpify-license-action', 'license-action'), $this->get_full_url()));
        exit;
    }
    public function delete_activation_token()
    {
        if (empty($_GET['slug']) || empty($_GET['wpify-license-action'])) {
            return;
        }
        if ($_GET['slug'] !== $this->plugin) {
            return;
        }
        if ($_GET['wpify-license-action'] !== 'deactivate') {
            return;
        }
        if (!$this->current_user_can_deactivate()) {
            return;
        }
        $this->delete_option_license();
        $this->invalidate_update_cache();
        wp_redirect(remove_query_arg(array('slug', 'activation-token', 'wpify-license-action', 'license-action'), $this->get_full_url()));
        exit;
    }
    /**
     * admin_init also runs for unauthenticated admin-ajax.php requests, so the
     * activation callbacks must not trust the request alone.
     */
    private function current_user_can_manage(): bool
    {
        // Some plugins pass the network ID even on single sites, where no role has manage_network_options.
        $capability = $this->network_id && is_multisite() ? 'manage_network_options' : 'manage_options';
        return current_user_can($capability);
    }
    /**
     * On multisite the licence belongs to the whole network, so only a super admin may release it.
     */
    public function current_user_can_deactivate(): bool
    {
        return is_multisite() ? current_user_can('manage_network_options') : current_user_can('manage_options');
    }
    private function invalidate_update_cache(): void
    {
        delete_site_transient('update_plugins');
        delete_site_transient('puc_request_info-' . $this->plugin);
        delete_site_transient('puc_check_count-' . $this->plugin);
    }
    public function delete_option_license()
    {
        if ($this->network_id) {
            delete_network_option($this->network_id, $this->get_option_key());
        } else {
            delete_option($this->get_option_key());
        }
    }
    public function is_activated()
    {
        if ($this->network_id) {
            return get_network_option($this->network_id, $this->get_option_key());
        } else {
            return get_option($this->get_option_key());
        }
    }
    public function get_api_check_hook()
    {
        return sprintf('%s_license_check', $this->plugin);
    }
    public function schedule_licence_check()
    {
        if (function_exists('as_schedule_recurring_action')) {
            if (!as_has_scheduled_action($this->get_api_check_hook())) {
                as_schedule_recurring_action(time(), 12 * \HOUR_IN_SECONDS, $this->get_api_check_hook());
            }
        }
    }
    public function unschedule_licence_check()
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($this->get_api_check_hook());
        }
    }
    public function license_check()
    {
        // Do nothing if the license is not activated.
        if (!$this->is_activated()) {
            return;
        }
        $details = $this->get_api_license_details();
        if (is_wp_error($details)) {
            if ($details->get_error_code() === 'not-found') {
                $this->delete_option_license();
            }
            return;
        }
        if (!$details || !isset($details['valid'])) {
            return;
        }
        $this->save_option_license($details);
    }
    public function get_license()
    {
        return isset($this->is_activated()['license']) ? $this->is_activated()['license'] : null;
    }
    public function get_api_license_details()
    {
        $license = $this->is_activated()['license'];
        $url = add_query_arg(array('domain' => rawurlencode($this->get_license_domain()), 'slug' => $this->plugin), $this->get_base_url() . 'wp-json/wpify/v1/license/' . $license);
        $result = wp_remote_get($url, ['timeout' => 15]);
        if (!$result || is_wp_error($result)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($result), \true);
        if (is_wp_error($data) && $data->get_error_code() === 'license-not-found') {
            return $data;
        }
        if (!$data || is_wp_error($data)) {
            return null;
        }
        if (isset($data['code']) && $data['code'] === 'license-not-found') {
            return new \WP_Error('license-not-found', __('License not found', 'wpify-license'), array('status' => 404));
        }
        // Servers that know the domain answer with its validity for this site; older ones only with the subscription's.
        if (isset($data['wpify_license']['valid'])) {
            $data['item']['valid'] = (bool) $data['wpify_license']['valid'];
        }
        return $data['item'];
    }
    public function is_valid()
    {
        return $this->is_activated()['valid'] ?? \false;
    }
    /**
     * A licence on multisite belongs to the whole network, which identifies with its main site (as update checks do).
     * Licences activated from a subsite before 2.2.0 show a domain mismatch; support moves them.
     */
    public function get_license_domain(): string
    {
        return is_multisite() ? get_site_url(get_main_site_id()) : get_site_url();
    }
    private function get_ajax_action(): string
    {
        return 'wpify_license_status_' . $this->plugin;
    }
    /**
     * The update server answers every update check with the licence validity for
     * this site, so it is kept current without a request of its own.
     * Without the status block (older server, other plugin) nothing changes.
     */
    public function save_status_from_update_metadata($slug, $data)
    {
        if ($slug !== $this->plugin || !is_array($data) || empty($data['wpify_license']) || !is_array($data['wpify_license'])) {
            return;
        }
        $this->save_status($data['wpify_license']);
    }
    /**
     * The settings field fetches the status in the browser (the page does not
     * wait for the server) and sends its validity here when it differs from the stored one.
     */
    public function ajax_save_status()
    {
        check_ajax_referer($this->get_ajax_action());
        if (!$this->current_user_can_manage()) {
            wp_send_json_error(null, 403);
        }
        $status = json_decode(wp_unslash($_POST['status'] ?? ''), \true);
        if (!is_array($status) || !$this->save_status($status)) {
            wp_send_json_error(null, 400);
        }
        wp_send_json_success();
    }
    /**
     * Only the validity reported by the server is stored. The activation stays
     * even when the licence is not valid, so update checks keep running and the
     * validity recovers once the licence is fixed.
     */
    private function save_status(array $status): bool
    {
        $data = $this->is_activated();
        if (!is_array($data) || empty($data['license']) || !array_key_exists('valid', $status)) {
            return \false;
        }
        $data['valid'] = (bool) $status['valid'];
        $this->update_option_data($data);
        return \true;
    }
}
