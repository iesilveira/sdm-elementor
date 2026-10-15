#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""SPSES - Documentos para Download: migração 2.1 -> 2.1.1.
Salve este arquivo dentro do plugin e execute: python atualizador.py
Opcional: python atualizador.py --no-pause
Biblioteca padrão; Python 3.10+ (inclusive versões estáveis mais recentes).
Não acessa a internet, não executa Git e não altera o banco de dados.
Não cria backups nem relatórios persistentes.
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

BASE_VERSION = "2.1"
TARGET_VERSION = "2.1.1"
PACKAGE_NAME = "sdm-elementor"
PLUGIN_NAME = "SPSES - Documentos para Download"
SCRIPT_PATH = Path(__file__).resolve()
PROJECT_DIR = SCRIPT_PATH.parent
OUTPUT_DIR = PROJECT_DIR.parent
ZIP_PATH = OUTPUT_DIR / (PACKAGE_NAME + "-" + TARGET_VERSION + ".zip")
MAIN_FILE = "sdm-elementor.php"
CHANGELOG_FILE = "CHANGELOG.md"
REQUIRED_FILES = (
    MAIN_FILE, "includes/class-elementor-widget.php",
    "includes/class-tree-renderer.php",
    "assets/sdm-elementor.css", "assets/sdm-elementor.js",
)
IGNORE_DIRS = {".git", ".github", ".idea", ".vscode", "__pycache__", ".pytest_cache"}
IGNORE_NAMES = {
    "atualizador.py", "executar_atualizador.py", "atualizar_sdm_elementor.py",
    ".ds_store", "thumbs.db", "desktop.ini",
    "relatorio-atualizacao-sdm-elementor.txt", "relatorio-atualizacao.txt",
}
APPLIED = []
CHANGELOG_ENTRY = """<!-- sdm-elementor:2.1.1:start -->
## [2.1.1] - 2026-10-15
- Removido o registro da tela administrativa Central de Documentos.
- Removido o registro do formulário de configurações globais desse complemento.
- Removido seu link Configurações na lista de plugins.
- Mantidos o widget Downloads e todos os seus controles no Elementor.
- Preservadas as telas, categorias, documentos e configurações do SDM.
- Preservados o shortcode legado, os identificadores e as opções já armazenadas.
- Nenhuma migração ou exclusão de dados do banco é executada.
- Esta revisão não diagnostica nem declara corrigido o erro fatal relatado.
<!-- sdm-elementor:2.1.1:end -->"""

# Remoção restrita aos três registros conhecidos dentro de ICD_SDM_Central::boot().
HOOK_RULES = (
    (
        "submenu administrativo",
        r"""(?m)^[ \t]*add_action\(\s*['"]admin_menu['"]\s*,\s*array\(\s*__CLASS__\s*,\s*['"]menu['"]\s*\)\s*\)\s*;[ \t]*(?:\n|$)""",
    ),
    (
        "registro de configurações globais",
        r"""(?m)^[ \t]*add_action\(\s*['"]admin_init['"]\s*,\s*array\(\s*__CLASS__\s*,\s*['"]settings['"]\s*\)\s*\)\s*;[ \t]*(?:\n|$)""",
    ),
    (
        "link Configurações na lista de plugins",
        r"""(?m)^[ \t]*add_filter\(\s*['"]plugin_action_links_['"]\s*\.\s*plugin_basename\(\s*__FILE__\s*\)\s*,\s*array\(\s*__CLASS__\s*,\s*['"]action_links['"]\s*\)\s*\)\s*;[ \t]*(?:\n|$)""",
    ),
)


class UpdateError(RuntimeError):
    pass


def log(level, message):
    print("[" + level + "] " + str(message), flush=True)


def excluded(relative):
    if any(part.lower() in IGNORE_DIRS or
           part.lower().startswith((".backup", ".bak", ".sdm-tmp"))
           for part in relative.parts):
        return True
    name = relative.name.lower()
    if name in IGNORE_NAMES or name == SCRIPT_PATH.name.lower():
        return True
    if name.startswith(".env") or name in {
        "id_rsa", "id_ed25519", "wp-config.php", "auth.json"
    }:
        return True
    if name.startswith(("credentials", "service-account", "service_account")):
        return True
    return relative.suffix.lower() in {
        ".zip", ".pyc", ".pyo", ".log", ".tmp", ".swp", ".pem", ".key", ".p12", ".pfx"
    }


