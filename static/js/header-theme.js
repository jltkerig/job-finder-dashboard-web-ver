// The header's color and photo follow the date: a color for each month, a photo for each month when one is in
// static/img/header/, and a holiday photo for the week around Christmas, Thanksgiving, Easter, the Fourth of July and
// Halloween. A missing photo just leaves the color.
(() => {
  const header = document.querySelector("header");
  if (!header) return;
  const MONTHS = ["january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december"];
  const COLORS = ["#1e3a5f", "#7a2e4d", "#2f6b4f", "#3b7a8f", "#4a7c3a", "#1f7a8c", "#b4442f", "#c2761f", "#8a5a1e", "#b5541c", "#7a3d1c", "#7a1f2b"];
  const DAY = 86400000;

  function easter(year) {  // the Sunday after the Paschal full moon (Anonymous Gregorian algorithm)
    const a = year % 19, b = Math.floor(year / 100), c = year % 100, d = Math.floor(b / 4), e = b % 4;
    const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31), day = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(year, month - 1, day);
  }
  function thanksgiving(year) {  // the fourth Thursday of November
    const first = new Date(year, 10, 1).getDay();
    return new Date(year, 10, 1 + ((4 - first + 7) % 7) + 21);
  }
  function holiday(today) {  // the holiday whose date is within three days of today (a week around it), if any
    const year = today.getFullYear();
    const dates = { christmas: new Date(year, 11, 25), thanksgiving: thanksgiving(year), easter: easter(year),
      "fourth-of-july": new Date(year, 6, 4), halloween: new Date(year, 9, 31) };
    const day = new Date(year, today.getMonth(), today.getDate());
    return Object.keys(dates).find((name) => Math.abs(Math.round((dates[name] - day) / DAY)) <= 3) || "";
  }

  const today = new Date();
  const month = today.getMonth();
  const name = holiday(today);
  header.classList.add("themed");
  header.style.setProperty("--header-color", COLORS[month]);
  const file = name || MONTHS[month];
  const image = new Image();
  image.onload = () => {
    header.style.setProperty("--header-image", `url("/static/img/header/${file}.jpg")`);
    header.classList.add("has-photo");
  };
  image.src = `/static/img/header/${file}.jpg`;
})();
