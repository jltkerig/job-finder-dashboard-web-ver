// Top 10 picks: ranks the results shown on the Search page and lists the 10 best to apply to. After you untick any
// you don't want, "Build" keeps them on the Dashboard and copies one request for Resume Builder's Claude Desktop
// connector to make a tailored resume and cover letter for each.
(function () {
  const button = document.querySelector(".js-top-picks-button");
  const panel = document.getElementById("top-picks");
  if (!button || !panel) return;
  const list = document.getElementById("top-picks-list");
  const status = document.getElementById("top-picks-status");
  const build = document.getElementById("top-picks-build");
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
  const PICKS = 10;

  const number = (value) => { const n = parseFloat(value); return Number.isFinite(n) ? n : null; };

  // 0-100 for how close the job is: remote counts as next door; 60+ miles counts as far.
  function nearness(row) {
    if (row.dataset.workArrangement === "Remote") return 100;
    const miles = number(row.dataset.distance);
    if (miles === null || miles >= 99999) return 50;  // distance unknown
    return Math.max(0, Math.min(100, 100 - (Math.max(0, miles - 10) * 2)));
  }

  function rank(row) {
    const fit = number(row.dataset.jobFit);
    const career = number(row.dataset.careerCredibility) || 0;  // 0-10
    const usa = number(row.dataset.usaCredibility) || 0;  // 0-10
    const near = nearness(row);
    const score = (fit === null ? 50 : fit) * 0.4 + career * 10 * 0.25 + usa * 10 * 0.15 + near * 0.2;
    const reasons = [];
    reasons.push(fit === null ? "skill fit unknown" : `${Math.round(fit)}% skill fit`);
    if (row.dataset.workArrangement === "Remote") reasons.push("remote");
    else {
      const miles = number(row.dataset.distance);
      if (miles !== null && miles < 99999) reasons.push(`${Math.round(miles)} miles away`);
    }
    reasons.push(`credibility ${Math.round(career)}/10`);
    return { row, score, reasons };
  }

  function shown(row) {
    return !row.hidden && row.style.display !== "none" && row.offsetParent !== null;
  }

  function pick() {
    const rows = Array.from(document.querySelectorAll(".js-results-table .result-row"))
      .filter((row) => shown(row) && row.dataset.status !== "closed" && row.dataset.saved !== "true");  // kept jobs are done
    return rows.map(rank).sort((a, b) => b.score - a.score).slice(0, PICKS);
  }

  // The same arrow as the listings' Open button.
  const ARROW = '<svg class="listing-arrow" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 17 17 7M8 7h9v9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function render(picks) {
    list.replaceChildren();
    picks.forEach(({ row, reasons }) => {
      const item = document.createElement("li");
      item.className = "card top-pick";
      const box = document.createElement("input");
      box.type = "checkbox";
      box.checked = true;
      box.value = row.dataset.companyId;
      box.dataset.job = row.dataset.job || "";
      box.dataset.company = row.dataset.company || "";
      box.id = `top-pick-${row.dataset.companyId}`;
      box.setAttribute("aria-label", `Include ${row.dataset.job || "this job"}`);
      const body = document.createElement("div");
      body.className = "top-pick-body";
      const title = document.createElement("label");
      title.className = "top-pick-title";
      title.htmlFor = box.id;
      title.textContent = row.dataset.job || `Job #${row.dataset.companyId}`;
      body.append(title);
      if (window.TopPicksFacts) {
        body.append(window.TopPicksFacts.line(row, row.dataset.company || ""));
        const domain = window.TopPicksFacts.domain(row);
        if (domain) body.append(domain);
        body.append(window.TopPicksFacts.stats(row));
      } else {
        body.append(Object.assign(document.createElement("span"), { className: "top-pick-company", textContent: row.dataset.company || "" }));
      }
      const chips = document.createElement("div");
      chips.className = "top-pick-chips";
      reasons.forEach((reason) => {
        const chip = document.createElement("button");
        chip.type = "button";
        chip.className = "navy-chip";
        chip.textContent = reason;
        chips.append(chip);
      });
      body.append(chips);
      item.append(box, body);  // the order of the list is the ranking
      const link = row.querySelector("a.js-listing-open[href^='http']");  // the row's Open button
      if (link) {
        const actions = document.createElement("div");
        actions.className = "top-pick-actions";
        const open = document.createElement("a");
        open.className = "bordered-button";
        open.href = link.href;
        open.target = "_blank";
        open.rel = "noopener noreferrer";
        open.innerHTML = `Open ${ARROW}`;
        actions.append(open);
        item.append(actions);
      }
      list.append(item);
    });
  }

  button.addEventListener("click", (event) => {
    event.preventDefault();  // the button sits in the box's title bar: do not also open or close the box
    const picks = pick();
    status.textContent = "";
    if (!picks.length) {
      status.textContent = "No open results to pick from. Run a search or clear the filters.";
      list.replaceChildren();
    } else {
      render(picks);
      if (picks.length < PICKS) status.textContent = `Only ${picks.length} open results to pick from.`;
    }
    panel.hidden = false;
    panel.scrollIntoView({ behavior: "smooth", block: "start" });
  });

  build.addEventListener("click", async () => {
    const chosen = Array.from(list.querySelectorAll("input:checked"));
    if (!chosen.length) { status.textContent = "Tick at least one job first."; return; }
    build.disabled = true;
    status.textContent = "Keeping them on your Dashboard…";
    try {
      const response = await fetch("/save-kept", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
        body: JSON.stringify({ company_ids: chosen.map((box) => box.value) }),
      });
      if (!response.ok) throw new Error("Could not keep these jobs on the Dashboard.");
    } catch (error) {
      status.textContent = error.message;
      build.disabled = false;
      return;
    }
    const jobs = chosen.map((box) => {
      const job = [box.dataset.job, box.dataset.company].filter(Boolean).join(" at ");
      return `- Job Finder job #${box.value}${job ? ` (${job})` : ""}`;
    }).join("\n");
    const request = "Using Resume Builder, read my writing rules, then for each of these Job Finder jobs tailor my " +
      "resume and write a cover letter. Read the whole listing first each time, and save both as PDFs.\n" + jobs;
    try {
      await navigator.clipboard.writeText(request);
      status.textContent = `Kept ${chosen.length} on the Dashboard. Request copied: paste it in Claude Desktop.`;
    } catch {
      window.prompt("Copy this request, then paste it into Claude Desktop:", request);
      status.textContent = `Kept ${chosen.length} on the Dashboard.`;
    }
    build.disabled = false;
    window.location.href = "claude://";
  });
})();

// Top 10 Picks opens and closes from its heading; the choice is remembered.
(() => {
  const panel = document.getElementById("top-picks-panel");
  if (!panel) return;
  try { panel.open = localStorage.getItem("jobFinder.topPicksOpen") !== "0"; } catch {}
  panel.addEventListener("toggle", () => { try { localStorage.setItem("jobFinder.topPicksOpen", panel.open ? "1" : "0"); } catch {} });
})();
