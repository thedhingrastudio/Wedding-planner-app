console.log("main.js loaded");

document.addEventListener("DOMContentLoaded", () => {
  // Sidebar collapse toggle
  const toggleBtn = document.getElementById("toggleSidebar");
  const sidebar = document.getElementById("sidebar");
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener("click", () => sidebar.classList.toggle("collapsed"));
  }

  // =========================
  // EVENTS (Name + Date + Venue) -> table rows + hidden inputs
  // =========================
  const addEventBtn = document.querySelector(".btn-add-event");
  const tableBody = document.querySelector("#events-table tbody");

  if (addEventBtn && tableBody) {
    addEventBtn.addEventListener("click", () => {
      const nameInput = document.getElementById("event-name-input");
      const dateInput = document.getElementById("event-date-input");
      const venueInput = document.getElementById("event-venue-input");

      const name = (nameInput?.value || "").trim();
      const date = (dateInput?.value || "").trim();
      const venue = (venueInput?.value || "").trim();

      if (!name || !date || !venue) {
        alert("Please fill all event fields.");
        return;
      }

      const row = document.createElement("tr");
      row.innerHTML = `
        <td>
          ${name}
          <input type="hidden" name="event_names[]" value="${name}">
        </td>
        <td>
          ${date}
          <input type="hidden" name="event_dates[]" value="${date}">
        </td>
        <td>
          ${venue}
          <input type="hidden" name="event_venues[]" value="${venue}">
        </td>
        <td>
          <button type="button" class="btn-delete">Remove</button>
        </td>
      `;

      tableBody.appendChild(row);

      nameInput.value = "";
      dateInput.value = "";
      venueInput.value = "";
    });

    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("btn-delete")) {
        e.target.closest("tr")?.remove();
      }
    });
  }

  // =========================
  // TEAM MEMBERS (stack multiple) -> stacked rows + hidden inputs
  // =========================
  document.querySelectorAll("[data-team]").forEach((block) => {
    const teamKey = block.getAttribute("data-team"); // rsvp_team, hospitality_team, transport_team
    const select = block.querySelector("select");
    const addBtn = block.querySelector("[data-add-member]");
    const list = block.querySelector("[data-selected-list]");
    const hiddenWrap = block.querySelector("[data-hidden-wrap]");

    if (!teamKey || !select || !addBtn || !list || !hiddenWrap) return;

    addBtn.addEventListener("click", () => {
      const id = select.value;
      if (!id) return;

      // no duplicates
      if (hiddenWrap.querySelector(`input[value="${id}"]`)) {
        select.value = "";
        return;
      }

      const label = select.options[select.selectedIndex].text;

      const row = document.createElement("div");
      row.className = "stack-row";
      row.innerHTML = `
        <div class="stack-row-text"></div>
        <button type="button" class="stack-row-x" aria-label="Remove">×</button>
      `;
      row.querySelector(".stack-row-text").textContent = label;

      row.querySelector(".stack-row-x").addEventListener("click", () => {
        hiddenWrap.querySelector(`input[value="${id}"]`)?.remove();
        row.remove();
      });

      list.appendChild(row);

      const input = document.createElement("input");
      input.type = "hidden";
      input.name = `${teamKey}[]`;
      input.value = id;
      hiddenWrap.appendChild(input);

      select.value = "";
    });
  });
});

// Sidebar collapse/expand
document.addEventListener("DOMContentLoaded", () => {
  const sidebar = document.querySelector("[data-sidebar]");
  const toggle = document.querySelector("[data-sidebar-toggle]");
  if (!sidebar || !toggle) return;

  const KEY = "vidhaan_sidebar_collapsed";

  const apply = (collapsed) => {
    sidebar.classList.toggle("is-collapsed", collapsed);
    toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
  };

  apply(localStorage.getItem(KEY) === "1");

  toggle.addEventListener("click", () => {
    const collapsed = !sidebar.classList.contains("is-collapsed");
    apply(collapsed);
    localStorage.setItem(KEY, collapsed ? "1" : "0");
  });
});

// ===== Project Team page: search filter =====
document.addEventListener("DOMContentLoaded", () => {
  const input = document.querySelector("[data-team-search]");
  if (!input) return;

  const rows = Array.from(document.querySelectorAll("[data-team-member]"));

  input.addEventListener("input", () => {
    const q = (input.value || "").trim().toLowerCase();
    rows.forEach((row) => {
      const hay = (row.getAttribute("data-team-member") || "").toLowerCase();
      row.style.display = !q || hay.includes(q) ? "" : "none";
    });
  });
});

// ===== Confirm delete task =====
document.addEventListener("submit", (e) => {
  const form = e.target.closest("[data-confirm-delete-form]");
  if (!form) return;

  const ok = window.confirm("Delete this task?\n\nThis can’t be undone.");
  if (!ok) e.preventDefault();
});