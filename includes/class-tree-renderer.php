<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Render por instância: não armazena HTML, senha, permissão nem contador em cache.
 * Um documento aparece apenas nas categorias às quais está diretamente vinculado.
 */
final class ICD_SDM_Tree_Renderer {
    private $terms;
    private $children;
    private $args;
    private $uid;
    private $counter = 0;
    private $icons = array();

    public function __construct($terms, $children, $args) {
        $this->terms = $terms; $this->children = $children; $this->args = $args;
        $this->uid = wp_unique_id('icd-');
        $this->icons['closed'] = $this->icon($args['icon_closed'], '+');
        $this->icons['open'] = $this->icon($args['icon_open'], '−');
    }

    private function icon($icon, $fallback) {
        if (class_exists('\Elementor\Icons_Manager') && is_array($icon) && !empty($icon['value'])) {
            ob_start();
            \Elementor\Icons_Manager::render_icon($icon, array('aria-hidden' => 'true'));
            $html = ob_get_clean();
            if ('' !== trim($html)) { return $html; }
        }
        return '<span aria-hidden="true">' . esc_html($fallback) . '</span>';
    }

    public function level($ids, $depth, $path) {
        $html = ''; $visible = 0;
        foreach ($ids as $id) {
            $node = $this->node($id, $depth, $path, $visible);
            if ('' !== $node) { $html .= $node; $visible++; }
        }
        return $html === '' ? '' : '<div class="icd-level" data-depth="' . esc_attr($depth) . '">' . $html . '</div>';
    }

    private function node($id, $depth, $path, $position) {
        if (!isset($this->terms[$id]) || isset($path[$id])) { return ''; }
        $path[$id] = true;
        $child_ids = isset($this->children[$id]) ? $this->children[$id] : array();
        $nested = $this->level($child_ids, $depth + 1, $path);
        $documents = array();

        // No modo somente folhas, pais não listam arquivos diretamente vinculados a eles.
        if ('leaves' !== $this->args['documents_mode'] || !$child_ids) {
            $orderby = in_array($this->args['orderby'], array('title', 'date', 'modified', 'menu_order'), true)
                ? $this->args['orderby'] : 'title';
            $order = 'DESC' === $this->args['order'] ? 'DESC' : 'ASC';
            $documents = get_posts(array(
                'post_type' => 'sdm_downloads', 'post_status' => 'publish',
                'posts_per_page' => -1, 'no_found_rows' => true,
                'orderby' => array($orderby => $order, 'ID' => $order),
                'suppress_filters' => false,
                'tax_query' => array(array('taxonomy' => 'sdm_categories', 'field' => 'term_id',
                    'terms' => array($id), 'include_children' => false)),
            ));
        }
        if (!$documents && '' === $nested && empty($this->args['show_empty'])) { return ''; }
        $this->counter++;
        $summary_id = $this->uid . '-heading-' . $this->counter;
        $panel_id = $this->uid . '-panel-' . $this->counter;
        $initial = $this->args['initial'];
        $open = ('all' === $initial && (empty($this->args['single']) || 0 === $position))
            || ('first' === $initial && 0 === $depth && 0 === $position);
        $html = '<details class="icd-category ' . (0 === $depth ? 'icd-root-category' : 'icd-subcategory') . '"' .
            ($open ? ' open' : '') . ' data-term="' . esc_attr($id) . '">' .
            '<summary class="icd-heading" id="' . esc_attr($summary_id) . '" aria-controls="' . esc_attr($panel_id) .
            '" aria-expanded="' . ($open ? 'true' : 'false') . '">' .
            '<span class="icd-category-title">' . esc_html($this->terms[$id]->name) . '</span>' .
            '<span class="icd-toggle-icon" aria-hidden="true"><span class="icd-icon-closed">' . $this->icons['closed'] .
            '</span><span class="icd-icon-open">' . $this->icons['open'] . '</span></span></summary>' .
            '<div class="icd-body" id="' . esc_attr($panel_id) . '">';
        if (!empty($this->args['show_description']) && '' !== trim($this->terms[$id]->description)) {
            $html .= '<div class="icd-category-description">' .
                wpautop(wp_kses_post($this->terms[$id]->description)) . '</div>';
        }
        $html .= $nested;
        if ($documents) {
            $html .= '<ul class="icd-documents">';
            foreach ($documents as $document) { $html .= $this->document($document); }
            $html .= '</ul>';
        } elseif ('' === $nested) {
            $html .= '<p class="icd-empty">' . esc_html($this->args['empty_text']) . '</p>';
        }
        return $html . '</div></details>';
    }

