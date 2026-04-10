#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
# CloudiLMS — Daemon monitor de pasta de vídeos
#
# Monitora recursivamente a pasta de vídeos via inotify.
# Quando detecta criação, remoção ou renomeação de arquivos/pastas,
# aguarda DEBOUNCE segundos (para agrupar rajadas de eventos) e então
# executa generate_manifest.php para atualizar os manifestos.
#
# REQUISITOS:
#   sudo apt install inotify-tools   # Debian/Ubuntu
#   sudo yum install inotify-tools   # RHEL/CentOS
#
# USO:
#   bash watch_videos.sh             # foreground (testes)
#   Como serviço systemd: veja cloudilms-video-watcher.service
#
# VARIÁVEIS DE AMBIENTE (opcionais):
#   PHP_BIN=/usr/bin/php8.2    # caminho do PHP (padrão: auto-detectado)
#   DEBOUNCE=3                 # segundos de espera após último evento (padrão: 5)
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEBOUNCE="${DEBOUNCE:-5}"
TIMER_FILE="/tmp/cloudilms_manifest_regen_timer"
LOG_PREFIX="[cloudilms-video-watcher]"

# ── Detecta o PHP ────────────────────────────────────────────────────────────
if [ -n "${PHP_BIN:-}" ] && [ -x "$PHP_BIN" ]; then
    : # já definido via env
elif command -v php &>/dev/null; then
    PHP_BIN="$(command -v php)"
else
    # Tenta versões específicas
    for v in php8.3 php8.2 php8.1 php8.0 php7.4; do
        if command -v "$v" &>/dev/null; then
            PHP_BIN="$(command -v "$v")"
            break
        fi
    done
fi

if [ -z "${PHP_BIN:-}" ] || ! [ -x "$PHP_BIN" ]; then
    echo "$LOG_PREFIX ERRO: PHP não encontrado."
    echo "  Defina: export PHP_BIN=/usr/bin/php8.2"
    exit 1
fi

# ── Verifica dependências ────────────────────────────────────────────────────
if ! command -v inotifywait &>/dev/null; then
    echo "$LOG_PREFIX ERRO: inotifywait não encontrado."
    echo "  Instale com: sudo apt install inotify-tools"
    exit 1
fi

if [ ! -f "$SCRIPT_DIR/generate_manifest.php" ]; then
    echo "$LOG_PREFIX ERRO: generate_manifest.php não encontrado em $SCRIPT_DIR"
    exit 1
fi

# ── Função de regeneração ────────────────────────────────────────────────────
regen() {
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') Regenerando manifestos..."
    "$PHP_BIN" "$SCRIPT_DIR/generate_manifest.php" 2>&1 | sed "s/^/$LOG_PREFIX   /"
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') ✅ Concluído."
}

# ── Timer de debounce (em background) ───────────────────────────────────────
# Roda em segundo plano verificando a cada segundo se passou tempo suficiente
# desde o último evento antes de disparar a regeneração.
debounce_timer() {
    while true; do
        sleep 1
        if [ ! -f "$TIMER_FILE" ]; then
            continue
        fi
        LAST=$(cat "$TIMER_FILE" 2>/dev/null || echo 0)
        NOW=$(date +%s)
        DIFF=$((NOW - LAST))
        if [ "$DIFF" -ge "$DEBOUNCE" ]; then
            rm -f "$TIMER_FILE"
            regen
        fi
    done
}

# ── Início ───────────────────────────────────────────────────────────────────
echo "$LOG_PREFIX ───────────────────────────────────────────"
echo "$LOG_PREFIX CloudiLMS Video Manifest Watcher"
echo "$LOG_PREFIX   Pasta monitorada : $SCRIPT_DIR"
echo "$LOG_PREFIX   PHP              : $PHP_BIN ($($PHP_BIN -r 'echo PHP_VERSION;'))"
echo "$LOG_PREFIX   inotifywait      : $(command -v inotifywait)"
echo "$LOG_PREFIX   Debounce         : ${DEBOUNCE}s"
echo "$LOG_PREFIX ───────────────────────────────────────────"
echo ""

# Geração inicial
regen
echo ""

# Inicia o timer de debounce em background
debounce_timer &
TIMER_PID=$!
trap "kill $TIMER_PID 2>/dev/null; rm -f $TIMER_FILE; exit 0" SIGTERM SIGINT

echo "$LOG_PREFIX Aguardando mudanças na pasta..."
echo ""

# ── Loop principal de eventos ────────────────────────────────────────────────
inotifywait \
    --monitor \
    --recursive \
    --event create \
    --event delete \
    --event moved_to \
    --event moved_from \
    --format '%T %w%f %e' \
    --timefmt '%H:%M:%S' \
    --exclude '(manifest\.json|index\.json|\.php|\.sh|\.service|\.swp|~$)' \
    "$SCRIPT_DIR" 2>/dev/null \
| while IFS= read -r line; do
    echo "$LOG_PREFIX 📁 $line"
    # Registra timestamp do último evento (dispara debounce)
    date +%s > "$TIMER_FILE"
done

# Limpa se o inotifywait sair
kill $TIMER_PID 2>/dev/null
rm -f "$TIMER_FILE"
