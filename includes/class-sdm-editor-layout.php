<?php
/**
 * Simplificação somente visual do editor clássico de downloads SDM.
 * Os campos continuam renderizados e são enviados pelos formulários nativos.
 */
if (!defined('ABSPATH')) { exit; }

final class SPSES_SDM_Download_Editor_Layout {
    private static $booted = false;
    private static $plugin_file = '';
    private static $version = '';

    public static function boot($plugin_file, $version) {
        if (self::$booted) { return; }
        self::$booted = true;
        self::$plugin_file = $plugin_file;
        self::$version = $version;
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'), 100);
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
        add_filter('hidden_meta_boxes', array(__CLASS__, 'hidden_boxes'), 100, 3);
    }

    private static function target_screen($screen = null) {
        if (!is_admin()) { return false; }
        if (null === $screen) { $screen = get_current_screen(); }
        if (!is_object($screen)
            || !isset($screen->post_type, $screen->base)
            || 'sdm_downloads' !== $screen->post_type
            || 'post' !== $screen->base) {
            return false;
        }
        if (method_exists($screen, 'is_block_editor') && $screen->is_block_editor()) {
            return false;
        }
        return true;
    }

    private static function visible_boxes() {
        return array(
            'sdm_description_meta_box',
            'sdm_upload_meta_box',
            'sdm_categoriesdiv',
            'submitdiv',
        );
    }

    public static function enqueue_styles($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)
            || !self::target_screen()) {
            return;
        }
        wp_enqueue_style(
            'spses-sdm-editor-layout',
            plugins_url('assets/sdm-elementor-admin.css', self::$plugin_file),
            array(),
            self::$version
        );
    }

    public static function body_class($classes) {
        if (self::target_screen()
            && !preg_match('/(?:^|\s)spses-sdm-simple-editor(?:\s|$)/', $classes)) {
            $classes .= ' spses-sdm-simple-editor';
        }
        return $classes;
    }

    public static function hidden_boxes($hidden, $screen, $use_defaults) {
        if (!self::target_screen($screen)) { return $hidden; }
        // Não persiste preferências: somente garante acesso aos campos necessários.
        return array_values(array_diff($hidden, self::visible_boxes()));
    }
}
