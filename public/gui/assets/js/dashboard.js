"use strict";

const $ = (id) => document.getElementById(id);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const MAX_PAGE_LIMIT = 1000;

const VIEW_META = {
  overview: {
    title: "Overview",
    subtitle: "Archive email intelligence and contact preparation workspace.",
    primary: "Load latest emails",
  },
  emails: {
    title: "Emails",
    subtitle: "Search archived messages and inspect metadata.",
    primary: "Refresh",
  },
  contacts: {
    title: "Contacts",
    subtitle: "Review cleaned contact candidates before sync.",
    primary: "Extract contacts",
  },
  sync: {
    title: "Sync",
    subtitle: "Future Listmonk connection and synchronization workflow.",
    primary: "Connect Listmonk",
  },
  settings: {
    title: "Settings",
    subtitle: "Runtime information and diagnostics.",
    primary: "Refresh status",
  },
};

const state = {
  view: "overview",
  emails: [],
  displayedRows: [],
  selectedId: null,
  selectedEmail: null,
  selectedAttachments: [],
  selectedEmailContacts: [],
  attachmentCache: {},
  uniqueContacts: [],
  visibleContacts: [],
  aiContacts: [],
  aiSummary: {
    contacts_analyzed: 0,
    high_value_contacts: 0,
    low_confidence_contacts: 0,
    categories_count: {},
    segments_count: {},
  },
  aiStatus: {
    enabled: false,
    provider: "rule_based",
    mode: "unknown",
    notice: "AI contact intelligence status unknown.",
    batch_size: 50,
  },
  aiLoading: false,
  contactStats: {
    total_extracted_addresses: 0,
    valid_contacts: 0,
    duplicates_removed: 0,
    ignored_invalid_or_system_addresses: 0,
  },
  sortBy: "date",
  sortDir: "desc",
  drawerTab: "summary",
  mode: "list",
  pagination: {
    limit: 100,
    offset: 0,
    count: 0,
  },
  dateFrom: "",
  dateTo: "",
  localFilter: "",
  contactSearch: "",
  categoryFilter: "all",
  contactStatusFilter: "all",
  trace: [],
  pending: 0,
  isLoadingEmails: false,
  tableLoadingMessage: "Loading email records...",
  loadingAttachmentsId: null,
  lastPayload: null,
  health: "checking",
  dataSourceLabel: "Detecting",
  adminUser: null,
  csrfToken: "",
};

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatNumber(value) {
  return new Intl.NumberFormat().format(Number(value) || 0);
}

function setText(id, value) {
  const el = $(id);
  if (el) {
    el.textContent = value;
  }
}

function emptyAiSummary() {
  return {
    contacts_analyzed: 0,
    high_value_contacts: 0,
    low_confidence_contacts: 0,
    categories_count: {},
    segments_count: {},
  };
}

