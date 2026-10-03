// engine/run_task.js
require('dotenv').config({ path: __dirname + '/../.env' });
const mysql = require('mysql2/promise');
const crypto = require('crypto');
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const {
    analyzeScreenAndGetAction,
    analyzeAfterLogin,
    answerFromContext,
} = require('./gemini_healer');

const taskId = process.argv[2];
if (!taskId) { console.error("No Task ID."); process.exit(1); }

let SCREENSHOTS_DIR = process.env.SCREENSHOTS_DIR || path.join(__dirname, '..', 'storage', 'screenshots');
let SCREENSHOTS_URL = (process.env.SCREENSHOTS_URL || '/storage/screenshots').replace(/\/+$/, '');

try {
    if (!fs.existsSync(SCREENSHOTS_DIR)) fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
    const t = path.join(SCREENSHOTS_DIR, '.write_test');
    fs.writeFileSync(t, 'ok'); fs.unlinkSync(t);
} catch (e) {
    console.error(`⚠️ Cannot use ${SCREENSHOTS_DIR}: ${e.message}`);
    SCREENSHOTS_DIR = path.join(__dirname, '..', 'storage', 'screenshots');
    if (!fs.existsSync(SCREENSHOTS_DIR)) fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

const MAX_STEPS = 20;
let connection;
let browser;

async function log(type, message, imagePath = null) {
    try {
        await connection.execute(
            'INSERT INTO task_logs (task_id, log_type, message, image_path) VALUES (?, ?, ?, ?)',
            [taskId, type, message, imagePath]
        );
    } catch (e) { console.error('Log fail:', e.message); }
}

async function saveScreenshot(page, label = 'state') {
    const filename = `task_${taskId}_${Date.now()}.png`;
    const fullPath = path.join(SCREENSHOTS_DIR, filename);
    try {
        if (!fs.existsSync(SCREENSHOTS_DIR)) fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
        await page.screenshot({ path: fullPath, type: 'png', fullPage: false });
        if (!fs.existsSync(fullPath)) return null;
        const size = fs.statSync(fullPath).size;
        if (size < 100) return null;
        const webPath = `${SCREENSHOTS_URL}/${filename}`;
        await log('screenshot', label, webPath);
        return webPath;
    } catch (e) { return null; }
}

async function findWorkingSelector(page, rawSelectors) {
    const candidates = Array.isArray(rawSelectors)
        ? rawSelectors
        : String(rawSelectors || '').split(',').map(s => s.trim()).filter(Boolean);

    for (const sel of candidates) {
        if (/:contains\(|:has-text\(|:text\(/i.test(sel)) continue;
        if (sel.startsWith('//') || /^xpath=/i.test(sel)) continue;
        try {
            const el = await page.$(sel);
            if (el) return sel;
        } catch (_) {}
    }
    return null;
}

async function findByText(page, text) {
    if (!text) return null;
    return await page.evaluate((txt) => {
        const tags = 'a, button, [role="button"], input[type="submit"], input[type="button"], .btn, [onclick], li, span';
        const els = [...document.querySelectorAll(tags)];
        const lower = txt.toLowerCase().trim();
        const hit = els.find(el => {
            const elText = (el.textContent || el.value || '').trim().toLowerCase();
            return elText === lower || (elText.length < 100 && elText.includes(lower));
        });
        if (hit) {
            hit.setAttribute('data-adaptica-hit', '1');
            return '[data-adaptica-hit="1"]';
        }
        return null;
    }, text);
}

async function extractPageContext(page) {
    try {
        const meta = await page.evaluate(() => {
            const main = document.querySelector('main, #content, .content, .dashboard, #app, [role="main"]') || document.body;
            const text = (main.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 14000);
            const tables = [...document.querySelectorAll('table')].slice(0, 8).map(t =>
                [...t.querySelectorAll('tr')].slice(0, 30).map(r =>
                    [...r.children].map(c => c.innerText.trim()).join(' | ')
                ).join('\n')
            );
            const kpis = [...document.querySelectorAll('[class*="stat" i], [class*="metric" i], [class*="kpi" i], [class*="card" i]')]
                .slice(0, 40)
                .map(el => el.innerText.trim().replace(/\s+/g, ' '))
                .filter(s => s.length > 4 && s.length < 250);
            return {
                title: document.title,
                url: window.location.href,
                text, tables, kpis,
                headings: [...document.querySelectorAll('h1, h2, h3')].slice(0, 25).map(h => h.innerText.trim()),
            };
        });
        const combined = [
            `URL: ${meta.url}`, `TITLE: ${meta.title}`,
            `HEADINGS: ${meta.headings.join(' | ')}`,
            `KEY METRICS:\n${meta.kpis.join('\n')}`,
            `PAGE TEXT:\n${meta.text}`,
            meta.tables.length ? `TABLES:\n${meta.tables.join('\n\n')}` : '',
        ].filter(Boolean).join('\n\n');
        return { meta, combined };
    } catch (e) { return { meta: {}, combined: '' }; }
}

/* ------------------------------------------------------------------ */
/* MAIN — full autonomous                                          */
/* ------------------------------------------------------------------ */
async function runAutomation() {
    let collectedAnswer = null;
    let finalContext = '';

    try {
        connection = await mysql.createConnection({
            host: process.env.DB_HOST || '127.0.0.1',
            user: process.env.DB_USER || 'root',
            password: process.env.DB_PASSWORD || '',
            database: process.env.DB_NAME || 'ai_sentinel',
        });

        const [rows] = await connection.execute('SELECT * FROM automation_tasks WHERE id = ?', [taskId]);
        if (rows.length === 0) throw new Error(`Task ${taskId} not found.`);
        const task = rows[0];

        await connection.execute(
            'UPDATE automation_tasks SET status=?, started_at=COALESCE(started_at, NOW()) WHERE id=?',
            ['running', taskId]
        );
        await log('status', `🚀 Task started: ${task.task_name}`);

        // Decrypt password
        const decodedPayload = Buffer.from(task.encrypted_password, 'base64').toString('utf8');
        const [encryptedBase64, ivBase64] = decodedPayload.split('::');
        const iv = Buffer.from(ivBase64, 'base64');
        const secretKey = crypto.createHash('sha256').update(process.env.ADAPTICA_SECRET_KEY).digest().slice(0, 32);
        const decipher = crypto.createDecipheriv('aes-256-cbc', secretKey, iv);
        let decryptedPassword = decipher.update(encryptedBase64, 'base64', 'utf8');
        decryptedPassword += decipher.final('utf8');

        console.log(`\n🤖 ${task.task_name}`);
        console.log(`🎯 Goal: ${task.action_intent}`);

        await log('status', '🌐 Launching browser...');
        browser = await puppeteer.launch({
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox'],
            defaultViewport: { width: 1280, height: 800 },
        });
        const page = await browser.newPage();

        await log('status', `📍 Navigating to ${task.target_url}`);
        await page.goto(task.target_url, { waitUntil: 'networkidle2', timeout: 45000 });
        await saveScreenshot(page, 'Initial page');

        /* ============ LOGIN PHASE ============ */
        const loginShot = await page.screenshot({ encoding: 'base64' });
        const loginPlan = await analyzeScreenAndGetAction(loginShot, task.action_intent, task.target_url, 4, log);

        if (!loginPlan) throw new Error('AI failed to analyze initial screen.');

        if (loginPlan.action_type === 'login') {
            await log('status', '⌨️ Locating login form fields...');
            const userSel = await findWorkingSelector(page, loginPlan.selectors.username_field);
            const passSel = await findWorkingSelector(page, loginPlan.selectors.password_field);
            const btnSel  = await findWorkingSelector(page, loginPlan.selectors.submit_button);

            if (!userSel || !passSel || !btnSel) throw new Error('Could not find all login fields.');
            await log('action', `Login selectors → ${userSel} | ${passSel} | ${btnSel}`);

            await page.click(userSel, { clickCount: 3 });
            await page.type(userSel, task.login_id);
            await page.click(passSel, { clickCount: 3 });
            await page.type(passSel, decryptedPassword);
            await saveScreenshot(page, 'Credentials filled');

            await log('action', '🖱️ Submitting login…');
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
                page.click(btnSel),
            ]);
            await new Promise(r => setTimeout(r, 2500));
            await saveScreenshot(page, 'After login');

            const afterUrl = page.url();
            if (afterUrl.toLowerCase().includes('login') || afterUrl.toLowerCase().includes('signin')) {
                throw new Error('Login failed — still on login page.');
            }
            await log('result', `✅ Logged in — ${afterUrl}`);
        } else {
            await log('status', 'No login required. Proceeding.');
        }

        /* ============ AUTONOMOUS ACTION LOOP ============ */
        await log('status', '🧭 Planning next actions…');

        const actionHistory = [];

        for (let step = 1; step <= MAX_STEPS; step++) {
            const ctx = await extractPageContext(page);
            finalContext = ctx.combined;

            const shot = await page.screenshot({ encoding: 'base64' });
            const decision = await analyzeAfterLogin({
                screenshotBase64: shot,
                currentUrl: page.url(),
                pageContext: ctx.combined,
                goal: task.action_intent,
                history: actionHistory,
                logger: log,
            });

            await log('status', `Step ${step}: AI chose "${decision.action}"`);

            /* ---- ANSWER ---- */
            if (decision.action === 'answer') {
                collectedAnswer = decision.answer;
                await log('result', `✅ ${collectedAnswer}`);
                break;
            }

            /* ---- GIVEUP ---- */
            if (decision.action === 'giveup') {
                await log('error', `AI gave up: ${decision.reasoning || 'unknown'}`);
                break;
            }

            /* ---- VERIFY / DONE ---- */
            if (decision.action === 'verify') {
                collectedAnswer = `✅ Completed: ${decision.expect || 'action chain finished'}`;
                await log('result', collectedAnswer);
                break;
            }

            /* ---- WAIT ---- */
            if (decision.action === 'wait') {
                const secs = Math.min(decision.seconds || 2, 10);
                await log('action', `⏳ Waiting ${secs}s…`);
                await new Promise(r => setTimeout(r, secs * 1000));
                actionHistory.push(`Waited ${secs}s`);
                continue;
            }

            /* ---- FILL ---- */
            if (decision.action === 'fill') {
                let sel = await findWorkingSelector(page, decision.selectors);
                if (!sel) sel = await findByText(page, decision.text_match);
                if (!sel) {
                    await log('error', `No selector found for fill.`);
                    actionHistory.push(`FAILED to find fill target`);
                    continue;
                }
                await log('action', `⌨️ Typing "${decision.value}" into ${sel}`);
                await page.click(sel, { clickCount: 3 });
                await page.type(sel, String(decision.value));
                await new Promise(r => setTimeout(r, 800));
                await saveScreenshot(page, `Step ${step}: filled`);
                actionHistory.push(`Filled "${decision.value}" into ${sel}`);
                continue;
            }

            /* ---- CLICK ---- */
            if (decision.action === 'click') {
                let sel = await findWorkingSelector(page, decision.selectors);
                if (!sel) sel = await findByText(page, decision.text_match);
                if (!sel) {
                    await log('error', `Could not find click target: ${decision.text_match || (decision.selectors||[]).join(' | ')}`);
                    actionHistory.push(`FAILED to find click target`);
                    continue;
                }
                await log('action', `🖱️ Clicking ${sel}${decision.text_match ? ` ("${decision.text_match}")` : ''}`);
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 8000 }).catch(() => {}),
                    page.click(sel).catch(() => {}),
                ]);
                await new Promise(r => setTimeout(r, 2000));
                await saveScreenshot(page, `Step ${step}: after click`);
                actionHistory.push(`Clicked ${sel}`);
                continue;
            }

            /* ---- PRESS ---- */
            if (decision.action === 'press') {
                await log('action', `⌨️ Pressing ${decision.key}`);
                await page.keyboard.press(decision.key);
                await new Promise(r => setTimeout(r, 1500));
                await saveScreenshot(page, `Step ${step}: pressed ${decision.key}`);
                actionHistory.push(`Pressed ${decision.key}`);
                continue;
            }

            /* ---- GOTO ---- */
            if (decision.action === 'goto' && decision.url) {
                await log('action', `🌐 Navigating to ${decision.url}`);
                await page.goto(decision.url, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
                await new Promise(r => setTimeout(r, 2000));
                await saveScreenshot(page, `Step ${step}: navigated`);
                actionHistory.push(`Navigated to ${decision.url}`);
                continue;
            }

            await log('error', `Unknown action: ${decision.action}`);
            break;
        }

        /* ============ FALLBACK ANSWER ============ */
        if (!collectedAnswer && finalContext) {
            await log('status', '📝 Generating final answer from page content…');
            collectedAnswer = await answerFromContext(finalContext, task.action_intent, task.action_intent, log);
        }

        /* ============ SAVE RESULT ============ */
        const finalShot = await saveScreenshot(page, 'Final state');
        const summary = collectedAnswer || 'Task completed — no explicit answer extracted.';

        await connection.execute(
            `UPDATE automation_tasks
             SET status='completed', result_summary=?, finished_at=NOW(),
                 page_context=?, page_screenshot=?, approval_state='none', pending_action=NULL
             WHERE id=?`,
            [summary.slice(0, 4000), finalContext.slice(0, 60000), finalShot, taskId]
        );

        await log('result', '🏁 Task complete.');
        console.log(`🎉 Task ${taskId} done.`);

    } catch (error) {
        console.error(`❌ Failed:`, error.message);
        try {
            await log('error', `❌ ${error.message}`);
            if (connection) {
                await connection.execute(
                    'UPDATE automation_tasks SET status=?, result_summary=?, finished_at=NOW() WHERE id=?',
                    ['failed', error.message.slice(0, 500), taskId]
                );
            }
        } catch (_) {}
    } finally {
        if (browser) await browser.close();
        if (connection) await connection.end();
    }
}

runAutomation();