'use strict';

/* ================================================================
 * app.js — API 工作台前端（原生 JS，无框架、无 CDN、无构建）
 * 分区：工具函数 / 状态 / 数据层 / 渲染层 / 事件层 / 轮询 / 初始化
 * XSS 防护：所有外部文本（.http 内容、API 响应）一律经 textContent /
 * createTextNode 渲染，禁止把外部文本 innerHTML 直接插入。
 * ================================================================ */

/* ---------------------- 工具函数 ---------------------- */

/** 安全创建元素：attrs.class 为类名，on* 为事件，其余为属性。 */
function el(tag, attrs, ...children) {
  const node = document.createElement(tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined) continue;
      if (k === 'class') node.className = v;
      else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v);
    }
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined) continue;
    node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
  }
  return node;
}

/** 方法徽章：GET 绿 / POST 橙 / PUT 蓝 / DELETE 红 / 其他灰。 */
function methodBadge(method) {
  const m = String(method || 'other').toLowerCase();
  const known = ['get', 'post', 'put', 'delete', 'patch', 'options', 'head'];
  const cls = known.includes(m) ? m : 'other';
  return el('span', { class: 'badge ' + cls }, method || 'OTHER');
}

function shortText(s, n) {
  const str = String(s);
  return str.length <= n ? str : str.slice(0, n) + '…';
}

/** 递归收集某请求的所有祖先 key（文件 rel、文件::分组）。 */
function collectAncestors(tree, reqId) {
  const files = [];
  const groups = [];
  for (const f of (tree.files || [])) {
    for (const g of (f.groups || [])) {
      for (const r of (g.requests || [])) {
        if (r.id === reqId) {
          files.push(f.rel);
          groups.push(f.rel + '::' + g.name);
          return { files, groups };
        }
      }
    }
  }
  return { files, groups };
}

/* ---------------------- 状态 ---------------------- */

const state = {
  tree: null,          // api=tree 响应
  vars: null,          // api=vars 响应
  varsSource: '',      // 变量来源文件
  env: '',             // 当前环境名（'' 表示默认，仅 $shared）；localStorage 记忆
  envLoading: false,   // 环境切换中（重拉变量表）
  selectedId: null,    // 当前选中请求 id
  expandedFiles: new Set(),   // 展开的文件
  expandedGroups: new Set(),  // 展开的分组（key: rel::group）
  varExpanded: new Set(),     // 展开显示的长变量
  varsError: null,            // vars 加载失败时的错误提示（只影响变量面板）
  searchText: '',      // 搜索关键词
  lastVersion: null,   // 上次 tree version（轮询对比）
  tryIt: null,         // Try it out 面板状态（见 ensureTryIt）
};

/* ---------------------- 数据层 ---------------------- */