def snapshot():
    files = {}
    for current, dirs, names in os.walk(PROJECT_DIR, followlinks=False):
        parent = Path(current)
        kept = []
        for name in dirs:
            path = parent / name
            rel = path.relative_to(PROJECT_DIR)
            if excluded(rel):
                continue
            if path.is_symlink() or (hasattr(path, "is_junction") and path.is_junction()):
                raise UpdateError("Link/junção não permitido no pacote: " + str(rel))
            kept.append(name)
        dirs[:] = kept
        for name in names:
            path = parent / name
            rel = path.relative_to(PROJECT_DIR)
            if excluded(rel) or path.absolute() == SCRIPT_PATH:
                continue
            if path.is_symlink() or not path.is_file():
                raise UpdateError("Arquivo não regular/link: " + str(rel))
            files[rel.as_posix()] = path.read_bytes()
    return files


def text(data, name):
    try:
        return data.decode("utf-8-sig").replace("\r\n", "\n")
    except UnicodeDecodeError as error:
        raise UpdateError("UTF-8 esperado em " + name) from error


def encode_like(content, original, name):
    if content == text(original, name):
        return original
    bom = b"\xef\xbb\xbf" if original.startswith(b"\xef\xbb\xbf") else b""
    body = original[len(bom):]
    if b"\r" in body.replace(b"\r\n", b""):
        raise UpdateError("Quebra de linha CR isolada não suportada em " + name)
    if b"\r\n" in body:
        content = content.replace("\n", "\r\n")
    return bom + content.encode("utf-8")


def header(source, name):
    values = re.findall(
        r"(?m)^[ \t]*\*[ \t]*" + re.escape(name) + r":[ \t]*(.*?)[ \t]*$", source
    )
    if len(values) != 1:
        raise UpdateError("Cabeçalho ausente ou duplicado: " + name)
    return values[0]


def method_span(source, name):
    pattern = (r"(?m)^    (?:public|private|protected)\s+(?:static\s+)?function\s+" +
               re.escape(name) + r"\([^\n]*\)(?:\s*:\s*[^{\n]+)?\s*\{")
    matches = list(re.finditer(pattern, source))
    if len(matches) != 1:
        raise UpdateError("Método ausente ou duplicado: " + name)
    match = matches[0]
    i, depth, state = match.end(), 1, ""
    while i < len(source):
        char = source[i]
        pair = source[i:i + 2]
        if state in {"'", '"'}:
            if char == "\\":
                i += 2
                continue
            if char == state:
                state = ""
        elif state == "//":
            if char == "\n":
                state = ""
        elif state == "/*":
            if pair == "*/":
                state = ""
                i += 2
                continue
        else:
            if source[i:i + 3] == "<<<" or pair == "?>":
                raise UpdateError("Construção PHP não prevista no método " + name)
            if char in {"'", '"'}:
                state = char
            elif pair in {"//", "/*"}:
                state = pair
                i += 2
                continue
            elif char == "#":
                state = "//"
            elif char == "{":
                depth += 1
            elif char == "}":
                depth -= 1
                if depth == 0:
                    return match.start(), i + 1
        i += 1
    raise UpdateError("Fechamento do método não encontrado: " + name)