function formatDate(value) {
  if (!value) {
    return "Unknown";
  }

  const normalized = String(value).includes("T")
    ? String(value)
    : `${String(value).replace(" ", "T")}Z`;
  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleString(undefined, {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function extractEmail(raw) {
  if (!raw) {
    return "";
  }

  const text = String(raw);
  const match = text.match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i);
  return match ? match[0].toLowerCase() : text.trim().toLowerCase();
}

function extractDomain(raw) {
  const email = extractEmail(raw);
  if (!email.includes("@")) {
    return "";
  }

  return email.split("@")[1] || "";
}

function extractAddressesFromValue(value) {
  if (Array.isArray(value)) {
    return value.flatMap((entry) => extractAddressesFromValue(entry));
  }

  if (value === null || value === undefined) {
    return [];
  }

  const matches = String(value).match(/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/gi);
  return matches || [];
}

function isSystemAddress(email) {
  const localPart = (email.split("@")[0] || "").toLowerCase();
  return /^(no-?reply|do-?not-?reply|mailer-daemon|postmaster)$/.test(localPart);
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function guessCategory(email) {
  const domain = extractDomain(email);
  const localPart = email.split("@")[0] || "";

  if (/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster)$/i.test(localPart)) {
    return "system_noise";
  }

  if (/(^|[._%+\-])(devops|support|admin|tech|it)([._%+\-]|$)/i.test(localPart)) {
    return "technical";
  }

  if (/(^|[._%+\-])(marketing|sales|growth|campaign|crm)([._%+\-]|$)/i.test(localPart)) {
    return "marketing";
  }

  if (domain.endsWith(".edu")) {
    return "education";
  }

  if (domain.includes("gov")) {
    return "government";
  }

  return "business";
}

function categoryLabel(category) {
  const labels = {
    "education-or-local-domain": "Education / local",
    "public-sector": "Public sector",
    "business-or-general": "Business / general",
    business: "Business",
    education: "Education",
    government: "Government",
    technical: "Technical",
    marketing: "Marketing",
    internal: "Internal",
    system_noise: "System noise",
    unknown: "Unknown",
  };

  return labels[category] || category || "Uncategorized";
}

function computeContactsFromEmails(emails) {
  const contactsByEmail = new Map();
  const stats = {
    total_extracted_addresses: 0,
    valid_contacts: 0,
    duplicates_removed: 0,
    ignored_invalid_or_system_addresses: 0,
  };

  emails.forEach((email, emailIndex) => {
    const sourceId = String(email.id ?? `row-${emailIndex}`);

    ["from", "to", "cc"].forEach((field) => {
      const addresses = extractAddressesFromValue(email[field]);
      stats.total_extracted_addresses += addresses.length;

      addresses.forEach((rawAddress) => {
        const normalized = String(rawAddress || "").trim().toLowerCase();

        if (!normalized || !isValidEmail(normalized) || isSystemAddress(normalized)) {
          stats.ignored_invalid_or_system_addresses++;
          return;
        }

        if (contactsByEmail.has(normalized)) {
          stats.duplicates_removed++;
        }

        const existing = contactsByEmail.get(normalized) || {
          email: normalized,
          domain: extractDomain(normalized),
          category: guessCategory(normalized),
          segment: "Rule-based candidate",
          lead_score: 50,
          confidence: 0.5,
          reason: "Local browser rule before backend AI enrichment.",
          status: "Ready",
          sourceIds: new Set(),
        };

        existing.sourceIds.add(sourceId);
        contactsByEmail.set(normalized, existing);
      });
    });
  });

  const contacts = Array.from(contactsByEmail.values())
    .map((contact) => ({
      email: contact.email,
      domain: contact.domain,
      category: contact.category,
      segment: contact.segment,
      lead_score: contact.lead_score,
      confidence: contact.confidence,
      reason: contact.reason,
      sourceCount: contact.sourceIds.size,
      status: contact.status,
    }))
    .sort((a, b) => a.email.localeCompare(b.email));

  stats.valid_contacts = contacts.length;

  return { contacts, stats };
}

function syncContactsFromLoadedEmails() {
  const result = computeContactsFromEmails(state.emails);
  state.uniqueContacts = result.contacts;
  state.aiContacts = [];
  state.aiSummary = emptyAiSummary();
  state.contactStats = result.stats;
}

function inferDataSource() {
  if (state.emails.length === 0) {
    return "API auto";
  }

  const mockSubjects = new Set([
    "Project Kickoff Meeting",
    "Re: API Logs Export",
    "Internship Convention Signature",
    "Marketing campaign contact list",
    "Archive extraction dry run",
    "Client follow-up",
  ]);

  const looksLikeMock = state.emails.length <= 6
    && state.emails.every((email) => mockSubjects.has(email.subject));

  if (looksLikeMock) {
    return "Mock data";
  }

  if (state.mode === "all" || state.emails.length > 6) {
    return "SQL dump/cache";
  }

  return "API data";
}

function setLoading(flag) {
  state.pending = Math.max(0, state.pending + (flag ? 1 : -1));
  $("appRoot")?.classList.toggle("loading", state.pending > 0);
}

function toast(message, type = "ok") {
  const host = $("toasts");
  if (!host) {
    return;
  }

  const el = document.createElement("div");
  el.className = `toast ${type}`;
  el.textContent = message;
  host.appendChild(el);

  window.setTimeout(() => {
    el.remove();
  }, 3600);
}

function addTrace(path, status, ok, durationMs) {
  state.trace.unshift({
    time: new Date().toLocaleTimeString(),
    path,
    status,
    ok,
    durationMs,
  });

  if (state.trace.length > 40) {
    state.trace.length = 40;
  }

  renderTraceList();

  if (state.drawerTab === "trace") {
    renderDrawer();
  }
}

async function fetchJSON(path, options = {}) {
  const { toastErrors = true, method = "GET", body = null, skipAuthRedirect = false } = options;
  const started = performance.now();
  setLoading(true);

  try {
    const headers = { Accept: "application/json" };
    const request = { method, headers, credentials: "same-origin" };
    if (body !== null) {
      headers["Content-Type"] = "application/json";
      if (state.csrfToken) {
        headers["X-CSRF-Token"] = state.csrfToken;
      }
      request.body = JSON.stringify(body);
    }

    const response = await fetch(path, request);
    const text = await response.text();
    let data;

    try {
      data = JSON.parse(text);
    } catch {
      data = { success: false, raw: text };
    }

    const durationMs = Math.round(performance.now() - started);
    addTrace(path, response.status, response.ok, durationMs);

    if (response.status === 401 && !skipAuthRedirect) {
      window.location.href = "/login";
    }

    if (!response.ok && toastErrors) {
      const message = data?.error?.message || `Request failed with status ${response.status}`;
      toast(message, "err");
    }

    return {
      ok: response.ok,
      status: response.status,
      data,
    };
  } catch (error) {
    const durationMs = Math.round(performance.now() - started);
    addTrace(path, 0, false, durationMs);

    if (toastErrors) {
      toast("API is offline or unreachable.", "err");
    }

    return {
      ok: false,
      status: 0,
      data: {
        success: false,
        error: { message: String(error) },
      },
    };
  } finally {
    setLoading(false);
  }
}

async function loadCurrentUser() {
  const response = await fetchJSON("/auth/me", { toastErrors: false, skipAuthRedirect: true });
  const data = response.data?.data || {};
  if (!response.ok || !data.authenticated) {
    window.location.href = "/login";
    return false;
  }

  state.adminUser = data.user || null;
  state.csrfToken = data.csrf_token || "";
  renderAdminIdentity();
  return true;
}

function renderAdminIdentity() {
  const label = state.adminUser?.name || state.adminUser?.email || "Admin";
  setText("adminIdentity", label);
  setText("adminEmail", state.adminUser?.email || "Signed in");
}

async function logout() {
  const response = await fetchJSON("/auth/logout", {
    method: "POST",
    body: { csrf_token: state.csrfToken },
    toastErrors: true,
    skipAuthRedirect: true,
  });

  if (response.ok) {
    window.location.href = "/login";
  }
}

function setView(view) {
  if (!VIEW_META[view]) {
    return;
  }

  state.view = view;

  $$("[data-view-panel]").forEach((panel) => {
    panel.classList.toggle("active", panel.dataset.viewPanel === view);
  });

  $$(".nav-item").forEach((item) => {
    item.classList.toggle("active", item.dataset.view === view);
  });

  const meta = VIEW_META[view];
  setText("pageTitle", meta.title);
  setText("pageSubtitle", meta.subtitle);

  const primary = $("topPrimaryAction");
  if (primary) {
    primary.textContent = meta.primary;
    const disabled = view === "sync";
    primary.disabled = disabled;
    primary.classList.toggle("disabled", disabled);
  }

  if (view === "contacts" && state.uniqueContacts.length === 0) {
    loadPersistedContacts(false);
  }
}

function handleTopPrimaryAction() {
  if (state.view === "overview" || state.view === "emails") {
    loadListEmails();
    return;
  }

  if (state.view === "contacts") {
    extractContactsAction();
    return;
  }

  if (state.view === "settings") {
    runHealthCheck(true);
  }
}

function updateHealthUI() {
  const dot = $("healthDot");
  const label = $("apiBadge");
  const overview = $("overviewHealth");

  dot?.classList.remove("ok", "warn", "err", "neutral");

  if (state.health === "ok") {
    dot?.classList.add("ok");
    if (label) label.textContent = "API healthy";
    if (overview) overview.textContent = "API healthy";
    return;
  }

  if (state.health === "offline") {
    dot?.classList.add("err");
    if (label) label.textContent = "API offline";
    if (overview) overview.textContent = "API offline";
    return;
  }

  dot?.classList.add("warn");
  if (label) label.textContent = "API status unknown";
  if (overview) overview.textContent = "API status unknown";
}

async function runHealthCheck(showToast = false) {
  state.health = "checking";
  updateHealthUI();

  const response = await fetchJSON("/health", { toastErrors: showToast });

  if (response.ok && response.data?.success) {
    state.health = "ok";
    if (showToast) {
      toast("Health check passed.", "ok");
    }
  } else {
    state.health = "offline";
    if (showToast) {
      toast("Health check failed.", "err");
    }
  }

  updateHealthUI();
}

async function runRootPing() {
  const response = await fetchJSON("/");
  if (response.ok && response.data?.success) {
    toast("Root endpoint reachable.", "ok");
  }
}

async function loadAiStatus() {
  const response = await fetchJSON("/ai/status", { toastErrors: false });
  if (response.ok && response.data?.success && response.data.data) {
    state.aiStatus = response.data.data;
  }

  renderStatusBadges();
  renderAiInsights();
}

function sourceCountsFromContacts() {
  const counts = {};
  state.uniqueContacts.forEach((contact) => {
    counts[contact.email] = contact.sourceCount || 1;
  });
  return counts;
}

async function analyzeContactsWithAi(showToast = false) {
  if (state.uniqueContacts.length === 0) {
    state.aiContacts = [];
    state.aiSummary = emptyAiSummary();
    renderAiInsights();
    renderContactsTable();
    if (showToast) {
      toast("Load or extract contacts before running AI insights.", "warn");
    }
    return;
  }

  state.aiLoading = true;
  renderAiInsights();

  const response = await fetchJSON("/contacts/intelligence", {
    method: "POST",
    body: {
      contacts: state.uniqueContacts.map((contact) => contact.email),
      source_counts: sourceCountsFromContacts(),
    },
  });

  state.aiLoading = false;

  if (!response.ok || !response.data?.success || !response.data.data) {
    renderAiInsights();
    if (showToast) {
      toast("AI insights could not be generated.", "err");
    }
    return;
  }

  const payload = response.data.data;
  const sourceCounts = sourceCountsFromContacts();
  state.aiStatus = {
    enabled: Boolean(payload.enabled),
    provider: payload.provider || "rule_based",
    mode: payload.mode || "unknown",
    notice: payload.notice || "AI contact intelligence completed.",
    batch_size: payload.batch_size || 50,
  };
  state.aiSummary = payload.stats || emptyAiSummary();
  state.aiContacts = Array.isArray(payload.contacts)
    ? payload.contacts.map((contact) => ({
        ...contact,
        sourceCount: sourceCounts[contact.email] || 1,
        status: contact.category === "system_noise" ? "Review" : "Ready",
      }))
    : [];

  renderAiInsights();
  renderContactsTable();

  if (showToast) {
    toast(`AI insights ready for ${formatNumber(state.aiContacts.length)} contacts.`, "ok");
  }
}

function mapPersistedContact(row) {
  return {
    id: Number(row.id || 0),
    email: row.email || "",
    domain: row.domain || extractDomain(row.email || ""),
    category: row.category || "business",
    segment: `${row.status || "pending"} review`,
    lead_score: 0,
    confidence: 0,
    reason: row.notes || `Saved contact in ${row.status || "pending"} status.`,
    sourceCount: Number(row.source_count || 0),
    status: row.status || "pending",
    notes: row.notes || "",
  };
}

async function loadPersistedContacts(showToast = false) {
  const params = new URLSearchParams({
    status: state.contactStatusFilter || "all",
    limit: "1000",
    offset: "0",
  });
  if (state.contactSearch.trim()) {
    params.set("q", state.contactSearch.trim());
  }

  const response = await fetchJSON(`/contacts?${params.toString()}`, { toastErrors: showToast });
  if (!response.ok || !response.data?.success) {
    return false;
  }

  const data = response.data.data || {};
  const rows = Array.isArray(data.contacts) ? data.contacts : Array.isArray(response.data.data) ? response.data.data : [];
  state.uniqueContacts = rows.map(mapPersistedContact);
  state.aiContacts = [];
  await refreshContactStats(false);
  renderMetrics();
  renderContactsTable();

  if (showToast) {
    toast(`Loaded ${formatNumber(state.uniqueContacts.length)} saved contacts.`, "ok");
  }

  return true;
}

async function refreshContactStats(showToast = false) {
  const response = await fetchJSON("/contacts/stats", { toastErrors: showToast });
  if (!response.ok || !response.data?.success || !response.data.data) {
    return false;
  }

  const stats = response.data.data;
  state.contactStats = {
    total_extracted_addresses: state.contactStats.total_extracted_addresses,
    valid_contacts: Number(stats.total || 0),
    duplicates_removed: state.contactStats.duplicates_removed,
    ignored_invalid_or_system_addresses: Number(stats.ignored || 0) + Number(stats.blocked || 0),
  };

  return true;
}

function getLimitFromInput() {
  const input = $("limit");
  const raw = Number(input?.value || 100);
  const safe = Number.isFinite(raw) ? raw : 100;
  const limit = Math.max(1, Math.min(Math.floor(safe), MAX_PAGE_LIMIT));

  if (input) {
    input.value = String(limit);
  }

  return limit;
}

function getOffsetFromInput() {
  const input = $("offset");
  const raw = Number(input?.value || 0);
  const safe = Number.isFinite(raw) ? raw : 0;
  const offset = Math.max(0, Math.floor(safe));

  if (input) {
    input.value = String(offset);
  }

  return offset;
}

function getDateRangeFromInputs() {
  const dateFrom = $("dateFrom")?.value || "";
  const dateTo = $("dateTo")?.value || "";

  state.dateFrom = dateFrom;
  state.dateTo = dateTo;

  return { dateFrom, dateTo };
}

function dateQueryString() {
  const { dateFrom, dateTo } = getDateRangeFromInputs();
  const params = new URLSearchParams();

  if (dateFrom) {
    params.set("date_from", dateFrom);
  }

  if (dateTo) {
    params.set("date_to", dateTo);
  }

  return params.toString();
}

function dateRangeLabel() {
  if (state.dateFrom && state.dateTo) {
    return ` | Date ${state.dateFrom} to ${state.dateTo}`;
  }

  if (state.dateFrom) {
    return ` | From ${state.dateFrom}`;
  }

  if (state.dateTo) {
    return ` | Until ${state.dateTo}`;
  }

  return "";
}

function hasInvalidDateRange() {
  const { dateFrom, dateTo } = getDateRangeFromInputs();
  return Boolean(dateFrom && dateTo && dateFrom > dateTo);
}

function clearSelection() {
  state.selectedId = null;
  state.selectedEmail = null;
  state.selectedAttachments = [];
  state.selectedEmailContacts = [];
}

function setEmailLoading(message) {
  state.isLoadingEmails = true;
  state.tableLoadingMessage = message;
  renderEmailTable();
  renderOverviewSamples();
}

function finishEmailLoading() {
  state.isLoadingEmails = false;
}

async function loadListEmails(options = {}) {
  const { toastOnSuccess = true } = options;
  if (hasInvalidDateRange()) {
    toast("Date from must be before Date to.", "warn");
    return;
  }

  state.mode = "list";
  const limit = getLimitFromInput();
  const offset = getOffsetFromInput();

  state.pagination.limit = limit;
  state.pagination.offset = offset;
  const dateParams = dateQueryString();
  const query = new URLSearchParams({
    limit: String(limit),
    offset: String(offset),
  });
  if (dateParams) {
    const dates = new URLSearchParams(dateParams);
    dates.forEach((value, key) => query.set(key, value));
  }

  setEmailLoading("Loading latest email records...");
  const result = await fetchJSON(`/emails?${query.toString()}`);
  state.lastPayload = result.data;
  finishEmailLoading();

  if (!result.ok || !result.data?.success) {
    renderAll();
    return;
  }

  state.emails = Array.isArray(result.data.data) ? result.data.data : [];
  state.pagination.count = Number(result.data.pagination?.count || state.emails.length);
  clearSelection();
  syncContactsFromLoadedEmails();
  renderAll();
  await analyzeContactsWithAi(false);

  if (toastOnSuccess) {
    toast(`Loaded ${state.emails.length} emails.`, "ok");
  }
}

async function runSearch() {
  const query = $("serverSearch")?.value.trim() || "";

  if (!query) {
    toast("Enter a server search query first.", "warn");
    return;
  }

  if (hasInvalidDateRange()) {
    toast("Date from must be before Date to.", "warn");
    return;
  }

  state.mode = "search";
  state.pagination.offset = 0;
  if ($("offset")) {
    $("offset").value = "0";
  }

  const dateParams = dateQueryString();
  setEmailLoading("Searching archive...");
  const result = await fetchJSON(`/emails/search?q=${encodeURIComponent(query)}${dateParams ? `&${dateParams}` : ""}`);
  state.lastPayload = result.data;
  finishEmailLoading();

  if (!result.ok || !result.data?.success) {
    renderAll();
    return;
  }

  state.emails = Array.isArray(result.data.data) ? result.data.data : [];
  state.pagination.count = Number(result.data.count || state.emails.length);
  clearSelection();
  syncContactsFromLoadedEmails();
  renderAll();
  await analyzeContactsWithAi(false);
  toast(`Search returned ${state.emails.length} rows.`, "ok");
}

async function loadFullArchive() {
  if ($("limit")) $("limit").value = String(MAX_PAGE_LIMIT);

  state.mode = "list";
  toast(
    `Loading ${formatNumber(MAX_PAGE_LIMIT)} emails. Use offset or Next to continue through the archive.`,
    "warn"
  );
  await loadListEmails();
}

function changePage(direction) {
  if (state.mode !== "list") {
    toast("Pagination is available in list mode. Reset filters to page through the archive.", "warn");
    return;
  }

  const step = getLimitFromInput();
  const nextOffset = Math.max(0, state.pagination.offset + direction * step);
  state.pagination.offset = nextOffset;
  if ($("offset")) {
    $("offset").value = String(nextOffset);
  }

  loadListEmails();
}

function resetFilters() {
  if ($("serverSearch")) $("serverSearch").value = "";
  if ($("localFilter")) $("localFilter").value = "";
  if ($("offset")) $("offset").value = "0";
  if ($("dateFrom")) $("dateFrom").value = "";
  if ($("dateTo")) $("dateTo").value = "";

  state.localFilter = "";
  state.dateFrom = "";
  state.dateTo = "";
  state.sortBy = "date";
  state.sortDir = "desc";
  clearSelection();
  closeDrawer();
  loadListEmails();
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
    const direction = state.sortDir === "asc" ? 1 : -1;

    if (state.sortBy === "date") {
      return String(a.date || "").localeCompare(String(b.date || "")) * direction;
    }

    if (state.sortBy === "toCount") {
      const aCount = Array.isArray(a.to) ? a.to.length : 0;
      const bCount = Array.isArray(b.to) ? b.to.length : 0;
      return (aCount - bCount) * direction;
    }

    const aValue = String(a[state.sortBy] || "").toLowerCase();
    const bValue = String(b[state.sortBy] || "").toLowerCase();
    return aValue.localeCompare(bValue) * direction;
  });

  return rows;
}

