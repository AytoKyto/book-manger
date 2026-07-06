const AGENT_ICONS = {
  correcteur: '✓',
  styliste: '✎',
  continuite: '🔗',
  'beta-lecteur': '👁',
};

const state = {
  authenticated: false,
  books: [],
  agents: [],
  currentBook: null, // full book incl. chapters
  saveTimer: null,
  runEventSource: null,
};

const app = document.getElementById('app');

// ---------------------------------------------------------------- API ----

async function api(method, path, body) {
  const res = await fetch(path, {
    method,
    headers: body !== undefined ? { 'Content-Type': 'application/json' } : {},
    body: body !== undefined ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  });

  if (res.status === 401) {
    state.authenticated = false;
    route();
    throw new Error('unauthenticated');
  }

  if (res.status === 204) return null;

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || `Erreur ${res.status}`);
  }
  return data;
}

// -------------------------------------------------------------- Router ----

function route() {
  if (!state.authenticated) {
    renderLogin();
    return;
  }

  const hash = location.hash.replace(/^#/, '') || '/';
  const bookChapter = hash.match(/^\/books\/(\d+)\/chapters\/(\d+)$/);
  const bookOnly = hash.match(/^\/books\/(\d+)$/);
  const runOnly = hash.match(/^\/runs\/(\d+)$/);

  if (bookChapter) {
    renderShell();
    openBook(Number(bookChapter[1])).then(() => renderEditor(Number(bookChapter[2])));
  } else if (bookOnly) {
    renderShell();
    openBook(Number(bookOnly[1])).then(() => renderBookOverview());
  } else if (runOnly) {
    renderShell();
    renderRun(Number(runOnly[1]));
  } else {
    renderShell();
    renderLibrary();
  }
}

window.addEventListener('hashchange', route);

// --------------------------------------------------------------- Login ----

function renderLogin() {
  app.innerHTML = `
    <div class="login-screen">
      <div class="login-card">
        <div class="logo">📖</div>
        <h1>Book Manager</h1>
        <form id="login-form">
          <input type="password" id="login-password" placeholder="Mot de passe" autofocus>
          <button class="btn primary" type="submit">Entrer</button>
          <div class="error" id="login-error"></div>
        </form>
      </div>
    </div>`;

  document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const password = document.getElementById('login-password').value;
    try {
      await api('POST', '/api/login', { password });
      state.authenticated = true;
      route();
    } catch {
      document.getElementById('login-error').textContent = 'Mot de passe incorrect';
    }
  });
}

// --------------------------------------------------------------- Shell ----

function closeMobilePanels() {
  document.getElementById('sidebar')?.classList.remove('open');
  document.querySelector('.side-panel')?.classList.remove('open');
  document.getElementById('mobile-backdrop')?.classList.remove('show');
}

function renderShell() {
  app.innerHTML = `
    <button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="Ouvrir le menu">☰</button>
    <div class="mobile-backdrop" id="mobile-backdrop"></div>
    <div class="app-shell">
      <nav class="sidebar" id="sidebar">
        <div class="sidebar-header" id="go-home">
          <span class="logo">📖</span> Book Manager
        </div>
        <div id="sidebar-content"></div>
        <div class="sidebar-footer">
          <button class="btn ghost small" id="logout-btn" style="width:100%">Se déconnecter</button>
        </div>
      </nav>
      <div class="main">
        <div class="topbar" id="topbar"></div>
        <div class="view" id="view"></div>
      </div>
    </div>`;

  document.getElementById('go-home').addEventListener('click', () => { closeMobilePanels(); location.hash = '/'; });
  document.getElementById('logout-btn').addEventListener('click', async () => {
    await api('POST', '/api/logout');
    state.authenticated = false;
    route();
  });
  document.getElementById('mobile-menu-btn').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('mobile-backdrop').classList.toggle('show');
  });
  document.getElementById('mobile-backdrop').addEventListener('click', closeMobilePanels);

  renderSidebar();
}