def patch_main(source, current):
    if header(source, "Plugin Name") != PLUGIN_NAME:
        raise UpdateError("O arquivo não pertence ao plugin SPSES esperado.")
    if not re.search(r"\bclass\s+ICD_SDM_Central\b", source):
        raise UpdateError("Classe principal ICD_SDM_Central não encontrada.")
    for marker in (
        "add_shortcode('central_documentos'",
        "'icd_sdm_central_options'",
        "assets/sdm-elementor.css",
        "assets/sdm-elementor.js",
    ):
        if marker not in source:
            raise UpdateError("Estrutura esperada não encontrada: " + marker)
    constants = re.findall(
        r"(?m)^    const VERSION = ['\"]([^'\"]+)['\"];[ \t]*$", source
    )
    if constants != [current]:
        raise UpdateError("Cabeçalho e constante VERSION estão divergentes.")
    start, end = method_span(source, "boot")
    boot = source[start:end]
    counts = []
    for label, pattern in HOOK_RULES:
        matches = list(re.finditer(pattern, boot))
        if len(matches) > 1:
            raise UpdateError("Registro duplicado: " + label)
        boot, count = re.subn(pattern, "", boot)
        counts.append((label, count))
    # Falha conservadora se houver uma variante desconhecida desses callbacks.
    unknown_callback = re.search(
        r"""array\(\s*__CLASS__\s*,\s*['"](?:menu|settings|action_links)['"]\s*\)""", boot
    )
    if unknown_callback:
        raise UpdateError("Registro administrativo em formato não previsto; nada será gravado.")
    if re.search(
        r"""(?m)^[ \t]*add_(?:action|filter)\(\s*['"](?:admin_menu|admin_init|plugin_action_links_)""",
        boot,
    ):
        raise UpdateError("Hook administrativo remanescente em boot().")
    result = source[:start] + boot + source[end:]
    result, count = re.subn(
        r"(?m)^([ \t]*\*[ \t]*Version:[ \t]*).*$",
        lambda match: match.group(1) + TARGET_VERSION, result,
    )
    if count != 1:
        raise UpdateError("Cabeçalho Version inesperado.")
    result, count = re.subn(
        r"(?m)^    const VERSION = ['\"][^'\"]+['\"];[ \t]*$",
        "    const VERSION = '" + TARGET_VERSION + "';", result,
    )
    if count != 1:
        raise UpdateError("Constante VERSION inesperada.")
    return result, counts


def update_changelog(original):
    if original is None:
        return ("# Changelog\n\n" + CHANGELOG_ENTRY + "\n").encode("utf-8")
    source = text(original, CHANGELOG_FILE)
    marker = "sdm-elementor:2.1.1:start"
    heading = re.search(r"(?m)^##?\s+\[?2\.1\.1\]?(?:\s|$)", source)
    if marker in source or heading:
        if source.count(CHANGELOG_ENTRY) != 1:
            raise UpdateError("Entrada 2.1.1 do changelog difere desta atualização.")
        return original
    if source.startswith("# "):
        end = source.find("\n")
        if end == -1:
            source += "\n"
            end = len(source) - 1
        updated = source[:end + 1] + "\n" + CHANGELOG_ENTRY + "\n" + source[end + 1:]
    else:
        updated = "# Changelog\n\n" + CHANGELOG_ENTRY + "\n\n" + source
    return encode_like(updated, original, CHANGELOG_FILE)


def prepare(files):
    missing = sorted(set(REQUIRED_FILES) - set(files))
    if missing:
        raise UpdateError("Arquivos obrigatórios ausentes: " + ", ".join(missing))
    if "central-documentos.php" in files:
        raise UpdateError("Há um arquivo principal antigo coexistindo com sdm-elementor.php.")
    source = text(files[MAIN_FILE], MAIN_FILE)
    current = header(source, "Version")
    if current not in {BASE_VERSION, TARGET_VERSION}:
        raise UpdateError("Versão incompatível: " + current + ". Aceitas: 2.1 e 2.1.1.")
    widget = text(files["includes/class-elementor-widget.php"], "widget")
    renderer = text(files["includes/class-tree-renderer.php"], "renderer")
    if "return 'icd_sdm_documents';" not in widget:
        raise UpdateError("Identificador esperado do widget não encontrado.")
    if "ICD_SDM_Tree_Renderer" not in renderer:
        raise UpdateError("Renderer esperado não encontrado.")
    updated, counts = patch_main(source, current)
    candidate = dict(files)
    candidate[MAIN_FILE] = encode_like(updated, files[MAIN_FILE], MAIN_FILE)
    candidate[CHANGELOG_FILE] = update_changelog(files.get(CHANGELOG_FILE))
    if current == TARGET_VERSION and candidate != files:
        raise UpdateError("A versão é 2.1.1, mas seu conteúdo difere desta revisão. Nada será gravado.")
    # Apenas o arquivo principal e o changelog podem mudar.
    changed = {
        name for name in set(files) | set(candidate)
        if files.get(name) != candidate.get(name)
    }
    if not changed.issubset({MAIN_FILE, CHANGELOG_FILE}):
        raise UpdateError("Alteração fora do escopo detectada.")
    verify_candidate(candidate)
    return candidate, current, counts


