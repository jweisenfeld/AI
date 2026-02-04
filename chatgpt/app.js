/*
  Sample ChatGPT Classroom Chatbot
  --------------------------------
  This file intentionally separates UI code from logic so we can unit-test
  the logic in tests.js without calling the real API.
*/

const CHAT_STORAGE_KEY = 'sample-chatgpt-history-v1';

/**
 * Return a sanitized string the UI can safely display.
 * This is a light escape since we only insert as textContent.
 */
function sanitizeUserText(text) {
  return String(text || '').trim();
}

/**
 * Build the request payload expected by chat_api.php.
 */
function buildRequestPayload({ systemMessage, messages, model, temperature, maxTokens }) {
  return {
    model,
    temperature,
    max_output_tokens: maxTokens,
    system: sanitizeUserText(systemMessage),
    messages: messages.map((message) => ({
      role: message.role,
      content: sanitizeUserText(message.content),
    })),
  };
}

/**
 * Save the chat transcript to localStorage so it reloads on refresh.
 */
function saveTranscript(messages) {
  localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages));
}

/**
 * Load the transcript from localStorage.
 */
function loadTranscript() {
  const raw = localStorage.getItem(CHAT_STORAGE_KEY);
  if (!raw) {
    return [];
  }

  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    return [];
  }
}

/**
 * Create a message object in the format we want to store locally.
 */
function createMessage(role, content) {
  return {
    role,
    content: sanitizeUserText(content),
    timestamp: new Date().toISOString(),
  };
}

/**
 * Render chat messages into the log container.
 */
function renderMessages(messages, chatLog) {
  chatLog.innerHTML = '';
  messages.forEach((message) => {
    const bubble = document.createElement('div');
    bubble.className = `bubble ${message.role}`;

    const header = document.createElement('div');
    header.className = 'bubble-header';
    header.textContent = `${message.role.toUpperCase()} · ${new Date(message.timestamp).toLocaleTimeString()}`;

    const body = document.createElement('div');
    body.className = 'bubble-body';
    body.textContent = message.content;

    bubble.append(header, body);
    chatLog.appendChild(bubble);
  });
  chatLog.scrollTop = chatLog.scrollHeight;
}

/**
 * Update the status message below the chat window.
 */
function setStatus(element, message) {
  element.textContent = message;
}

/**
 * Post a chat payload to the PHP endpoint and return the assistant reply.
 */
async function sendChatRequest(payload) {
  const response = await fetch('chat_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`API error (${response.status}): ${errorText}`);
  }

  const data = await response.json();
  return data.reply;
}

/**
 * Initialize the UI behavior.
 */
function initChatbot() {
  const chatLog = document.getElementById('chatLog');
  const chatForm = document.getElementById('chatForm');
  const userMessage = document.getElementById('userMessage');
  const status = document.getElementById('status');
  const clearButton = document.getElementById('clearChat');
  const themeToggle = document.getElementById('themeToggle');

  const modelInput = document.getElementById('model');
  const temperatureInput = document.getElementById('temperature');
  const maxTokensInput = document.getElementById('maxTokens');
  const systemMessageInput = document.getElementById('systemMessage');

  let messages = loadTranscript();
  renderMessages(messages, chatLog);

  themeToggle.addEventListener('click', () => {
    document.body.classList.toggle('dark');
  });

  clearButton.addEventListener('click', () => {
    messages = [];
    saveTranscript(messages);
    renderMessages(messages, chatLog);
    setStatus(status, 'Chat cleared.');
  });

  chatForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const content = sanitizeUserText(userMessage.value);
    if (!content) {
      setStatus(status, 'Please type a message first.');
      return;
    }

    const userEntry = createMessage('user', content);
    messages.push(userEntry);
    renderMessages(messages, chatLog);
    saveTranscript(messages);

    userMessage.value = '';
    setStatus(status, 'Sending...');

    const payload = buildRequestPayload({
      systemMessage: systemMessageInput.value,
      messages,
      model: modelInput.value,
      temperature: Number(temperatureInput.value),
      maxTokens: Number(maxTokensInput.value),
    });

    try {
      const replyText = await sendChatRequest(payload);
      const assistantEntry = createMessage('assistant', replyText);
      messages.push(assistantEntry);
      renderMessages(messages, chatLog);
      saveTranscript(messages);
      setStatus(status, 'Response received.');
    } catch (error) {
      setStatus(status, error.message);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initChatbot);
} else {
  initChatbot();
}

// Export functions for unit tests.
window.ChatbotUtils = {
  sanitizeUserText,
  buildRequestPayload,
  saveTranscript,
  loadTranscript,
  createMessage,
};
