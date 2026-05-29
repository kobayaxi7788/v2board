<!DOCTYPE html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>订阅风控 - {{ $title }}</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f6f7f9;
      --panel: #ffffff;
      --line: #d9dee6;
      --text: #17202a;
      --muted: #687386;
      --primary: #1769e0;
      --danger: #c93535;
      --warning: #9a6700;
      --success: #237a42;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: 14px;
    }

    header {
      border-bottom: 1px solid var(--line);
      background: var(--panel);
      padding: 18px 24px;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    main {
      padding: 20px 24px 48px;
    }

    h1,
    h2,
    h3 {
      margin: 0;
      letter-spacing: 0;
    }

    h1 {
      font-size: 22px;
    }

    h2 {
      font-size: 16px;
      margin-bottom: 12px;
    }

    h3 {
      font-size: 14px;
      margin-bottom: 10px;
    }

    a {
      color: var(--primary);
      text-decoration: none;
    }

    .topbar {
      align-items: center;
      display: flex;
      gap: 16px;
      justify-content: space-between;
    }

    .muted {
      color: var(--muted);
    }

    .grid {
      display: grid;
      gap: 14px;
    }

    .stats {
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      margin-bottom: 18px;
    }

    .columns {
      grid-template-columns: minmax(0, 1fr);
    }

    @media (min-width: 1100px) {
      .columns.two {
        grid-template-columns: minmax(360px, 0.7fr) minmax(0, 1.3fr);
      }
    }

    .panel,
    .stat {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
    }

    .panel {
      margin-bottom: 16px;
      padding: 16px;
    }

    .stat {
      padding: 14px;
    }

    .stat-label {
      color: var(--muted);
      font-size: 12px;
    }

    .stat-value {
      font-size: 24px;
      font-weight: 700;
      margin-top: 6px;
    }

    .toolbar,
    .form-grid {
      align-items: end;
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      margin-bottom: 12px;
    }

    label {
      color: var(--muted);
      display: block;
      font-size: 12px;
      margin-bottom: 5px;
    }

    input,
    select,
    textarea,
    button {
      font: inherit;
    }

    input,
    select,
    textarea {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 6px;
      color: var(--text);
      min-height: 36px;
      padding: 8px 10px;
      width: 100%;
    }

    textarea {
      min-height: 76px;
      resize: vertical;
    }

    button {
      align-items: center;
      border: 1px solid transparent;
      border-radius: 6px;
      cursor: pointer;
      display: inline-flex;
      gap: 6px;
      justify-content: center;
      min-height: 36px;
      padding: 7px 12px;
      white-space: nowrap;
    }

    button.primary {
      background: var(--primary);
      color: #fff;
    }

    button.secondary {
      background: #fff;
      border-color: var(--line);
      color: var(--text);
    }

    button.warning {
      background: #fff4d6;
      border-color: #e6c35f;
      color: var(--warning);
    }

    button.danger {
      background: #fff0f0;
      border-color: #e3a3a3;
      color: var(--danger);
    }

    button:disabled {
      cursor: not-allowed;
      opacity: 0.55;
    }

    .table-wrap {
      border: 1px solid var(--line);
      border-radius: 8px;
      overflow: auto;
    }

    table {
      border-collapse: collapse;
      min-width: 760px;
      width: 100%;
    }

    th,
    td {
      border-bottom: 1px solid var(--line);
      padding: 9px 10px;
      text-align: left;
      vertical-align: top;
    }

    th {
      background: #f0f3f7;
      color: var(--muted);
      font-size: 12px;
      font-weight: 600;
      position: sticky;
      top: 0;
    }

    tr:last-child td {
      border-bottom: 0;
    }

    .badge {
      border-radius: 999px;
      display: inline-block;
      font-size: 12px;
      line-height: 1;
      padding: 5px 8px;
    }

    .badge.high,
    .badge.blocked {
      background: #fde8e8;
      color: var(--danger);
    }

    .badge.medium,
    .badge.watching {
      background: #fff4d6;
      color: var(--warning);
    }

    .badge.low,
    .badge.enabled {
      background: #e9f7ef;
      color: var(--success);
    }

    .badge.disabled,
    .badge.released {
      background: #eef1f5;
      color: var(--muted);
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .message {
      border-radius: 6px;
      display: none;
      margin-top: 10px;
      padding: 10px 12px;
    }

    .message.show {
      display: block;
    }

    .message.ok {
      background: #e9f7ef;
      color: var(--success);
    }

    .message.err {
      background: #fde8e8;
      color: var(--danger);
    }

    .tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 12px;
    }

    .tab-panel {
      display: none;
    }

    .tab-panel.active {
      display: block;
    }
  </style>
</head>

<body>
  <header>
    <div class="topbar">
      <div>
        <h1>订阅风控</h1>
        <div class="muted">后台路径：/{{ $secure_path }}/subscription-control</div>
      </div>
      <div class="actions">
        <a href="/{{ $secure_path }}">返回管理后台</a>
        <button class="secondary" id="reload-all">刷新</button>
      </div>
    </div>
    <div id="message" class="message"></div>
  </header>

  <main>
    <section class="grid stats" id="stats"></section>

    <section class="panel">
      <div class="topbar">
        <h2>高危订阅用户分析</h2>
        <button class="secondary" id="export-risk">导出 CSV</button>
      </div>
      <div class="toolbar">
        <div>
          <label>统计窗口</label>
          <select id="risk-hours">
            <option value="24">24小时</option>
            <option value="6">6小时</option>
            <option value="72">72小时</option>
            <option value="168">7天</option>
          </select>
        </div>
        <div>
          <label>最低分</label>
          <input id="risk-min-score" type="number" min="0" max="100" value="0">
        </div>
        <div>
          <label>批量 SS2022 域名</label>
          <input id="batch-ss2022-domain" placeholder="ss2022.example.com">
        </div>
        <div>
          <label>批量 AnyTLS 域名</label>
          <input id="batch-anytls-domain" placeholder="anytls.example.com">
        </div>
        <div>
          <label>AnyTLS SNI</label>
          <input id="batch-anytls-sni" placeholder="默认同域名">
        </div>
        <button class="primary" id="reload-risk">查询</button>
        <button class="warning" id="batch-block">批量加入封控</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>选择</th>
              <th>用户</th>
              <th>请求</th>
              <th>来源</th>
              <th>5分钟峰值</th>
              <th>风险</th>
              <th>标签</th>
              <th>策略</th>
              <th>最后请求</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="risk-body"></tbody>
        </table>
      </div>
    </section>

    <section class="grid columns two">
      <div class="panel">
        <h2>用户策略</h2>
        <div class="form-grid">
          <div>
            <label>用户 ID</label>
            <input id="policy-user-id" type="number">
          </div>
          <div>
            <label>状态</label>
            <select id="policy-status">
              <option value="blocked">封控</option>
              <option value="watching">观察</option>
              <option value="released">解除</option>
            </select>
          </div>
          <div>
            <label>SS2022 域名</label>
            <input id="policy-ss2022-domain">
          </div>
          <div>
            <label>AnyTLS 域名</label>
            <input id="policy-anytls-domain">
          </div>
          <div>
            <label>AnyTLS SNI</label>
            <input id="policy-anytls-sni">
          </div>
          <div>
            <label>启用</label>
            <select id="policy-enabled">
              <option value="1">启用</option>
              <option value="0">停用</option>
            </select>
          </div>
        </div>
        <div>
          <label>原因</label>
          <textarea id="policy-reason"></textarea>
        </div>
        <div class="actions" style="margin-top: 10px;">
          <button class="primary" id="save-policy">保存用户策略</button>
          <button class="secondary" id="reset-policy-form">清空</button>
        </div>
      </div>

      <div class="panel">
        <div class="topbar">
          <h2>封控用户列表</h2>
          <button class="secondary" id="reload-policies">刷新</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>用户</th>
                <th>状态</th>
                <th>入口</th>
                <th>原因</th>
                <th>更新时间</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody id="policy-body"></tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="topbar">
        <h2>规则配置</h2>
        <div class="tabs">
          <button class="secondary" data-tab="region">地区</button>
          <button class="secondary" data-tab="ip">IP/CIDR</button>
          <button class="secondary" data-tab="ua">UA</button>
        </div>
      </div>

      <div id="tab-region" class="tab-panel active">
        <h3>地区关键词规则</h3>
        <div class="form-grid">
          <input id="region-id" type="hidden">
          <div><label>名称</label><input id="region-name"></div>
          <div><label>关键词</label><input id="region-keywords" placeholder="辽宁 联通"></div>
          <div><label>匹配方式</label><select id="region-match-mode"><option value="all">全部命中</option><option value="any">任一命中</option></select></div>
          <div><label>SS2022 域名</label><input id="region-ss2022-domain"></div>
          <div><label>AnyTLS 域名</label><input id="region-anytls-domain"></div>
          <div><label>AnyTLS SNI</label><input id="region-anytls-sni"></div>
          <div><label>优先级</label><input id="region-priority" type="number" value="100"></div>
          <div><label>启用</label><select id="region-enabled"><option value="1">启用</option><option value="0">停用</option></select></div>
          <button class="primary" id="save-region">保存地区规则</button>
          <button class="secondary" data-reset-rule="region">清空</button>
        </div>
        <div class="table-wrap"><table><thead><tr><th>优先级</th><th>规则</th><th>匹配</th><th>入口</th><th>状态</th><th>操作</th></tr></thead><tbody id="region-body"></tbody></table></div>
      </div>

      <div id="tab-ip" class="tab-panel">
        <h3>IP/CIDR 规则</h3>
        <div class="form-grid">
          <input id="ip-id" type="hidden">
          <div><label>名称</label><input id="ip-name"></div>
          <div><label>IP/CIDR</label><textarea id="ip-rule-value" placeholder="每行一个，例如：&#10;119.113.134.12&#10;119.113.134.0/24"></textarea></div>
          <div><label>SS2022 域名</label><input id="ip-ss2022-domain"></div>
          <div><label>AnyTLS 域名</label><input id="ip-anytls-domain"></div>
          <div><label>AnyTLS SNI</label><input id="ip-anytls-sni"></div>
          <div><label>优先级</label><input id="ip-priority" type="number" value="80"></div>
          <div><label>启用</label><select id="ip-enabled"><option value="1">启用</option><option value="0">停用</option></select></div>
          <button class="primary" id="save-ip">保存 IP 规则</button>
          <button class="secondary" data-reset-rule="ip">清空</button>
        </div>
        <div class="table-wrap"><table><thead><tr><th>优先级</th><th>规则</th><th>类型</th><th>入口</th><th>状态</th><th>操作</th></tr></thead><tbody id="ip-body"></tbody></table></div>
      </div>

      <div id="tab-ua" class="tab-panel">
        <h3>UA 关键词规则</h3>
        <div class="form-grid">
          <input id="ua-id" type="hidden">
          <div><label>名称</label><input id="ua-name"></div>
          <div><label>关键词</label><input id="ua-keywords" placeholder="curl python go-http-client"></div>
          <div><label>匹配方式</label><select id="ua-match-mode"><option value="any">任一命中</option><option value="all">全部命中</option></select></div>
          <div><label>SS2022 域名</label><input id="ua-ss2022-domain"></div>
          <div><label>AnyTLS 域名</label><input id="ua-anytls-domain"></div>
          <div><label>AnyTLS SNI</label><input id="ua-anytls-sni"></div>
          <div><label>优先级</label><input id="ua-priority" type="number" value="90"></div>
          <div><label>启用</label><select id="ua-enabled"><option value="1">启用</option><option value="0">停用</option></select></div>
          <button class="primary" id="save-ua">保存 UA 规则</button>
          <button class="secondary" data-reset-rule="ua">清空</button>
        </div>
        <div class="table-wrap"><table><thead><tr><th>优先级</th><th>规则</th><th>匹配</th><th>入口</th><th>状态</th><th>操作</th></tr></thead><tbody id="ua-body"></tbody></table></div>
      </div>
    </section>

    <section class="grid columns two">
      <div class="panel">
        <h2>策略命中测试</h2>
        <div class="form-grid">
          <div><label>IP</label><input id="test-ip" placeholder="119.113.134.12"></div>
          <div><label>用户 ID</label><input id="test-user-id" type="number" placeholder="可选"></div>
        </div>
        <div><label>UA</label><textarea id="test-user-agent" placeholder="curl/8.0 或 Clash/xxx"></textarea></div>
        <div class="actions" style="margin-top: 10px;"><button class="primary" id="test-match">测试匹配</button></div>
        <pre id="test-result" class="muted"></pre>
      </div>

      <div class="panel">
        <div class="topbar">
          <h2>策略命中记录</h2>
          <button class="secondary" id="reload-hits">刷新</button>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>时间</th><th>用户</th><th>策略</th><th>IP归属</th><th>替换类型</th><th>订阅类型</th></tr></thead>
            <tbody id="hit-body"></tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  <script>
    const SECURE_PATH = @json($secure_path);
    const API_BASE = `/api/v1/${SECURE_PATH}/subscription-control`;
    const state = { policies: [], region: [], ip: [], ua: [], loading: false };

    const $ = (id) => document.getElementById(id);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    const nl = (value) => esc(value).replace(/\n/g, '<br>');

    function token() {
      const raw = localStorage.getItem('authorization') || localStorage.getItem('XBOARD_ACCESS_TOKEN') || localStorage.getItem('access_token');
      if (!raw) return '';
      try {
        const parsed = JSON.parse(raw);
        return parsed.value || parsed.access_token || parsed.token || '';
      } catch {
        return raw;
      }
    }

    function showMessage(text, ok = true) {
      const el = $('message');
      el.textContent = text;
      el.className = `message show ${ok ? 'ok' : 'err'}`;
      clearTimeout(showMessage.timer);
      showMessage.timer = setTimeout(() => el.className = 'message', 4200);
    }

    async function api(path, options = {}) {
      const auth = token();
      if (!auth) throw new Error('未检测到后台登录凭据，请先在 V2Board 管理后台登录');
      const authorization = auth.toLowerCase().startsWith('bearer ') ? auth.slice(7) : auth;
      const headers = { 'Accept': 'application/json', 'Authorization': authorization, ...(options.headers || {}) };
      if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
      const res = await fetch(`${API_BASE}${path}`, { ...options, headers });
      if (!res.ok) {
        const text = await res.text();
        let message = `请求失败：${res.status}`;
        try {
          const json = JSON.parse(text);
          message = json.message || json.error || message;
        } catch {
          if (text) message = text.slice(0, 160);
        }
        throw new Error(message);
      }
      const json = await res.json();
      if (json.status === 'fail') throw new Error(json.message || '操作失败');
      return json.data ?? json;
    }

    function qs(params) {
      const sp = new URLSearchParams();
      Object.entries(params).forEach(([k, v]) => {
        if (v !== '' && v !== null && v !== undefined) sp.set(k, v);
      });
      return sp.toString();
    }

    async function loadStats() {
      const data = await api('/stats');
      const items = [
        ['今日订阅', data.today_subscribe_count],
        ['高危用户', data.high_risk_count],
        ['封控用户', data.active_user_policy_count],
        ['地区规则', data.region_rule_count],
        ['IP规则', data.ip_rule_count],
        ['UA规则', data.ua_rule_count],
        ['今日命中', data.today_hit_count],
        ['处理模式', '人工'],
      ];
      $('stats').innerHTML = items.map(([label, value]) => `<div class="stat"><div class="stat-label">${esc(label)}</div><div class="stat-value">${esc(value)}</div></div>`).join('');
    }

    async function loadRisk() {
      const query = qs({ hours: $('risk-hours').value, min_score: $('risk-min-score').value });
      const rows = await api(`/risk-users?${query}`);
      $('risk-body').innerHTML = rows.map((row) => `
        <tr>
          <td><input type="checkbox" class="risk-check" value="${row.user_id}"></td>
          <td><strong>#${row.user_id}</strong><br>${esc(row.email || '-')}</td>
          <td>${row.request_count}</td>
          <td>IP ${row.ip_count} / 地区 ${row.location_count} / UA ${row.ua_count}</td>
          <td>${row.max_5m}</td>
          <td><span class="badge ${row.risk_level}">${row.risk_score} / ${esc(row.risk_level_text)}</span></td>
          <td>${esc(row.risk_tags)}</td>
          <td>${row.policy_id ? `<span class="badge ${esc(row.policy_status)}">${esc(row.policy_status_text || row.policy_status)}</span>` : '<span class="badge disabled">未处理</span>'}</td>
          <td>${esc(row.last_time || '-')}</td>
          <td><button class="warning" data-policy-user="${row.user_id}">封控</button></td>
        </tr>`).join('');
    }

    async function loadPolicies() {
      state.policies = await api('/user-policies');
      $('policy-body').innerHTML = state.policies.map((row) => `
        <tr>
          <td>${row.id}</td>
          <td>#${row.user_id}<br>${esc(row.email || '-')}</td>
          <td><span class="badge ${row.enabled ? row.status : 'disabled'}">${esc(row.enabled ? row.status_text : '停用')}</span></td>
          <td>ss2022: ${esc(row.ss2022_domain || '-')}<br>anytls: ${esc(row.anytls_domain || '-')}<br>sni: ${esc(row.anytls_sni || '-')}</td>
          <td>${esc(row.reason || '-')}</td>
          <td>${esc(row.updated_at || '-')}</td>
          <td><div class="actions"><button class="secondary" data-edit-policy="${row.id}">编辑</button><button class="danger" data-release-policy="${row.id}">解除</button></div></td>
        </tr>`).join('');
    }

    function fillPolicy(row = {}) {
      $('policy-user-id').value = row.user_id || '';
      $('policy-status').value = row.status || 'blocked';
      $('policy-ss2022-domain').value = row.ss2022_domain || '';
      $('policy-anytls-domain').value = row.anytls_domain || '';
      $('policy-anytls-sni').value = row.anytls_sni || '';
      $('policy-enabled').value = row.enabled === 0 ? '0' : '1';
      $('policy-reason').value = row.reason || '';
    }

    async function savePolicy() {
      await api('/user-policy', {
        method: 'POST',
        body: JSON.stringify({
          user_id: $('policy-user-id').value,
          status: $('policy-status').value,
          ss2022_domain: $('policy-ss2022-domain').value,
          anytls_domain: $('policy-anytls-domain').value,
          anytls_sni: $('policy-anytls-sni').value,
          enabled: $('policy-enabled').value === '1',
          reason: $('policy-reason').value,
        })
      });
      showMessage('用户策略已保存');
      await Promise.all([loadRisk(), loadPolicies(), loadStats()]);
    }

    async function batchBlock() {
      const ids = [...document.querySelectorAll('.risk-check:checked')].map((el) => el.value);
      if (!ids.length) return showMessage('请先勾选高危用户', false);
      await api('/user-policy/batch', {
        method: 'POST',
        body: JSON.stringify({
          user_ids: ids,
          ss2022_domain: $('batch-ss2022-domain').value,
          anytls_domain: $('batch-anytls-domain').value,
          anytls_sni: $('batch-anytls-sni').value,
          reason: '批量高危订阅封控',
        })
      });
      showMessage('批量封控已保存');
      await Promise.all([loadRisk(), loadPolicies(), loadStats()]);
    }

    function rulePayload(type) {
      const base = {
        id: $(`${type}-id`).value,
        name: $(`${type}-name`).value,
        ss2022_domain: $(`${type}-ss2022-domain`).value,
        anytls_domain: $(`${type}-anytls-domain`).value,
        anytls_sni: $(`${type}-anytls-sni`).value,
        priority: $(`${type}-priority`).value,
        enabled: $(`${type}-enabled`).value === '1',
      };
      if (type === 'ip') return { ...base, rule_value: $('ip-rule-value').value };
      return { ...base, keywords: $(`${type}-keywords`).value, match_mode: $(`${type}-match-mode`).value };
    }

    function fillRule(type, row = {}) {
      $(`${type}-id`).value = row.id || '';
      $(`${type}-name`).value = row.name || '';
      if (type === 'ip') $('ip-rule-value').value = row.rule_value || '';
      else {
        $(`${type}-keywords`).value = row.keywords || '';
        $(`${type}-match-mode`).value = row.match_mode || (type === 'region' ? 'all' : 'any');
      }
      $(`${type}-ss2022-domain`).value = row.ss2022_domain || '';
      $(`${type}-anytls-domain`).value = row.anytls_domain || '';
      $(`${type}-anytls-sni`).value = row.anytls_sni || '';
      $(`${type}-priority`).value = row.priority || (type === 'ip' ? 80 : type === 'ua' ? 90 : 100);
      $(`${type}-enabled`).value = row.enabled === 0 ? '0' : '1';
    }

    function renderKeywordRows(type, rows) {
      $(`${type}-body`).innerHTML = rows.map((row) => `
        <tr>
          <td>${row.priority}</td>
          <td>${esc(row.name)}<br><span class="muted">${esc(row.keywords)}</span></td>
          <td>${esc(row.match_mode_text)}</td>
          <td>ss2022: ${esc(row.ss2022_domain || '-')}<br>anytls: ${esc(row.anytls_domain || '-')}<br>sni: ${esc(row.anytls_sni || '-')}</td>
          <td><span class="badge ${row.enabled ? 'enabled' : 'disabled'}">${row.enabled ? '启用' : '停用'}</span></td>
          <td><div class="actions"><button class="secondary" data-edit-${type}="${row.id}">编辑</button><button class="danger" data-delete-${type}="${row.id}">删除</button></div></td>
        </tr>`).join('');
    }

    function renderIpRows(rows) {
      $('ip-body').innerHTML = rows.map((row) => `
        <tr>
          <td>${row.priority}</td>
          <td>${esc(row.name)}<br><span class="muted">${esc(row.rule_value)}</span></td>
          <td>${esc(row.rule_type_text)}<br><span class="muted">IPv${row.ip_version}</span></td>
          <td>ss2022: ${esc(row.ss2022_domain || '-')}<br>anytls: ${esc(row.anytls_domain || '-')}<br>sni: ${esc(row.anytls_sni || '-')}</td>
          <td><span class="badge ${row.enabled ? 'enabled' : 'disabled'}">${row.enabled ? '启用' : '停用'}</span></td>
          <td><div class="actions"><button class="secondary" data-edit-ip="${row.id}">编辑</button><button class="danger" data-delete-ip="${row.id}">删除</button></div></td>
        </tr>`).join('');
    }

    async function loadRules(type) {
      state[type] = await api(`/${type}-rules`);
      if (type === 'ip') renderIpRows(state.ip);
      else renderKeywordRows(type, state[type]);
    }

    async function saveRule(type) {
      const endpoint = type === 'region' ? '/region-rule' : type === 'ip' ? '/ip-rule' : '/ua-rule';
      await api(endpoint, { method: 'POST', body: JSON.stringify(rulePayload(type)) });
      showMessage('规则已保存');
      fillRule(type);
      await Promise.all([loadRules(type), loadStats()]);
    }

    async function deleteRule(type, id) {
      if (!confirm('确认删除该规则？')) return;
      const endpoint = type === 'region' ? '/region-rule/delete' : type === 'ip' ? '/ip-rule/delete' : '/ua-rule/delete';
      await api(endpoint, { method: 'POST', body: JSON.stringify({ id }) });
      showMessage('规则已删除');
      await Promise.all([loadRules(type), loadStats()]);
    }

    async function loadHits() {
      const rows = await api('/hit-logs');
      $('hit-body').innerHTML = rows.map((row) => `
        <tr>
          <td>${esc(row.created_at || '-')}</td>
          <td>#${row.user_id}<br>${esc(row.email || '-')}</td>
          <td>${esc(row.policy_type_text)} #${row.policy_id}</td>
          <td>${esc(row.request_ip)}<br><span class="muted">${esc(row.ip_location)}</span></td>
          <td>${esc(row.replaced_types)}</td>
          <td>${esc(row.subscribe_type)}</td>
        </tr>`).join('');
    }

    async function testMatch() {
      const data = await api('/test-match', {
        method: 'POST',
        body: JSON.stringify({
          ip: $('test-ip').value,
          user_id: $('test-user-id').value || null,
          user_agent: $('test-user-agent').value,
        })
      });
      $('test-result').textContent = JSON.stringify(data, null, 2);
    }

    async function exportRisk() {
      const auth = token();
      if (!auth) throw new Error('未检测到后台登录凭据，请先在 V2Board 管理后台登录');
      const authorization = auth.toLowerCase().startsWith('bearer ') ? auth.slice(7) : auth;
      const query = qs({ hours: $('risk-hours').value, min_score: $('risk-min-score').value });
      const res = await fetch(`${API_BASE}/export-risk?${query}`, { headers: { 'Authorization': authorization } });
      if (!res.ok) throw new Error(`导出失败：${res.status}`);
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `subscription-risk-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    }

    async function reloadAll() {
      try {
        await Promise.all([loadStats(), loadRisk(), loadPolicies(), loadRules('region'), loadRules('ip'), loadRules('ua'), loadHits()]);
        showMessage('数据已刷新');
      } catch (err) {
        showMessage(err.message, false);
      }
    }

    document.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      try {
        if (target.dataset.policyUser) fillPolicy({ user_id: target.dataset.policyUser, status: 'blocked', enabled: 1, reason: '订阅异常封控' });
        if (target.dataset.editPolicy) fillPolicy(state.policies.find((row) => String(row.id) === target.dataset.editPolicy));
        if (target.dataset.releasePolicy && confirm('确认解除该用户订阅封控？')) {
          await api('/user-policy/release', { method: 'POST', body: JSON.stringify({ id: target.dataset.releasePolicy }) });
          showMessage('已解除用户策略');
          await Promise.all([loadRisk(), loadPolicies(), loadStats()]);
        }
        for (const type of ['region', 'ip', 'ua']) {
          if (target.dataset[`edit${type[0].toUpperCase()}${type.slice(1)}`]) {
            const id = target.dataset[`edit${type[0].toUpperCase()}${type.slice(1)}`];
            fillRule(type, state[type].find((row) => String(row.id) === id));
          }
          if (target.dataset[`delete${type[0].toUpperCase()}${type.slice(1)}`]) {
            await deleteRule(type, target.dataset[`delete${type[0].toUpperCase()}${type.slice(1)}`]);
          }
        }
        if (target.dataset.tab) {
          document.querySelectorAll('.tab-panel').forEach((el) => el.classList.remove('active'));
          $(`tab-${target.dataset.tab}`).classList.add('active');
        }
        if (target.dataset.resetRule) fillRule(target.dataset.resetRule);
      } catch (err) {
        showMessage(err.message, false);
      }
    });

    $('reload-all').addEventListener('click', reloadAll);
    $('reload-risk').addEventListener('click', () => loadRisk().catch((err) => showMessage(err.message, false)));
    $('reload-policies').addEventListener('click', () => loadPolicies().catch((err) => showMessage(err.message, false)));
    $('reload-hits').addEventListener('click', () => loadHits().catch((err) => showMessage(err.message, false)));
    $('save-policy').addEventListener('click', () => savePolicy().catch((err) => showMessage(err.message, false)));
    $('reset-policy-form').addEventListener('click', () => fillPolicy());
    $('batch-block').addEventListener('click', () => batchBlock().catch((err) => showMessage(err.message, false)));
    $('save-region').addEventListener('click', () => saveRule('region').catch((err) => showMessage(err.message, false)));
    $('save-ip').addEventListener('click', () => saveRule('ip').catch((err) => showMessage(err.message, false)));
    $('save-ua').addEventListener('click', () => saveRule('ua').catch((err) => showMessage(err.message, false)));
    $('test-match').addEventListener('click', () => testMatch().catch((err) => showMessage(err.message, false)));
    $('export-risk').addEventListener('click', () => exportRisk().catch((err) => showMessage(err.message, false)));

    reloadAll();
  </script>
</body>

</html>