function updateSortIcons() {
  ["date", "from", "subject", "toCount"].forEach((key) => {
    const icon = $(`s-${key}`);
    if (!icon) {
      return;
    }

    icon.textContent = state.sortBy === key
      ? (state.sortDir === "asc" ? "↑" : "↓")
      : "↕";
  });
}

function renderSkeletonRows() {
  return Array.from({ length: 5 })
    .map(() => `
      <tr>
        <td data-label="Date"><div class="skeleton-line" style="width: 110px;"></div></td>
        <td data-label="Sender"><div class="skeleton-line" style="width: 170px;"></div></td>
        <td data-label="Subject"><div class="skeleton-line" style="width: 100%;"></div></td>
        <td data-label="Recipients"><div class="skeleton-line" style="width: 70px;"></div></td>
        <td data-label="Attachments"><div class="skeleton-line" style="width: 70px;"></div></td>
        <td data-label="Actions"><div class="skeleton-line" style="width: 120px;"></div></td>
      </tr>
    `)
    .join("");
}

function renderEmailTable() {
  const body = $("emailsBody");
  if (!body) {
    return;
  }

  if (state.isLoadingEmails) {
    state.displayedRows = [];
    body.innerHTML = renderSkeletonRows();
    setText("tableMeta", state.tableLoadingMessage);
    updatePagination();
    return;
  }

  state.displayedRows = getFilteredAndSortedRows();

  if (state.displayedRows.length === 0) {
    body.innerHTML = `
      <tr class="empty-row">
        <td colspan="6">
          <div class="empty-state compact">
            <span class="material-symbols-outlined" aria-hidden="true">inbox</span>
            <h3>No email rows found</h3>
            <p>Load records, change your search, or clear local filters.</p>
          </div>
        </td>
      </tr>
    `;
  } else {
    body.innerHTML = state.displayedRows.map((email) => {
      const selectedClass = Number(email.id) === Number(state.selectedId) ? "is-selected" : "";
      const recipients = Array.isArray(email.to) ? email.to : [];
      const senderEmail = extractEmail(email.from || "");
      const senderDisplay = senderEmail || String(email.from || "Unknown sender");
      const senderDomain = extractDomain(email.from || "");
      const attachmentKey = String(email.id);
      const hasAttachmentCache = Object.prototype.hasOwnProperty.call(state.attachmentCache, attachmentKey);
      const attachmentLabel = hasAttachmentCache
        ? `${state.attachmentCache[attachmentKey].length} found`
        : "Check";

      return `
        <tr class="${selectedClass}" data-row-id="${escapeHtml(email.id)}" tabindex="0">
          <td data-label="Date" class="date-cell">${escapeHtml(formatDate(email.date))}</td>
          <td data-label="Sender" class="sender-cell">
            <span class="sender-main">${escapeHtml(senderDisplay)}</span>
            <span class="sender-domain">${escapeHtml(senderDomain || "unknown domain")}</span>
          </td>
          <td data-label="Subject" class="subject-cell">
            <span class="subject-text">${escapeHtml(email.subject || "(no subject)")}</span>
            <span class="subject-preview">${escapeHtml(email.body_preview || "No body preview available")}</span>
          </td>
          <td data-label="Recipients">
            <span class="recipient-count">${formatNumber(recipients.length)}</span>
          </td>
          <td data-label="Attachments">
            <button class="link-button ghost" type="button" data-action="attachments" data-id="${escapeHtml(email.id)}">${escapeHtml(attachmentLabel)}</button>
          </td>
          <td data-label="Actions">
            <div class="row-actions">
              <button class="link-button" type="button" data-action="inspect" data-id="${escapeHtml(email.id)}">Inspect</button>
            </div>
          </td>
        </tr>
      `;
    }).join("");
  }

  updateSortIcons();
  updatePagination();
}