async function fetchTree() {
  const res = await fetch('index.php?api=tree');
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

async function fetchVars(env) {
  const url = env ? 'index.php?api=vars&env=' + encodeURIComponent(env) : 'index.php?api=vars';
  const res = await fetch(url);
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

/** {{var}} -> 真实值（与后端 Env::interpolate 语义一致：未知变量保留原文）。 */
function interpolate(text, vars) {
  const table = vars || mergedVarsForSelected();
  return String(text == null ? '' : text).replace(/\{\{([^{}]*)\}\}/g, (m, p1) => {
    const key = String(p1).trim();
    if (key === '' || table[key] === undefined) return m;
    const v = table[key];
    if (v === null) return '';
    if (typeof v === 'object') { try { return JSON.stringify(v); } catch (e) { return m; } }
    return String(v);
  });
}

/** .vscode 环境变量表（$shared + 当前 env 覆盖）。 */
function envVars() {
  return (state.vars && state.vars.vars) || {};
}

/** 某文件节点的文件级变量表（api=tree 的 files[].vars），没有则为 {}。 */
function fileVarsOf(fileRel) {
  if (!state.tree || !fileRel) return {};
  for (const f of (state.tree.files || [])) {
    if (f.rel === fileRel) return f.vars || {};
  }
  return {};
}

/**
 * 合并变量表：文件级 @var 作底，环境变量（用户在界面选择的）覆盖同名的键。
 * 与后端 FileVars::merge 语义一致 —— 用户界面选择优先于文件里写死的值。
 */
function mergedVars(fileRel) {
  return Object.assign({}, fileVarsOf(fileRel), envVars());
}

/** 当前选中请求所在文件的合并变量表。 */
function mergedVarsForSelected() {
  const found = state.selectedId ? findRequest(state.selectedId) : null;
  return mergedVars(found ? found.fileRel : null);
}

/** 按请求 id 取合并变量表。 */
function mergedVarsForRequest(reqId) {
  const found = findRequest(reqId);
  return mergedVars(found ? found.fileRel : null);
}

/** 当前选中请求所在文件的相对路径（无选中时 null）。 */
function selectedFileRel() {
  const found = state.selectedId ? findRequest(state.selectedId) : null;
  return found ? found.fileRel : null;
}

/* ---------------------- 渲染层 ---------------------- */

function renderApp() {
  const app = document.getElementById('app');
  app.textContent = '';

  const header = el('div', { class: 'header' },
    el('div', { class: 'title' }, 'API 工作台'),
    el('div', { class: 'stats', id: 'stats' }),
    el('div', { class: 'spacer' }),
    renderEnvSelect(),
    el('div', { class: 'search-wrap' },
      el('input', {
        id: 'search-input',
        type: 'text',
        placeholder: '搜索名称、method、路径、varName...',
        value: state.searchText,
        oninput: onSearchInput,
      }),
    ),
    el('button', { onclick: onRefresh, title: '手动刷新树' }, '刷新'),
    el('button', {
      id: 'theme-toggle',
      onclick: onThemeToggle,
      title: '切换浅色/深色主题',
    }, document.documentElement.dataset.theme === 'dark' ? '☀ 浅色' : '☾ 深色'),
    el('div', {
      class: 'root-tip',
      id: 'root-tip',
      title: state.tree ? state.tree.root : '',
    }, state.tree ? '📁 ' + state.tree.root : ''),
  );
  app.appendChild(header);

  // 错误横幅（首次加载失败 / 轮询失败时显示，轮询继续）
  app.appendChild(el('div', { id: 'error-banner', class: 'error-banner', style: 'display:none;' }));

  app.appendChild(el('div', { class: 'body' },
    el('div', { class: 'sidebar' },
      el('div', { class: 'sidebar-header' }, '接口导航'),
      el('div', { id: 'nav-tree' }),
    ),
    el('div', { class: 'main' },
      el('div', { id: 'detail' }),
    ),
  ));

  renderNavTree();
  renderDetail();
}

/**
 * 顶部全局环境下拉：环境名来自 api=vars 的 environments，切换后重拉变量表并整页重渲染。
 * 环境是「后端概念」——前端只传名字，变量解析与请求发送都由 PHP 完成。
 */
function renderEnvSelect() {
  const envs = (state.vars && state.vars.environments) || [];
  const select = el('select', {
    id: 'env-select',
    class: 'env-select',
    title: '环境（来自 .vscode/settings.json 的 rest-client.environmentVariables）',
    onchange: (e) => onEnvChange(e.target.value),
  }, el('option', { value: '' }, '默认（$shared）'));
  for (const name of envs) {
    const opt = el('option', { value: name }, name);
    if (name === state.env) opt.selected = true;
    select.appendChild(opt);
  }
  if (state.envLoading) select.disabled = true;
  return el('div', { class: 'env-wrap' },
    el('span', { class: 'env-label' }, '环境'),
    select,
    state.envLoading ? el('span', { class: 'muted' }, '切换中...') : null,
  );
}

/** 环境切换：重拉 api=vars&env=<名称>，按新变量重算并整页重渲染。 */
async function onEnvChange(env) {
  if (state.env === env) return;
  state.env = env;
  state.envLoading = true;
  persistEnv();
  renderApp();
  try {
    const data = await fetchVars(env);
    state.vars = data;
    state.varsSource = data.source || '';
    state.varsError = null;
  } catch (e) {
    state.varsError = '变量加载失败: ' + e.message;
  }
  state.envLoading = false;
  // Try it out 面板按新变量重算（URL/请求头模板不变，仅展示与发送参数更新）
  syncTryItWithEnv();
  renderApp();
  renderNavTree();
  renderDetail();
}

/** 环境名写入 localStorage，刷新后保持。 */
function persistEnv() {
  try { localStorage.setItem(ENV_KEY, state.env); } catch (e) { /* 隐私模式忽略 */ }
}

function renderStats() {
  const statsEl = document.getElementById('stats');
  if (!statsEl) return;
  if (!state.tree) { statsEl.textContent = '加载中...'; return; }
  let reqCount = 0;
  for (const f of (state.tree.files ||[])) {
    for (const g of (f.groups || [])) reqCount += (g.requests || []).length;
  }
  const fileCount = (state.tree.files || []).length;
  statsEl.textContent = '文件 ' + fileCount + ' / 接口 ' + reqCount;
}

function renderRootTip() {
  const tip = document.getElementById('root-tip');
  if (tip && state.tree) {
    tip.textContent = '📁 ' + state.tree.root;
    tip.title = state.tree.root;
  }
}

function matchesSearch(req) {
  const q = state.searchText.toLowerCase().trim();
  if (!q) return true;
  return [req.name, req.method, req.path, req.url, req.varName]
    .some((v) => String(v || '').toLowerCase().includes(q));
}

function renderNavTree() {
  const container = document.getElementById('nav-tree');
  if (!container) return;
  container.textContent = '';

  if (!state.tree) {
    container.appendChild(el('div', { class: 'empty' }, '加载中...'));
    return;
  }

  const files = state.tree.files || [];
  if (files.length === 0) {
    container.appendChild(el('div', { class: 'empty' }, '未找到任何 .http 文件'));
    return;
  }

  const searching = !!state.searchText.trim();
  let visibleCount = 0;

  for (const file of files) {
    const groups = file.groups || [];
    let totalReqs = 0;
    for (const g of groups) totalReqs += (g.requests || []).length;

    // 文件级过滤：搜索时仅当有命中请求才显示
    if (searching && !groups.some((g) => (g.requests || []).some(matchesSearch))) continue;
    visibleCount++;

    const fileExpanded = searching || state.expandedFiles.has(file.rel);
    const childrenEl = el('div', { class: 'tree-children' + (fileExpanded ? '' : ' collapsed') });

    for (const group of groups) {
      const reqs = (group.requests || []).filter((r) => !searching || matchesSearch(r));
      if (searching && reqs.length === 0) continue;

      const gKey = file.rel + '::' + group.name;
      const gExpanded = searching || state.expandedGroups.has(gKey);
      const gChildren = el('div', { class: 'tree-children' + (gExpanded ? '' : ' collapsed') });

      for (const req of reqs) {
        const isActive = state.selectedId === req.id;
        gChildren.appendChild(el('div', {
          class: 'req-node' + (isActive ? ' active' : ''),
          'data-id': req.id,
          onclick: () => onReqClick(req.id),
        },
          methodBadge(req.method),
          el('span', { class: 'req-name', title: req.varName || req.name || req.path },
            req.name || req.path || req.varName || '(未命名)'),
        ));
      }

      const gToggle = el('div', { class: 'tree-toggle' },
        el('span', { class: 'arrow' }, gExpanded ? '▼' : '▶'),
        el('span', { class: 'tree-label' }, '📂 ' + group.name + ' (' + reqs.length + ')'),
      );
      gToggle.addEventListener('click', () => toggleGroup(gKey, gChildren, gToggle));
      childrenEl.appendChild(el('div', { class: 'tree-node' }, gToggle, gChildren));
    }

    const fToggle = el('div', { class: 'tree-toggle' },
      el('span', { class: 'arrow' }, fileExpanded ? '▼' : '▶'),
      el('span', { class: 'tree-label', title: file.rel }, '📄 ' + file.rel),
      file.error ? el('span', { class: 'file-error', title: String(file.error) }, '⚠ 解析失败') : null,
      el('span', { class: 'muted' }, '(' + totalReqs + ')'),
    );
    fToggle.addEventListener('click', () => toggleFile(file.rel, childrenEl, fToggle));

    container.appendChild(el('div', { class: 'tree-node' }, fToggle, childrenEl));
  }

  if (visibleCount === 0) {
    container.appendChild(el('div', { class: 'empty' }, '无匹配结果'));
  }
}

function findRequest(id) {
  for (const f of (state.tree.files || [])) {
    for (const g of (f.groups || [])) {
      for (const r of (g.requests || [])) {
        if (r.id === id) return { req: r, fileRel: f.rel };
      }
    }
  }
  return null;
}

function renderDetail() {
  const detail = document.getElementById('detail');
  if (!detail) return;
  detail.textContent = '';

  if (!state.tree) {
    detail.appendChild(el('div', { class: 'empty' }, '加载中...'));
    return;
  }

  if (!state.selectedId) {
    detail.appendChild(el('div', { class: 'empty' }, '← 点击左侧任意请求查看详情'));
    appendVarsSection(detail);
    return;
  }

  const found = findRequest(state.selectedId);
  if (!found) {
    detail.appendChild(el('div', { class: 'empty' }, '请求不存在（文件可能已删除），请重新选择'));
    appendVarsSection(detail); // varsError 也照常展示，不因请求缺失而隐藏
    return;
  }
  const req = found.req;
  // URL 高亮与 tooltip：环境变量 + 该文件文件级变量的合并表
  const reqVars = mergedVars(found.fileRel);

  const body = el('div', { class: 'section-body' },
    renderUrlRow(req.url, reqVars),
    renderRow('方法', req.method),
    renderRow('文件', found.fileRel + ':' + req.line),
    renderRow('变量名', req.varName || '(无)'),
    renderRow('路径', req.path || ''),
    req.notes ? el('div', { class: 'row' },
      el('span', { class: 'row-label' }, '备注'),
      // notes 保留原文换行，textContent 转义
      el('pre', { class: 'code-block' }, String(req.notes)),
    ) : null,
    renderHeaders(req.headers),
    renderBody(req),
  );

  detail.appendChild(el('div', { class: 'section' },
    el('div', { class: 'section-header' },
      methodBadge(req.method),
      el('span', {}, req.name || '(未命名)'),
      el('span', { class: 'spacer' }),
      el('button', {
        class: 'btn-primary',
        onclick: onToggleTryIt,
        title: '展开/收起发送面板',
      }, (state.tryIt && state.tryIt.reqId === req.id && state.tryIt.open) ? '▼ 收起' : '▶ 发送请求'),
    ),
    body,
  ));

  const tryIt = ensureTryIt(req);
  if (tryIt.open) detail.appendChild(renderTryItSection(req));

  if (req.raw) {
    detail.appendChild(el('div', { class: 'section' },
      el('div', { class: 'section-header' },
        '📄 原始 .http 片段',
        el('span', { class: 'muted' }, found.fileRel + ':' + req.line),
        el('span', { class: 'spacer' }),
        el('button', { class: 'btn-small', onclick: () => copyToClipboard(String(req.raw)) }, '复制'),
      ),
      el('pre', { class: 'code-block' }, String(req.raw)),
    ));
  }

  appendVarsSection(detail);
}

/** 变量区：成功则渲染面板，失败只在面板位置显示错误（不影响其余功能）。 */
function appendVarsSection(detail) {
  if (state.vars) {
    detail.appendChild(renderVarPanel());
  } else if (state.varsError) {
    detail.appendChild(el('div', { class: 'section' },
      el('div', { class: 'section-header' }, '🔧 变量'),
      el('div', { class: 'section-body error-text' }, state.varsError),
    ));
  }
}

/**
 * URL 行：主显 {{var}} 替换后的真实地址（便于确认当前环境实际打到哪里），
 * 模板与真实值不同时保留原始模板为副行。
 */
function renderUrlRow(template, vars) {
  const raw = String(template == null ? '' : template);
  const real = interpolate(raw, vars);
  const valEl = el('div', { class: 'url-vars' });
  valEl.appendChild(document.createTextNode(real));
  if (real !== raw) {
    valEl.appendChild(el('div', { class: 'url-template' }, '模板: ' + raw));
  }
  return el('div', { class: 'row' },
    el('span', { class: 'row-label' }, 'URL'),
    valEl,
  );
}

/** 渲染一行键值；highlightVars=true 时把 {{var}} 用 <code> 高亮并附真实值 tooltip。 */
function renderRow(label, value, highlightVars, varsTable) {
  if (value === null || value === undefined || value === '') return null;
  const valEl = el('div', { class: 'url-vars' });
  const table = varsTable || mergedVarsForSelected();
  const str = String(value);
  if (highlightVars) {
    for (const p of str.split(/(\{\{[^}]+\}\})/g)) {
      if (p === '') continue;
      if (/^\{\{[^}]+\}\}$/.test(p)) {
        const varName = p.slice(2, -2);
        const real = table[varName] !== undefined ? table[varName] : '(未定义)';
        valEl.appendChild(el('code', { title: '真实值: ' + real }, p));
      } else {
        valEl.appendChild(document.createTextNode(p));
      }
    }
  } else {
    valEl.appendChild(document.createTextNode(str));
  }
  return el('div', { class: 'row' },
    el('span', { class: 'row-label' }, label),
    valEl,
  );
}

