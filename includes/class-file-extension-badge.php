<?php
/**
 * SPSES: badge da extensão do arquivo.
 */
if (!defined('ABSPATH')) { exit; }

final class SPSES_SDM_File_Extension_Badge {
    private static $booted = false;
    private static $plugin_file = '';
    private static $version = '';

    public static function boot($plugin_file, $version) {
        if (self::$booted) { return; }

        self::$booted = true;
        self::$plugin_file = $plugin_file;
        self::$version = $version;

        add_action(
            'wp_enqueue_scripts',
            array(__CLASS__, 'enqueue_styles'),
            50
        );

        add_action(
            'elementor/preview/enqueue_styles',
            array(__CLASS__, 'enqueue_styles')
        );
    }

    public static function enqueue_styles() {
        wp_enqueue_style(
            'spses-sdm-extension-badge',
            plugins_url(
                'assets/sdm-elementor-extension-badge.css',
                self::$plugin_file
            ),
            array(),
            self::$version
        );
    }

    public static function extension_for_document($id) {
        // Campo nativo do Simple Download Monitor.
        $file = get_post_meta((int) $id, 'sdm_upload', true);

        if (!is_string($file) || '' === trim($file)) {
            return '';
        }

        $path = wp_parse_url(
            str_replace('\\', '/', $file),
            PHP_URL_PATH
        );

        if (!is_string($path) || '' === $path) {
            return '';
        }

        // Query strings e fragmentos não participam da identificação.
        $filename = basename(
            str_replace('\\', '/', rawurldecode($path))
        );

        if ('' === $filename) {
            return '';
        }

        $extension = strtolower(
            pathinfo($filename, PATHINFO_EXTENSION)
        );

        // Não inventa nem aceita HTML como extensão.
        if (!preg_match('/^[a-z0-9][a-z0-9_+-]{0,19}$/', $extension)) {
            return '';
        }

        return $extension;
    }

    public static function render_for_document($id) {
        $extension = self::extension_for_document($id);

        if ('' === $extension) {
            return '';
        }

        return '<span class="icd-file-extension-badge">'
            . esc_html($extension)
            . '</span>';
    }
}
