const $ = (id) => document.getElementById(id);

const state = {
  emails: [],
  displayedRows: [],
  selectedId: null,
  selectedEmail: null,
  selectedAttachments: [],
  uniqueContacts: [],
  contactStats: {
    extracted: 0,
    valid: 0,
    duplicates: 0,
    ignored: 0,
  },
  sortBy: "date",
  sortDir: "desc",
  tab: "summary",
  mode: "list",
  pagination: {
    limit: 100,
    offset: 0,
    count: 0,
  },
  localFilter: "",
  trace: [],
  lastPayload: null,
  pending: 0,
};

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function extractEmail(raw) {
  if (!raw) return "";
  const text = String(raw);
  const match = text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i);
  return match ? match[0].toLowerCase() : text.trim().toLowerCase();
}

function extractDomain(raw) {
  const email = extractEmail(raw);
  if (!email.includes("@")) return "";
  return email.split("@")[1] || "";
}

function extractAddressesFromValue(value) {
  if (Array.isArray(value)) {
    return value.flatMap((entry) => extractAddressesFromValue(entry));
  }

  if (value === null || value === undefined) {
    return [];
  }

  const text = String(value);
  const matches = text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi);
  return matches || [];
}

function isSystemAddress(email) {
  const localPart = (email.split("@")[0] || "").toLowerCase();
  return /^(no-?reply|do-?not-?reply|mailer-daemon|postmaster)$/.test(localPart);
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function computeUniqueContactsFromEmails(emails) {
  const unique = new Set();
  let extracted = 0;
  let duplicates = 0;
  let ignored = 0;

  emails.forEach((email) => {
    ["from", "to", "cc"].forEach((field) => {
      const addresses = extractAddressesFromValue(email[field]);
      extracted += addresses.length;

      addresses.forEach((raw) => {
        const normalized = String(raw || "").trim().toLowerCase();
        if (!normalized || !isValidEmail(normalized) || isSystemAddress(normalized)) {
          ignored++;
          return;
        }

        if (unique.has(normalized)) {
          duplicates++;
          return;
        }

        unique.add(normalized);
      });
    });
  });

  state.uniqueContacts = Array.from(unique).sort((a, b) => a.localeCompare(b));
  state.contactStats = {
    extracted,
    valid: state.uniqueContacts.length,
    duplicates,
    ignored,
  };
}

function getLimitFromInput() {
  const raw = Number($("limit").value || 100);
  const safe = Number.isFinite(raw) ? raw : 100;
  const limit = Math.max(1, Math.min(Math.floor(safe), 100));
  $("limit").value = String(limit);
  return limit;
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(String(value).replace(" ", "T") + "Z");
  if (Number.isNaN(date.getTime())) return String(value);

  return date.toLocaleString(undefined, {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function setLoading(flag) {
  state.pending = Math.max(0, state.pending + (flag ? 1 : -1));
  const app = $("appRoot");
  if (state.pending > 0) {
    app.classList.add("loading");
  } else {
    app.classList.remove("loading");
  }
}

function addTrace(path, status, ok, durationMs) {
  state.trace.unshift({
    time: new Date().toLocaleTimeString(),
    path,
    status,
    ok,
    durationMs,
  });

  if (state.trace.length > 25) {
    state.trace.length = 25;
  }

  if (state.tab === "trace") {
    renderTabPanel();
  }

  renderInlineTrace();
}

function toast(message, type = "ok") {
  const host = $("toasts");
  const el = document.createElement("div");
  el.className = `toast ${type}`;
  el.textContent = message;
  host.appendChild(el);
  setTimeout(() => {
    el.remove();
  }, 3200);
}

async function fetchJSON(path) {
  const started = performance.now();
  setLoading(true);

  try {
    const response = await fetch(path, { headers: { Accept: "application/json" } });
    const text = await response.text();
    let data = null;

    try {
      data = JSON.parse(text);
    } catch {
      data = { raw: text };
    }

    const durationMs = Math.round(performance.now() - started);
    addTrace(path, response.status, response.ok, durationMs);

    if (!response.ok) {
      toast(`Request failed ${response.status}: ${path}`, "err");
    }

    return {
      ok: response.ok,
      status: response.status,
      data,
    };
  } catch (error) {
    const durationMs = Math.round(performance.now() - started);
    addTrace(path, 0, false, durationMs);
    toast(`Network error: ${path}`, "err");

    return {
      ok: false,
      status: 0,
      data: { success: false, error: { message: String(error) } },
    };
  } finally {
    setLoading(false);
  }
}

function getFilteredAndSortedRows() {
  const needle = state.localFilter.trim().toLowerCase();
  let rows = [...state.emails];

  if (needle) {
    rows = rows.filter((email) => {
      const toText = Array.isArray(email.to) ? email.to.join(" ") : "";
      const ccText = Array.isArray(email.cc) ? email.cc.join(" ") : "";
      return `${email.id} ${email.subject} ${email.from} ${toText} ${ccText}`.toLowerCase().includes(needle);
    });
  }

  rows.sort((a, b) => {
    const dir = state.sortDir === "asc" ? 1 : -1;

    if (state.sortBy === "id") {
      return ((Number(a.id) || 0) - (Number(b.id) || 0)) * dir;
    }

    if (state.sortBy === "date") {
      const av = String(a.date || "");
      const bv = String(b.date || "");
      return av.localeCompare(bv) * dir;
    }

    if (state.sortBy === "toCount") {
      const av = Array.isArray(a.to) ? a.to.length : 0;
      const bv = Array.isArray(b.to) ? b.to.length : 0;
      return (av - bv) * dir;
    }

    const av = String(a[state.sortBy] || "").toLowerCase();
    const bv = String(b[state.sortBy] || "").toLowerCase();
    return av.localeCompare(bv) * dir;
  });

  return rows;
}

function updateSortIcons() {
  ["id", "date", "from", "subject", "toCount"].forEach((key) => {
    const icon = $(`s-${key}`);
    if (!icon) return;

    if (state.sortBy === key) {
      icon.textContent = state.sortDir === "asc" ? "↑" : "↓";
    } else {
      icon.textContent = "↕";
    }
  });
}

function updateMetrics() {
  const rows = state.displayedRows;
  const senders = new Set();
  const domains = new Set();
  let recipients = 0;

  rows.forEach((email) => {
    const sender = extractEmail(email.from || "");
    if (sender) {
      senders.add(sender);
    }

    const domain = extractDomain(email.from || "");
    if (domain) {
      domains.add(domain);
    }

    if (Array.isArray(email.to)) {
      recipients += email.to.length;
    }
  });

  $("mLoaded").textContent = String(rows.length);
  $("mSenders").textContent = String(senders.size);
  $("mDomains").textContent = String(domains.size);
  $("mRecipients").textContent = String(recipients);
  $("mContacts").textContent = String(state.uniqueContacts.length);
}

function renderTable() {
  const body = $("emailsBody");
  state.displayedRows = getFilteredAndSortedRows();

  if (state.displayedRows.length === 0) {
    body.innerHTML = `
      <tr>
        <td colspan="6" class="empty">No rows match current filters.</td>
      </tr>
    `;
  } else {
    body.innerHTML = state.displayedRows
      .map((email) => {
        const selectedClass = Number(email.id) === Number(state.selectedId) ? "is-selected" : "";
        const recipients = Array.isArray(email.to) ? email.to : [];

        return `
          <tr class="data-row ${selectedClass}" data-row-id="${escapeHtml(email.id)}">
            <td class="mono">${escapeHtml(email.id)}</td>
            <td class="mono">${escapeHtml(formatDate(email.date))}</td>
            <td>${escapeHtml(email.from || "")}</td>
            <td><div class="truncate-2">${escapeHtml(email.subject || "")}</div></td>
            <td class="recipient-cell">${escapeHtml(recipients.join(", "))}</td>
            <td>
              <div class="action-stack">
                <button data-action="view" data-id="${escapeHtml(email.id)}">View</button>
                <button class="alt" data-action="attachments" data-id="${escapeHtml(email.id)}">Attachments</button>
              </div>
            </td>
          </tr>
        `;
      })
      .join("");
  }

  updateSortIcons();
  updateMetrics();
  renderMeta();
}

function renderMeta() {
  const modeText = state.mode === "search"
    ? "Mode: search"
    : state.mode === "all"
      ? "Mode: all-rows"
      : "Mode: list";
  $("modeBadge").textContent = modeText;
  $("tableMeta").textContent = `Showing ${state.displayedRows.length} rows | Raw count ${state.pagination.count} | Offset ${state.pagination.offset}`;
  $("pagerInfo").textContent = `Limit ${state.pagination.limit} | Offset ${state.pagination.offset} | Raw ${state.pagination.count}`;
  $("selectionChip").textContent = state.selectedId ? `Selected: #${state.selectedId}` : "Selected: none";
  $("dataBadge").textContent = state.emails.length > 50 ? "Data source: SQL dump/cache" : "Data source: mock/list";
}

function renderSummaryView() {
  const host = $("summaryView");

  if (!state.selectedEmail) {
    host.innerHTML = '<div class="empty">No email selected yet.</div>';
    return;
  }

  const email = state.selectedEmail;
  const recipients = Array.isArray(email.to) ? email.to : [];
  const cc = Array.isArray(email.cc) ? email.cc : [];

  host.innerHTML = `
    <div class="kv">
      <div class="k">Email ID</div>
      <div class="v mono">${escapeHtml(email.id)}</div>
    </div>
    <div class="kv">
      <div class="k">Date</div>
      <div class="v">${escapeHtml(formatDate(email.date))}</div>
    </div>
    <div class="kv">
      <div class="k">From</div>
      <div class="v">${escapeHtml(email.from || "")}</div>
    </div>
    <div class="kv">
      <div class="k">Subject</div>
      <div class="v">${escapeHtml(email.subject || "")}</div>
    </div>
    <div class="kv">
      <div class="k">To (${recipients.length})</div>
      <div class="chip-row">${recipients.length ? recipients.map((addr) => `<span class="chip">${escapeHtml(addr)}</span>`).join("") : '<span class="muted">None</span>'}</div>
    </div>
    <div class="kv">
      <div class="k">CC (${cc.length})</div>
      <div class="chip-row">${cc.length ? cc.map((addr) => `<span class="chip">${escapeHtml(addr)}</span>`).join("") : '<span class="muted">None</span>'}</div>
    </div>
  `;
}

function renderTabPanel() {
  const panel = $("tabPanel");

  if (state.tab === "summary") {
    if (!state.selectedEmail) {
      panel.innerHTML = '<div class="empty">Select an email row to inspect details.</div>';
      return;
    }

    panel.innerHTML = `
      <div class="detail-summary">
        <div class="kv">
          <div class="k">Message</div>
          <div class="v">${escapeHtml(state.selectedEmail.subject || "")}</div>
        </div>
        <div class="kv">
          <div class="k">Preview</div>
          <div class="v">${escapeHtml(state.selectedEmail.body_preview || "(no preview available)")}</div>
        </div>
        <div class="kv">
          <div class="k">Attachments Loaded</div>
          <div class="v mono">${state.selectedAttachments.length}</div>
        </div>
        <div class="chip-row">
          <span class="chip">From Domain: ${escapeHtml(extractDomain(state.selectedEmail.from || "") || "n/a")}</span>
          <span class="chip">Recipients: ${Array.isArray(state.selectedEmail.to) ? state.selectedEmail.to.length : 0}</span>
        </div>
      </div>
    `;
    return;
  }

  if (state.tab === "json") {
    const payload = state.selectedEmail
      ? { email: state.selectedEmail, attachments: state.selectedAttachments }
      : state.lastPayload || { info: "No payload yet" };

    panel.innerHTML = `<pre>${escapeHtml(JSON.stringify(payload, null, 2))}</pre>`;
    return;
  }

  if (state.tab === "attachments") {
    if (!state.selectedEmail) {
      panel.innerHTML = '<div class="empty">Select an email and click Attachments.</div>';
      return;
    }

    if (!state.selectedAttachments.length) {
      panel.innerHTML = '<div class="empty">No attachment metadata found for this email.</div>';
      return;
    }

    panel.innerHTML = `
      <div class="attachment-list">
        ${state.selectedAttachments
          .map((att) => {
            return `
              <div class="attachment-item">
                <div class="name">${escapeHtml(att.filename || "(no filename)")}</div>
                <div class="mono">ID: ${escapeHtml(att.id)} | Size: ${escapeHtml(att.size)} bytes</div>
                <div class="mono">Type: ${escapeHtml(att.type || "unknown")}</div>
              </div>
            `;
          })
          .join("")}
      </div>
    `;
    return;
  }

  if (state.tab === "contacts") {
    if (!state.uniqueContacts.length) {
      panel.innerHTML = '<div class="empty">No contacts generated yet. Load rows first.</div>';
      return;
    }

    const stats = state.contactStats;
    panel.innerHTML = `
      <div class="detail-summary" style="margin-bottom:10px;">
        <div class="chip-row">
          <span class="chip">Extracted: ${escapeHtml(stats.extracted)}</span>
          <span class="chip">Valid: ${escapeHtml(stats.valid)}</span>
          <span class="chip">Duplicates removed: ${escapeHtml(stats.duplicates)}</span>
          <span class="chip">Ignored: ${escapeHtml(stats.ignored)}</span>
        </div>
        <div class="actions" style="margin-top:8px;">
          <button class="ghost" id="btnCopyContacts">Copy Contacts</button>
        </div>
      </div>
      <div class="attachment-list">
        ${state.uniqueContacts
          .map((contact, index) => {
            return `
              <div class="attachment-item">
                <div class="name">${escapeHtml(contact)}</div>
                <div class="mono">#${escapeHtml(index + 1)}</div>
              </div>
            `;
          })
          .join("")}
      </div>
    `;

    const copyButton = panel.querySelector("#btnCopyContacts");
    if (copyButton) {
      copyButton.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(state.uniqueContacts.join("\\n"));
          toast(`Copied ${state.uniqueContacts.length} contacts`, "ok");
        } catch {
          toast("Clipboard copy failed", "err");
        }
      });
    }

    return;
  }

  if (state.tab === "trace") {
    if (!state.trace.length) {
      panel.innerHTML = '<div class="empty">No requests yet.</div>';
      return;
    }

    panel.innerHTML = `
      <div class="trace-list">
        ${state.trace
          .map((entry) => {
            return `
              <div class="trace-item ${entry.ok ? "ok" : "err"}">
                <div><strong>${escapeHtml(entry.time)}</strong> - ${escapeHtml(entry.path)}</div>
                <div class="mono">status ${escapeHtml(entry.status)} | ${escapeHtml(entry.durationMs)}ms</div>
              </div>
            `;
          })
          .join("")}
      </div>
    `;
  }
}

function renderInlineTrace() {
  const host = $("traceInline");
  if (!host) return;

  if (!state.trace.length) {
    host.classList.add("empty");
    host.textContent = "No requests yet.";
    return;
  }

  host.classList.remove("empty");
  host.innerHTML = state.trace
    .slice(0, 8)
    .map((entry) => {
      const labelClass = entry.ok ? "ok" : "err";
      return `
        <div class="trace-item ${labelClass}">
          <div><strong>${escapeHtml(entry.time)}</strong> - ${escapeHtml(entry.path)}</div>
          <div class="mono">status ${escapeHtml(entry.status)} | ${escapeHtml(entry.durationMs)}ms</div>
        </div>
      `;
    })
    .join("");
}

async function loadListEmails() {
  state.mode = "list";
  const limit = getLimitFromInput();
  const offset = Math.max(0, Number($("offset").value || 0));

  state.pagination.limit = limit;
  state.pagination.offset = offset;

  const result = await fetchJSON(`/emails?limit=${encodeURIComponent(limit)}&offset=${encodeURIComponent(offset)}`);
  state.lastPayload = result.data;

  if (!result.ok || !result.data?.success) {
    renderMeta();
    return;
  }

  state.emails = Array.isArray(result.data.data) ? result.data.data : [];
  state.pagination.count = Number(result.data.pagination?.count || state.emails.length);
  state.selectedId = null;
  state.selectedEmail = null;
  state.selectedAttachments = [];
  renderSummaryView();
  computeUniqueContactsFromEmails(state.emails);
  renderTable();
  renderTabPanel();
  toast(`Loaded ${state.emails.length} emails`, "ok");
}

async function loadAllEmails() {
  state.mode = "all";
  const limit = getLimitFromInput();
  const allRows = [];
  const seenIds = new Set();
  let offset = 0;
  const maxRequests = 20000;
  let stagnantPages = 0;

  for (let requestIndex = 0; requestIndex < maxRequests; requestIndex++) {
    const result = await fetchJSON(`/emails?limit=${encodeURIComponent(limit)}&offset=${encodeURIComponent(offset)}`);
    state.lastPayload = result.data;

    if (!result.ok || !result.data?.success) {
      break;
    }

    const batch = Array.isArray(result.data.data) ? result.data.data : [];
    let addedCount = 0;
    batch.forEach((row) => {
      const key = Number(row.id) || String(row.id);
      if (!seenIds.has(key)) {
        seenIds.add(key);
        allRows.push(row);
        addedCount++;
      }
    });

    if (batch.length < limit) {
      break;
    }

    if (addedCount === 0) {
      stagnantPages++;
      if (stagnantPages >= 3) {
        break;
      }
    } else {
      stagnantPages = 0;
    }

    offset += limit;
  }

  state.emails = allRows;
  state.pagination.limit = limit;
  state.pagination.offset = 0;
  state.pagination.count = allRows.length;
  state.selectedId = null;
  state.selectedEmail = null;
  state.selectedAttachments = [];
  renderSummaryView();
  $("offset").value = "0";

  computeUniqueContactsFromEmails(state.emails);
  renderTable();
  renderTabPanel();
  toast(`Loaded all rows: ${state.emails.length}`, "ok");
}

async function runSearch() {
  const q = $("serverSearch").value.trim();
  if (!q) {
    toast("Enter a search query first", "err");
    return;
  }

  state.mode = "search";
  const result = await fetchJSON(`/emails/search?q=${encodeURIComponent(q)}`);
  state.lastPayload = result.data;

  if (!result.ok || !result.data?.success) {
    renderMeta();
    return;
  }

  state.emails = Array.isArray(result.data.data) ? result.data.data : [];
  state.pagination.count = Number(result.data.count || state.emails.length);
  state.pagination.offset = 0;
  state.selectedId = null;
  state.selectedEmail = null;
  state.selectedAttachments = [];
  renderSummaryView();
  $("offset").value = "0";

  computeUniqueContactsFromEmails(state.emails);
  renderTable();
  renderTabPanel();
  toast(`Search returned ${state.emails.length} rows`, "ok");
}

function setActiveTab(name) {
  state.tab = name;
  document.querySelectorAll(".tab").forEach((tab) => {
    tab.classList.toggle("active", tab.dataset.tab === name);
  });
  renderTabPanel();
}

async function selectEmail(id, focusTab = "summary") {
  state.selectedId = Number(id);
  $("inspectorMeta").textContent = `Email #${state.selectedId}`;
  renderTable();

  const response = await fetchJSON(`/emails/${encodeURIComponent(state.selectedId)}`);
  if (response.ok && response.data?.success) {
    state.selectedEmail = response.data.data;
  } else {
    state.selectedEmail = state.displayedRows.find((e) => Number(e.id) === Number(state.selectedId)) || null;
  }

  renderSummaryView();

  if (focusTab === "attachments") {
    await loadAttachments(state.selectedId, false);
    setActiveTab("attachments");
  } else {
    setActiveTab(focusTab);
  }
}

async function loadAttachments(emailId, switchTab = true) {
  const response = await fetchJSON(`/emails/${encodeURIComponent(emailId)}/attachments`);
  if (response.ok && response.data?.success) {
    state.selectedAttachments = Array.isArray(response.data.data) ? response.data.data : [];
    toast(`Attachments loaded: ${state.selectedAttachments.length}`, "ok");
  } else {
    state.selectedAttachments = [];
  }

  renderTabPanel();
  if (switchTab) {
    setActiveTab("attachments");
  }
}

function changePage(direction) {
  if (state.mode !== "list") {
    toast("Pagination controls work in list mode. Reset filters to use paging.", "err");
    return;
  }

  const step = getLimitFromInput();
  const nextOffset = Math.max(0, state.pagination.offset + direction * step);
  state.pagination.offset = nextOffset;
  $("offset").value = String(nextOffset);
  loadListEmails();
}

function resetFilters() {
  $("serverSearch").value = "";
  $("localFilter").value = "";
  state.localFilter = "";
  state.sortBy = "date";
  state.sortDir = "desc";
  state.selectedId = null;
  state.selectedEmail = null;
  state.selectedAttachments = [];
  $("offset").value = "0";
  renderSummaryView();
  setActiveTab("summary");
  loadListEmails();
}

async function runHealthCheck() {
  const response = await fetchJSON("/health");
  const badge = $("apiBadge");

  if (response.ok && response.data?.success) {
    badge.classList.remove("warn");
    badge.classList.add("ok");
    badge.textContent = `API healthy: ${window.location.host}`;
    toast("Health check passed", "ok");
  } else {
    badge.classList.remove("ok");
    badge.classList.add("warn");
    badge.textContent = `API issue: ${window.location.host}`;
  }
}

async function runRootPing() {
  const response = await fetchJSON("/");
  if (response.ok && response.data?.success) {
    toast("Root endpoint reachable", "ok");
  }
}

function bindEvents() {
  $("btnLoad").addEventListener("click", loadListEmails);
  $("btnLoadAll").addEventListener("click", loadAllEmails);
  $("btnLoadAllInline").addEventListener("click", loadAllEmails);
  $("btnSearch").addEventListener("click", runSearch);
  $("btnClear").addEventListener("click", resetFilters);
  $("btnPrev").addEventListener("click", () => changePage(-1));
  $("btnNext").addEventListener("click", () => changePage(1));
  $("btnPrevBottom").addEventListener("click", () => changePage(-1));
  $("btnNextBottom").addEventListener("click", () => changePage(1));
  $("btnHealth").addEventListener("click", runHealthCheck);
  $("btnRoot").addEventListener("click", runRootPing);

  $("serverSearch").addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      runSearch();
    }
  });

  $("localFilter").addEventListener("input", (event) => {
    state.localFilter = event.target.value || "";
    renderTable();
  });

  const emailsHead = document.querySelector("#emailsTable thead");
  emailsHead?.addEventListener("click", (event) => {
    const target = event.target.closest("button[data-sort]");
    if (!target) return;

    const key = target.dataset.sort;
    if (!key) return;

    if (state.sortBy === key) {
      state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
    } else {
      state.sortBy = key;
      state.sortDir = key === "id" ? "asc" : "desc";
    }

    renderTable();
  });

  $("emailsBody").addEventListener("click", async (event) => {
    const button = event.target.closest("button[data-action]");
    if (button) {
      const action = button.dataset.action;
      const id = Number(button.dataset.id || 0);
      if (!id) return;

      if (action === "view") {
        await selectEmail(id, "summary");
      }

      if (action === "attachments") {
        await selectEmail(id, "attachments");
      }

      return;
    }

    const row = event.target.closest("tr[data-row-id]");
    if (!row) return;
    const id = Number(row.dataset.rowId || 0);
    if (!id) return;

    await selectEmail(id, "summary");
  });

  document.querySelectorAll(".tab").forEach((tabButton) => {
    tabButton.addEventListener("click", () => {
      const tab = tabButton.dataset.tab;
      if (!tab) return;
      setActiveTab(tab);
    });
  });
}

async function bootstrap() {
  $("apiBadge").textContent = `API: ${window.location.host}`;
  renderSummaryView();
  renderInlineTrace();
  bindEvents();
  await runHealthCheck();
  await loadListEmails();
}

bootstrap();