async function renderSidebar() {
  if (state.books.length === 0) {
    const data = await api('GET', '/api/books');
    state.books = data.books;
  }

  const sidebarContent = document.getElementById('sidebar-content');
  const activeBookId = state.currentBook ? state.currentBook.id : null;

  let html = `<div class="sidebar-section">
    <div class="heading">Bibliothèque</div>
    <ul class="book-list">`;
  for (const b of state.books) {
    html += `<li><div class="nav-item ${b.id === activeBookId ? 'active' : ''}" data-book="${b.id}">${escapeHtml(b.title)}</div></li>`;
  }
  html += `</ul></div>`;

  if (state.currentBook) {
    html += `<div class="sidebar-section">
      <div class="heading">Chapitres <span style="cursor:pointer" id="add-chapter">＋</span></div>
      <ul class="chapter-list">`;
    for (const c of state.currentBook.chapters) {
      const active = location.hash === `#/books/${state.currentBook.id}/chapters/${c.id}`;
      html += `<li><div class="nav-item ${active ? 'active' : ''}" data-chapter="${c.id}">${escapeHtml(c.title)}<span class="badge">${c.word_count}</span></div></li>`;
    }
    html += `</ul></div>`;
  }

  sidebarContent.innerHTML = html;

  sidebarContent.querySelectorAll('[data-book]').forEach((el) => {
    el.addEventListener('click', () => { closeMobilePanels(); location.hash = `/books/${el.dataset.book}`; });
  });
  sidebarContent.querySelectorAll('[data-chapter]').forEach((el) => {
    el.addEventListener('click', () => {
      closeMobilePanels();
      location.hash = `/books/${state.currentBook.id}/chapters/${el.dataset.chapter}`;
    });
  });
  const addChapterBtn = document.getElementById('add-chapter');
  if (addChapterBtn) {
    addChapterBtn.addEventListener('click', async () => {
      const title = prompt('Titre du chapitre ?');
      if (!title) return;
      closeMobilePanels();
      const data = await api('POST', `/api/books/${state.currentBook.id}/chapters`, { title });
      await openBook(state.currentBook.id, true);
      location.hash = `/books/${state.currentBook.id}/chapters/${data.chapter.id}`;
    });
  }
}

async function openBook(bookId, force = false) {
  if (!force && state.currentBook && state.currentBook.id === bookId) return;
  const data = await api('GET', `/api/books/${bookId}`);
  state.currentBook = data.book;
  await renderSidebar();
}

// ------------------------------------------------------------- Library ----

async function renderLibrary() {
  document.getElementById('topbar').innerHTML = `<div class="title">Bibliothèque</div>
    <div class="spacer"></div><button class="btn primary small" id="new-book">＋ Nouveau livre</button>`;

  const data = await api('GET', '/api/books');
  state.books = data.books;

  const view = document.getElementById('view');
  if (state.books.length === 0) {
    view.innerHTML = `<div class="empty-state">Aucun livre pour l'instant. Crée le premier avec le bouton en haut à droite.</div>`;
  } else {
    view.innerHTML = `<div class="library-grid">${state.books.map(bookCardHtml).join('')}</div>`;
    view.querySelectorAll('[data-book]').forEach((el) => {
      el.addEventListener('click', () => { location.hash = `/books/${el.dataset.book}`; });
    });
  }

  await renderSidebar();

  document.getElementById('new-book').addEventListener('click', async () => {
    const title = prompt('Titre du livre ?');
    if (!title) return;
    const genre = prompt('Genre (optionnel) ?') || '';
    await api('POST', '/api/books', { title, genre, word_target: 0 });
    await renderLibrary();
  });
}

function bookCardHtml(b) {
  return `<div class="book-card" data-book="${b.id}">
    <div class="title">${escapeHtml(b.title)}</div>
    <div class="meta">
      <span class="pill status-${b.status}">${b.status}</span>
      ${b.genre ? `<span class="pill">${escapeHtml(b.genre)}</span>` : ''}
    </div>
  </div>`;
}

