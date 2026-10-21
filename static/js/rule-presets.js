// Rules pages: the "Add common rules" dropdowns. Ticking a rule adds its line to the rules box under its heading;
// unticking removes it. The box is still what gets saved.
(function () {
  "use strict";

  function lines(box) {
    return box.value.replace(/\r\n/g, "\n").split("\n");
  }

  function has(box, text) {
    return lines(box).some(function (line) { return line.trim() === "- " + text; });
  }

  function add(box, heading, text) {
    var list = lines(box);
    var at = list.findIndex(function (line) { return line.trim() === heading; });
    if (at < 0) {
      while (list.length && list[list.length - 1].trim() === "") list.pop();
      if (list.length) list.push("");
      list.push(heading, "- " + text);
    } else {
      var end = at + 1;
      while (end < list.length && list[end].trim().indexOf("- ") === 0) end += 1;
      list.splice(end, 0, "- " + text);
    }
    box.value = list.join("\n");
  }

  function remove(box, heading, text) {
    var list = lines(box).filter(function (line) { return line.trim() !== "- " + text; });
    // A heading left with no rules under it goes too, with the blank line after it.
    var at = list.findIndex(function (line) { return line.trim() === heading; });
    if (at >= 0 && !(list[at + 1] || "").trim().startsWith("- ")) {
      list.splice(at, list[at + 1] !== undefined && list[at + 1].trim() === "" ? 2 : 1);
    }
    box.value = list.join("\n");
  }

  function sync(panel, box) {
    panel.querySelectorAll("input[data-rule]").forEach(function (input) {
      input.checked = has(box, input.dataset.rule);
    });
  }

  document.querySelectorAll("details.rule-presets").forEach(function (panel) {
    var box = document.getElementById(panel.dataset.target);
    if (!box) return;
    sync(panel, box);
    panel.addEventListener("change", function (event) {
      var input = event.target;
      if (!input.matches("input[data-rule]")) return;
      if (input.checked) add(box, input.dataset.heading, input.dataset.rule);
      else remove(box, input.dataset.heading, input.dataset.rule);
      box.dispatchEvent(new Event("input", { bubbles: true }));
    });
    box.addEventListener("input", function () { sync(panel, box); });
  });
})();