def verify_candidate(files):
    if not set(REQUIRED_FILES).issubset(files) or CHANGELOG_FILE not in files:
        raise UpdateError("Candidato incompleto.")
    source = text(files[MAIN_FILE], MAIN_FILE)
    if header(source, "Version") != TARGET_VERSION:
        raise UpdateError("Versão incorreta no candidato.")
    if header(source, "Plugin Name") != PLUGIN_NAME:
        raise UpdateError("Nome do plugin incorreto no candidato.")
    if "    const VERSION = '2.1.1';" not in source:
        raise UpdateError("Constante VERSION incorreta.")
    start, end = method_span(source, "boot")
    boot = source[start:end]
    for label, pattern in HOOK_RULES:
        if re.search(pattern, boot):
            raise UpdateError("Hook não removido: " + label)
    if text(files[CHANGELOG_FILE], CHANGELOG_FILE).count(CHANGELOG_ENTRY) != 1:
        raise UpdateError("Changelog incompleto ou duplicado.")


def validate(files):
    verify_candidate(files)
    for name, data in files.items():
        if name.lower().endswith(".json"):
            try:
                json.loads(data.decode("utf-8-sig"))
            except (UnicodeDecodeError, ValueError) as error:
                raise UpdateError("JSON inválido: " + name) from error
    with tempfile.TemporaryDirectory(prefix="sdm-elementor-validar-") as temporary:
        root = Path(temporary)
        for name, data in files.items():
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        for program, suffix, arguments in (
            ("php", ".php", ["-l"]), ("node", ".js", ["--check"])
        ):
            executable = shutil.which(program)
            if not executable:
                log("AVISO", program + " CLI ausente: validação ignorada, não aprovada.")
                continue
            checked = 0
            for name in sorted(files):
                if not name.lower().endswith(suffix):
                    continue
                try:
                    process = subprocess.run(
                        [executable] + arguments + [str(root / name)],
                        capture_output=True, text=True, errors="replace", timeout=45,
                    )
                except (OSError, subprocess.TimeoutExpired) as error:
                    raise UpdateError("Não foi possível validar " + name + ": " + str(error)) from error
                if process.returncode:
                    raise UpdateError(
                        "Sintaxe inválida em " + name + ":\n" + process.stdout + process.stderr
                    )
                checked += 1
            log("OK", program + ": sintaxe aprovada em " + str(checked) + " arquivo(s).")


def verify_zip(path, files):
    try:
        with zipfile.ZipFile(path) as archive:
            expected = {PACKAGE_NAME + "/" + name for name in files}
            names = archive.namelist()
            if not names or len(names) != len(set(names)) or set(names) != expected:
                raise UpdateError("Estrutura do ZIP não corresponde ao candidato.")
            if archive.testzip() is not None:
                raise UpdateError("ZIP contém arquivo corrompido.")
            for name, data in files.items():
                if excluded(Path(name)):
                    raise UpdateError("Arquivo proibido no ZIP: " + name)
                if archive.read(PACKAGE_NAME + "/" + name) != data:
                    raise UpdateError("Conteúdo divergente no ZIP: " + name)
    except (OSError, zipfile.BadZipFile, RuntimeError) as error:
        if isinstance(error, UpdateError):
            raise
        raise UpdateError("Falha ao conferir ZIP: " + str(error)) from error