function renderHeaders(headers) {
  if (!headers || Object.keys(headers).length === 0) return null;
  const rows = Object.entries(headers).map(([k, v]) =>
    el('tr', {}, el('td', {}, String(k)), el('td', { class: 'code' }, String(v))));
  return el('div', { class: 'row', style: 'display:block;' },
    el('span', { class: 'row-label' }, '请求头'),
    el('table', { class: 'table' },
      el('thead', {}, el('tr', {}, el('th', {}, 'Key'), el('th', {}, 'Value'))),
      el('tbody', {}, ...rows),
    ),
  );
}

/** body 预览：Content-Type 为 json 时尝试格式化 + token 着色；一律 textContent 渲染。 */
function renderBody(req) {
  if (!req.body) return null;
  // header 键名按 .http 原样保留（可能是 content-type 等），按键名小写比较
  let ct = '';
  if (req.headers) {
    for (const [k, v] of Object.entries(req.headers)) {
      if (String(k).toLowerCase() === 'content-type') { ct = String(v); break; }
    }
  }
  const raw = String(req.body);
  let formatted = raw;
  let jsonNode = null;
  if (ct.toLowerCase().includes('json')) {
    try {
      formatted = JSON.stringify(JSON.parse(raw), null, 2);
      jsonNode = highlightJson(formatted);
    } catch (e) { /* 非法 JSON 保持原文 */ }
  }
  const pre = el('pre', { class: 'code-block' });
  if (jsonNode) pre.appendChild(jsonNode);
  else pre.appendChild(document.createTextNode(formatted));
  return el('div', { class: 'row', style: 'display:block;' },
    el('span', { class: 'row-label' }, '请求体'),
    pre,
  );
}

