#!/usr/bin/env bash
#MISE description="Stream raw LLM provider wire logs for a conversation (tail -f style)"
#USAGE arg "<conversation_uuid>" help="The conversation UUID to filter for"
#USAGE flag "--all" help="Show all wire log entries (no UUID filter)"
#USAGE flag "--lines <n>" default="50" help="Number of initial lines to show (default: 50)"

set -e

CONVERSATION_UUID="${usage_conversation_uuid:-}"
SHOW_ALL="${usage_all:-false}"
INITIAL_LINES="${usage_lines:-50}"
LOG_FILE="var/log/llm-wire.log"

if [ "${SHOW_ALL}" != "true" ] && [ -z "${CONVERSATION_UUID}" ]; then
    echo "Usage: mise run conversation-log <conversation_uuid>"
    echo "       mise run conversation-log --all"
    echo ""
    echo "Streams raw LLM provider API wire logs in real time."
    echo "Requires LLM_WIRE_LOG_ENABLED=1 in the app environment."
    exit 1
fi

echo "Streaming LLM wire logs from ${LOG_FILE}..."
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