function updatePagination() {
  const count = state.mode === "list" ? state.pagination.count : state.emails.length;
  const visible = state.displayedRows.length;
  const dateLabel = dateRangeLabel();
  const label = state.mode === "search"
    ? `Search results: ${formatNumber(visible)} shown from ${formatNumber(count)} returned${dateLabel}`
    : state.mode === "all"
      ? `Full archive working set: ${formatNumber(visible)} shown from ${formatNumber(count)} loaded${dateLabel}`
      : `Showing ${formatNumber(visible)} rows | Limit ${state.pagination.limit} | Offset ${formatNumber(state.pagination.offset)}${dateLabel}`;

  setText("tableMeta", label);
  setText("pagerInfo", label);
  setText("selectionChip", state.selectedId ? `Selected email #${state.selectedId}` : "No row selected");

  const prevDisabled = state.mode !== "list" || state.pagination.offset === 0;
  const nextDisabled = state.mode !== "list" || state.pagination.count < state.pagination.limit;

  ["btnPrev"].forEach((id) => {
    const button = $(id);
    if (button) button.disabled = prevDisabled;
  });

  ["btnNext"].forEach((id) => {
    const button = $(id);
    if (button) button.disabled = nextDisabled;
  });
}

function renderMetrics() {
  const senders = new Set();
  const domains = new Set();
  let recipientVolume = 0;

  state.emails.forEach((email) => {
    const sender = extractEmail(email.from || "");
    if (sender) {
      senders.add(sender);
    }

    const domain = extractDomain(email.from || "");
    if (domain) {
      domains.add(domain);
    }

    if (Array.isArray(email.to)) {
      recipientVolume += email.to.length;
    }
  });

  setText("mLoaded", formatNumber(state.emails.length));
  setText("mSenders", formatNumber(senders.size));
  setText("mDomains", formatNumber(domains.size));
  setText("mRecipients", formatNumber(recipientVolume));
  setText("mContacts", formatNumber(state.uniqueContacts.length));

  setText("cExtracted", formatNumber(state.contactStats.total_extracted_addresses));
  setText("cValid", formatNumber(state.contactStats.valid_contacts));
  setText("cDuplicates", formatNumber(state.contactStats.duplicates_removed));
  setText("cIgnored", formatNumber(state.contactStats.ignored_invalid_or_system_addresses));
  setText("cUnique", formatNumber(state.uniqueContacts.length));
}