/** JSON 语法高亮：把已格式化的 JSON 按 token 切分并染色（返回 DocumentFragment）。 */
function highlightJson(text) {
  const frag = document.createDocumentFragment();
  const re = /("(?:\\.|[^"\\])*"\s*:)|("(?:\\.|[^"\\])*")|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|\b(true|false)\b|\b(null)\b|([{}\[\],])/g;
  let last = 0;
  let m;
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
    const cls = m[1] ? 'j-key' : m[2] ? 'j-str' : m[3] ? 'j-num' : m[4] ? 'j-bool' : m[5] ? 'j-null' : 'j-punct';
    frag.appendChild(el('span', { class: cls }, m[0]));
    last = re.lastIndex;
  }
  if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
  return frag;
}

/** 变量行：长值折叠显示前 16 字符，可展开/复制。stateKey 为折叠状态键（文件级变量加前缀避免同名冲突）。 */
function varRow(stateKey, key, val) {
  const expanded = state.varExpanded.has(stateKey);
  if (val.length <= 16) {
    return el('div', { class: 'var-row' },
      el('span', { class: 'var-key' }, key),
      el('span', { class: 'var-val expanded' }, val),
      el('span', { class: 'var-actions' },
        el('button', { class: 'btn-small', onclick: () => copyToClipboard(val) }, '复制')),
    );
  }
  return el('div', { class: 'var-row' },
    el('span', { class: 'var-key' }, key),
    el('span', { class: 'var-val ' + (expanded ? 'expanded' : 'collapsed') },
      el('span', { class: 'short' }, shortText(val, 16)),
      el('span', { class: 'full' }, val),
    ),
    el('span', { class: 'var-actions' },
      el('button', { class: 'btn-small', onclick: () => toggleVar(stateKey) }, expanded ? '折叠' : '展开'),
      el('button', { class: 'btn-small', onclick: () => copyToClipboard(val) }, '复制'),
    ),
  );
}

