// Web only: uploads the Web Job Scraper's jobs.json files to /captures/import and shows what happened.
// (The desktop's captures.js asks to move files out of Downloads instead; a web server can't see them.)
(() => {
  "use strict";
  const form = document.getElementById("capture-form");
  const result = document.getElementById("capture-result");
  if (!form || !result) return;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";

  function say(lines) {
    result.replaceChildren(...lines.map((text) => { const p = document.createElement("p"); p.textContent = text; return p; }));
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector("button");
    button.disabled = true;
    say(["Importing… this can take up to half a minute."]);
    try {
      const response = await fetch("/captures/import", { method: "POST", headers: { "X-CSRF-Token": csrfToken }, body: new FormData(form) });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error_code ? `${data.message} [${data.error_code}]` : data.message || "The import failed.");
      const c = data.counts || {};
      const lines = [`Checked ${data.jobs} job${data.jobs === 1 ? "" : "s"} from ${data.files} file${data.files === 1 ? "" : "s"}: `
        + `${c.added || 0} added, ${c.updated || 0} updated, ${c.linked || 0} matched jobs already in your list, `
        + `${(c.skipped || 0) + (c.rejected || 0)} filtered out.`];
      if (data.lookups_left) lines.push(`Company websites still to look up: ${data.lookups_left}. The cron job finishes them in the next minutes.`);
      say(lines);
      const details = document.createElement("details");
      const summary = document.createElement("summary");
      summary.textContent = "What happened to each job";
      const pre = document.createElement("pre");
      pre.textContent = (data.log || []).join("\n");
      details.append(summary, pre);
      result.append(details);
      if ((c.added || 0) + (c.updated || 0) + (c.linked || 0)) {
        const reload = document.createElement("button");
        reload.type = "button";
        reload.className = "bordered-button secondary-action";
        reload.textContent = "Show the results";
        reload.addEventListener("click", () => window.location.reload());
        result.append(reload);
      }
      form.reset();
    } catch (error) {
      say([error.message || "The import failed."]);
    } finally {
      button.disabled = false;
    }
  });
})();
