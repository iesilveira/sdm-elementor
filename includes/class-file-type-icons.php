<?php
/**
 * SPSES: mapeamento de extensões para ícones de fonte do Elementor.
 */
if (!defined('ABSPATH')) { exit; }

final class SPSES_SDM_File_Type_Icons {
    const OPTION = 'spses_sdm_file_type_icons';
    const MAX_ROWS = 200;

    private static $booted = false;
    private static $plugin_file = '';
    private static $version = '';
    private static $page_hook = '';
    private static $libraries = null;
    private static $config_loaded = false;
    private static $config = null;

    public static function boot($plugin_file, $version) {
        if (self::$booted) { return; }

        self::$booted = true;
        self::$plugin_file = $plugin_file;
        self::$version = $version;

        add_action(
            'wp_enqueue_scripts',
            array(__CLASS__, 'frontend_assets'),
            40
        );

        add_action(
            'elementor/preview/enqueue_styles',
            array(__CLASS__, 'frontend_assets')
        );

        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'menu'), 30);
            add_action(
                'admin_enqueue_scripts',
                array(__CLASS__, 'admin_assets')
            );
            add_action(
                'admin_post_spses_sdm_save_icons',
                array(__CLASS__, 'save')
            );
        }
    }

    public static function menu() {
        self::$page_hook = add_submenu_page(
            'edit.php?post_type=sdm_downloads',
            'Ícones dos documentos',
            'Ícones',
            'manage_options',
            'spses-sdm-icons',
            array(__CLASS__, 'page')
        );
    }

    private static function page_url() {
        return admin_url(
            'edit.php?post_type=sdm_downloads&page=spses-sdm-icons'
        );
    }

    private static function asset_url($path) {
        return plugins_url($path, self::$plugin_file);
    }

    private static function usable_url($value) {
        if (!is_string($value)) { return ''; }

        $value = trim($value);

        if (!preg_match('~^(?:https?:)?//|^/~i', $value)) {
            return '';
        }

        return esc_url_raw($value, array('http', 'https'));
    }

    public static function libraries() {
        if (null !== self::$libraries) {
            return self::$libraries;
        }

        $raw = array();

        if (defined('ELEMENTOR_ASSETS_URL')) {
            $base = trailingslashit(ELEMENTOR_ASSETS_URL);
            $common = $base . 'lib/font-awesome/css/fontawesome.min.css';

            foreach (array(
                'solid' => array('fa-solid', 'fas', 'Solid'),
                'regular' => array('fa-regular', 'far', 'Regular'),
                'brands' => array('fa-brands', 'fab', 'Brands'),
            ) as $file => $data) {
                $raw[$data[0]] = array(
                    'name' => $data[0],
                    'label' => 'Font Awesome - ' . $data[2],
                    'prefix' => 'fa-',
                    'displayPrefix' => $data[1],
                    'url' => $base . 'lib/font-awesome/css/' . $file . '.min.css',
                    'enqueue' => array($common),
                    'fetchJson' => $base . 'lib/font-awesome/js/' . $file . '.js',
                );
            }
        }

        if (class_exists('\Elementor\Icons_Manager')
            && is_callable(array(
                '\Elementor\Icons_Manager',
                'get_icon_manager_tabs_config'
            ))) {
            try {
                $registered = \Elementor\Icons_Manager::get_icon_manager_tabs_config();

                if (is_array($registered)) {
                    foreach ($registered as $key => $tab) {
                        if (!is_array($tab)) { continue; }

                        $name = isset($tab['name']) && is_string($tab['name'])
                            ? $tab['name'] : $key;

                        if (!is_string($name)) { continue; }

                        $raw[$name] = isset($raw[$name])
                            ? array_merge($raw[$name], $tab)
                            : $tab;
                    }
                }
            } catch (\Throwable $error) {
                // O catálogo nativo acima continua disponível.
            }
        }

        $result = array();

        foreach ($raw as $key => $tab) {
            if (!is_array($tab)
                || !is_string($key)
                || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $key)
                || empty($tab['fetchJson'])
                || empty($tab['displayPrefix'])
                || !isset($tab['prefix'])) {
                continue;
            }

            $display = (string) $tab['displayPrefix'];
            $prefix = (string) $tab['prefix'];

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $display)
                || !preg_match('/^[A-Za-z0-9_-]*$/', $prefix)) {
                continue;
            }

            $json_url = self::usable_url($tab['fetchJson']);

            if ('' === $json_url) { continue; }

            $styles = array();

            if (!empty($tab['enqueue']) && is_array($tab['enqueue'])) {
                foreach ($tab['enqueue'] as $url) {
                    $safe = self::usable_url($url);
                    if ('' !== $safe) { $styles[] = $safe; }
                }
            }

            if (!empty($tab['url'])) {
                $safe = self::usable_url($tab['url']);
                if ('' !== $safe) { $styles[] = $safe; }
            }

            $result[$key] = array(
                'name' => $key,
                'label' => isset($tab['label']) && is_string($tab['label'])
                    ? wp_strip_all_tags($tab['label']) : $key,
                'prefix' => $prefix,
                'displayPrefix' => $display,
                'fetchJson' => $json_url,
                'styles' => array_values(array_unique($styles)),
            );
        }

        self::$libraries = $result;
        return $result;
    }

    private static function enqueue_library_styles($names) {
        $libraries = self::libraries();

        foreach (array_unique($names) as $name) {
            if (!isset($libraries[$name])) { continue; }

            foreach ($libraries[$name]['styles'] as $url) {
                wp_enqueue_style(
                    'spses-sdm-icon-font-' . md5($url),
                    $url,
                    array(),
                    defined('ELEMENTOR_VERSION')
                        ? ELEMENTOR_VERSION : self::$version
                );
            }
        }
    }

    private static function empty_icon() {
        return array('library' => '', 'value' => '');
    }

    private static function defaults() {
        $groups = array(
            'file-pdf' => 'pdf',
            'file-word' => 'doc docx odt rtf',
            'file-powerpoint' => 'ppt pptx odp',
            'file-excel' => 'xls xlsx ods csv',
            'file-alt' => 'txt md log epub',
            'file-image' => 'jpg jpeg png gif svg webp avif bmp tif tiff ico',
            'file-archive' => 'zip rar 7z tar gz tar.gz bz2',
            'file-audio' => 'mp3 wav ogg m4a flac aac',
            'file-video' => 'mp4 webm mov avi mkv m4v',
            'file-code' => 'html htm xml json css js php py sql',
            'file' => 'exe msi bin',
        );

        $mappings = array();

        foreach ($groups as $icon => $extensions) {
            foreach (explode(' ', $extensions) as $extension) {
                $mappings[$extension] = array(
                    'library' => 'fa-solid',
                    'value' => 'fas fa-' . $icon,
                );
            }
        }

        ksort($mappings);

        return array(
            'schema' => 1,
            'mappings' => $mappings,
            'default' => array(
                'library' => 'fa-solid',
                'value' => 'fas fa-file',
            ),
        );
    }

    private static function extension($value) {
        if (!is_string($value)) { return false; }

        $value = strtolower(trim($value));

        if ('.' === substr($value, 0, 1)) {
            $value = substr($value, 1);
        }

        if (strlen($value) > 50
            || !preg_match('/^[a-z0-9]{1,16}(?:\.[a-z0-9]{1,16}){0,2}$/', $value)) {
            return false;
        }

        return $value;
    }

    private static function normalize_icon($raw, $check_library) {
        if (!is_array($raw)
            || !array_key_exists('library', $raw)
            || !array_key_exists('value', $raw)
            || !is_string($raw['library'])
            || !is_string($raw['value'])) {
            return new WP_Error('icon_shape', 'Dados incompletos do ícone.');
        }

        $library = trim($raw['library']);
        $value = preg_replace('/\s+/', ' ', trim($raw['value']));

        if ('' === $library && '' === $value) {
            return self::empty_icon();
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $library)
            || strlen($value) > 180
            || !preg_match('/^[A-Za-z0-9_-]+ [A-Za-z0-9_-]+$/', $value)) {
            return new WP_Error('icon_value', 'Biblioteca ou classe de ícone inválida.');
        }

        if ($check_library) {
            $libraries = self::libraries();

            if (!isset($libraries[$library])) {
                return new WP_Error(
                    'icon_library',
                    'Biblioteca de ícones indisponível: ' . $library
                );
            }

            $start = $libraries[$library]['displayPrefix'] . ' '
                . $libraries[$library]['prefix'];

            if (0 !== strpos($value, $start)
                || strlen($value) <= strlen($start)) {
                return new WP_Error(
                    'icon_prefix',
                    'O ícone não corresponde à biblioteca selecionada.'
                );
            }
        }

        return array('library' => $library, 'value' => $value);
    }

    public static function config() {
        if (self::$config_loaded) { return self::$config; }

        self::$config_loaded = true;
        $stored = get_option(self::OPTION, false);

        if (false === $stored) {
            self::$config = self::defaults();
            return self::$config;
        }

        if (!is_array($stored)
            || !isset($stored['schema'])
            || 1 !== $stored['schema']
            || !isset($stored['mappings'])
            || !is_array($stored['mappings'])
            || count($stored['mappings']) > self::MAX_ROWS
            || !array_key_exists('default', $stored)) {
            self::$config = new WP_Error(
                'stored_config',
                'A configuração de ícones possui um formato desconhecido. Nada foi redefinido.'
            );
            return self::$config;
        }

        $default = self::normalize_icon($stored['default'], false);

        if (is_wp_error($default)) {
            self::$config = $default;
            return self::$config;
        }

        $mappings = array();

        foreach ($stored['mappings'] as $extension => $icon) {
            $normalized = self::extension((string) $extension);
            $normalized_icon = self::normalize_icon($icon, false);

            if (false === $normalized
                || $normalized !== (string) $extension
                || is_wp_error($normalized_icon)) {
                self::$config = new WP_Error(
                    'stored_mapping',
                    'Há um mapeamento de ícone inválido. A configuração foi preservada.'
                );
                return self::$config;
            }

            $mappings[$normalized] = $normalized_icon;
        }

        self::$config = array(
            'schema' => 1,
            'mappings' => $mappings,
            'default' => $default,
        );

        return self::$config;
    }

    public static function validate_submission($post) {
        if (!is_array($post)
            || !isset($post['spses_row_count'])
            || !is_string($post['spses_row_count'])
            || !ctype_digit($post['spses_row_count'])
            || !array_key_exists('spses_default_icon', $post)) {
            return new WP_Error('form', 'O formulário está incompleto.');
        }

        $rows = isset($post['spses_icon_rows'])
            ? $post['spses_icon_rows'] : array();

        if (!is_array($rows)
            || count($rows) !== (int) $post['spses_row_count']
            || count($rows) > self::MAX_ROWS) {
            return new WP_Error(
                'row_count',
                'Quantidade de linhas inválida ou formulário truncado pelo servidor.'
            );
        }

        $default = self::normalize_icon($post['spses_default_icon'], true);

        if (is_wp_error($default)) { return $default; }

        $mappings = array();

        foreach ($rows as $row) {
            if (!is_array($row)
                || !array_key_exists('extension', $row)
                || !array_key_exists('library', $row)
                || !array_key_exists('value', $row)
                || !is_string($row['extension'])) {
                return new WP_Error('row', 'Uma linha do formulário está incompleta.');
            }

            $icon = self::normalize_icon($row, true);

            if (is_wp_error($icon)) { return $icon; }

            if ('' === trim($row['extension'])
                && '' === $icon['value']) {
                continue;
            }

            $extension = self::extension($row['extension']);

            if (false === $extension) {
                return new WP_Error(
                    'extension',
                    'Extensão inválida. Use exemplos como pdf, pptx, png ou tar.gz.'
                );
            }

            if (array_key_exists($extension, $mappings)) {
                return new WP_Error(
                    'duplicate',
                    'Extensão duplicada: .' . $extension
                );
            }

            $mappings[$extension] = $icon;
        }

        ksort($mappings);

        return array(
            'schema' => 1,
            'mappings' => $mappings,
            'default' => $default,
        );
    }

    public static function save() {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.', 'SPSES', array('response' => 403));
        }

        check_admin_referer('spses_sdm_save_icons');

        $existing = self::config();

        if (is_wp_error($existing)) {
            wp_die(
                esc_html($existing->get_error_message()),
                'SPSES',
                array('response' => 400, 'back_link' => true)
            );
        }

        $valid = self::validate_submission(wp_unslash($_POST));

        if (is_wp_error($valid)) {
            wp_die(
                esc_html($valid->get_error_message()),
                'SPSES',
                array('response' => 400, 'back_link' => true)
            );
        }

        update_option(self::OPTION, $valid, false);

        if (get_option(self::OPTION, false) !== $valid) {
            wp_die(
                'Não foi possível confirmar a gravação. A atualização não foi confirmada.',
                'SPSES',
                array('response' => 500, 'back_link' => true)
            );
        }

        wp_safe_redirect(add_query_arg('saved', '1', self::page_url()));
        exit;
    }

    private static function picker($base, $icon) {
        echo '<span class="spses-icon-preview" data-icon-preview aria-hidden="true">';

        if ('' !== $icon['value']) {
            echo '<i class="' . esc_attr($icon['value']) . '"></i>';
        }

        echo '</span> ';
        echo '<span class="spses-icon-name" data-icon-name>'
            . esc_html('' !== $icon['value'] ? $icon['value'] : 'Sem ícone')
            . '</span> ';

        echo '<input type="hidden" data-icon-library name="'
            . esc_attr($base . '[library]') . '" value="'
            . esc_attr($icon['library']) . '">';

        echo '<input type="hidden" data-icon-value name="'
            . esc_attr($base . '[value]') . '" value="'
            . esc_attr($icon['value']) . '">';

        echo '<button type="button" class="button" data-pick-icon>Escolher ícone</button> ';
        echo '<button type="button" class="button" data-clear-icon>Sem ícone</button>';
    }

    private static function row($index, $extension, $icon) {
        $base = 'spses_icon_rows[' . $index . ']';

        echo '<tr data-icon-row>';
        echo '<td><label><span class="screen-reader-text">Extensão</span>';
        echo '<input type="text" class="regular-text spses-extension" maxlength="50"'
            . ' placeholder="Ex.: pptx" name="'
            . esc_attr($base . '[extension]') . '" value="'
            . esc_attr($extension) . '"></label></td>';

        echo '<td>';
        self::picker($base, $icon);
        echo '</td>';

        echo '<td><button type="button" class="button-link-delete"'
            . ' data-remove-row>Remover</button></td>';
        echo '</tr>';
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.', 'SPSES', array('response' => 403));
        }

        $config = self::config();

        if (is_wp_error($config)) {
            wp_die(
                esc_html($config->get_error_message()),
                'SPSES',
                array('response' => 400)
            );
        }

        echo '<div class="wrap spses-sdm-icons-admin">';
        echo '<h1>Ícones dos documentos</h1>';

        if (isset($_GET['saved']) && '1' === $_GET['saved']) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . 'Configurações salvas.</p></div>';
        }

        echo '<p>Escolha o ícone de cada extensão. O ponto inicial é opcional. '
            . 'Para uma extensão não cadastrada, será utilizado o ícone padrão.</p>';

        echo '<p>Escolher “Sem ícone” em uma extensão impede também o uso '
            . 'do ícone padrão para essa extensão.</p>';

        if (!self::libraries()) {
            echo '<div class="notice notice-warning"><p>'
                . 'Nenhuma biblioteca de fonte compatível foi encontrada. '
                . 'Confirme que o Elementor está ativo.</p></div>';
        }

        echo '<form id="spses-icon-form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '">';

        echo '<input type="hidden" name="action" value="spses_sdm_save_icons">';
        wp_nonce_field('spses_sdm_save_icons');

        echo '<input type="hidden" id="spses-row-count"'
            . ' name="spses_row_count" value="0">';

        echo '<h2>Ícone padrão</h2>';
        echo '<div class="spses-default-icon" data-icon-row>';
        self::picker('spses_default_icon', $config['default']);
        echo '</div>';

        echo '<h2>Extensões</h2>';
        echo '<table class="widefat striped spses-icons-table">';
        echo '<thead><tr><th>Extensão</th><th>Ícone</th><th>Ação</th></tr></thead>';

        $index = 0;
        $total = count($config['mappings']);
        $next = $total < self::MAX_ROWS ? $total + 1 : $total;

        echo '<tbody id="spses-icon-rows" data-next-index="'
            . esc_attr((string) $next) . '">';

        foreach ($config['mappings'] as $extension => $icon) {
            self::row($index++, $extension, $icon);
        }

        if ($total < self::MAX_ROWS) {
            self::row($index, '', self::empty_icon());
        }

        echo '</tbody></table>';

        echo '<p><button type="button" class="button" id="spses-add-extension">'
            . 'Adicionar extensão</button></p>';

        submit_button('Salvar ícones');
        echo '</form>';

        echo '<template id="spses-icon-row-template">';
        self::row('__INDEX__', '', self::empty_icon());
        echo '</template>';

        echo '<dialog id="spses-icon-dialog" aria-labelledby="spses-icon-dialog-title">';
        echo '<div class="spses-dialog-header">';
        echo '<h2 id="spses-icon-dialog-title">Escolher ícone</h2>';
        echo '<button type="button" class="button" data-close-picker>Fechar</button>';
        echo '</div>';

        echo '<div class="spses-picker-filters">';
        echo '<label>Biblioteca <select id="spses-icon-library"></select></label>';
        echo '<label>Buscar <input type="search" id="spses-icon-search"'
            . ' placeholder="Ex.: file-pdf, image, powerpoint"></label>';
        echo '</div>';

        echo '<p id="spses-icon-status" role="status" aria-live="polite"></p>';
        echo '<div id="spses-icon-grid"></div>';
        echo '<p><button type="button" class="button" id="spses-icon-more" hidden>'
            . 'Mostrar mais</button></p>';
        echo '</dialog></div>';
    }

    public static function admin_assets($hook) {
        if (!self::$page_hook || $hook !== self::$page_hook) { return; }

        $libraries = self::libraries();

        self::enqueue_library_styles(array_keys($libraries));

        wp_enqueue_style(
            'spses-sdm-icons-admin',
            self::asset_url('assets/sdm-elementor-icons-admin.css'),
            array(),
            self::$version
        );

        wp_enqueue_script(
            'spses-sdm-icons-admin',
            self::asset_url('assets/sdm-elementor-icons-admin.js'),
            array(),
            self::$version,
            true
        );

        wp_localize_script(
            'spses-sdm-icons-admin',
            'SPSESSDMIconsData',
            array(
                'libraries' => $libraries,
                'maxRows' => self::MAX_ROWS,
            )
        );
    }

    public static function frontend_assets() {
        $config = self::config();

        if (is_wp_error($config)) { return; }

        $names = array();

        foreach ($config['mappings'] as $icon) {
            if ('' !== $icon['value']) { $names[] = $icon['library']; }
        }

        if ('' !== $config['default']['value']) {
            $names[] = $config['default']['library'];
        }

        self::enqueue_library_styles($names);

        wp_enqueue_style(
            'spses-sdm-file-icons',
            self::asset_url('assets/sdm-elementor-file-icons.css'),
            array(),
            self::$version
        );
    }

    private static function icon_for_document($id, $config) {
        // O metadado nativo do SDM é sdm_upload, sem sublinhado inicial.
        $file = get_post_meta($id, 'sdm_upload', true);

        if (!is_string($file) || '' === trim($file)) {
            return $config['default'];
        }

        $path = wp_parse_url(str_replace('\\', '/', $file), PHP_URL_PATH);

        if (!is_string($path) || '' === $path) {
            return $config['default'];
        }

        $filename = strtolower(
            basename(str_replace('\\', '/', rawurldecode($path)))
        );

        $extensions = array_keys($config['mappings']);

        usort($extensions, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($extensions as $extension) {
            $suffix = '.' . $extension;

            if (strlen($filename) >= strlen($suffix)
                && substr($filename, -strlen($suffix)) === $suffix) {
                return $config['mappings'][$extension];
            }
        }

        return $config['default'];
    }

    public static function render_for_document($id) {
        $config = self::config();

        if (is_wp_error($config)) { return ''; }

        $icon = self::normalize_icon(
            self::icon_for_document((int) $id, $config),
            true
        );

        if (is_wp_error($icon) || '' === $icon['value']) { return ''; }

        $markup = '';

        if (class_exists('\Elementor\Icons_Manager')
            && is_callable(array('\Elementor\Icons_Manager', 'render_icon'))) {
            $level = ob_get_level();
            ob_start();

            try {
                \Elementor\Icons_Manager::render_icon(
                    $icon,
                    array('aria-hidden' => 'true')
                );
                $markup = (string) ob_get_contents();
            } catch (\Throwable $error) {
                $markup = '';
            }

            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        if ('' === trim($markup)) {
            $markup = '<i class="' . esc_attr($icon['value'])
                . '" aria-hidden="true"></i>';
        }

        return '<span class="icd-file-type-icon" aria-hidden="true">'
            . $markup . '</span>';
    }
}
