// engine/gemini_healer.js
const { GoogleGenAI } = require('@google/genai');

const ai = new GoogleGenAI({ apiKey: process.env.GEMINI_API_KEY });

const MODEL_CHAIN = ['gemini-3.5-flash-lite', 'gemini-3.8-flash'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function isTransient(error) {
    const status = error?.status ?? error?.code ?? error?.response?.status;
    if (status === 429 || status === 503 || status === 500) return true;
    const msg = String(error?.message ?? '').toUpperCase();
    return msg.includes('UNAVAILABLE') || msg.includes('OVERLOADED') ||
           msg.includes('RESOURCE_EXHAUSTED') || msg.includes('RATE LIMIT') ||
           msg.includes('503') || msg.includes('429');
}

function readText(response) {
    if (typeof response?.text === 'function') return response.text();
    return response?.text ?? '';
}

function sanitizeSelectorList(raw) {
    if (!raw) return [];
    return String(raw).split(',').map(s => s.trim()).filter(s => {
        if (!s) return false;
        if (/:contains\(|:has-text\(|:text\(/i.test(s)) return false;
        if (s.startsWith('//') || /^xpath=/i.test(s)) return false;
        return true;
    });
}

async function callGemini(parts, { json = false, logger = null } = {}) {
    const log = async (t, m) => { if (logger) { try { await logger(t, m); } catch(_){} } };
    let lastErr = null;

    for (let attempt = 1; attempt <= 4; attempt++) {
        const model = MODEL_CHAIN[Math.min(attempt - 1, MODEL_CHAIN.length - 1)];
        try {
            await log('status', `Calling ${model} (attempt ${attempt}/4)…`);
            const config = { maxOutputTokens: 2048 };
            if (json) config.responseMimeType = 'application/json';
            const response = await ai.models.generateContent({ model, contents: parts, config });
            const raw = readText(response);
            if (!raw) throw new Error('Empty response');
            return raw;
        } catch (error) {
            lastErr = error;
            await log('error', `Attempt ${attempt}: ${error.message.slice(0, 180)}`);
            if (attempt === 4) break;
            const delay = isTransient(error) ? 1000 * 2 ** (attempt - 1) + Math.random() * 1000 : 1500;
            await sleep(delay);
        }
    }
    console.error('❌ Gemini failed:', lastErr?.message);
    return null;
}

/* ------------------------------------------------------------------ */
/* STEP 1: Initial screen analysis (login OR skip)                     */
/* ------------------------------------------------------------------ */
async function analyzeScreenAndGetAction(screenshotBase64, actionIntent, url, maxRetries = 4, logger = null) {
    const prompt = `
You are an autonomous web automation agent.
Website: ${url}
User's goal: "${actionIntent}"

Look at the screenshot. Decide the FIRST action:

If it's a login page → return:
{
  "action_type": "login",
  "selectors": {
    "username_field": "css, fallback, selectors",
    "password_field": "css, fallback, selectors",
    "submit_button": "css, fallback, selectors"
  },
  "reasoning": "..."
}

If no login is needed (already logged in or public site) → return:
{
  "action_type": "skip_login",
  "reasoning": "No login form visible"
}

⚠️ VALID CSS SELECTORS ONLY. No :contains, no :has-text, no XPath.
Return JSON only.`;

    const raw = await callGemini([
        prompt,
        { inlineData: { data: screenshotBase64, mimeType: 'image/png' } },
    ], { json: true, logger });

    if (!raw) return null;
    try {
        const parsed = JSON.parse(raw);
        if (parsed.selectors) {
            for (const k of Object.keys(parsed.selectors)) {
                parsed.selectors[k] = sanitizeSelectorList(parsed.selectors[k]).join(', ');
            }
        }
        if (parsed.reasoning && logger) await logger('reasoning', parsed.reasoning);
        return parsed;
    } catch (e) {
        if (logger) await logger('error', 'Bad JSON: ' + raw.slice(0, 200));
        return null;
    }
}

/* ------------------------------------------------------------------ */
/* STEP 2: Plan the next action                                        */
/* ------------------------------------------------------------------ */
async function analyzeAfterLogin({ screenshotBase64, currentUrl, pageContext, goal, history = [], logger = null }) {
    const historyText = history.length
        ? `ACTIONS ALREADY DONE:\n${history.map((h, i) => `  ${i+1}. ${h}`).join('\n')}\n`
        : 'No actions taken yet.\n';

    const prompt = `
You are an autonomous web automation agent. You can do ANYTHING on a website.

CURRENT URL: ${currentUrl}
USER'S GOAL: "${goal}"

${historyText}

PAGE TEXT (extracted):
"""
${(pageContext || '').slice(0, 6000)}
"""

Look at the screenshot and the page text. Decide the NEXT single action.

Return STRICT JSON in ONE of these forms:

1) GOAL ACHIEVED — you have the answer/confirmation:
{ "action": "answer", "answer": "...", "reasoning": "..." }

2) CLICK something (button, link, menu item, icon):
{ "action": "click", "selectors": ["css1","css2"], "text_match": "optional visible text", "reasoning": "..." }

3) TYPE into a field:
{ "action": "fill", "selectors": ["css1","css2"], "value": "text to type", "reasoning": "..." }

4) PRESS a key:
{ "action": "press", "key": "Enter", "reasoning": "..." }

5) NAVIGATE directly:
{ "action": "goto", "url": "https://...", "reasoning": "..." }

6) WAIT for something to load:
{ "action": "wait", "seconds": 2, "reasoning": "..." }

7) DONE with an action chain:
{ "action": "verify", "expect": "user was deleted", "reasoning": "..." }

8) CANNOT PROCEED:
{ "action": "giveup", "reasoning": "..." }

⚠️ CRITICAL RULES:
- Use VALID CSS SELECTORS ONLY (no :contains, no :has-text, no XPath).
- Provide 2-4 fallback selectors in the array.
- Include "text_match" for buttons/links where you know the visible label.
- Adapt to ANY UI: bootstrap, tailwind, material, custom.
- If you see a search box and the goal is to find something, fill it first, then click search or press Enter.
- Break multi-step tasks into single actions. Don't try to do everything at once.

Return JSON only, no markdown.`;

    const raw = await callGemini([
        prompt,
        { inlineData: { data: screenshotBase64, mimeType: 'image/png' } },
    ], { json: true, logger });

    if (!raw) return { action: 'giveup', reasoning: 'AI unavailable' };
    try {
        const parsed = JSON.parse(raw);
        if (parsed.selectors && Array.isArray(parsed.selectors)) {
            parsed.selectors = parsed.selectors.flatMap(s => sanitizeSelectorList(s)).filter(Boolean);
        }
        if (parsed.reasoning && logger) await logger('reasoning', parsed.reasoning);
        return parsed;
    } catch (e) {
        if (logger) await logger('error', 'Bad JSON: ' + raw.slice(0, 200));
        return { action: 'giveup', reasoning: 'Parse failure' };
    }
}

/* ------------------------------------------------------------------ */
/* STEP 3: Answer a question from page context                         */
/* ------------------------------------------------------------------ */
async function answerFromContext(pageContext, question, goal, logger = null) {
    const prompt = `You are answering a question about a webpage an automation bot just visited.

ORIGINAL GOAL: "${goal}"
QUESTION: "${question}"

PAGE CONTENT:
"""
${(pageContext || '').slice(0, 8000)}
"""

Answer based ONLY on the page content. Quote real values. If the answer isn't there, say so.`;
    const raw = await callGemini([prompt], { json: false, logger });
    return raw || 'Could not generate an answer.';
}

module.exports = {
    analyzeScreenAndGetAction,
    analyzeAfterLogin,
    answerFromContext,
    callGemini,
};