/** 变量面板：.vscode 环境变量与文件级变量分两块显示，避免混淆。 */
function renderVarPanel() {
  const varsObj = state.vars.vars || {};
  const bodyEl = el('div', { class: 'var-body' });
  for (const [k, v] of Object.entries(varsObj)) {
    bodyEl.appendChild(varRow('env::' + k, k, String(v)));
  }

  const nodes = [
    el('div', { class: 'var-header' }, '🔧 变量（来源: ' + (state.varsSource || '未知') + '）'),
    bodyEl,
  ];

  // 文件级变量单独一块（当前选中请求所在文件）
  const rel = selectedFileRel();
  if (rel) {
    const fileVars = fileVarsOf(rel);
    const fileBody = el('div', { class: 'var-body' });
    const fileKeys = Object.keys(fileVars);
    if (fileKeys.length === 0) {
      fileBody.appendChild(el('div', { class: 'muted' }, '（该文件无文件级变量）'));
    } else {
      for (const k of fileKeys) {
        fileBody.appendChild(varRow('file::' + k, k, String(fileVars[k])));
      }
    }
    nodes.push(
      el('div', { class: 'var-header' }, '📁 文件变量（' + rel + '）'),
      fileBody,
    );
  }

  return el('div', { class: 'var-panel' }, ...nodes);
}

/* ---------------------- Try it out ---------------------- */

/** 当前环境的 localStorage 键。 */
const ENV_KEY = 'httpviewer.env';

const TRYIT_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
/** 响应体超过该字符数时默认折叠。 */
const RESPONSE_COLLAPSE_THRESHOLD = 3000;

/** 为选中请求建立/复用 Try it out 面板状态。 */
function ensureTryIt(req) {
  if (state.tryIt && state.tryIt.reqId === req.id) return state.tryIt;
  const found = findRequest(req.id);
  const fileRel = found ? found.fileRel : null;
  const vars = mergedVars(fileRel);
  state.tryIt = {
    reqId: req.id,
    fileRel,
    open: false,
    method: String(req.method || 'GET'),
    // urlTemplate 是原始模板（发送源，{{var}} 由 PHP 解析）；url 是输入框显示值（随环境重算）。
    // 用户手工改过 url 后置 urlEdited=true，此后环境切换不再覆盖用户输入。
    urlTemplate: String(req.url || ''),
    url: interpolate(req.url, vars),
    urlEdited: false,
    headers: Object.entries(req.headers || {}).map(([k, v]) => ({ key: String(k), value: String(v) })),
    body: String(req.body || ''),
    timeout: 15,
    sending: false,
    response: null,
    error: null,
    responseExpanded: false,
  };
  return state.tryIt;
}

function onToggleTryIt() {
  const found = findRequest(state.selectedId);
  if (!found) return;
  const t = ensureTryIt(found.req);
  t.open = !t.open;
  renderDetail();
}