def build_zip(path, files):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name in sorted(files):
            info = zipfile.ZipInfo(
                PACKAGE_NAME + "/" + name, date_time=(2026, 10, 15, 0, 0, 0)
            )
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, files[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    verify_zip(path, files)


def atomic_write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=".sdm-tmp-", dir=path.parent)
    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        mode = path.stat().st_mode & 0o777 if path.exists() else 0o644
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def run():
    APPLIED.clear()
    if sys.version_info < (3, 10):
        raise UpdateError("Use Python 3.10 ou superior; recomenda-se a versão estável atual.")
    log("INFO", "SPSES - Documentos para Download: 2.1 -> 2.1.1")
    log("INFO", "Projeto: " + str(PROJECT_DIR))
    log("INFO", "Backup: desativado. Relatório: somente terminal.")
    compile(SCRIPT_PATH.read_text(encoding="utf-8-sig"), str(SCRIPT_PATH), "exec")
    files = snapshot()
    candidate, current, counts = prepare(files)
    log("OK", "Estrutura e versão-base validadas: " + current)
    for label, count in counts:
        log("PREPARADO" if count else "JÁ REMOVIDO", label)
    validate(candidate)
    if ZIP_PATH.is_symlink():
        raise UpdateError("Destino do ZIP é um link simbólico.")
    existing_package = ZIP_PATH.exists()
    if existing_package:
        try:
            verify_zip(ZIP_PATH, candidate)
        except UpdateError as error:
            raise UpdateError(
                "Já existe ZIP 2.1.1 diferente ou inválido. Ele foi preservado; "
                "mova-o manualmente para continuar: " + str(ZIP_PATH)
            ) from error
        log("JÁ ATUALIZADO", "ZIP existente idêntico; será preservado.")
    temporary_zip = None
    try:
        if not existing_package:
            descriptor, name = tempfile.mkstemp(
                prefix=".sdm-elementor-", suffix=".zip", dir=OUTPUT_DIR
            )
            os.close(descriptor)
            temporary_zip = Path(name)
            build_zip(temporary_zip, candidate)
            log("OK", "Pacote temporário completo e verificado.")
        if snapshot() != files:
            raise UpdateError("O projeto mudou durante a preparação. Nenhuma gravação iniciada.")
        for name in candidate:
            if name not in files and (PROJECT_DIR / name).exists():
                raise UpdateError("Destino novo em conflito: " + name)
        for name in sorted(candidate):
            if files.get(name) == candidate[name]:
                continue
            atomic_write(PROJECT_DIR / name, candidate[name])
            APPLIED.append(name)
            log("ALTERADO", name)
        if snapshot() != candidate:
            raise UpdateError("Conferência dos arquivos locais divergiu do candidato.")
        if temporary_zip is not None:
            if ZIP_PATH.exists():
                raise UpdateError("O ZIP de destino apareceu durante a execução; não será sobrescrito.")
            os.replace(temporary_zip, ZIP_PATH)
            temporary_zip = None
        verify_zip(ZIP_PATH, candidate)
    finally:
        if temporary_zip is not None and temporary_zip.exists():
            temporary_zip.unlink()
    if not APPLIED:
        log("JÁ ATUALIZADO", "Nenhum arquivo precisou ser regravado.")
    log("SUCESSO", "Plugin na versão " + TARGET_VERSION + "; tela administrativa desregistrada.")
    log("ZIP", ZIP_PATH)
    log("INFO", "Widget Elementor, SDM, shortcode e opções existentes foram preservados.")
    log("AVISO", "Esta revisão não diagnostica o erro fatal anterior.")
    log("AVISO", "É necessário homologar no WordPress com Elementor e SDM reais.")


def main():
    code = 0
    arguments = set(sys.argv[1:])
    try:
        if arguments - {"--no-pause", "--help"}:
            raise UpdateError("Argumento desconhecido. Use --no-pause ou --help.")
        if "--help" in arguments:
            print(__doc__)
        else:
            run()
    except KeyboardInterrupt:
        code = 130
        log("CANCELADO", "Execução interrompida pelo usuário.")
    except Exception as error:
        code = 1
        log("ERRO", str(error))
    finally:
        if code:
            if APPLIED:
                log("ATENÇÃO", "Houve gravações antes da falha: " + ", ".join(APPLIED))
                log("ATENÇÃO", "Pode haver atualização parcial; não há backup/rollback automático.")
            else:
                log("INFO", "Nenhuma alteração nos arquivos do projeto foi aplicada.")
        if "--no-pause" not in arguments and "--help" not in arguments:
            try:
                input("\nPressione Enter para fechar...")
            except (EOFError, KeyboardInterrupt):
                pass
    return code


if __name__ == "__main__":
    sys.exit(main())