function renderBookOverview() {
  const book = state.currentBook;
  document.getElementById('topbar').innerHTML = `<div class="title">${escapeHtml(book.title)}</div>
    <div class="sub">${book.chapters.length} chapitre(s)</div>
    <div class="spacer"></div>
    <button class="btn danger small" id="delete-book">Supprimer</button>`;

  const view = document.getElementById('view');
  if (book.chapters.length === 0) {
    view.innerHTML = `<div class="empty-state">Pas encore de chapitre. Ajoute-en un depuis la barre latérale.</div>`;
  } else {
    view.innerHTML = `<div class="library-grid">${book.chapters.map((c) => `
      <div class="book-card" data-chapter="${c.id}">
        <div class="title" style="font-size:15px">${escapeHtml(c.title)}</div>
        <div class="meta"><span class="pill">${c.word_count} mots</span></div>
      </div>`).join('')}</div>`;
    view.querySelectorAll('[data-chapter]').forEach((el) => {
      el.addEventListener('click', () => { location.hash = `/books/${book.id}/chapters/${el.dataset.chapter}`; });
    });
  }

  document.getElementById('delete-book').addEventListener('click', async () => {
    if (!confirm(`Supprimer définitivement « ${book.title} » et tous ses chapitres ?`)) return;
    await api('DELETE', `/api/books/${book.id}`);
    state.books = [];
    state.currentBook = null;
    location.hash = '/';
  });
}

// -------------------------------------------------------------- Editor ----

async function renderEditor(chapterId) {
  const book = state.currentBook;
  const data = await api('GET', `/api/books/${book.id}/chapters/${chapterId}`);
  const chapter = data.chapter;

  if (state.agents.length === 0) {
    const a = await api('GET', '/api/agents');
    state.agents = a.agents;
  }

  document.getElementById('topbar').innerHTML = `<div class="title">${escapeHtml(chapter.title)}</div>
    <div class="sub" id="save-indicator">Enregistré</div>
    <div class="spacer"></div>
    <button class="btn small mobile-only" id="agents-toggle-btn">Agents</button>
    <button class="btn small" id="history-btn">Historique</button>`;

  const view = document.getElementById('view');
  view.style.padding = '0';
  view.innerHTML = `
    <div class="editor-layout">
      <div style="display:flex;flex-direction:column;min-width:0">
        <div class="editor-toolbar">
          <button data-cmd="bold" title="Gras"><b>B</b></button>
          <button data-cmd="italic" title="Italique"><i>I</i></button>
          <button data-block="h1" title="Titre">H1</button>
          <button data-block="h2" title="Sous-titre">H2</button>
          <button data-block="blockquote" title="Citation">”</button>
        </div>
        <div class="editor-pane">
          <div class="editor-doc" id="editor-doc" contenteditable="true">${mdToHtml(chapter.content || '# ' + chapter.title)}</div>
        </div>
      </div>
      <div class="side-panel">
        <h3>Demander à un agent</h3>
        <div id="agent-list"></div>
        <div class="field">
          <label>Instruction complémentaire (optionnel)</label>
          <textarea id="agent-instruction" rows="3" placeholder="Ex : insiste sur les dialogues"></textarea>
        </div>
        <button class="btn primary" id="launch-agent" style="width:100%">Lancer l'agent</button>
      </div>
    </div>`;

  const doc = document.getElementById('editor-doc');
  document.execCommand('defaultParagraphSeparator', false, 'p');

  const scheduleSave = () => {
    document.getElementById('save-indicator').textContent = 'Modifié…';
    clearTimeout(state.saveTimer);
    state.saveTimer = setTimeout(async () => {
      const content = htmlToMd(doc);
      const updated = await api('PUT', `/api/books/${book.id}/chapters/${chapterId}`, { content, title: chapter.title });
      document.getElementById('save-indicator').textContent = 'Enregistré';
      const idx = book.chapters.findIndex((c) => c.id === chapterId);
      if (idx >= 0) book.chapters[idx].word_count = updated.chapter.word_count;
      renderSidebar();
    }, 900);
  };
  doc.addEventListener('input', scheduleSave);

  view.querySelectorAll('.editor-toolbar [data-cmd]').forEach((btn) => {
    btn.addEventListener('click', () => { document.execCommand(btn.dataset.cmd); doc.focus(); scheduleSave(); });
  });
  view.querySelectorAll('.editor-toolbar [data-block]').forEach((btn) => {
    btn.addEventListener('click', () => { document.execCommand('formatBlock', false, btn.dataset.block); doc.focus(); scheduleSave(); });
  });

  document.getElementById('history-btn').addEventListener('click', () => showHistory(book, chapter));
  document.getElementById('agents-toggle-btn').addEventListener('click', () => {
    document.querySelector('.side-panel').classList.toggle('open');
    document.getElementById('mobile-backdrop').classList.toggle('show');
  });

  let selectedAgent = null;
  const agentList = document.getElementById('agent-list');
  agentList.innerHTML = state.agents.map((a) => `
    <div class="agent-card" data-agent="${a.name}">
      <div class="name">${AGENT_ICONS[a.name] || '•'} ${a.name}</div>
      <div class="desc">${escapeHtml(a.description)}</div>
      <div class="mode">${a.permissionMode === 'plan' ? 'lecture seule' : 'peut éditer'}</div>
    </div>`).join('');
  agentList.querySelectorAll('.agent-card').forEach((el) => {
    el.addEventListener('click', () => {
      selectedAgent = el.dataset.agent;
      agentList.querySelectorAll('.agent-card').forEach((c) => c.classList.remove('selected'));
      el.classList.add('selected');
    });
  });

  document.getElementById('launch-agent').addEventListener('click', async () => {
    if (!selectedAgent) { alert('Choisis un agent dans la liste.'); return; }
    const instruction = document.getElementById('agent-instruction').value;
    const run = await api('POST', '/api/runs', {
      book_id: book.id,
      chapter_id: chapterId,
      agent_name: selectedAgent,
      instruction,
    });
    location.hash = `/runs/${run.run.id}`;
  });
}