function renderTryItSection(req) {
  const t = state.tryIt;

  // 当前环境：在顶部全局切换，这里只读展示
  const envTip = el('span', { class: 'muted' },
    (state.env ? state.env : '默认（$shared）') + '（在顶部「环境」处切换）');

  // method 下拉
  const methodSelect = el('select', { onchange: (e) => { t.method = e.target.value; } });
  const methods = TRYIT_METHODS.includes(t.method) ? TRYIT_METHODS : [t.method, ...TRYIT_METHODS];
  for (const m of methods) {
    const opt = el('option', { value: m }, m);
    if (m === t.method) opt.selected = true;
    methodSelect.appendChild(opt);
  }

  // URL 输入框显示「替换后的真实地址」（随环境动态变更）；
  // 未编辑时下方显示原始模板，发送时仍以模板为源交给 PHP 解析。
  const urlInput = el('input', {
    type: 'text', class: 'tryit-input tryit-url', value: t.url,
    spellcheck: 'false', placeholder: 'http://... 或 {{host}}/path',
    oninput: (e) => { t.url = e.target.value; t.urlEdited = true; renderUrlTemplateTip(); },
  });

  const urlTemplateTip = el('div', { class: 'url-template-tip muted' });
  function renderUrlTemplateTip() {
    if (t.urlEdited) {
      urlTemplateTip.textContent = '已手工编辑（发送此地址）';
      return;
    }
    urlTemplateTip.textContent = '模板: ' + t.urlTemplate;
  }
  renderUrlTemplateTip();

  const bodyArea = el('textarea', {
    class: 'tryit-input tryit-body', rows: '8', spellcheck: 'false',
    placeholder: '请求体（{{var}} 发送前自动替换）',
    oninput: (e) => { t.body = e.target.value; },
  }, t.body);

  const timeoutInput = el('input', {
    type: 'number', class: 'tryit-input tryit-timeout', min: '1', max: '60',
    value: String(t.timeout),
    oninput: (e) => { t.timeout = e.target.value; },
  });

  const sendBtn = el('button', {
    class: 'btn-primary',
    onclick: onTryItSend,
    disabled: t.sending ? 'disabled' : null,
  }, t.sending ? '⏳ 发送中...' : '🚀 发送');

  const headersTable = renderTryItHeadersTable(t);

  const panel = el('div', { class: 'section tryit-panel' },
    el('div', { class: 'section-header' }, '⚡ Try it out（发送面板）'),
    el('div', { class: 'section-body' },
      el('div', { class: 'tryit-row' },
        el('span', { class: 'tryit-label' }, '环境'),
        envTip,
      ),
      el('div', { class: 'tryit-row' },
        el('span', { class: 'tryit-label' }, 'Method'),
        methodSelect,
        el('span', { class: 'tryit-label' }, 'URL'),
        urlInput,
      ),
      el('div', { class: 'tryit-row tryit-url-preview-row' }, urlTemplateTip),
      el('div', { class: 'tryit-row', style: 'display:block;' },
        el('span', { class: 'tryit-label' }, 'Headers'),
        headersTable,
        el('button', {
          class: 'btn-small',
          onclick: () => { t.headers.push({ key: '', value: '' }); renderDetail(); },
        }, '＋ 添加请求头'),
      ),
      el('div', { class: 'tryit-row', style: 'display:block;' },
        el('span', { class: 'tryit-label' }, 'Body'),
        bodyArea,
      ),
      el('div', { class: 'tryit-row' },
        el('span', { class: 'tryit-label' }, '超时(秒)'),
        timeoutInput,
        sendBtn,
        el('span', { class: 'muted' }, '经服务端代理发送（{{var}} 按「环境变量 + 文件变量」替换）'),
      ),
      el('div', { id: 'tryit-response' }, ...renderTryItResponse(t)),
    ),
  );
  return panel;
}

function renderTryItHeadersTable(t) {
  const tbody = el('tbody');
  t.headers.forEach((row, idx) => {
    tbody.appendChild(el('tr', {},
      el('td', {}, el('input', {
        type: 'text', class: 'tryit-input', value: row.key, placeholder: '名称',
        oninput: (e) => { row.key = e.target.value; },
      })),
      el('td', {}, el('input', {
        type: 'text', class: 'tryit-input', value: row.value,
        placeholder: '值（支持 {{var}}）',
        oninput: (e) => { row.value = e.target.value; },
      })),
      el('td', { style: 'width:36px;text-align:center;' },
        el('button', {
          class: 'btn-small', title: '删除该请求头',
          onclick: () => { t.headers.splice(idx, 1); renderDetail(); },
        }, '✕')),
    ));
  });
  if (t.headers.length === 0) {
    tbody.appendChild(el('tr', {}, el('td', { colspan: '3', class: 'muted' }, '（无请求头）')));
  }
  return el('table', { class: 'table' },
    el('thead', {}, el('tr', {}, el('th', {}, 'Key'), el('th', {}, 'Value'), el('th', {}, ''))),
    tbody,
  );
}

async function onTryItSend() {
  const t = state.tryIt;
  if (!t || t.sending) return;
  const fileRel = t.fileRel || (findRequest(t.reqId) || {}).fileRel || null;

  // 前端只上送「模板 + file + env」：{{var}} 的解析全部由 PHP 完成
  // （环境变量来自 settings.json，文件级 @var 由服务端读 file 指向的 .http）。
  const headers = {};
  for (const row of t.headers) {
    const k = String(row.key).trim();
    if (!k) continue;
    headers[k] = String(row.value);
  }
  const payload = {
    method: t.method,
    // 未编辑：传模板交给 PHP 按当前环境解析；已编辑：传用户输入的地址
    url: t.urlEdited ? String(t.url) : String(t.urlTemplate),
    headers,
    body: t.body === '' ? '' : String(t.body),
    timeout: Math.max(1, Math.min(60, parseInt(t.timeout, 10) || 15)),
  };
  if (fileRel) payload.file = fileRel;
  if (state.env) payload.env = state.env;

  t.sending = true;
  t.error = null;
  t.response = null;
  t.responseExpanded = false;
  renderDetail();

  try {
    const res = await fetch('index.php?api=send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data && data.ok === false) {
      t.error = String(data.error || '请求失败（HTTP ' + res.status + '）');
    }
    t.response = data;
  } catch (e) {
    t.error = '发送失败: ' + e.message;
  }
  t.sending = false;
  renderDetail();
}

