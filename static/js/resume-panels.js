// Résumé Builder page: panels remember being open or closed, and open when a link points at them (adding a reference, saving a design...).
(function () {
  [["references-panel", "resumeBuilder.referencesOpen", "#references"], ["design-panel", "resumeBuilder.designOpen", "#design"],
   ["profile-panel", "resumeBuilder.profileOpen", "#profile", true], ["resume-rules-panel", "resumeBuilder.resumeRulesOpen", "#resume-rules"],
   ["cover-letter-rules-panel", "resumeBuilder.letterRulesOpen", "#cover-letter-rules"]]
    .forEach(function (item) {
      var panel = document.getElementById(item[0]);
      if (!panel) return;
      try { var saved = localStorage.getItem(item[1]); panel.open = saved === null ? Boolean(item[3]) : saved === "1"; } catch (e) {}
      if (location.hash === item[2] || (item[2] === "#design" && /[?&]preset=/.test(location.search))) panel.open = true;
      panel.addEventListener("toggle", function () { try { localStorage.setItem(item[1], panel.open ? "1" : "0"); } catch (e) {} });
    });
  document.addEventListener("click", function (event) {
    var button = event.target.closest("[data-copy]");
    if (!button || !navigator.clipboard) return;
    navigator.clipboard.writeText(button.dataset.copy).then(function () { button.textContent = "Copied"; });
  });
})();