async function showHistory(book, chapter) {
  const data = await api('GET', `/api/books/${book.id}/chapters/${chapter.id}/snapshots`);
  const list = data.snapshots.map((s) => `${s.id} — ${s.reason} — ${s.created_at}`).join('\n');
  const choice = prompt(`Versions précédentes (id — raison — date) :\n${list}\n\nEntre l'id à restaurer, ou annule :`);
  if (!choice) return;
  await api('POST', `/api/books/${book.id}/chapters/${chapter.id}/snapshots/${choice}/restore`);
  renderEditor(chapter.id);
}

// ----------------------------------------------------------- Run review ----

function statusLabel(status) {
  return {
    pending: 'En file d’attente',
    running: 'En cours…',
    awaiting_review: 'À valider',
    applied: 'Appliqué',
    rejected: 'Refusé',
    error: 'Erreur',
  }[status] || status;
}

async function renderRun(runId) {
  document.getElementById('topbar').innerHTML = `<div class="title">Run d'agent #${runId}</div>`;
  const view = document.getElementById('view');
  view.style.padding = '28px 36px';
  view.innerHTML = `
    <div style="max-width:760px">
      <div class="run-status-row">
        <span class="status-dot" id="status-dot"></span>
        <span id="status-label">Chargement…</span>
      </div>
      <div class="run-log" id="run-log"></div>
      <div id="diffs-container"></div>
      <div id="run-actions"></div>
    </div>`;

  if (state.runEventSource) state.runEventSource.close();

  const logEl = document.getElementById('run-log');
  const dotEl = document.getElementById('status-dot');
  const labelEl = document.getElementById('status-label');

  const run = await api('GET', `/api/runs/${runId}`);
  updateRunStatus(run.run.status, dotEl, labelEl);

  if (run.run.status === 'awaiting_review' || run.run.status === 'applied' || run.run.status === 'rejected' || run.run.status === 'error') {
    await loadDiffsAndActions(runId, run.run);
    return;
  }

  const es = new EventSource(`/api/runs/${runId}/events`);
  state.runEventSource = es;

  es.addEventListener('log', (e) => {
    const data = JSON.parse(e.data);
    const line = document.createElement('div');
    line.className = 'line';
    line.textContent = summarizeLogLine(data);
    logEl.appendChild(line);
    logEl.scrollTop = logEl.scrollHeight;
  });

  es.addEventListener('status', async (e) => {
    const data = JSON.parse(e.data);
    updateRunStatus(data.status, dotEl, labelEl);
    es.close();
    const fresh = await api('GET', `/api/runs/${runId}`);
    await loadDiffsAndActions(runId, fresh.run);
  });
}

