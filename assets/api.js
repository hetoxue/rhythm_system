// assets/api.js

const API_BASE = 'api.php'; // 与 index.html / user.html / admin.html 同目录

function showMessage(container, type, text) {
    if (!container) return;
    container.innerHTML = '';
    if (!text) return;
    const div = document.createElement('div');
    div.className = 'message ' + (type === 'error' ? 'message-error' : 'message-success');
    div.textContent = text;
    container.appendChild(div);
}

/**
 * 通用请求封装
 * @param {string} module
 * @param {string} action
 * @param {string} method 'GET' or 'POST'
 * @param {Object} data
 */
async function apiRequest(module, action, method = 'POST', data = {}) {
    const url = `${API_BASE}?module=${encodeURIComponent(module)}&action=${encodeURIComponent(action)}`;
    let finalUrl = url;
    if (method === 'GET' && Object.keys(data).length > 0) {
        finalUrl += '&' + new URLSearchParams(data).toString();
    }
    const options = {
        method,
        credentials: 'include', // 携带 cookie，使用 PHP Session
        headers: {}
    };
    if (method === 'POST') {
        options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
        options.body = new URLSearchParams(data).toString();
    }
    const resp = await fetch(finalUrl, options);
    const json = await resp.json();
    if (json.code !== 0) {
        const err = new Error(json.message || '请求失败');
        err.code = json.code;
        err.status = resp.status;
        throw err;
    }
    return json.data;
}

// 工具：格式化金额（分 -> 元）
function formatAmount(amount) {
    if (amount == null) return '-';
    const v = Number(amount) / 100;
    return v.toFixed(2);
}

// 工具：格式化时间
function formatDateTime(str) {
    if (!str) return '-';
    return str.replace('T', ' ').substring(0, 19);
}