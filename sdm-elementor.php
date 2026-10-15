<?php
/**
 * Plugin Name: SPSES - Documentos para Download
 * Description: Complemento para o plugin Simple Download Monitor, faz com que as categorias dos downloads sejam organizadas visualmente por hierarquia em páginas construídas com elementor
 * Version: 2.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: elementor, simple-download-monitor
 * Author: Ismael Elói da Silveira Silva
 * License: GPL-2.0-or-later
 * Text Domain: sdm-elementor
 */
if (!defined('ABSPATH')) { exit; }

final class ICD_SDM_Central {
    const VERSION = '2.1.1';
    const OPTION = 'icd_sdm_central_options';
    const GROUP = 'icd_sdm_central_group';

    public static function boot() {
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 5);
        add_action('elementor/frontend/after_register_styles', array(__CLASS__, 'assets'));
        add_action('elementor/frontend/after_register_scripts', array(__CLASS__, 'assets'));
        add_action('elementor/widgets/register', array(__CLASS__, 'widget'));
        add_shortcode('central_documentos', array(__CLASS__, 'shortcode'));
    }

    public static function ready() {
        return post_type_exists('sdm_downloads') && taxonomy_exists('sdm_categories')
            && shortcode_exists('sdm_download');
    }

    public static function options() {
        $saved = get_option(self::OPTION, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'categories' => array(), 'show_counts' => 1, 'show_empty' => 0,
        ));
    }

    public static function assets() {
        if (!wp_style_is('icd-central-style', 'registered')) {
            wp_register_style('icd-central-style', plugins_url('assets/sdm-elementor.css', __FILE__),
                array(), self::VERSION);
        }
        if (!wp_script_is('icd-central-script', 'registered')) {
            wp_register_script('icd-central-script', plugins_url('assets/sdm-elementor.js', __FILE__),
                array(), self::VERSION, true);
        }
    }

    public static function widget($manager) {
        if (!class_exists('\Elementor\Widget_Base')) { return; }
        require_once __DIR__ . '/includes/class-elementor-widget.php';
        $manager->register(new \ICD_SDM_Elementor_Widget());
    }

    public static function notice() {
        if (!current_user_can('manage_options')) { return; }
        if (!self::ready()) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__('SPSES - Documentos para Download: ative o Simple Download Monitor (SDM).', 'sdm-elementor') .
                '</p></div>';
        }
        if (!did_action('elementor/loaded') && !class_exists('\Elementor\Plugin')) {
            echo '<div class="notice notice-warning"><p>' .
                esc_html__('SPSES - Documentos para Download: ative o Elementor para utilizar o widget Downloads.', 'sdm-elementor') .
                '</p></div>';
        }
    }

    public static function action_links($links) {
        if (current_user_can('manage_options') && post_type_exists('sdm_downloads')) {
            array_unshift($links, '<a href="' .
                esc_url(admin_url('edit.php?post_type=sdm_downloads&page=icd-central-documentos')) .
                '">Configurações</a>');
        }
        return $links;
    }

    public static function menu() {
        if (post_type_exists('sdm_downloads')) {
            add_submenu_page('edit.php?post_type=sdm_downloads', 'Central de Documentos',
                'Central de Documentos', 'manage_options', 'icd-central-documentos',
                array(__CLASS__, 'page'));
        }
    }

    public static function settings() {
        register_setting(self::GROUP, self::OPTION, array(
            'type' => 'array', 'sanitize_callback' => array(__CLASS__, 'sanitize'), 'default' => array(),
        ));
    }

    public static function sanitize($input) {
        $input = is_array($input) ? $input : array();
        $ids = isset($input['selected']) && is_array($input['selected'])
            ? array_unique(array_map('absint', $input['selected'])) : array();
        $order = isset($input['order']) && is_array($input['order']) ? $input['order'] : array();
        $selected = array();
        foreach ($ids as $id) {
            $exists = term_exists($id, 'sdm_categories');
            if ($id && $exists && !is_wp_error($exists)) {
                $selected[$id] = isset($order[$id]) && is_scalar($order[$id]) ? absint($order[$id]) : 0;
            }
        }
        asort($selected, SORT_NUMERIC);
        return array('categories' => array_map('absint', array_keys($selected)),
            'show_counts' => empty($input['show_counts']) ? 0 : 1,
            'show_empty' => empty($input['show_empty']) ? 0 : 1);
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $o = self::options();
        $ids = wp_parse_id_list($o['categories']);
        $terms = get_terms(array('taxonomy' => 'sdm_categories', 'hide_empty' => false, 'orderby' => 'name'));
        ?>
        <div class="wrap">
            <h1>SPSES - Documentos para Download — <?php echo esc_html(self::VERSION); ?></h1>
            <p>A hierarquia é cadastrada em <strong>Downloads → Categorias</strong>, usando a categoria-pai.
            O complemento lê essa estrutura; não cria uma segunda hierarquia.</p>
            <p><strong>Elementor:</strong> procure o widget <strong>Downloads</strong> na categoria Geral.
            Escolha nele todas as categorias principais ou apenas alguns ramos. As opções abaixo são o padrão do shortcode.</p>
            <p>Shortcode: <code>[central_documentos]</code>.
            Hierarquia completa: <code>[central_documentos todas="1"]</code>.</p>
            <?php settings_errors(); ?>
            <?php if (is_wp_error($terms)) : ?>
                <p><?php echo esc_html($terms->get_error_message()); ?></p>
            <?php else : ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <h2>Categorias iniciais do shortcode</h2>
                <p>As descendentes são incluídas automaticamente. A ordem numérica vale apenas para estas entradas iniciais.</p>
                <table class="widefat striped"><thead><tr><th>Exibir</th><th>Hierarquia no SDM</th><th>Ordem</th></tr></thead><tbody>
                <?php foreach ($terms as $term) :
                    $id = (int) $term->term_id;
                    $position = array_search($id, $ids, true);
                    $names = array();
                    foreach (array_reverse(get_ancestors($id, 'sdm_categories', 'taxonomy')) as $ancestor_id) {
                        $ancestor = get_term($ancestor_id, 'sdm_categories');
                        if ($ancestor && !is_wp_error($ancestor)) { $names[] = $ancestor->name; }
                    }
                    $names[] = $term->name;
                    ?>
                    <tr><td><input id="icd-cat-<?php echo esc_attr($id); ?>" type="checkbox"
                        name="<?php echo esc_attr(self::OPTION); ?>[selected][]" value="<?php echo esc_attr($id); ?>"
                        <?php checked(in_array($id, $ids, true)); ?>></td>
                        <td><label for="icd-cat-<?php echo esc_attr($id); ?>"><?php echo esc_html(implode(' / ', $names)); ?></label></td>
                        <td><label class="screen-reader-text" for="icd-order-<?php echo esc_attr($id); ?>">Ordem de <?php echo esc_html($term->name); ?></label>
                        <input id="icd-order-<?php echo esc_attr($id); ?>" type="number" min="0" step="1" style="width:80px"
                        name="<?php echo esc_attr(self::OPTION); ?>[order][<?php echo esc_attr($id); ?>]"
                        value="<?php echo esc_attr(false === $position ? 0 : $position + 1); ?>"></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_counts]" value="1"
                    <?php checked(!empty($o['show_counts'])); ?>> Mostrar contador nos shortcodes</label></p>
                <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[show_empty]" value="1"
                    <?php checked(!empty($o['show_empty'])); ?>> Mostrar categorias vazias nos shortcodes</label></p>
                <?php submit_button('Salvar configurações'); ?>
            </form>
            <?php endif; ?>
            <hr><p>As opções de cada widget Elementor são independentes. O complemento não altera arquivos, contadores ou categorias.
            Seleção de ramos não é controle de acesso. Homologue em uma cópia do site antes de publicar.</p>
        </div>
        <?php
    }

    public static function shortcode($atts = array()) {
        $o = self::options();
        $a = shortcode_atts(array('categorias' => '', 'todas' => '0', 'botao' => '1'), $atts, 'central_documentos');
        $roots = trim((string) $a['categorias']) !== '' ? wp_parse_id_list($a['categorias']) : wp_parse_id_list($o['categories']);
        $all = '1' === (string) $a['todas'];
        if (!$all && !$roots) {
            return '<p class="icd-message">' . esc_html(current_user_can('manage_options')
                ? 'Selecione as categorias em Downloads → Central de Documentos ou use todas="1".'
                : 'Nenhuma categoria disponível no momento.') . '</p>';
        }
        self::assets();
        // Shortcodes também podem estar em builders: CSS isolado é enfileirado no render.
        wp_enqueue_style('icd-central-style');
        wp_enqueue_script('icd-central-script');
        return self::render(array('scope' => $all ? 'all' : 'selected', 'roots' => $roots,
            'show_counts' => !empty($o['show_counts']), 'show_empty' => !empty($o['show_empty']),
            'show_button' => '0' !== (string) $a['botao']));
    }

    public static function render($args = array()) {
        if (!self::ready()) {
            return current_user_can('edit_posts') ? '<p class="icd-message">Ative o Simple Download Monitor para visualizar os documentos.</p>' : '';
        }
        $args = wp_parse_args($args, array(
            'scope' => 'all', 'roots' => array(), 'show_counts' => true, 'show_empty' => false,
            'show_description' => false, 'documents_mode' => 'direct', 'orderby' => 'title', 'order' => 'ASC',
            'single' => true, 'initial' => 'none', 'icon_side' => 'end',
            'button_text' => 'Baixar', 'show_button' => true, 'empty_text' => 'Nenhum documento nesta categoria.',
            'icon_closed' => array(), 'icon_open' => array(),
        ));
        $terms = get_terms(array('taxonomy' => 'sdm_categories', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        if (is_wp_error($terms)) { return '<p class="icd-message">Não foi possível carregar as categorias.</p>'; }
        $by_id = array(); $children = array();
        foreach ($terms as $term) {
            $id = (int) $term->term_id; $by_id[$id] = $term;
            $children[(int) $term->parent][] = $id;
        }
        $roots = 'selected' === $args['scope'] ? wp_parse_id_list($args['roots']) : (isset($children[0]) ? $children[0] : array());
        $normalized = array();
        foreach ($roots as $id) {
            if (isset($by_id[$id]) && !array_intersect($roots, get_ancestors($id, 'sdm_categories', 'taxonomy'))) {
                $normalized[] = $id;
            }
        }
        if (!$normalized) {
            return '<p class="icd-message">' . esc_html('selected' === $args['scope']
                ? 'Selecione pelo menos uma categoria no widget.' : 'Nenhuma categoria disponível.') . '</p>';
        }
        $renderer = new ICD_SDM_Tree_Renderer($by_id, $children, $args);
        $content = $renderer->level($normalized, 0, array());
        if ('' === $content) {
            return '<p class="icd-message">Nenhum documento disponível nas categorias selecionadas.</p>';
        }
        return '<div class="icd-central icd-icons-' . esc_attr('start' === $args['icon_side'] ? 'start' : 'end') .
            '" data-single="' . (!empty($args['single']) ? '1' : '0') . '">' . $content . '</div>';
    }
}

require_once __DIR__ . '/includes/class-tree-renderer.php';
ICD_SDM_Central::boot();
