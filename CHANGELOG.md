# Changelog

<!-- sdm-elementor:2.1.5:start -->
## [2.1.5]
- Badge da extensão reposicionado para depois do título.
- Corrigido o seletor que removia a aparência de botão do título.
- Removidas borda, fundo e sombra próprios do botão apenas no link do título.
- Restaurada a herança de tipografia e cor configuradas no Elementor.
- Preservados os controles e os estilos independentes do badge.
- Preservados os ícones, os links, os formulários e as verificações do SDM.
- Mantidos os identificadores dos controles e do widget.
<!-- sdm-elementor:2.1.5:end -->

<!-- sdm-elementor:2.1.4:start -->
## [2.1.4]
- Adicionado badge com a extensão antes do título do documento.
- A extensão aparece em minúsculas e sem ponto inicial.
- Adicionada opção de exibir ou ocultar o badge por widget.
- Adicionados controles de tipografia, cores, bordas e arredondamento.
- Adicionados controles responsivos de distância e preenchimento.
- Adicionado controle de sombra.
- Preservados os ícones, links, contadores e proteções do SDM.
- URLs sem extensão identificável não exibem um badge inventado.
<!-- sdm-elementor:2.1.4:end -->

<!-- sdm-elementor:2.1.3:start -->
## [2.1.3]
- Adicionada a tela Downloads → Ícones.
- Adicionado mapeamento de extensões para ícones de fonte do Elementor.
- Incluídas extensões comuns e cadastro de extensões adicionais.
- Adicionado seletor pesquisável de ícones.
- Adicionado ícone padrão para extensões sem mapeamento.
- Adicionados ícones à esquerda dos títulos dos documentos.
- Adicionados controles de visibilidade, cor, tamanho e espaçamento no Elementor.
- A extensão é obtida do arquivo configurado no SDM, sem baixar o documento.
- Preservados os links, formulários, contadores e verificações de download do SDM.
- Configurações de ícones são gravadas somente ao salvar a nova tela administrativa.
- Não são enviados relatórios ao disco nem alterados dados por este atualizador.
<!-- sdm-elementor:2.1.3:end -->

<!-- sdm-elementor:2.1.2:start -->
## [2.1.2] - 2026-10-15
- Simplificada visualmente a criação e a edição de downloads no editor clássico do SDM.
- Mantidos título, descrição nativa SDM, arquivo para download e categorias.
- Mantidos os botões nativos de salvar/publicar/atualizar, prévia e lixeira.
- Ocultados os demais metaboxes, o permalink e os detalhes secundários de publicação.
- Campos ocultos continuam no formulário, para não alterar suas configurações ao salvar.
- Mantidos callbacks, nonces, campos ocultos e processamento de gravação originais.
- Visibilidade mínima dos metaboxes necessários aplicada sem alterar preferências no banco.
- Nenhuma alteração nas listagens, taxonomias, opções SDM, Elementor ou frontend.
- Em editor de blocos, a simplificação não é aplicada; nenhuma troca de editor é forçada.
- A revisão não diagnostica nem declara corrigido o erro fatal anteriormente relatado.
<!-- sdm-elementor:2.1.2:end -->

<!-- sdm-elementor:2.1.1:start -->
## [2.1.1] - 2026-10-15
- Removido o registro da tela administrativa Central de Documentos.
- Removido o registro do formulário de configurações globais desse complemento.
- Removido seu link Configurações na lista de plugins.
- Mantidos o widget Downloads e todos os seus controles no Elementor.
- Preservadas as telas, categorias, documentos e configurações do SDM.
- Preservados o shortcode legado, os identificadores e as opções já armazenadas.
- Nenhuma migração ou exclusão de dados do banco é executada.
- Esta revisão não diagnostica nem declara corrigido o erro fatal relatado.
<!-- sdm-elementor:2.1.1:end -->

<!-- sdm-elementor:2.1:start -->
## [2.1] - 2026-10-15
- Renomeados o PHP principal e os arquivos CSS/JS para sdm-elementor.
- Atualizados nome, descrição, autor, versão e dependências do plugin.
- Widget renomeado visualmente para Downloads; identificador e opções preservados.
- Adicionada opção Exibir botão de download no widget e botao="0" no shortcode.
- Título usa o link gerado pelo SDM; formulários e restrições não são contornados.
- Botão usa cor neutra no SDM para respeitar os estilos do Elementor.
- Corrigidos alinhamento vertical e seletores para links e campos de envio.
- Histórico validacao.json da versão 2.0.0 preservado, sem atribuir seus testes à 2.1.
<!-- sdm-elementor:2.1:end -->