/**
 * 环境切换后同步 Try it out 面板的展示值（前端仅用于「显示」，发送时仍由 PHP 解析）。
 * URL 与请求头按新变量重新插值；用户已手工编辑过的 URL 会被重置为模板渲染结果。
 */
function syncTryItWithEnv() {
  const t = state.tryIt;
  if (!t) return;
  const found = findRequest(t.reqId);
  if (!found) return;
  // 模板与请求头始终回到解析结果原文（发送源）；url 显示值在未被手工编辑时随环境重算
  t.urlTemplate = String(found.req.url || '');
  if (!t.urlEdited) {
    t.url = interpolate(t.urlTemplate, mergedVarsForRequest(t.reqId));
  }
  t.headers = Object.entries(found.req.headers || {})
    .map(([k, v]) => ({ key: String(k), value: String(v) }));
}

/** 响应视图：错误横幅 / 状态徽章 / 耗时 / 响应头 / 响应体（高亮、折叠、复制）。 */
function renderTryItResponse(t) {
  const nodes = [];
  if (t.error) {
    nodes.push(el('div', { class: 'tryit-error' }, '✕ ' + t.error));
  }
  const r = t.response;
  if (!r || r.ok === false) return nodes;

  const status = Number(r.status) || 0;
  const cls = (status >= 200 && status < 300) ? 'ok'
    : (status >= 400 && status < 500) ? 'warn'
    : (status >= 500) ? 'err' : 'neutral';
  nodes.push(el('div', { class: 'resp-summary' },
    el('span', { class: 'status-badge ' + cls }, status + (r.statusText ? ' ' + r.statusText : '')),
    el('span', { class: 'muted' }, '耗时 ' + r.durationMs + ' ms'),
    r.truncated
      ? el('span', { class: 'tryit-warn' }, '⚠ 响应体已截断（超过 512KB，仅保留前段）')
      : null,
  ));

  const respHeaders = r.headers || {};
  const names = Object.keys(respHeaders);
  if (names.length > 0) {
    const tbody = el('tbody');
    for (const name of names) {
      tbody.appendChild(el('tr', {}, el('td', {}, name), el('td', { class: 'code' }, String(respHeaders[name]))));
    }
    nodes.push(el('div', { class: 'row', style: 'display:block;' },
      el('span', { class: 'row-label' }, '响应头'),
      el('table', { class: 'table' },
        el('thead', {}, el('tr', {}, el('th', {}, 'Key'), el('th', {}, 'Value'))),
        tbody),
    ));
  }

  nodes.push(renderTryItResponseBody(t));
  return nodes;
}

function renderTryItResponseBody(t) {
  const r = t.response;
  const rawBody = String(r.body || '');
  let text = rawBody;
  let jsonNode = null;
  // 能按 JSON 解析就格式化 + 高亮（不依赖 content-type）
  if (rawBody !== '') {
    try {
      text = JSON.stringify(JSON.parse(rawBody), null, 2);
      jsonNode = highlightJson(text);
    } catch (e) { /* 非 JSON 原样展示 */ }
  }
  const longBody = text.length > RESPONSE_COLLAPSE_THRESHOLD;
  const pre = el('pre', {
    class: 'code-block' + (longBody && !t.responseExpanded ? ' resp-collapsed' : ''),
  });
  if (jsonNode) pre.appendChild(jsonNode);
  else pre.appendChild(document.createTextNode(text));

  return el('div', { class: 'row', style: 'display:block;' },
    el('div', { class: 'resp-body-head' },
      el('span', { class: 'row-label' }, '响应体'),
      el('span', { class: 'spacer' }),
      longBody ? el('button', {
        class: 'btn-small',
        onclick: () => { t.responseExpanded = !t.responseExpanded; renderDetail(); },
      }, t.responseExpanded ? '折叠' : '展开 (' + text.length + ' 字符)') : null,
      el('button', { class: 'btn-small', onclick: () => copyToClipboard(text) }, '复制'),
    ),
    pre,
  );
}

/* ---------------------- 事件层 ---------------------- */

function onSearchInput(e) {
  state.searchText = e.target.value;
  renderNavTree();
}

function onReqClick(id) {
  // 切换请求时丢弃上一个请求的发送面板状态
  if (state.tryIt && state.tryIt.reqId !== id) state.tryIt = null;
  state.selectedId = id;
  // 选中时自动展开祖先节点（搜索态下本就全展开）
  const { files, groups } = collectAncestors(state.tree, id);
  files.forEach((f) => state.expandedFiles.add(f));
  groups.forEach((g) => state.expandedGroups.add(g));
  renderNavTree();
  renderDetail();
}