    private function document_title_link($download_html, $title, $action_id) {
        $label = esc_html($title);
        // Nunca transformar formulários de senha, termos ou CAPTCHA em link direto.
        if (preg_match('~<(?:form|input|button|select|textarea)\b~i', $download_html)) {
            return array('<a class="icd-title-download" href="#' .
                esc_attr($action_id) . '">' . $label . '</a>', true);
        }
        // Apenas clona a âncora oficial do SDM; não constrói URL de arquivo.
        $pattern = '~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i';
        if (preg_match_all($pattern, $download_html, $links)) {
            foreach ($links[0] as $opening) {
                if (!preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $opening, $classes) ||
                    !preg_match('~(?:^|\s)sdm_download(?:\s|$)~', $classes[2]) ||
                    !preg_match('~\bhref\s*=\s*(["\'])(.*?)\1~is', $opening)) {
                    continue;
                }
                // Evita duplicar IDs, preservando destino, classes e atributos SDM.
                $opening = preg_replace('~\s+id\s*=\s*(["\'])(.*?)\1~is', '', $opening);
                $opening = preg_replace_callback('~\bclass\s*=\s*(["\'])(.*?)\1~is',
                    function ($match) {
                        return 'class="' . esc_attr(trim($match[2] . ' icd-title-download')) . '"';
                    }, $opening, 1);
                return array($opening . $label . '</a>', false);
            }
        }
        // Indisponibilidade/permissões: mantém a mensagem oficial, mesmo sem botão.
        return array($label, true);
    }

    private function document($document) {
        $id = (int) $document->ID;
        $action_id = wp_unique_id('icd-download-action-');
        $label = isset($this->args['button_text']) ? (string) $this->args['button_text'] : 'Baixar';
        $label = sanitize_text_field($label);
        $label = str_replace(array('[', ']', '"', "'", "\r", "\n"), '', $label);
        // Cor neutra evita as regras de cor !important fornecidas pelo SDM.
        $button = '[sdm_download id="' . $id . '" fancy="0" color="icd-neutral"';
        if ('' !== $label) { $button .= ' button_text="' . esc_attr($label) . '"'; }
        $button .= ']';
        // Executa o shortcode uma vez; preserva formulários e verificações SDM.
        $download_html = do_shortcode($button);
        list($title_html, $requires_ui) = $this->document_title_link(
            $download_html, get_the_title($id), $action_id);
        $show_button = !array_key_exists('show_button', $this->args) || !empty($this->args['show_button']);
        $file_icon_html = '';
        $show_file_icons = !array_key_exists('show_file_type_icons', $this->args)
            || !empty($this->args['show_file_type_icons']);

        if ($show_file_icons && class_exists('SPSES_SDM_File_Type_Icons')) {
            $file_icon_html = SPSES_SDM_File_Type_Icons::render_for_document($id);
        }

        $extension_badge_html = '';
        $show_extension_badge =
            !array_key_exists('show_file_extension_badge', $this->args)
            || !empty($this->args['show_file_extension_badge']);

        if ($show_extension_badge
            && class_exists('SPSES_SDM_File_Extension_Badge')) {
            $extension_badge_html =
                SPSES_SDM_File_Extension_Badge::render_for_document($id);
        }

        $title_label_html = '<span class="icd-file-title-label">'
            . '<span class="icd-file-title-text">'
            . $title_html
            . '</span>'
            . $extension_badge_html
            . '</span>';

        $html = '<li class="icd-document"><div class="icd-document-info">' .
            '<span class="icd-document-title">' . $file_icon_html . $title_label_html . '</span>';
        if (!empty($this->args['show_counts']) && shortcode_exists('sdm_download_counter')) {
            $html .= '<div class="icd-counter">' .
                do_shortcode('[sdm_download_counter id="' . $id . '"]') . '</div>';
        }
        $html .= '</div>';
        if ($show_button || $requires_ui) {
            $html .= '<div class="icd-action" id="' . esc_attr($action_id) .
                '" tabindex="-1">' . $download_html . '</div>';
        }
        return $html . '</li>';
    }
}