function summarizeLogLine(data) {
  if (data.type === 'assistant' && data.message?.content) {
    const textPart = data.message.content.find((c) => c.type === 'text');
    if (textPart) return textPart.text.trim();
    const tool = data.message.content.find((c) => c.type === 'tool_use');
    if (tool) return `→ outil ${tool.name} (${tool.input?.file_path || ''})`;
  }
  if (data.type === 'result') return `✓ ${data.result || 'terminé'}`;
  if (data.type === 'stderr') return `⚠ ${data.text}`;
  if (data.type === 'error') return `✗ ${data.text}`;
  return JSON.stringify(data);
}

function updateRunStatus(status, dotEl, labelEl) {
  dotEl.className = `status-dot ${status}`;
  labelEl.textContent = statusLabel(status);
}

async function loadDiffsAndActions(runId, run) {
  const container = document.getElementById('diffs-container');
  const actions = document.getElementById('run-actions');

  if (run.status === 'error') {
    container.innerHTML = `<div class="empty-state">${escapeHtml(run.error_message || 'Erreur inconnue')}</div>`;
    return;
  }

  const data = await api('GET', `/api/runs/${runId}/diffs`);
  if (data.diffs.length === 0) {
    container.innerHTML = `<div class="empty-state">Aucun changement proposé.</div>`;
    return;
  }

  container.innerHTML = data.diffs.map((d) => `
    <div class="diff-card" data-diff="${d.id}">
      <div class="diff-card-head">
        <span class="path">${escapeHtml(d.file_path)}</span>
        <span class="spacer"></span>
        <span class="pill" data-decision-label>${d.decision}</span>
        ${run.status === 'awaiting_review' ? `
          <button class="btn small" data-accept="${d.id}">Accepter</button>
          <button class="btn small danger" data-reject="${d.id}">Refuser</button>` : ''}
      </div>
      <div class="diff-body">${renderDiffLines(d.diff_text)}</div>
    </div>`).join('');

  container.querySelectorAll('[data-accept]').forEach((btn) => {
    btn.addEventListener('click', () => decide(runId, btn.dataset.accept, 'accepted'));
  });
  container.querySelectorAll('[data-reject]').forEach((btn) => {
    btn.addEventListener('click', () => decide(runId, btn.dataset.reject, 'rejected'));
  });

  if (run.status === 'awaiting_review') {
    actions.innerHTML = `<button class="btn primary" id="finalize-btn">Appliquer les changements acceptés</button>
      <button class="btn ghost" id="cancel-run-btn">Tout refuser / annuler</button>`;
    document.getElementById('finalize-btn').addEventListener('click', async () => {
      const res = await api('POST', `/api/runs/${runId}/finalize`);
      alert(`Fichiers appliqués : ${res.applied_files.join(', ') || 'aucun'}`);
      location.hash = `/books/${state.currentBook.id}`;
    });
    document.getElementById('cancel-run-btn').addEventListener('click', async () => {
      await api('DELETE', `/api/runs/${runId}`);
      location.hash = state.currentBook ? `/books/${state.currentBook.id}` : '/';
    });
  } else {
    actions.innerHTML = '';
  }
}

async function decide(runId, diffId, decision) {
  await api('POST', `/api/runs/${runId}/diffs/${diffId}/decision`, { decision });
  const card = document.querySelector(`[data-diff="${diffId}"] [data-decision-label]`);
  if (card) card.textContent = decision;
}

function renderDiffLines(diffText) {
  return diffText.split('\n').map((line) => {
    let cls = 'ctx';
    if (line.startsWith('+++') || line.startsWith('---')) cls = 'hunk';
    else if (line.startsWith('@@')) cls = 'hunk';
    else if (line.startsWith('+')) cls = 'add';
    else if (line.startsWith('-')) cls = 'del';
    return `<div class="diff-line ${cls}">${escapeHtml(line) || '&nbsp;'}</div>`;
  }).join('');
}

// --------------------------------------------------------------- Init ----

(async function init() {
  const status = await fetch('/api/status', { credentials: 'same-origin' }).then((r) => r.json());
  state.authenticated = status.authenticated;
  route();
})();
