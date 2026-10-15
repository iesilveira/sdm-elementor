<?php
if (!defined('ABSPATH')) { exit; }

class ICD_SDM_Elementor_Widget extends \Elementor\Widget_Base {
    public function get_name() { return 'icd_sdm_documents'; }
    public function get_title() { return 'Downloads'; }
    public function get_icon() { return 'eicon-folder'; }
    public function get_categories() { return array('general'); }
    public function get_keywords() { return array('sdm', 'documentos', 'download', 'categorias', 'acordeao', 'accordion'); }
    public function get_style_depends(): array { return array('icd-central-style'); }
    public function get_script_depends(): array { return array('icd-central-script'); }
    // Dados dependem do SDM e do visitante: não usar cache de saída do widget.
    protected function is_dynamic_content(): bool { return true; }

    private function color($id, $label, $selector, $property = 'color') {
        $this->add_control($id, array('label' => $label, 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array($selector => $property . ': {{VALUE}};')));
    }

    private function dimensions($id, $label, $selector, $property = 'padding') {
        $this->add_responsive_control($id, array('label' => $label,
            'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array('px', 'em', '%'),
            'selectors' => array($selector => $property . ': {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};')));
    }

    private function slider($id, $label, $selector, $property, $max = 100) {
        $this->add_responsive_control($id, array('label' => $label,
            'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array('px', 'em'),
            'range' => array('px' => array('min' => 0, 'max' => $max), 'em' => array('min' => 0, 'max' => 10, 'step' => .1)),
            'selectors' => array($selector => $property . ': {{SIZE}}{{UNIT}};')));
    }

