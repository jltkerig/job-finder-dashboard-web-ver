// Dashboard: the Search page's Results filters for the Apply Queue, Saved Jobs and Applied Jobs. Each filter bar
// (.dash-toolbar) filters and sorts only the list it names, by the data-* values on every job (templates/_dash_filter.html).
(() => {
  const stateCode = window.jobFinderStateCode || ((value) => String(value || "").trim().toUpperCase());
  const rank = (item, name) => {
    const raw = item.dataset[name];
    return raw === undefined || raw === "" || Number.isNaN(Number(raw)) ? -1 : Number(raw);
  };
  const num = (item, name, fallback = 0) => Number(item.dataset[name] || fallback);
  const text = (item, name) => item.dataset[name] || "";

  document.querySelectorAll(".dash-toolbar").forEach((bar) => {
    const list = document.querySelector(bar.dataset.target);
    if (!list) return;
    const control = (name) => bar.querySelector(`[data-f="${name}"]`);
    const count = bar.querySelector("[data-count]");
    const items = () => [...list.querySelectorAll(`:scope > ${bar.dataset.item}`)];
    const stateBox = control("state");
    [...new Set(items().map((item) => stateCode(item.dataset.state)).filter(Boolean))].sort().forEach((code) => {
      stateBox.append(new Option(code, code));
    });

    const sorters = {
      oldest: (a, b) => num(b, "order") - num(a, "order"),
      newest: (a, b) => text(b, "added").localeCompare(text(a, "added")) || num(b, "order") - num(a, "order"),
      company: (a, b) => text(a, "company").localeCompare(text(b, "company")),
      job: (a, b) => text(a, "job").localeCompare(text(b, "job")),
      distance: (a, b) => num(a, "distance", 99999) - num(b, "distance", 99999),
      "job-fit": (a, b) => rank(b, "jobFit") - rank(a, "jobFit") || num(b, "fitCount") - num(a, "fitCount"),
      "skill-fit": (a, b) => rank(b, "skillFit") - rank(a, "skillFit"),
      "career-credibility": (a, b) => num(b, "careerCredibility") - num(a, "careerCredibility"),
      "usa-credibility": (a, b) => num(b, "usaCredibility") - num(a, "usaCredibility"),
    };

    function update() {
      const rows = items();
      rows.forEach((row, index) => { if (row.dataset.order === undefined) row.dataset.order = String(index); });
      const query = control("text").value.trim().toLowerCase();
      const state = control("state").value, arrangement = control("arrangement").value, status = control("status").value;
      const source = control("source").value, schedule = control("schedule").value, minScore = Number(control("credibility").value);
      rows.forEach((row) => {
        const rowState = stateCode(row.dataset.state);
        const haystack = `${text(row, "company")} ${text(row, "job")} ${text(row, "city")}`.toLowerCase();
        const kind = text(row, "schedule").toLowerCase(), work = text(row, "workArrangement");
        row.hidden = !((!query || haystack.includes(query))
          && (state === "all" || rowState === state)
          && (arrangement === "all" || work === arrangement || (arrangement === "unknown" && !work))
          && (status === "all" || text(row, "status") === status)
          && (source === "all" || text(row, "source") === source)
          && (minScore === -1 || num(row, "careerCredibility") >= Math.max(3, minScore))
          && (schedule === "all" || (schedule === "unknown" ? !kind : schedule === "freelance"
            ? /freelanc|gig|project.based|independent contractor/.test(kind) : kind.includes(schedule))));
      });
      // The list keeps its first (server) order unless asked; moving rows must not scroll the page.
      const sorter = sorters[control("sort").value];
      const before = bar.getBoundingClientRect().top;
      rows.sort(sorter || ((a, b) => num(a, "order") - num(b, "order"))).forEach((row) => list.append(row));
      const shift = bar.getBoundingClientRect().top - before;
      if (shift) window.scrollBy(0, shift);
      const shown = rows.filter((row) => !row.hidden).length;
      if (count) count.textContent = `${shown} result${shown === 1 ? "" : "s"}`;
      document.dispatchEvent(new CustomEvent("dashboard-filtered", { detail: { bar, shown } }));
    }
    bar.addEventListener("input", update);
    bar.addEventListener("change", update);
    update();
  });
})();
