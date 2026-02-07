#!/usr/bin/env bash
#MISE description="Stream LLM conversation logs for a conversation (tail -f style)"
#USAGE arg "<conversation_uuid>" help="The conversation UUID to filter for"
#USAGE flag "--all" help="Show all log entries (no UUID filter)"
#USAGE flag "-H --human" help="Human-readable mode (simplified, semantic-level output)"
#USAGE flag "--lines <n>" default="50" help="Number of initial lines to show (default: 50)"
#USAGE flag "--generate-viewer" help="Generate a self-contained HTML viewer file (wire log only)"

set -e

CONVERSATION_UUID="${usage_conversation_uuid:-}"
SHOW_ALL="${usage_all:-false}"
HUMAN_READABLE="${usage_human:-false}"
GENERATE_VIEWER="${usage_generate_viewer:-false}"
INITIAL_LINES="${usage_lines:-50}"

WIRE_LOG_FILE="var/log/llm-wire.log"
CONVERSATION_LOG_FILE="var/log/llm-conversation.log"

# ── Usage help ───────────────────────────────────────────────────

if [ "${GENERATE_VIEWER}" != "true" ] && [ "${SHOW_ALL}" != "true" ] && [ -z "${CONVERSATION_UUID}" ]; then
    echo "Usage: mise run conversation-log <conversation_uuid>"
    echo "       mise run conversation-log -H <conversation_uuid>"
    echo "       mise run conversation-log --generate-viewer <conversation_uuid>"
    echo "       mise run conversation-log --all"
    echo ""
    echo "Streams LLM provider API logs in real time."
    echo ""
    echo "Flags:"
    echo "  -H, --human          Human-readable mode (semantic events instead of raw HTTP)"
    echo "  --generate-viewer    Generate a self-contained HTML viewer file"
    echo "  --all                Show all entries (no UUID filter)"
    echo "  --lines <n>          Number of initial lines to show (default: 50)"
    echo ""
    echo "Requires LLM_WIRE_LOG_ENABLED=1 in the app environment."
    exit 1
fi

# ── Check that wire logging is enabled ───────────────────────────

WIRE_ENABLED=$(docker compose exec -T messenger php -r \
    'require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->loadEnv(".env"); echo $_ENV["LLM_WIRE_LOG_ENABLED"] ?? "0";' 2>/dev/null)

if [ "${WIRE_ENABLED}" != "1" ]; then
    echo "ERROR: LLM wire logging is not enabled in the messenger container."
    echo ""
    echo "  LLM_WIRE_LOG_ENABLED is currently '${WIRE_ENABLED}'."
    echo ""
    echo "  To enable it, ensure LLM_WIRE_LOG_ENABLED=1 is set in .env.dev"
    echo "  (or .env.local), then restart the messenger:"
    echo ""
    echo "    docker compose exec messenger php bin/console cache:clear"
    echo "    docker compose restart messenger"
    echo ""
    exit 1
fi

# ── Generate HTML viewer mode ────────────────────────────────────

if [ "${GENERATE_VIEWER}" == "true" ]; then
    if [ -z "${CONVERSATION_UUID}" ]; then
        echo "ERROR: --generate-viewer requires a conversation UUID."
        echo "Usage: mise run conversation-log --generate-viewer <conversation_uuid>"
        exit 1
    fi

    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    TEMPLATE="${SCRIPT_DIR}/conversation-log-viewer-template.html"

    if [ ! -f "${TEMPLATE}" ]; then
        echo "ERROR: Viewer template not found at ${TEMPLATE}"
        exit 1
    fi

    # Ensure the wire log file exists
    docker compose exec -T messenger touch "${WIRE_LOG_FILE}"

    # Extract all matching log lines (one-shot, not streaming)
    RAW_LOGS=$(docker compose exec -T messenger grep -F "${CONVERSATION_UUID}" "${WIRE_LOG_FILE}" 2>/dev/null || true)

    if [ -z "${RAW_LOGS}" ]; then
        echo "No wire log entries found for conversation ${CONVERSATION_UUID}."
        echo "Make sure LLM wire logging was enabled during the conversation."
        exit 1
    fi

    ENTRY_COUNT=$(echo "${RAW_LOGS}" | wc -l | tr -d ' ')
    OUTPUT_FILE="conversation-log-${CONVERSATION_UUID}.html"

    # Find the placeholder line number in the template
    PLACEHOLDER_LINE=$(grep -n '__LOG_DATA_B64__' "${TEMPLATE}" | head -1 | cut -d: -f1)

    # Build the HTML: head + base64 data + tail
    head -n $((PLACEHOLDER_LINE - 1)) "${TEMPLATE}" > "${OUTPUT_FILE}"
    printf '%s' "${RAW_LOGS}" | base64 >> "${OUTPUT_FILE}"
    tail -n +$((PLACEHOLDER_LINE + 1)) "${TEMPLATE}" >> "${OUTPUT_FILE}"

    echo "Generated ${OUTPUT_FILE}"
    echo "  ${ENTRY_COUNT} log entries for conversation ${CONVERSATION_UUID}"
    echo "  Open in any browser to explore."
    exit 0
fi

# ── Streaming mode ───────────────────────────────────────────────

if [ "${HUMAN_READABLE}" == "true" ]; then
    LOG_FILE="${CONVERSATION_LOG_FILE}"
    MODE_LABEL="human-readable conversation"
else
    LOG_FILE="${WIRE_LOG_FILE}"
    MODE_LABEL="raw wire"
fi

echo "Streaming ${MODE_LABEL} logs from ${LOG_FILE}..."
if [ "${SHOW_ALL}" == "true" ]; then
    echo "Filter: none (showing all entries)"
else
    echo "Filter: conversationId=${CONVERSATION_UUID}"
fi
echo "---"

# Ensure the log file exists so tail -F doesn't fail silently.
# The messenger container writes the log; both containers share the volume.
docker compose exec -T messenger touch "${LOG_FILE}"

if [ "${SHOW_ALL}" == "true" ]; then
    docker compose exec -T messenger tail -n "${INITIAL_LINES}" -F "${LOG_FILE}"
else
    docker compose exec -T messenger tail -n 1000 -F "${LOG_FILE}" | grep --line-buffered "${CONVERSATION_UUID}"
fi