function renderOverviewSamples() {
  const host = $("overviewSamples");
  if (!host) {
    return;
  }

  if (state.isLoadingEmails) {
    host.innerHTML = Array.from({ length: 4 })
      .map(() => `
        <div class="sample-row">
          <div class="skeleton-line"></div>
          <div class="sample-title"><div class="skeleton-line"></div></div>
          <div class="skeleton-line"></div>
        </div>
      `)
      .join("");
    setText("overviewSamplesMeta", "Loading email samples...");
    return;
  }

  if (state.emails.length === 0) {
    host.innerHTML = `
      <div class="empty-state">
        <span class="material-symbols-outlined" aria-hidden="true">inbox</span>
        <h3>No emails loaded yet</h3>
        <p>Load the latest records to inspect archive metadata.</p>
      </div>
    `;
    setText("overviewSamplesMeta", "Load emails to preview archive data.");
    return;
  }

  setText("overviewSamplesMeta", `${formatNumber(state.emails.length)} emails in the current working set.`);
  host.innerHTML = state.emails.slice(0, 6).map((email) => `
    <button class="sample-row" type="button" data-sample-id="${escapeHtml(email.id)}">
      <span class="sample-date">${escapeHtml(formatDate(email.date))}</span>
      <span class="sample-title">
        <strong>${escapeHtml(email.subject || "(no subject)")}</strong>
        <span>${escapeHtml(extractEmail(email.from || "") || email.from || "Unknown sender")}</span>
      </span>
      <span class="tag">Inspect</span>
    </button>
  `).join("");
}

function renderPipelineSteps() {
  const host = $("pipelineSteps");
  if (!host) {
    return;
  }

  const loaded = state.emails.length > 0;
  const contactsReady = state.uniqueContacts.length > 0;
  const stats = state.contactStats;
  const steps = [
    {
      title: "Extract addresses",
      detail: loaded
        ? `${formatNumber(stats.total_extracted_addresses)} raw addresses found in loaded emails.`
        : "Load emails to start extracting addresses.",
      done: loaded && stats.total_extracted_addresses > 0,
    },
    {
      title: "Clean invalid/system emails",
      detail: loaded
        ? `${formatNumber(stats.ignored_invalid_or_system_addresses)} invalid or system addresses ignored.`
        : "System addresses like noreply and mailer-daemon are filtered.",
      done: loaded,
    },
    {
      title: "Remove duplicates",
      detail: loaded
        ? `${formatNumber(stats.duplicates_removed)} duplicate addresses removed.`
        : "Repeated contacts are collapsed into one candidate.",
      done: loaded,
    },
    {
      title: "Classify contacts",
      detail: contactsReady
        ? `${formatNumber(state.uniqueContacts.length)} contacts categorized with rule-based plus AI-ready enrichment.`
        : "Contacts are grouped by simple domain rules, then enriched when AI is enabled.",
      done: contactsReady,
    },
    {
      title: "Prepare Listmonk payload",
      detail: contactsReady
        ? "Payload preview is ready. Live Listmonk sync is still pending."
        : "Listmonk sync is not connected yet.",
      done: contactsReady,
      pending: !contactsReady,
    },
  ];

  host.innerHTML = steps.map((step) => {
    const iconClass = step.done ? "ok" : "pending";
    const icon = step.done ? "check" : "hourglass_top";

    return `
      <div class="step-item">
        <span class="step-icon ${iconClass}">
          <span class="material-symbols-outlined" aria-hidden="true">${icon}</span>
        </span>
        <span>
          <strong>${escapeHtml(step.title)}</strong>
          <span>${escapeHtml(step.detail)}</span>
        </span>
      </div>
    `;
  }).join("");
}

function getFilteredContacts() {
  const query = state.contactSearch.trim().toLowerCase();
  const contacts = state.uniqueContacts;

  return contacts.filter((contact) => {
    const matchesQuery = !query
      || contact.email.toLowerCase().includes(query)
      || contact.domain.toLowerCase().includes(query)
      || String(contact.segment || "").toLowerCase().includes(query)
      || String(contact.reason || "").toLowerCase().includes(query);
    const matchesCategory = state.categoryFilter === "all" || contact.category === state.categoryFilter;
    const matchesStatus = state.contactStatusFilter === "all" || contact.status === state.contactStatusFilter;

    return matchesQuery && matchesCategory && matchesStatus;
  });
}

function getContactRowsForActions() {
  return state.uniqueContacts;
}

function renderBreakdownList(map, emptyText) {
  const entries = Object.entries(map || {})
    .filter(([, count]) => Number(count) > 0)
    .sort((a, b) => Number(b[1]) - Number(a[1]));

  if (!entries.length) {
    return `<div class="empty-mini">${escapeHtml(emptyText)}</div>`;
  }

  const total = entries.reduce((sum, [, count]) => sum + Number(count || 0), 0) || 1;

  return entries.map(([label, count]) => {
    const percent = Math.round((Number(count) / total) * 100);
    const displayLabel = map === state.aiSummary.categories_count ? categoryLabel(label) : label;

    return `
      <div class="breakdown-row">
        <span>${escapeHtml(displayLabel)}</span>
        <strong>${formatNumber(count)}</strong>
        <div class="breakdown-bar" aria-hidden="true">
          <span style="width: ${percent}%"></span>
        </div>
      </div>
    `;
  }).join("");
}

function renderAiInsights() {
  setText("aiNotice", state.aiLoading ? "Analyzing contacts with the configured intelligence provider..." : state.aiStatus.notice);
  setText("aiAnalyzed", formatNumber(state.aiSummary.contacts_analyzed));
  setText("aiHighValue", formatNumber(state.aiSummary.high_value_contacts));
  setText("aiLowConfidence", formatNumber(state.aiSummary.low_confidence_contacts));

  const button = $("btnRunAiInsights");
  if (button) {
    button.disabled = state.aiLoading || state.uniqueContacts.length === 0;
    button.textContent = state.aiLoading ? "Analyzing..." : "Run AI insights";
  }

  const categoryHost = $("aiCategoryBreakdown");
  if (categoryHost) {
    categoryHost.innerHTML = renderBreakdownList(state.aiSummary.categories_count, "No AI categories yet.");
  }

  const segmentHost = $("aiSegmentBreakdown");
  if (segmentHost) {
    segmentHost.innerHTML = renderBreakdownList(state.aiSummary.segments_count, "No AI segments yet.");
  }
}