    private function typography($id, $selector) {
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(),
            array('name' => $id, 'selector' => $selector));
    }

    private function border($id, $selector) {
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(),
            array('name' => $id, 'selector' => $selector));
    }

    private function shadow($id, $selector) {
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(),
            array('name' => $id, 'selector' => $selector));
    }

    protected function register_controls() {
        $C = '\Elementor\Controls_Manager';
        $this->start_controls_section('content', array('label' => 'Categorias e documentos'));
        $this->add_control('scope', array('label' => 'Categorias iniciais', 'type' => $C::SELECT,
            'default' => 'all', 'options' => array('all' => 'Todas as categorias principais', 'selected' => 'Escolher ramos')));
        $options = array();
        if (taxonomy_exists('sdm_categories')) {
            $terms = get_terms(array('taxonomy' => 'sdm_categories', 'hide_empty' => false, 'orderby' => 'name'));
            if (!is_wp_error($terms)) {
                $map = array();
                foreach ($terms as $term) { $map[$term->term_id] = $term; }
                foreach ($terms as $term) {
                    $names = array($term->name); $parent = (int) $term->parent; $seen = array($term->term_id => true);
                    while ($parent && isset($map[$parent]) && !isset($seen[$parent])) {
                        $seen[$parent] = true; array_unshift($names, $map[$parent]->name);
                        $parent = (int) $map[$parent]->parent;
                    }
                    $options[$term->term_id] = implode(' / ', $names);
                }
            }
        }
        $this->add_control('roots', array('label' => 'Ramos para exibir', 'type' => $C::SELECT2,
            'multiple' => true, 'label_block' => true, 'options' => $options, 'default' => array(),
            'condition' => array('scope' => 'selected'),
            'description' => 'Inclui automaticamente as descendentes. A hierarquia vem de Downloads → Categorias. Reabra o editor se cadastrar categorias novas.'));
        $this->add_control('documents_mode', array('label' => 'Documentos', 'type' => $C::SELECT, 'default' => 'direct',
            'options' => array('direct' => 'Em cada categoria onde estão cadastrados', 'leaves' => 'Somente nas categorias finais (folhas)'),
            'description' => 'Em “somente folhas”, documentos vinculados apenas a categorias-pai não aparecem. Não são movidos automaticamente.'));
        $this->add_control('show_empty', array('label' => 'Mostrar categorias vazias', 'type' => $C::SWITCHER, 'default' => ''));
        $this->add_control('show_counts', array('label' => 'Mostrar contador de downloads', 'type' => $C::SWITCHER, 'default' => 'yes'));
        $this->add_control('show_button', array('label' => 'Exibir botão de download', 'type' => $C::SWITCHER,
            'default' => 'yes', 'description' => 'Oculta o botão simples; formulários de senha, termos e avisos SDM continuam disponíveis.'));
        $this->add_control('show_description', array('label' => 'Mostrar descrição da categoria', 'type' => $C::SWITCHER, 'default' => ''));
        $this->add_control('orderby', array('label' => 'Ordenar documentos por', 'type' => $C::SELECT, 'default' => 'title',
            'options' => array('title' => 'Título', 'date' => 'Data de publicação', 'modified' => 'Última alteração', 'menu_order' => 'Ordem do cadastro')));
        $this->add_control('order', array('label' => 'Direção', 'type' => $C::SELECT, 'default' => 'ASC',
            'options' => array('ASC' => 'Crescente', 'DESC' => 'Decrescente')));
        $this->add_control('button_text', array('label' => 'Texto do botão', 'type' => $C::TEXT, 'default' => 'Baixar',
            'description' => 'Deixe vazio para usar o texto configurado no SDM.'));
        $this->add_control('empty_text', array('label' => 'Mensagem de categoria vazia', 'type' => $C::TEXT,
            'default' => 'Nenhum documento nesta categoria.'));
        $this->end_controls_section();

        $this->start_controls_section('behavior', array('label' => 'Comportamento e ícones'));
        $this->add_control('single', array('label' => 'Abrir apenas uma categoria por nível', 'type' => $C::SWITCHER, 'default' => 'yes',
            'description' => 'Fechar uma categoria irmã não fecha sua categoria-pai. Requer JavaScript para exclusividade.'));
        $this->add_control('initial', array('label' => 'Estado inicial', 'type' => $C::SELECT, 'default' => 'none',
            'options' => array('none' => 'Tudo fechado', 'first' => 'Primeira categoria principal aberta', 'all' => 'Tudo aberto'),
            'description' => 'Com exclusividade ligada, “tudo aberto” abre apenas a primeira categoria de cada nível.'));
        $this->add_control('icon_side', array('label' => 'Posição do ícone', 'type' => $C::SELECT, 'default' => 'end',
            'options' => array('start' => 'Início', 'end' => 'Fim')));
        $this->add_control('icon_closed', array('label' => 'Ícone fechado', 'type' => $C::ICONS,
            'description' => 'Vazio usa o símbolo +.'));
        $this->add_control('icon_open', array('label' => 'Ícone aberto', 'type' => $C::ICONS,
            'description' => 'Vazio usa o símbolo −.'));
        $this->end_controls_section();

        // O CSS é sempre isolado por {{WRAPPER}}, permitindo estilos independentes por widget.
        $W = '{{WRAPPER}} .icd-central';
        $this->start_controls_section('style_structure', array('label' => 'Estrutura do acordeão', 'tab' => $C::TAB_STYLE));
        $this->slider('category_gap', 'Espaço entre categorias', $W, '--icd-gap', 80);
        $this->dimensions('body_padding', 'Espaçamento interno do conteúdo', $W . ' .icd-body');
        $this->color('body_background', 'Fundo do conteúdo', $W . ' .icd-body', 'background-color');
        $this->slider('nested_indent', 'Recuo das subcategorias', $W, '--icd-indent', 100);
        $this->slider('category_radius', 'Arredondamento', $W . ' .icd-category', 'border-radius', 80);
        $this->border('category_border', $W . ' .icd-category');
        $this->shadow('category_shadow', $W . ' .icd-category');
        $this->color('focus_color', 'Contorno de foco do teclado', $W, '--icd-focus');
        $this->end_controls_section();

        foreach (array('root' => 'Títulos — categorias principais', 'sub' => 'Títulos — subcategorias') as $type => $label) {
            $selector = $W . ('root' === $type ? ' .icd-root-category' : ' .icd-subcategory');
            $heading = $selector . ' > .icd-heading';
            $this->start_controls_section('style_' . $type, array('label' => $label, 'tab' => $C::TAB_STYLE));
            $this->typography($type . '_type', $heading . ' .icd-category-title');
            $this->dimensions($type . '_padding', 'Espaçamento interno', $heading);
            $this->start_controls_tabs($type . '_tabs');
            foreach (array('normal' => 'Normal', 'hover' => 'Ao passar o mouse', 'active' => 'Aberto') as $state => $name) {
                $s = 'active' === $state ? $selector . '[open] > .icd-heading' :
                    $heading . ('hover' === $state ? ':hover' : '');
                $this->start_controls_tab($type . '_' . $state . '_tab', array('label' => $name));
                $this->color($type . '_' . $state . '_color', 'Cor do texto', $s);
                $this->color($type . '_' . $state . '_bg', 'Cor de fundo', $s, 'background-color');
                $this->color($type . '_' . $state . '_icon', 'Cor do ícone', $s . ' .icd-toggle-icon');
                $this->end_controls_tab();
            }
            $this->end_controls_tabs();
            $this->end_controls_section();
        }

        $this->start_controls_section('style_icons', array('label' => 'Ícones', 'tab' => $C::TAB_STYLE));
        $this->slider('icon_size', 'Tamanho', $W . ' .icd-toggle-icon', 'font-size', 80);
        $this->slider('icon_gap', 'Distância do título', $W . ' .icd-heading', 'column-gap', 80);
        $this->end_controls_section();

        $this->start_controls_section('style_documents', array('label' => 'Lista de documentos', 'tab' => $C::TAB_STYLE));
        $this->typography('document_type', $W . ' .icd-document-title');
        $this->color('document_color', 'Cor dos títulos', $W . ' .icd-document-title');
        $this->color('document_background', 'Fundo da linha', $W . ' .icd-document', 'background-color');
        $this->color('document_hover_background', 'Fundo da linha ao passar o mouse', $W . ' .icd-document:hover', 'background-color');
        $this->dimensions('document_padding', 'Espaçamento interno da linha', $W . ' .icd-document');
        $this->slider('document_gap', 'Espaço entre linhas', $W . ' .icd-documents', 'gap', 80);
        $this->border('document_border', $W . ' .icd-document');
        $this->slider('document_radius', 'Arredondamento da linha', $W . ' .icd-document', 'border-radius', 80);
        $this->add_responsive_control('document_direction', array('label' => 'Disposição da linha', 'type' => $C::SELECT,
            'options' => array('row' => 'Título e botão lado a lado', 'column' => 'Título e botão empilhados'),
            'selectors' => array($W . ' .icd-document' => 'flex-direction: {{VALUE}};')));
        $this->end_controls_section();

        $this->start_controls_section('style_texts', array('label' => 'Contador, descrição e mensagem', 'tab' => $C::TAB_STYLE));
        $this->add_control('counter_heading', array('label' => 'Contador', 'type' => $C::HEADING));
        $this->typography('counter_type', $W . ' .icd-counter');
        $this->color('counter_color', 'Cor do contador', $W . ' .icd-counter');
        $this->add_control('description_heading', array('label' => 'Descrição da categoria', 'type' => $C::HEADING, 'separator' => 'before'));
        $this->typography('description_type', $W . ' .icd-category-description');
        $this->color('description_color', 'Cor da descrição', $W . ' .icd-category-description');
        $this->add_control('empty_heading', array('label' => 'Mensagem de categoria vazia', 'type' => $C::HEADING, 'separator' => 'before'));
        $this->typography('empty_type', $W . ' .icd-empty');
        $this->color('empty_color', 'Cor da mensagem', $W . ' .icd-empty');
        $this->end_controls_section();

        // Uma classe adicional no seletor supera os estilos de cor do botão SDM.
        $B = $W . ' .icd-action .sdm_download';
        $this->start_controls_section('style_button', array('label' => 'Botão de download', 'tab' => $C::TAB_STYLE));
        $this->typography('button_type', $B);
        $this->dimensions('button_padding', 'Espaçamento interno', $B);
        $this->slider('button_radius', 'Arredondamento', $B, 'border-radius', 80);
        $this->border('button_border', $B);
        $this->shadow('button_shadow', $B);
        $this->start_controls_tabs('button_tabs');
        foreach (array('normal' => 'Normal', 'hover' => 'Ao passar o mouse') as $state => $label) {
            $s = $B . ('hover' === $state ? ':hover' : '');
            $this->start_controls_tab('button_' . $state . '_tab', array('label' => $label));
            $this->color('button_' . $state . '_color', 'Cor do texto', $s);
            $this->color('button_' . $state . '_background', 'Cor de fundo', $s, 'background-color');
            $this->color('button_' . $state . '_border', 'Cor da borda', $s, 'border-color');
            $this->end_controls_tab();
        }
        $this->end_controls_tabs();
        $this->add_responsive_control('button_width', array('label' => 'Largura do botão', 'type' => $C::SELECT,
            'options' => array('auto' => 'Automática', '100%' => 'Toda a largura da área do botão'),
            'selectors' => array($B => 'width: {{VALUE}}; text-align:center;')));
        $this->end_controls_section();
    }

    protected function render() {
        ICD_SDM_Central::assets();
        $s = $this->get_settings_for_display();
        echo ICD_SDM_Central::render(array(
            'scope' => isset($s['scope']) ? $s['scope'] : 'all',
            'roots' => isset($s['roots']) ? $s['roots'] : array(),
            'show_button' => !array_key_exists('show_button', $s) || 'yes' === $s['show_button'],
            'show_counts' => !empty($s['show_counts']),
            'show_empty' => !empty($s['show_empty']),
            'show_description' => !empty($s['show_description']),
            'documents_mode' => isset($s['documents_mode']) ? $s['documents_mode'] : 'direct',
            'orderby' => isset($s['orderby']) ? $s['orderby'] : 'title',
            'order' => isset($s['order']) ? $s['order'] : 'ASC',
            'single' => !empty($s['single']),
            'initial' => isset($s['initial']) ? $s['initial'] : 'none',
            'icon_side' => isset($s['icon_side']) ? $s['icon_side'] : 'end',
            'button_text' => isset($s['button_text']) ? $s['button_text'] : 'Baixar',
            'empty_text' => isset($s['empty_text']) ? $s['empty_text'] : 'Nenhum documento nesta categoria.',
            'icon_closed' => isset($s['icon_closed']) ? $s['icon_closed'] : array(),
            'icon_open' => isset($s['icon_open']) ? $s['icon_open'] : array(),
        )); // Saída HTML escapada no renderer; HTML dos botões vem do shortcode oficial SDM.
    }
}