function toggleFile(rel, childrenEl, toggleEl) {
  if (state.expandedFiles.has(rel)) state.expandedFiles.delete(rel);
  else state.expandedFiles.add(rel);
  const collapsed = childrenEl.classList.toggle('collapsed');
  toggleEl.querySelector('.arrow').textContent = collapsed ? '▶' : '▼';
}

function toggleGroup(key, childrenEl, toggleEl) {
  if (state.expandedGroups.has(key)) state.expandedGroups.delete(key);
  else state.expandedGroups.add(key);
  const collapsed = childrenEl.classList.toggle('collapsed');
  toggleEl.querySelector('.arrow').textContent = collapsed ? '▶' : '▼';
}

function toggleVar(k) {
  if (state.varExpanded.has(k)) state.varExpanded.delete(k);
  else state.varExpanded.add(k);
  renderDetail();
}

async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
  } catch (e) {
    // 降级方案（非安全上下文）
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e2) { /* 忽略 */ }
    document.body.removeChild(ta);
  }
}

async function onRefresh() {
  try {
    state.tree = await fetchTree();
    state.lastVersion = state.tree.version;
    renderStats();
    renderRootTip();
    renderNavTree();
    renderDetail();
    // 渲染成功后再清横幅（同步渲染，catch 分支会重新显示）
    hideErrorBanner();
  } catch (e) {
    showErrorBanner('刷新失败: ' + e.message);
  }
}

function onThemeToggle() {
  const html = document.documentElement;
  const next = html.dataset.theme === 'dark' ? 'light' : 'dark';
  html.dataset.theme = next;
  try { localStorage.setItem('theme', next); } catch (e) { /* 隐私模式下忽略 */ }
  const btn = document.getElementById('theme-toggle');
  if (btn) btn.textContent = next === 'dark' ? '☀ 浅色' : '☾ 深色';
}

function showErrorBanner(msg) {
  const banner = document.getElementById('error-banner');
  if (banner) { banner.textContent = msg; banner.style.display = 'block'; }
}

function hideErrorBanner() {
  const banner = document.getElementById('error-banner');
  if (banner) banner.style.display = 'none';
}

/* ---------------------- 轮询（5 秒，version 变化静默更新） ---------------------- */

async function poll() {
  try {
    const data = await fetchTree();
    // 首载失败后（state.tree 为 null）的自愈路径：下一次轮询成功即完整渲染
    const firstLoadRecovered = state.tree === null;
    const versionChanged = state.lastVersion !== null && data.version !== state.lastVersion;
    if (firstLoadRecovered || versionChanged) {
      // 版本变化：静默刷新树与统计，保持当前选中请求与滚动位置
      const treeEl = document.getElementById('nav-tree');
      const detailEl = document.getElementById('detail');
      const treeScroll = treeEl ? treeEl.scrollTop : 0;
      const detailScroll = detailEl ? detailEl.scrollTop : 0;
      state.tree = data;
      state.lastVersion = data.version;
      renderStats();
      renderRootTip();
      renderNavTree();
      renderDetail();
      if (treeEl) treeEl.scrollTop = treeScroll;
      if (detailEl) detailEl.scrollTop = detailScroll;
    } else {
      state.tree = data;
      state.lastVersion = data.version;
    }
    // 渲染成功后再清横幅，避免先抹掉错误提示、页面却还停在加载态
    hideErrorBanner();
  } catch (e) {
    // 轮询失败显示横幅，下一轮继续
    showErrorBanner('轮询失败: ' + e.message);
  }
}

/* ---------------------- 初始化 ---------------------- */

async function init() {
  // 主题：localStorage 记忆，默认浅色
  let saved = null;
  try { saved = localStorage.getItem('theme'); } catch (e) { /* 忽略 */ }
  document.documentElement.dataset.theme = saved === 'dark' ? 'dark' : 'light';

  // 当前环境：localStorage 记忆（默认空 = 仅 $shared）
  try {
    const savedEnv = localStorage.getItem(ENV_KEY);
    if (savedEnv) state.env = savedEnv;
  } catch (e) { /* 忽略 */ }

  renderApp();

  // tree 与 vars 独立加载：vars 失败不连带丢弃已成功的 tree
  try {
    const treeData = await fetchTree();
    state.tree = treeData;
    state.lastVersion = treeData.version;
    renderStats();
    renderRootTip();
    renderNavTree();
    renderDetail();
  } catch (e) {
    showErrorBanner('加载失败: ' + e.message);
    // tree 失败仍尝试加载 vars，供后续轮询恢复后直接展示
  }

  try {
    const varsData = await fetchVars(state.env);
    // 记忆的环境名若已不存在（settings.json 改过），回落到默认并清掉记忆
    const envs = varsData.environments || [];
    if (state.env && !envs.includes(state.env)) {
      state.env = '';
      persistEnv();
    }
    state.vars = varsData;
    state.varsSource = varsData.source || '';
    state.varsError = null;
    renderApp();
    renderDetail();
  } catch (e) {
    state.varsError = '变量加载失败: ' + e.message;
    renderDetail();
  }

  // 每 5 秒轮询
  setInterval(poll, 5000);
}

init();