function renderContactsTable() {
  const body = $("contactsBody");
  if (!body) {
    return;
  }

  const contactRows = getContactRowsForActions();
  const enrichmentLabel = "saved";
  state.visibleContacts = getFilteredContacts();
  setText(
    "contactsMeta",
    contactRows.length
      ? `${formatNumber(state.visibleContacts.length)} shown from ${formatNumber(contactRows.length)} ${enrichmentLabel} contacts.`
      : "Load emails and extract contacts to populate this table."
  );

  if (state.visibleContacts.length === 0) {
    body.innerHTML = `
      <tr class="empty-row">
        <td colspan="7">
          <div class="empty-state compact">
            <span class="material-symbols-outlined" aria-hidden="true">group</span>
            <h3>No contacts to show</h3>
            <p>Load emails, extract contacts, or clear contact filters.</p>
          </div>
        </td>
      </tr>
    `;
    return;
  }

  body.innerHTML = state.visibleContacts.map((contact) => `
    <tr data-contact-id="${escapeHtml(contact.id || "")}">
      <td data-label="Email">
        <span class="contact-main">${escapeHtml(contact.email)}</span>
        <span class="contact-domain">${escapeHtml(contact.domain)}</span>
      </td>
      <td data-label="Domain">${escapeHtml(contact.domain || "unknown")}</td>
      <td data-label="Category"><span class="category-badge">${escapeHtml(categoryLabel(contact.category))}</span></td>
      <td data-label="Sources">${formatNumber(contact.sourceCount)}</td>
      <td data-label="Status"><span class="status-badge">${escapeHtml(contact.status)}</span></td>
      <td data-label="Notes" class="reason-cell">${escapeHtml(contact.notes || "-")}</td>
      <td data-label="Actions">
        <div class="row-actions">
          ${renderContactActions(contact)}
        </div>
      </td>
    </tr>
  `).join("");
}

function renderContactActions(contact) {
  const actions = [
    ["approved", "Approve"],
    ["ignored", "Ignore"],
    ["blocked", "Block"],
    ["pending", "Reset"],
  ];

  return actions.map(([status, label]) => `
    <button
      class="link-button ${contact.status === status ? "ghost" : ""}"
      type="button"
      data-contact-action="${status}"
      data-contact-id="${escapeHtml(contact.id)}"
      ${contact.status === status ? "disabled" : ""}
    >${escapeHtml(label)}</button>
  `).join("");
}

function renderStatusBadges() {
  state.dataSourceLabel = inferDataSource();
  setText("dataBadge", `Data source: ${state.dataSourceLabel}`);
  setText("sidebarDataSource", state.dataSourceLabel);
  setText("settingDataSource", state.dataSourceLabel);
  setText("settingApiUrl", window.location.origin);

  const cacheStatus = state.dataSourceLabel === "SQL dump/cache"
    ? "Likely SQL dump/cache"
    : state.dataSourceLabel === "Mock data"
      ? "Mock fallback"
      : "Not exposed";

  setText("settingCacheStatus", cacheStatus);
  setText("settingAiMode", state.aiStatus.enabled ? `${state.aiStatus.provider} (${state.aiStatus.mode})` : "Rule-based fallback");
  setText("settingAiNotice", state.aiStatus.notice);
}

function renderAll() {
  renderStatusBadges();
  renderMetrics();
  renderOverviewSamples();
  renderPipelineSteps();
  renderEmailTable();
  renderAiInsights();
  renderContactsTable();
  renderTraceList();

  if ($("detailDrawer")?.classList.contains("open")) {
    renderDrawer();
  }
}

async function openEmail(id, tabName = "summary") {
  const numericId = Number(id);
  if (!numericId) {
    return;
  }

  state.selectedId = numericId;
  state.selectedEmail = state.emails.find((email) => Number(email.id) === numericId) || null;
  state.selectedAttachments = Object.prototype.hasOwnProperty.call(state.attachmentCache, String(numericId))
    ? state.attachmentCache[String(numericId)]
    : [];

  renderEmailTable();
  openDrawer(tabName);

  const response = await fetchJSON(`/emails/${encodeURIComponent(numericId)}`);
  if (response.ok && response.data?.success) {
    state.selectedEmail = response.data.data;
  }

  if (tabName === "attachments") {
    await ensureAttachmentsLoaded(numericId);
  } else {
    renderDrawer();
  }
}

function openDrawer(tabName = "summary") {
  state.drawerTab = tabName;
  const drawer = $("detailDrawer");
  const backdrop = $("drawerBackdrop");

  if (drawer) {
    drawer.classList.add("open");
    drawer.setAttribute("aria-hidden", "false");
  }

  if (backdrop) {
    backdrop.hidden = false;
  }

  document.body.classList.add("drawer-open");
  renderDrawer();
}

function closeDrawer() {
  const drawer = $("detailDrawer");
  const backdrop = $("drawerBackdrop");

  if (drawer) {
    drawer.classList.remove("open");
    drawer.setAttribute("aria-hidden", "true");
  }

  if (backdrop) {
    backdrop.hidden = true;
  }

  document.body.classList.remove("drawer-open");
}

async function setDrawerTab(tabName) {
  state.drawerTab = tabName;
  renderDrawer();

  if (tabName === "attachments" && state.selectedId) {
    await ensureAttachmentsLoaded(state.selectedId);
  }
}

async function ensureAttachmentsLoaded(emailId) {
  const key = String(emailId);

  if (Object.prototype.hasOwnProperty.call(state.attachmentCache, key)) {
    state.selectedAttachments = state.attachmentCache[key];
    renderDrawer();
    renderEmailTable();
    return;
  }

  state.loadingAttachmentsId = Number(emailId);
  renderDrawer();

  const response = await fetchJSON(`/emails/${encodeURIComponent(emailId)}/attachments`);

  if (response.ok && response.data?.success) {
    state.attachmentCache[key] = Array.isArray(response.data.data) ? response.data.data : [];
    state.selectedAttachments = state.attachmentCache[key];
    toast(`Attachments loaded: ${state.selectedAttachments.length}.`, "ok");
  } else {
    state.attachmentCache[key] = [];
    state.selectedAttachments = [];
  }

  state.loadingAttachmentsId = null;
  renderDrawer();
  renderEmailTable();
}

function renderDrawer() {
  const body = $("drawerBody");
  if (!body) {
    return;
  }

  const selected = state.selectedEmail;
  setText("drawerKicker", selected ? `Email #${selected.id}` : "Email detail");
  setText("drawerTitle", selected ? (selected.subject || "(no subject)") : "No email selected");

  $$(".drawer-tab").forEach((tab) => {
    tab.classList.toggle("active", tab.dataset.drawerTab === state.drawerTab);
  });

  if (!selected) {
    body.innerHTML = `
      <div class="empty-state">
        <span class="material-symbols-outlined" aria-hidden="true">mail</span>
        <h3>Select an email</h3>
        <p>Choose a row in the archive to inspect its metadata.</p>
      </div>
    `;
    return;
  }

  if (state.drawerTab === "summary") {
    body.innerHTML = renderSummaryDrawer(selected);
    return;
  }

  if (state.drawerTab === "attachments") {
    body.innerHTML = renderAttachmentsDrawer(selected);
    return;
  }

  if (state.drawerTab === "contacts") {
    body.innerHTML = renderSelectedContactsDrawer(selected);
    return;
  }

  if (state.drawerTab === "json") {
    body.innerHTML = `
      <pre>${escapeHtml(JSON.stringify({
        email: selected,
        attachments: state.selectedAttachments,
      }, null, 2))}</pre>
    `;
    return;
  }

  if (state.drawerTab === "trace") {
    body.innerHTML = renderDrawerTrace();
  }
}

