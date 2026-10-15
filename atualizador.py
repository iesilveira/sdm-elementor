#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
SPSES - Documentos para Download
Migração local: 2.1.4 -> 2.1.5

Uso:
    python atualizador.py

Opções:
    --no-pause
    --help

O atualizador:
- deriva os caminhos de sua própria localização;
- valida a base antes de gravar;
- prepara as alterações em memória;
- preserva configurações e arquivos fora do escopo;
- não acessa a internet nem executa Git;
- não cria backups ou relatórios persistentes;
- gera o ZIP completo no diretório pai;
- não inclui o atualizador no ZIP;
- aceita reexecução na versão de destino, conferindo o conteúdo.
"""

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile


BASE_VERSION = "2.1.4"
TARGET_VERSION = "2.1.5"

PLUGIN_NAME = "SPSES - Documentos para Download"
PACKAGE_NAME = "sdm-elementor"

SCRIPT_PATH = Path(__file__).resolve()
PROJECT_DIR = SCRIPT_PATH.parent
OUTPUT_DIR = PROJECT_DIR.parent

ZIP_PATH = OUTPUT_DIR / (
    PACKAGE_NAME + "-" + TARGET_VERSION + ".zip"
)

MAIN_FILE = "sdm-elementor.php"
WIDGET_FILE = "includes/class-elementor-widget.php"
RENDERER_FILE = "includes/class-tree-renderer.php"
BASE_CSS_FILE = "assets/sdm-elementor.css"
BADGE_CSS_FILE = "assets/sdm-elementor-extension-badge.css"
CHANGELOG_FILE = "CHANGELOG.md"

REQUIRED_FILES = (
    MAIN_FILE,
    WIDGET_FILE,
    RENDERER_FILE,
    BASE_CSS_FILE,
    BADGE_CSS_FILE,
    "assets/sdm-elementor.js",
    "includes/class-file-extension-badge.php",
    "includes/class-file-type-icons.php",
    "includes/class-sdm-editor-layout.php",
    "assets/sdm-elementor-file-icons.css",
    "assets/sdm-elementor-icons-admin.css",
    "assets/sdm-elementor-icons-admin.js",
    "assets/sdm-elementor-admin.css",
)

IGNORE_DIRS = {
    ".git",
    ".github",
    ".idea",
    ".vscode",
    "__pycache__",
    ".pytest_cache",
}

IGNORE_NAMES = {
    "atualizador.py",
    "executar_atualizador.py",
    "atualizar_sdm_elementor.py",
    ".ds_store",
    "thumbs.db",
    "desktop.ini",
    "relatorio-atualizacao-sdm-elementor.txt",
    "relatorio-atualizacao.txt",
}

APPLIED = []

CHANGELOG_ENTRY = """<!-- sdm-elementor:2.1.5:start -->
## [2.1.5]
- Badge da extensão reposicionado para depois do título.
- Corrigido o seletor que removia a aparência de botão do título.
- Removidas borda, fundo e sombra próprios do botão apenas no link do título.
- Restaurada a herança de tipografia e cor configuradas no Elementor.
- Preservados os controles e os estilos independentes do badge.
- Preservados os ícones, os links, os formulários e as verificações do SDM.
- Mantidos os identificadores dos controles e do widget.
<!-- sdm-elementor:2.1.5:end -->"""

OLD_BADGE_MARKUP = r"""        $title_label_html = '<span class="icd-file-title-label">'
            . $extension_badge_html
            . '<span class="icd-file-title-text">'
            . $title_html
            . '</span></span>';"""

NEW_BADGE_MARKUP = r"""        $title_label_html = '<span class="icd-file-title-label">'
            . '<span class="icd-file-title-text">'
            . $title_html
            . '</span>'
            . $extension_badge_html
            . '</span>';"""

OLD_TITLE_RESET = """/* SPSES 2.1: link do título sem a aparência de botão SDM. */
.icd-central .icd-document-title > a.icd-title-download {
    display:inline !important; float:none !important; width:auto !important;
    margin:0 !important; padding:0 !important; border:0 !important;
    background:none !important; background-image:none !important;
    color:inherit !important; font:inherit !important; line-height:inherit !important;
    box-shadow:none !important; text-shadow:none !important; filter:none !important;
    text-decoration:none; vertical-align:baseline;
}
.icd-central .icd-document-title > a.icd-title-download:hover { text-decoration:underline; }
"""

NEW_TITLE_RESET = """/* SPSES 2.1.5: o link herda o estilo do título em qualquer profundidade. */
.icd-central .icd-document-title a.icd-title-download {
    display:inline !important;
    float:none !important;
    width:auto !important;
    height:auto !important;
    min-height:0 !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
    border-radius:0 !important;
    background:none !important;
    background-image:none !important;
    box-shadow:none !important;
    text-shadow:none !important;
    filter:none !important;
    color:inherit !important;
    font:inherit !important;
    line-height:inherit !important;
    letter-spacing:inherit !important;
    word-spacing:inherit !important;
    text-transform:inherit !important;
    text-decoration:inherit !important;
    white-space:normal !important;
    vertical-align:baseline;
}
/* Não remove o contorno de foco: a navegação por teclado é preservada. */
"""

OLD_LABEL_STYLE = """.icd-central .icd-file-title-label {
    display:inline-flex;
    align-items:center;
    gap:var(--icd-extension-badge-gap,.45em);
    min-width:0;
    max-width:100%;
}"""

NEW_LABEL_STYLE = """.icd-central .icd-file-title-label {
    display:inline-flex;
    align-items:center;
    gap:var(--icd-extension-badge-gap,.45em);
    min-width:0;
    max-width:100%;
    font:inherit;
    line-height:inherit;
    letter-spacing:inherit;
    word-spacing:inherit;
    text-transform:inherit;
    text-decoration:inherit;
    color:inherit;
}"""

OLD_TEXT_STYLE = """.icd-central .icd-file-title-text {
    display:block;
    min-width:0;
    overflow-wrap:anywhere;
}"""

NEW_TEXT_STYLE = """.icd-central .icd-file-title-text {
    display:block;
    flex:0 1 auto;
    min-width:0;
    overflow-wrap:anywhere;
    font:inherit;
    line-height:inherit;
    letter-spacing:inherit;
    word-spacing:inherit;
    text-transform:inherit;
    text-decoration:inherit;
    color:inherit;
}"""


class UpdateError(RuntimeError):
    pass


def log(level, message):
    print("[" + level + "] " + str(message), flush=True)


def is_link(path):
    return path.is_symlink() or (
        hasattr(path, "is_junction") and path.is_junction()
    )


def excluded(relative):
    for part in relative.parts:
        lower = part.lower()

        if lower in IGNORE_DIRS:
            return True

        if lower.startswith((".backup", ".bak", ".sdm-tmp")):
            return True

    name = relative.name.lower()

    if name in IGNORE_NAMES or name == SCRIPT_PATH.name.lower():
        return True

    if name.startswith(".env"):
        return True

    if name in {
        "id_rsa",
        "id_ed25519",
        "wp-config.php",
        "auth.json",
    }:
        return True

    if name.startswith((
        "credentials",
        "service-account",
        "service_account",
    )):
        return True

    return relative.suffix.lower() in {
        ".zip",
        ".pyc",
        ".pyo",
        ".log",
        ".tmp",
        ".swp",
        ".pem",
        ".key",
        ".p12",
        ".pfx",
    }


def snapshot():
    files = {}

    for current, directories, names in os.walk(
        PROJECT_DIR,
        followlinks=False,
    ):
        parent = Path(current)
        kept = []

        for name in directories:
            path = parent / name
            relative = path.relative_to(PROJECT_DIR)

            if excluded(relative):
                continue

            if is_link(path):
                raise UpdateError(
                    "Link/junção não permitido: " + str(relative)
                )

            kept.append(name)

        directories[:] = kept

        for name in names:
            path = parent / name
            relative = path.relative_to(PROJECT_DIR)

            if excluded(relative) or path.absolute() == SCRIPT_PATH:
                continue

            if is_link(path) or not path.is_file():
                raise UpdateError(
                    "Arquivo não regular: " + str(relative)
                )

            files[relative.as_posix()] = path.read_bytes()

    return files


def read_text(data, name):
    try:
        return data.decode("utf-8-sig").replace("\r\n", "\n")
    except UnicodeDecodeError as error:
        raise UpdateError(
            "UTF-8 esperado em " + name
        ) from error


def encode_like(content, original, name):
    if content == read_text(original, name):
        return original

    bom = (
        b"\xef\xbb\xbf"
        if original.startswith(b"\xef\xbb\xbf")
        else b""
    )

    body = original[len(bom):]

    if b"\r" in body.replace(b"\r\n", b""):
        raise UpdateError(
            "Quebra de linha CR isolada em " + name
        )

    if b"\r\n" in body:
        content = content.replace("\n", "\r\n")

    return bom + content.encode("utf-8")


def replace_one(source, old, new, label):
    old_count = source.count(old)
    new_count = source.count(new)

    if new_count == 1 and old_count == new.count(old):
        return source

    if new_count == 0 and old_count == 1:
        return source.replace(old, new, 1)

    raise UpdateError(
        "Padrão ausente, duplicado ou incompatível: " + label
    )


def header(source, name):
    matches = re.findall(
        r"(?m)^[ \t]*\*[ \t]*"
        + re.escape(name)
        + r":[ \t]*(.*?)[ \t]*$",
        source,
    )

    if len(matches) != 1:
        raise UpdateError(
            "Cabeçalho ausente ou duplicado: " + name
        )

    return matches[0]


def method_span(source, name):
    pattern = (
        r"(?m)^    (?:public|private|protected)\s+"
        r"(?:static\s+)?function\s+"
        + re.escape(name)
        + r"\([^\n]*\)(?:\s*:\s*[^{\n]+)?\s*\{"
    )

    matches = list(re.finditer(pattern, source))

    if len(matches) != 1:
        raise UpdateError(
            "Método ausente ou duplicado: " + name
        )

    match = matches[0]
    position = match.end()
    depth = 1
    state = ""

    while position < len(source):
        char = source[position]
        pair = source[position:position + 2]

        if state in {"'", '"'}:
            if char == "\\":
                position += 2
                continue

            if char == state:
                state = ""

        elif state == "//":
            if char == "\n":
                state = ""

        elif state == "/*":
            if pair == "*/":
                state = ""
                position += 2
                continue

        else:
            if source[position:position + 3] == "<<<" or pair == "?>":
                raise UpdateError(
                    "Construção PHP não prevista em " + name
                )

            if char in {"'", '"'}:
                state = char

            elif pair in {"//", "/*"}:
                state = pair
                position += 2
                continue

            elif char == "#":
                state = "//"

            elif char == "{":
                depth += 1

            elif char == "}":
                depth -= 1

                if depth == 0:
                    return match.start(), position + 1

        position += 1

    raise UpdateError(
        "Fechamento do método não encontrado: " + name
    )


def patch_main(source, current):
    if header(source, "Plugin Name") != PLUGIN_NAME:
        raise UpdateError(
            "O arquivo principal não pertence ao plugin esperado."
        )

    for marker in (
        "class ICD_SDM_Central",
        "add_shortcode('central_documentos'",
        "'icd_sdm_central_options'",
        "SPSES_SDM_File_Type_Icons::boot",
        "SPSES_SDM_File_Extension_Badge::boot",
        "SPSES_SDM_Download_Editor_Layout::boot",
    ):
        if marker not in source:
            raise UpdateError(
                "Estrutura esperada não encontrada: " + marker
            )

    constants = re.findall(
        r"""(?m)^    const VERSION = ['"]([^'"]+)['"];[ \t]*$""",
        source,
    )

    if constants != [current]:
        raise UpdateError(
            "Cabeçalho e constante VERSION estão divergentes."
        )

    updated, count = re.subn(
        r"(?m)^([ \t]*\*[ \t]*Version:[ \t]*).*$",
        lambda match: match.group(1) + TARGET_VERSION,
        source,
    )

    if count != 1:
        raise UpdateError("Cabeçalho Version inesperado.")

    updated, count = re.subn(
        r"""(?m)^    const VERSION = ['"][^'"]+['"];[ \t]*$""",
        "    const VERSION = '" + TARGET_VERSION + "';",
        updated,
    )

    if count != 1:
        raise UpdateError("Constante VERSION inesperada.")

    return updated


def patch_renderer(source):
    if "ICD_SDM_Tree_Renderer" not in source:
        raise UpdateError("Renderer esperado não encontrado.")

    start, end = method_span(source, "document")
    method = source[start:end]

    for marker in (
        "SPSES_SDM_File_Extension_Badge::render_for_document",
        "SPSES_SDM_File_Type_Icons::render_for_document",
        "$this->document_title_link(",
    ):
        if marker not in method:
            raise UpdateError(
                "Integração esperada ausente no renderer: " + marker
            )

    updated = replace_one(
        method,
        OLD_BADGE_MARKUP,
        NEW_BADGE_MARKUP,
        "posição do badge depois do título",
    )

    return source[:start] + updated + source[end:]


def patch_widget(source):
    required = (
        "return 'icd_sdm_documents';",
        "add_control('show_file_extension_badge'",
        "spses_extension_badge_typography",
        "spses_extension_badge_gap",
        "$this->typography('document_type', $W . ' .icd-document-title');",
        "$this->color('document_color', 'Cor dos títulos', $W . ' .icd-document-title');",
    )

    for marker in required:
        if marker not in source:
            raise UpdateError(
                "Controle esperado não encontrado: " + marker
            )

    return replace_one(
        source,
        (
            "'description' => "
            "'Mostra a extensão antes do título, sem ponto inicial.',"
        ),
        (
            "'description' => "
            "'Mostra a extensão depois do título, sem ponto inicial.',"
        ),
        "descrição da posição do badge",
    )


def patch_base_css(source):
    return replace_one(
        source,
        OLD_TITLE_RESET,
        NEW_TITLE_RESET,
        "remoção dos estilos de botão do link do título",
    )


def patch_badge_css(source):
    source = replace_one(
        source,
        OLD_LABEL_STYLE,
        NEW_LABEL_STYLE,
        "herança de estilos no agrupador de título e badge",
    )

    return replace_one(
        source,
        OLD_TEXT_STYLE,
        NEW_TEXT_STYLE,
        "herança de tipografia no texto do documento",
    )


def update_changelog(original):
    if original is None:
        return (
            "# Changelog\n\n" + CHANGELOG_ENTRY + "\n"
        ).encode("utf-8")

    source = read_text(original, CHANGELOG_FILE)

    heading = re.search(
        r"(?m)^##?\s+\[?2\.1\.5\]?(?:\s|$)",
        source,
    )

    if "sdm-elementor:2.1.5:start" in source or heading:
        if source.count(CHANGELOG_ENTRY) != 1:
            raise UpdateError(
                "Entrada 2.1.5 do changelog incompatível."
            )

        return original

    if source.startswith("# "):
        end = source.find("\n")

        if end == -1:
            source += "\n"
            end = len(source) - 1

        updated = (
            source[:end + 1]
            + "\n"
            + CHANGELOG_ENTRY
            + "\n"
            + source[end + 1:]
        )

    else:
        updated = (
            "# Changelog\n\n"
            + CHANGELOG_ENTRY
            + "\n\n"
            + source
        )

    return encode_like(
        updated,
        original,
        CHANGELOG_FILE,
    )


def patch_optional_metadata(candidate, original, current):
    if "VERSION" in original:
        source = read_text(original["VERSION"], "VERSION")
        value = source.strip()

        if value not in {current, TARGET_VERSION}:
            raise UpdateError(
                "O arquivo VERSION contém uma versão inesperada."
            )

        updated = source.replace(value, TARGET_VERSION, 1)

        candidate["VERSION"] = encode_like(
            updated,
            original["VERSION"],
            "VERSION",
        )

    if "readme.txt" in original:
        source = read_text(
            original["readme.txt"],
            "readme.txt",
        )

        pattern = r"(?mi)^(Stable tag:[ \t]*)([^\n]+)$"
        matches = list(re.finditer(pattern, source))

        if matches:
            if len(matches) != 1:
                raise UpdateError(
                    "Stable tag duplicada em readme.txt."
                )

            value = matches[0].group(2).strip()

            if value not in {current, TARGET_VERSION}:
                raise UpdateError(
                    "Stable tag inesperada: " + value
                )

            updated = re.sub(
                pattern,
                lambda match: match.group(1) + TARGET_VERSION,
                source,
                count=1,
            )

            candidate["readme.txt"] = encode_like(
                updated,
                original["readme.txt"],
                "readme.txt",
            )


def prepare(files):
    missing = sorted(set(REQUIRED_FILES) - set(files))

    if missing:
        raise UpdateError(
            "Arquivos obrigatórios ausentes: "
            + ", ".join(missing)
        )

    if "central-documentos.php" in files:
        raise UpdateError(
            "O arquivo principal antigo está coexistindo "
            "com o arquivo atual."
        )

    main = read_text(files[MAIN_FILE], MAIN_FILE)
    current = header(main, "Version")

    if current not in {BASE_VERSION, TARGET_VERSION}:
        raise UpdateError(
            "Versão incompatível: "
            + current
            + ". Aceitas: "
            + BASE_VERSION
            + " ou "
            + TARGET_VERSION
            + "."
        )

    candidate = dict(files)

    transformations = (
        (MAIN_FILE, patch_main(main, current)),
        (
            RENDERER_FILE,
            patch_renderer(
                read_text(files[RENDERER_FILE], RENDERER_FILE)
            ),
        ),
        (
            WIDGET_FILE,
            patch_widget(
                read_text(files[WIDGET_FILE], WIDGET_FILE)
            ),
        ),
        (
            BASE_CSS_FILE,
            patch_base_css(
                read_text(files[BASE_CSS_FILE], BASE_CSS_FILE)
            ),
        ),
        (
            BADGE_CSS_FILE,
            patch_badge_css(
                read_text(files[BADGE_CSS_FILE], BADGE_CSS_FILE)
            ),
        ),
    )

    for name, content in transformations:
        candidate[name] = encode_like(
            content,
            files[name],
            name,
        )

    candidate[CHANGELOG_FILE] = update_changelog(
        files.get(CHANGELOG_FILE)
    )

    patch_optional_metadata(candidate, files, current)

    changed = sorted(
        name
        for name in set(files) | set(candidate)
        if files.get(name) != candidate.get(name)
    )

    allowed = {
        MAIN_FILE,
        WIDGET_FILE,
        RENDERER_FILE,
        BASE_CSS_FILE,
        BADGE_CSS_FILE,
        CHANGELOG_FILE,
        "VERSION",
        "readme.txt",
    }

    unexpected = sorted(set(changed) - allowed)

    if unexpected:
        raise UpdateError(
            "Alteração fora do escopo: "
            + ", ".join(unexpected)
        )

    if current == TARGET_VERSION and changed:
        raise UpdateError(
            "O projeto informa "
            + TARGET_VERSION
            + ", mas os arquivos divergem desta revisão. "
            "Nada foi gravado. Divergências: "
            + ", ".join(changed)
        )

    verify_candidate(candidate)

    return current, candidate, changed


def verify_candidate(files):
    required = set(REQUIRED_FILES) | {CHANGELOG_FILE}
    missing = sorted(required - set(files))

    if missing:
        raise UpdateError(
            "Pacote incompleto: " + ", ".join(missing)
        )

    main = read_text(files[MAIN_FILE], MAIN_FILE)

    if header(main, "Plugin Name") != PLUGIN_NAME:
        raise UpdateError("Nome do plugin divergente.")

    if header(main, "Version") != TARGET_VERSION:
        raise UpdateError("Versão do cabeçalho divergente.")

    constants = re.findall(
        r"""(?m)^    const VERSION = ['"]([^'"]+)['"];[ \t]*$""",
        main,
    )

    if constants != [TARGET_VERSION]:
        raise UpdateError("Constante VERSION divergente.")

    renderer = read_text(
        files[RENDERER_FILE],
        RENDERER_FILE,
    )

    if renderer.count(NEW_BADGE_MARKUP) != 1:
        raise UpdateError(
            "A posição do badge não corresponde à correção."
        )

    if OLD_BADGE_MARKUP in renderer:
        raise UpdateError(
            "A marcação antiga do badge ainda está presente."
        )

    css = read_text(
        files[BASE_CSS_FILE],
        BASE_CSS_FILE,
    )

    if css.count(NEW_TITLE_RESET) != 1:
        raise UpdateError(
            "O reset visual do título está ausente ou duplicado."
        )

    if OLD_TITLE_RESET in css:
        raise UpdateError(
            "O seletor antigo do título ainda está presente."
        )

    badge_css = read_text(
        files[BADGE_CSS_FILE],
        BADGE_CSS_FILE,
    )

    for block in (NEW_LABEL_STYLE, NEW_TEXT_STYLE):
        if badge_css.count(block) != 1:
            raise UpdateError(
                "A herança de tipografia não está completa."
            )

    widget = read_text(files[WIDGET_FILE], WIDGET_FILE)

    if "return 'icd_sdm_documents';" not in widget:
        raise UpdateError(
            "Identificador persistente do widget alterado."
        )

    if (
        "$this->typography('document_type', "
        "$W . ' .icd-document-title');"
    ) not in widget:
        raise UpdateError(
            "Controle original de tipografia do título alterado."
        )

    if "spses_extension_badge_typography" not in widget:
        raise UpdateError(
            "Controle independente de tipografia do badge ausente."
        )

    changelog = read_text(
        files[CHANGELOG_FILE],
        CHANGELOG_FILE,
    )

    if changelog.count(CHANGELOG_ENTRY) != 1:
        raise UpdateError(
            "Entrada do changelog ausente ou duplicada."
        )

    for name in files:
        relative = Path(name)

        if (
            relative.is_absolute()
            or ".." in relative.parts
            or excluded(relative)
        ):
            raise UpdateError(
                "Arquivo indevido no pacote: " + name
            )


def validate_candidate(files):
    log("INFO", "Validando os arquivos preparados...")
    verify_candidate(files)

    compile(
        SCRIPT_PATH.read_text(encoding="utf-8-sig"),
        str(SCRIPT_PATH),
        "exec",
    )

    log("OK", "Sintaxe do atualizador Python validada.")

    for name, content in files.items():
        if name.lower().endswith(".json"):
            try:
                json.loads(read_text(content, name))
            except (ValueError, UpdateError) as error:
                raise UpdateError(
                    "JSON inválido em " + name
                ) from error

    log("OK", "Arquivos JSON existentes verificados.")

    with tempfile.TemporaryDirectory(
        prefix="spses-sdm-validacao-"
    ) as temporary:
        stage = Path(temporary)

        for name, content in files.items():
            target = stage / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)

        validators = (
            ("php", ".php", "-l", "PHP"),
            ("node", ".js", "--check", "JavaScript"),
        )

        for program, extension, flag, label in validators:
            executable = shutil.which(program)

            if not executable:
                log(
                    "AVISO",
                    label
                    + ": validador não encontrado. "
                    "Validação ignorada, não aprovada.",
                )
                continue

            names = sorted(
                name
                for name in files
                if name.lower().endswith(extension)
            )

            for name in names:
                try:
                    result = subprocess.run(
                        [
                            executable,
                            flag,
                            str(stage / name),
                        ],
                        capture_output=True,
                        text=True,
                        encoding="utf-8",
                        errors="replace",
                        timeout=60,
                        shell=False,
                    )
                except (
                    OSError,
                    subprocess.TimeoutExpired,
                ) as error:
                    raise UpdateError(
                        "Não foi possível validar "
                        + name
                        + ": "
                        + str(error)
                    ) from error

                if result.returncode != 0:
                    details = (
                        result.stdout + "\n" + result.stderr
                    ).strip()

                    raise UpdateError(
                        "Erro de sintaxe "
                        + label
                        + " em "
                        + name
                        + ":\n"
                        + details
                    )

            log(
                "OK",
                label
                + ": "
                + str(len(names))
                + " arquivo(s) validado(s).",
            )

    log(
        "AVISO",
        "Sintaxe válida não comprova funcionamento visual "
        "em WordPress, Elementor, SDM ou no tema instalado.",
    )


def zip_matches(path, files):
    if not path.is_file() or is_link(path):
        return False

    expected = {
        PACKAGE_NAME + "/" + name: content
        for name, content in files.items()
    }

    try:
        with zipfile.ZipFile(path, "r") as archive:
            names = archive.namelist()

            if (
                not names
                or len(names) != len(expected)
                or len(names) != len(set(names))
                or set(names) != set(expected)
                or archive.testzip() is not None
            ):
                return False

            return all(
                archive.read(name) == content
                for name, content in expected.items()
            )

    except (OSError, ValueError, zipfile.BadZipFile):
        return False


def build_temporary_zip(files):
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=".sdm-elementor-",
        suffix=".zip",
        dir=str(OUTPUT_DIR),
    )

    os.close(descriptor)
    temporary = Path(temporary_name)

    try:
        with zipfile.ZipFile(
            temporary,
            "w",
            compression=zipfile.ZIP_DEFLATED,
            compresslevel=9,
        ) as archive:
            for name in sorted(files):
                entry = zipfile.ZipInfo(
                    PACKAGE_NAME + "/" + name,
                    date_time=(2026, 10, 15, 0, 0, 0),
                )

                entry.compress_type = zipfile.ZIP_DEFLATED
                entry.external_attr = (
                    (0o100644 & 0xFFFF) << 16
                )

                archive.writestr(
                    entry,
                    files[name],
                    compress_type=zipfile.ZIP_DEFLATED,
                    compresslevel=9,
                )

        if not zip_matches(temporary, files):
            raise UpdateError(
                "O ZIP temporário não passou na conferência."
            )

        log(
            "OK",
            "ZIP temporário conferido: "
            + str(len(files))
            + " arquivo(s).",
        )

        return temporary

    except BaseException:
        try:
            temporary.unlink(missing_ok=True)
        except OSError:
            pass
        raise


def safe_destination(name):
    relative = Path(name)

    if relative.is_absolute() or ".." in relative.parts:
        raise UpdateError(
            "Destino inválido: " + name
        )

    current = PROJECT_DIR

    for part in relative.parts:
        current = current / part

        if is_link(current):
            raise UpdateError(
                "Link/junção encontrado no destino: " + name
            )

    return PROJECT_DIR / relative


def atomic_write(name, content):
    target = safe_destination(name)
    target.parent.mkdir(parents=True, exist_ok=True)

    if target.exists() and not target.is_file():
        raise UpdateError(
            "Destino não é um arquivo regular: " + name
        )

    mode = (
        target.stat().st_mode & 0o777
        if target.exists()
        else 0o644
    )

    descriptor, temporary_name = tempfile.mkstemp(
        prefix=".sdm-tmp-",
        dir=str(target.parent),
    )

    temporary = Path(temporary_name)

    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())

        os.chmod(temporary, mode)
        safe_destination(name)

        os.replace(temporary, target)
        APPLIED.append(name)

        if target.read_bytes() != content:
            raise UpdateError(
                "A conferência após gravação falhou: " + name
            )

        log("OK", "Atualizado: " + name)

    finally:
        try:
            temporary.unlink(missing_ok=True)
        except OSError:
            pass


def publish_zip(temporary, files):
    if ZIP_PATH.exists():
        if zip_matches(ZIP_PATH, files):
            log(
                "OK",
                "O ZIP existente é idêntico e foi preservado.",
            )
            return

        raise UpdateError(
            "Já existe um pacote diferente:\n"
            + str(ZIP_PATH)
            + "\nEle não será sobrescrito."
        )

    created = False

    try:
        with ZIP_PATH.open("xb") as destination:
            created = True

            with temporary.open("rb") as source:
                shutil.copyfileobj(source, destination)

            destination.flush()
            os.fsync(destination.fileno())

        if not zip_matches(ZIP_PATH, files):
            raise UpdateError(
                "A conferência do ZIP publicado falhou."
            )

    except FileExistsError:
        if zip_matches(ZIP_PATH, files):
            log(
                "OK",
                "Um ZIP idêntico já está disponível.",
            )
            return

        raise UpdateError(
            "Outro pacote apareceu no destino. "
            "Ele não foi sobrescrito."
        )

    except BaseException:
        # Somente um pacote novo e incompleto desta execução
        # pode ser removido; pacotes anteriores são preservados.
        if created:
            try:
                ZIP_PATH.unlink(missing_ok=True)
            except OSError:
                pass
        raise

    log("OK", "ZIP publicado: " + str(ZIP_PATH))


def run():
    if sys.version_info < (3, 10):
        raise UpdateError(
            "Use Python 3.10 ou superior, "
            "preferencialmente a versão estável atual."
        )

    APPLIED.clear()

    if OUTPUT_DIR == PROJECT_DIR:
        raise UpdateError(
            "O diretório de saída precisa estar fora do projeto."
        )

    print("=" * 64, flush=True)
    log("INFO", PLUGIN_NAME)
    log(
        "INFO",
        "Atualização " + BASE_VERSION + " → " + TARGET_VERSION,
    )
    log("INFO", "Python: " + sys.version.split()[0])
    log("INFO", "Projeto: " + str(PROJECT_DIR))
    log("INFO", "ZIP: " + str(ZIP_PATH))
    print("=" * 64, flush=True)

    log(
        "AVISO",
        "Backup automático desativado. "
        "Use uma cópia de homologação ou mantenha "
        "uma referência recuperável do projeto.",
    )

    original = snapshot()
    current, candidate, changed = prepare(original)

    log("OK", "Versão encontrada: " + current)

    if current == TARGET_VERSION:
        log(
            "JÁ ATUALIZADO",
            "Os arquivos correspondem a esta revisão.",
        )
    else:
        for name in changed:
            action = (
                "CRIAR"
                if name not in original
                else "ALTERAR"
            )
            log(action, name)

    validate_candidate(candidate)

    if is_link(ZIP_PATH):
        raise UpdateError(
            "O destino do ZIP é um link/junção."
        )

    if ZIP_PATH.exists() and not zip_matches(
        ZIP_PATH,
        candidate,
    ):
        raise UpdateError(
            "Existe um ZIP diferente com o mesmo nome:\n"
            + str(ZIP_PATH)
            + "\nNada foi gravado no projeto. "
            "Mova ou renomeie o pacote manualmente."
        )

    temporary_zip = None

    try:
        temporary_zip = build_temporary_zip(candidate)

        if snapshot() != original:
            raise UpdateError(
                "O projeto mudou durante a preparação. "
                "Nenhum arquivo foi gravado pelo atualizador."
            )

        for name in changed:
            atomic_write(name, candidate[name])

        final_snapshot = snapshot()

        if final_snapshot != candidate:
            raise UpdateError(
                "A conferência final encontrou divergências."
            )

        verify_candidate(final_snapshot)
        publish_zip(temporary_zip, candidate)

    finally:
        if temporary_zip is not None:
            try:
                temporary_zip.unlink(missing_ok=True)
            except OSError:
                pass

    print("", flush=True)

    log(
        "SUCESSO",
        "Atualização concluída: " + TARGET_VERSION,
    )

    log("INFO", "Pacote: " + str(ZIP_PATH))

    log(
        "INFO",
        "Título: Estilo → Lista de documentos → Tipografia/Cor dos títulos.",
    )

    log(
        "INFO",
        "Extensão: Estilo → Badge da extensão.",
    )

    log(
        "AVISO",
        "Teste em homologação e limpe os caches da página "
        "antes de avaliar o resultado visual.",
    )


def main():
    arguments = set(sys.argv[1:])

    if "--help" in arguments:
        print(__doc__)
        return 0

    unknown = arguments - {"--no-pause"}

    if unknown:
        log(
            "ERRO",
            "Argumentos desconhecidos: "
            + ", ".join(sorted(unknown)),
        )
        return 1

    exit_code = 1

    try:
        run()
        return 0

    except KeyboardInterrupt:
        print("", flush=True)
        log("ERRO", "Execução cancelada pelo usuário.")
        exit_code = 130

    except Exception as error:
        print("", flush=True)
        log("ERRO", str(error))

    if APPLIED:
        log(
            "ATENÇÃO",
            "Alguns arquivos já foram gravados. "
            "Não há reversão automática.",
        )

        for name in APPLIED:
            log("GRAVADO", name)

        log(
            "ATENÇÃO",
            "Confira o projeto antes de executar novamente.",
        )
    else:
        log(
            "INFO",
            "Nenhum arquivo do plugin foi gravado pelo atualizador.",
        )

    return exit_code


if __name__ == "__main__":
    exit_code = 1

    try:
        exit_code = main()
    finally:
        if (
            "--no-pause" not in sys.argv[1:]
            and "--help" not in sys.argv[1:]
        ):
            try:
                input("\nPressione Enter para fechar...")
            except (EOFError, KeyboardInterrupt):
                pass

    raise SystemExit(exit_code)