function renderSummaryDrawer(email) {
  const to = Array.isArray(email.to) ? email.to : [];
  const cc = Array.isArray(email.cc) ? email.cc : [];

  return `
    <div class="detail-stack">
      <div class="detail-card">
        <h3>Message summary</h3>
        <div class="kv-grid">
          <div class="kv">
            <span>Subject</span>
            <strong>${escapeHtml(email.subject || "(no subject)")}</strong>
          </div>
          <div class="kv">
            <span>From</span>
            <div>${escapeHtml(email.from || "Unknown sender")}</div>
          </div>
          <div class="kv">
            <span>Date</span>
            <div>${escapeHtml(formatDate(email.date))}</div>
          </div>
          <div class="kv">
            <span>To (${to.length})</span>
            <div class="chip-row">${renderChips(to)}</div>
          </div>
          <div class="kv">
            <span>CC (${cc.length})</span>
            <div class="chip-row">${renderChips(cc)}</div>
          </div>
          <div class="kv">
            <span>Body preview</span>
            <div>${escapeHtml(email.body_preview || "No body preview available.")}</div>
          </div>
        </div>
      </div>
      <div class="detail-card">
        <h3>Quick actions</h3>
        <div class="drawer-actions">
          <button class="button secondary" type="button" data-drawer-action="copy-sender">Copy sender</button>
          <button class="button primary" type="button" data-drawer-action="extract-selected">Extract contacts from this email</button>
        </div>
      </div>
    </div>
  `;
}

function renderChips(values) {
  if (!Array.isArray(values) || values.length === 0) {
    return `<span class="tag">None</span>`;
  }

  return values
    .map((value) => `<span class="chip">${escapeHtml(value)}</span>`)
    .join("");
}

function renderAttachmentsDrawer(email) {
  if (state.loadingAttachmentsId === Number(email.id)) {
    return `
      <div class="detail-stack">
        <div class="attachment-card">
          <div class="skeleton-line" style="width: 70%;"></div>
          <div class="skeleton-line" style="width: 45%; margin-top: 12px;"></div>
        </div>
      </div>
    `;
  }

  if (!state.selectedAttachments.length) {
    return `
      <div class="empty-state">
        <span class="material-symbols-outlined" aria-hidden="true">attach_file</span>
        <h3>No attachment metadata found</h3>
        <p>This email has no attachment records in the current data source.</p>
      </div>
    `;
  }

  return `
    <div class="detail-stack">
      ${state.selectedAttachments.map((attachment) => `
        <div class="attachment-card">
          <strong>${escapeHtml(attachment.filename || "(no filename)")}</strong>
          <div class="mono">ID ${escapeHtml(attachment.id)} | ${formatNumber(attachment.size)} bytes</div>
          <div class="mono">${escapeHtml(attachment.type || "unknown type")}</div>
        </div>
      `).join("")}
    </div>
  `;
}

function renderSelectedContactsDrawer(email) {
  const result = computeContactsFromEmails([email]);
  state.selectedEmailContacts = result.contacts;

  if (!state.selectedEmailContacts.length) {
    return `
      <div class="empty-state">
        <span class="material-symbols-outlined" aria-hidden="true">person_search</span>
        <h3>No clean contacts found</h3>
        <p>This message only contains invalid, duplicate, or system addresses after cleaning.</p>
      </div>
    `;
  }

  return `
    <div class="detail-stack">
      <div class="detail-card">
        <h3>Extracted from this email</h3>
        <div class="chip-row">
          <span class="tag">Extracted: ${formatNumber(result.stats.total_extracted_addresses)}</span>
          <span class="tag">Valid: ${formatNumber(result.stats.valid_contacts)}</span>
          <span class="tag">Ignored: ${formatNumber(result.stats.ignored_invalid_or_system_addresses)}</span>
        </div>
        <div class="drawer-actions" style="margin-top: 12px;">
          <button class="button secondary" type="button" data-drawer-action="copy-selected-contacts">Copy contacts</button>
        </div>
      </div>
      ${state.selectedEmailContacts.map((contact) => `
        <div class="contact-card">
          <strong>${escapeHtml(contact.email)}</strong>
          <div class="mono">${escapeHtml(contact.domain)} | ${escapeHtml(categoryLabel(contact.category))}</div>
        </div>
      `).join("")}
    </div>
  `;
}

function renderDrawerTrace() {
  const selectedId = state.selectedId ? String(state.selectedId) : "";
  const related = selectedId
    ? state.trace.filter((entry) => entry.path.includes(`/emails/${selectedId}`)).slice(0, 10)
    : [];
  const entries = related.length ? related : state.trace.slice(0, 10);

  if (!entries.length) {
    return `
      <div class="empty-state">
        <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
        <h3>No request logs yet</h3>
        <p>API calls made from this dashboard will appear here.</p>
      </div>
    `;
  }

  return `
    <div class="detail-stack">
      ${entries.map(renderTraceItem).join("")}
    </div>
  `;
}

function renderTraceItem(entry) {
  return `
    <div class="trace-item ${entry.ok ? "ok" : "err"}">
      <strong>${escapeHtml(entry.path)}</strong>
      <span>${escapeHtml(entry.time)} | status ${escapeHtml(entry.status)} | ${escapeHtml(entry.durationMs)}ms</span>
    </div>
  `;
}

function renderTraceList() {
  const host = $("traceList");
  if (!host) {
    return;
  }

  if (!state.trace.length) {
    host.innerHTML = `
      <div class="empty-state compact">
        <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
        <p>No requests yet.</p>
      </div>
    `;
    return;
  }

  host.innerHTML = state.trace.slice(0, 18).map(renderTraceItem).join("");
}

function toggleTracePanel(show) {
  const panel = $("tracePanel");
  if (!panel) {
    return;
  }

  const shouldOpen = typeof show === "boolean" ? show : !panel.classList.contains("open");
  panel.classList.toggle("open", shouldOpen);
  panel.setAttribute("aria-hidden", shouldOpen ? "false" : "true");
}

async function extractContactsAction() {
  setView("contacts");
  const { dateFrom, dateTo } = getDateRangeFromInputs();
  const response = await fetchJSON("/contacts/extract", {
    method: "POST",
    body: {
      limit: getLimitFromInput(),
      offset: getOffsetFromInput(),
      q: $("serverSearch")?.value.trim() || null,
      date_from: dateFrom || null,
      date_to: dateTo || null,
      include_sources: true,
    },
  });

  if (!response.ok || !response.data?.success) {
    toast("Contact extraction could not be saved.", "err");
    return;
  }

  const payload = response.data.data || {};
  if (payload.stats) {
    state.contactStats = payload.stats;
  }

  await loadPersistedContacts(false);
  toast(`Saved ${formatNumber(payload.stats?.unique_contacts || state.uniqueContacts.length)} contacts to the app database.`, "ok");
}

async function copyText(text, successMessage) {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const textarea = document.createElement("textarea");
      textarea.value = text;
      textarea.setAttribute("readonly", "");
      textarea.style.position = "fixed";
      textarea.style.left = "-9999px";
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand("copy");
      textarea.remove();
    }

    toast(successMessage, "ok");
  } catch {
    toast("Copy failed. Your browser may require a secure context.", "err");
  }
}

function copyAllContacts() {
  const contacts = state.visibleContacts.length ? state.visibleContacts : getFilteredContacts();
  if (!contacts.length) {
    toast("No contacts to copy yet.", "warn");
    return;
  }

  copyText(
    contacts.map((contact) => contact.email).join("\n"),
    `Copied ${formatNumber(contacts.length)} contacts.`
  );
}

function exportContactsCsv() {
  const params = new URLSearchParams({ status: "approved" });
  if (state.contactSearch.trim()) {
    params.set("q", state.contactSearch.trim());
  }

  window.location.href = `/contacts/export.csv?${params.toString()}`;
}

async function updateContactStatus(contactId, status) {
  if (!contactId || !status) {
    return;
  }

  const response = await fetchJSON(`/contacts/${encodeURIComponent(contactId)}/status`, {
    method: "PATCH",
    body: { status },
  });

  if (!response.ok || !response.data?.success) {
    toast("Contact status could not be updated.", "err");
    return;
  }

  await loadPersistedContacts(false);
  toast(`Contact marked ${status}.`, "ok");
}

function csvEscape(value) {
  const text = String(value ?? "");
  if (/[",\n]/.test(text)) {
    return `"${text.replaceAll('"', '""')}"`;
  }

  return text;
}

function handleDrawerAction(action) {
  if (!state.selectedEmail) {
    return;
  }

  if (action === "copy-sender") {
    const sender = extractEmail(state.selectedEmail.from || "") || state.selectedEmail.from || "";
    copyText(sender, "Sender copied.");
    return;
  }

  if (action === "extract-selected") {
    state.drawerTab = "contacts";
    renderDrawer();
    toast("Contacts extracted from selected email.", "ok");
    return;
  }

  if (action === "copy-selected-contacts") {
    if (!state.selectedEmailContacts.length) {
      toast("No selected-email contacts to copy.", "warn");
      return;
    }

    copyText(
      state.selectedEmailContacts.map((contact) => contact.email).join("\n"),
      `Copied ${formatNumber(state.selectedEmailContacts.length)} contacts.`
    );
  }
}

function bindEvents() {
  $$(".nav-item").forEach((item) => {
    item.addEventListener("click", () => setView(item.dataset.view));
  });

  $("topPrimaryAction")?.addEventListener("click", handleTopPrimaryAction);
  $("btnHealth")?.addEventListener("click", () => runHealthCheck(true));
  $("btnSettingsHealth")?.addEventListener("click", () => runHealthCheck(true));
  $("btnRoot")?.addEventListener("click", runRootPing);
  $("btnTracePanel")?.addEventListener("click", () => toggleTracePanel());
  $("btnCloseTrace")?.addEventListener("click", () => toggleTracePanel(false));
  $("btnLogout")?.addEventListener("click", logout);

  $("btnLoadLatest")?.addEventListener("click", () => loadListEmails());
  $("btnOverviewRefresh")?.addEventListener("click", () => loadListEmails());
  $("btnLoad")?.addEventListener("click", () => loadListEmails());
  $("btnApplyDateRange")?.addEventListener("click", () => {
    if ($("offset")) $("offset").value = "0";
    loadListEmails();
  });
  $("btnExtractContacts")?.addEventListener("click", extractContactsAction);
  $("btnReExtractContacts")?.addEventListener("click", extractContactsAction);
  $("btnRunAiInsights")?.addEventListener("click", () => analyzeContactsWithAi(true));
  $("btnOpenEmails")?.addEventListener("click", () => setView("emails"));
  $("btnLoadFullArchive")?.addEventListener("click", loadFullArchive);
  $("btnLoadAllInline")?.addEventListener("click", loadFullArchive);
  $("btnClear")?.addEventListener("click", resetFilters);
  $("btnPrev")?.addEventListener("click", () => changePage(-1));
  $("btnNext")?.addEventListener("click", () => changePage(1));

  $("emailSearchForm")?.addEventListener("submit", (event) => {
    event.preventDefault();
    runSearch();
  });

  $("localFilter")?.addEventListener("input", (event) => {
    state.localFilter = event.target.value || "";
    renderEmailTable();
  });

  $("contactSearch")?.addEventListener("input", (event) => {
    state.contactSearch = event.target.value || "";
    loadPersistedContacts(false);
  });

  $("contactStatusFilter")?.addEventListener("change", (event) => {
    state.contactStatusFilter = event.target.value || "all";
    loadPersistedContacts(false);
  });

  $("btnCopyAllContacts")?.addEventListener("click", copyAllContacts);
  $("btnExportCsv")?.addEventListener("click", exportContactsCsv);
  $("contactsBody")?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-contact-action]");
    if (!button) {
      return;
    }

    updateContactStatus(button.dataset.contactId, button.dataset.contactAction);
  });

  $("emailsTable")?.querySelector("thead")?.addEventListener("click", (event) => {
    const target = event.target.closest("button[data-sort]");
    if (!target) {
      return;
    }

    const key = target.dataset.sort;
    if (state.sortBy === key) {
      state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
    } else {
      state.sortBy = key;
      state.sortDir = key === "date" ? "desc" : "asc";
    }

    renderEmailTable();
  });

  $("emailsBody")?.addEventListener("click", (event) => {
    const actionButton = event.target.closest("button[data-action]");
    if (actionButton) {
      const id = actionButton.dataset.id;
      const action = actionButton.dataset.action;
      openEmail(id, action === "attachments" ? "attachments" : "summary");
      return;
    }

    const row = event.target.closest("tr[data-row-id]");
    if (row) {
      openEmail(row.dataset.rowId, "summary");
    }
  });

  $("emailsBody")?.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") {
      return;
    }

    const row = event.target.closest("tr[data-row-id]");
    if (row) {
      event.preventDefault();
      openEmail(row.dataset.rowId, "summary");
    }
  });

  $("overviewSamples")?.addEventListener("click", (event) => {
    const sample = event.target.closest("[data-sample-id]");
    if (sample) {
      openEmail(sample.dataset.sampleId, "summary");
    }
  });

  $("btnCloseDrawer")?.addEventListener("click", closeDrawer);
  $("drawerBackdrop")?.addEventListener("click", closeDrawer);

  $$(".drawer-tab").forEach((tab) => {
    tab.addEventListener("click", () => setDrawerTab(tab.dataset.drawerTab));
  });

  $("drawerBody")?.addEventListener("click", (event) => {
    const action = event.target.closest("[data-drawer-action]")?.dataset.drawerAction;
    if (action) {
      handleDrawerAction(action);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }

    closeDrawer();
    toggleTracePanel(false);
  });
}

async function bootstrap() {
  bindEvents();
  const authenticated = await loadCurrentUser();
  if (!authenticated) {
    return;
  }

  setView("overview");
  renderAll();
  updateHealthUI();
  runHealthCheck(false);
  await loadAiStatus();
  await loadListEmails({ toastOnSuccess: false });
}

bootstrap();
