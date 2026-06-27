(function () {
  var body = document.body;
  if (!body) {
    return;
  }

  var rawBreakpoint = parseInt(String(body.getAttribute("data-metis-nav-breakpoint") || "980"), 10);
  var mobileBreakpoint = isFinite(rawBreakpoint) ? rawBreakpoint : 980;
  if (mobileBreakpoint < 480) mobileBreakpoint = 480;
  if (mobileBreakpoint > 1600) mobileBreakpoint = 1600;

  var dropdownBehavior = String(body.getAttribute("data-metis-nav-dropdown-behavior") || "hover").toLowerCase();
  if (dropdownBehavior !== "click" && dropdownBehavior !== "hover") {
    dropdownBehavior = "hover";
  }
  if (dropdownBehavior === "click") {
    body.classList.add("metis-menu-dropdown-click");
  }
  var lastNavToggle = null;
  var hoverOpenDelay = 60;
  var hoverCloseDelay = 360;
  var hoverTimers = typeof WeakMap === "function" ? new WeakMap() : null;

  function itemTrigger(item) {
    if (!item || !item.querySelector) return null;
    return item.querySelector(":scope > .metis-shell-menu-link, :scope > .metis-shell-menu-btn, :scope > .metis-shell-menu-label");
  }

  function itemTimerState(item) {
    if (!item) return { open: 0, close: 0 };
    if (!hoverTimers) {
      if (!item._metisHoverTimers) item._metisHoverTimers = { open: 0, close: 0 };
      return item._metisHoverTimers;
    }
    var existing = hoverTimers.get(item);
    if (existing) return existing;
    var created = { open: 0, close: 0 };
    hoverTimers.set(item, created);
    return created;
  }

  function clearHoverTimers(item) {
    var state = itemTimerState(item);
    if (state.open) {
      window.clearTimeout(state.open);
      state.open = 0;
    }
    if (state.close) {
      window.clearTimeout(state.close);
      state.close = 0;
    }
  }

  function setItemOpen(item, open) {
    if (!item || !item.classList) return;
    clearHoverTimers(item);
    item.classList.toggle("is-open", !!open);
    var trigger = itemTrigger(item);
    if (trigger) {
      trigger.setAttribute("aria-expanded", open ? "true" : "false");
    }
  }

  function setMobileItemOpen(item, open) {
    if (!item || !item.classList) return;
    item.classList.toggle("is-open", !!open);
    var trigger = item.querySelector("[data-metis-mobile-toggle]");
    if (trigger) {
      trigger.setAttribute("aria-expanded", open ? "true" : "false");
    }
  }

  function triggerUrl(trigger) {
    if (!trigger) return "";
    var dataUrl = String(trigger.getAttribute("data-metis-nav-url") || "").trim();
    if (dataUrl) return dataUrl;
    if (trigger.tagName && trigger.tagName.toLowerCase() === "a") {
      return String(trigger.getAttribute("href") || "").trim();
    }
    return "";
  }

  function isNavigableUrl(url) {
    var value = String(url || "").trim();
    if (!value || value === "#") return false;
    return true;
  }

  function navigate(url) {
    var target = String(url || "").trim();
    if (!isNavigableUrl(target)) return false;
    if (window.Metis && Metis.navigation && typeof Metis.navigation.go === "function") {
      return Metis.navigation.go(target, { allowExternal: true });
    }
    window.location.assign(target);
    return true;
  }

  function s(value) {
    return value == null ? "" : String(value);
  }

  function escapeHtml(value) {
    return s(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function parseEventsState(block) {
    if (!block) return null;
    if (block._metisEventsStateParsed) return block._metisEventsState || null;
    block._metisEventsStateParsed = true;
    var script = block.querySelector(".metis-structured-events-state");
    if (!script) {
      block._metisEventsState = null;
      return null;
    }
    try {
      block._metisEventsState = JSON.parse(script.textContent || "{}");
      block._metisEventsStateScript = script.outerHTML || "";
    } catch (error) {
      block._metisEventsState = null;
      block._metisEventsStateScript = "";
    }
    return block._metisEventsState || null;
  }

  function parseIsoDate(value) {
    var match = s(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;
    return new Date(parseInt(match[1], 10), parseInt(match[2], 10) - 1, parseInt(match[3], 10));
  }

  function isoDateKey(date) {
    if (!(date instanceof Date) || isNaN(date.getTime())) return "";
    var year = String(date.getFullYear());
    var month = String(date.getMonth() + 1).padStart(2, "0");
    var day = String(date.getDate()).padStart(2, "0");
    return year + "-" + month + "-" + day;
  }

  function addDays(date, count) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate() + count);
  }

  function addMonths(date, count) {
    return new Date(date.getFullYear(), date.getMonth() + count, 1);
  }

  function startOfWeek(date) {
    return addDays(date, -date.getDay());
  }

  function endOfWeek(date) {
    return addDays(startOfWeek(date), 6);
  }

  function formatMonthYear(date) {
    return new Intl.DateTimeFormat("en-US", { month: "long", year: "numeric" }).format(date);
  }

  function formatShortDate(date) {
    return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric" }).format(date);
  }

  function formatWeekRangeLabel(startDate, endDate) {
    return formatShortDate(startDate) + " - " + new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(endDate);
  }

  function currentPageHref() {
    return window.location.href.replace(/[?#].*$/, "");
  }

  function buildEventsPrintHref(view, currentCursor) {
    var href = currentPageHref() || "#";
    var params = new URLSearchParams();
    params.set("metis_events_cursor", currentCursor);
    params.set("metis_events_print", "1");
    if (view === "week") {
      params.set("metis_events_view", "week");
    }
    return href + "?" + params.toString();
  }

  function openCalendarPrintView(block, fallbackHref) {
    if (!block || typeof window.open !== "function") {
      if (fallbackHref) {
        window.open(fallbackHref, "_blank", "noopener");
      }
      return;
    }

    var printWindow = window.open("", "_blank", "noopener");
    if (!printWindow) {
      if (fallbackHref) {
        window.open(fallbackHref, "_blank", "noopener");
      }
      return;
    }

    var assets = "";
    var nodes = document.querySelectorAll('link[rel="stylesheet"], style');
    for (var i = 0; i < nodes.length; i += 1) {
      assets += nodes[i].outerHTML || "";
    }

    var titleNode = block.querySelector(".metis-structured-events__nav-title");
    var title = titleNode ? s(titleNode.textContent || "").trim() : "Calendar";
    var blockHtml = block.outerHTML || "";
    var doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) + '</title><base href="' + escapeHtml(document.baseURI || currentPageHref() || "/") + '">' + assets + '</head><body class="metis-public-site metis-events-print-mode">' + blockHtml + '<script>window.addEventListener("load",function(){window.setTimeout(function(){window.print();},150);},{once:true});<\/script></body></html>';

    printWindow.document.open();
    printWindow.document.write(doc);
    printWindow.document.close();
  }

  function renderEventsNavHtml(view, label, currentCursor, prevCursor, nextCursor) {
    var prevLabel = view === "week" ? "Previous week" : "Previous month";
    var nextLabel = view === "week" ? "Next week" : "Next month";
    var href = escapeHtml(currentPageHref() || "#");
    var printHref = escapeHtml(buildEventsPrintHref(view, currentCursor));
    return '<div class="metis-structured-events__nav">' +
      '<a href="' + href + '" class="metis-structured-events__nav-btn" data-metis-events-nav="1" data-metis-events-cursor="' + escapeHtml(prevCursor) + '">' + escapeHtml(prevLabel) + '</a>' +
      '<div class="metis-structured-events__nav-title">' + escapeHtml(label) + '</div>' +
      '<div class="metis-structured-events__nav-actions"><a href="' + printHref + '" target="_blank" rel="noopener" class="metis-structured-events__print-btn" data-metis-events-print="' + printHref + '">Print</a><a href="' + href + '" class="metis-structured-events__nav-btn" data-metis-events-nav="1" data-metis-events-cursor="' + escapeHtml(nextCursor) + '">' + escapeHtml(nextLabel) + '</a></div>' +
      '</div>';
  }

  function renderEventPeekHtml(item, uid) {
    var fullTitle = s(item && item.title || "Event").trim() || "Event";
    var title = s(item && (item.tile_title || item.title) || "Event").trim() || "Event";
    var timeLabel = s(item && item.time_label || "").trim();
    var panelId = "metis-events-peek-" + uid;
    return '<div class="metis-structured-events-peek' + (timeLabel ? "" : " is-all-day") + '">' +
      '<button type="button" class="metis-structured-events-peek__trigger" aria-haspopup="dialog" aria-controls="' + escapeHtml(panelId) + '">' +
      '<span class="metis-structured-events-peek__line"><span class="metis-structured-events-peek__title">' + escapeHtml(title) + '</span></span>' +
      (timeLabel ? '<span class="metis-structured-events-peek__time">' + escapeHtml(timeLabel) + '</span>' : "") +
      '</button>' +
      '<div id="' + escapeHtml(panelId) + '" class="metis-structured-events-peek__panel" role="dialog" aria-label="' + escapeHtml(fullTitle) + '">' + s(item && item.detail_html || "") + '</div>' +
      '</div>';
  }

  function renderEventsMobileList(items, emptyMessage) {
    if (!items.length) {
      return '<div class="metis-structured-events-mobile-list"><p class="metis-structured-events-day__empty">' + escapeHtml(emptyMessage) + '</p></div>';
    }
    return '<div class="metis-structured-events-mobile-list">' + items.map(function (item) {
      return s(item && item.detail_html || "");
    }).join("") + '</div>';
  }

  function filterEventsForRange(items, startKey, endKeyExclusive) {
    return (Array.isArray(items) ? items : []).filter(function (item) {
      var key = s(item && item.date_key || "");
      return key >= startKey && key < endKeyExclusive;
    });
  }

  function renderCalendarBlockLocally(block, state, cursor) {
    var monthStart = parseIsoDate(cursor) || parseIsoDate(block.getAttribute("data-metis-events-cursor-current") || "");
    if (!monthStart) return false;
    monthStart = new Date(monthStart.getFullYear(), monthStart.getMonth(), 1);
    var nextMonthStart = addMonths(monthStart, 1);
    var prevMonthStart = addMonths(monthStart, -1);
    var firstCell = startOfWeek(monthStart);
    var lastCell = endOfWeek(addDays(nextMonthStart, -1));
    var currentKey = isoDateKey(monthStart);
    var nextKey = isoDateKey(nextMonthStart);
    var grouped = Object.create(null);
    var monthItems = filterEventsForRange(state.items || [], currentKey, nextKey);
    monthItems.forEach(function (item) {
      var key = s(item && item.date_key || "");
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(item);
    });

    var html = s(block._metisEventsStateScript || "");
    html += renderEventsNavHtml("calendar", formatMonthYear(monthStart), currentKey, isoDateKey(prevMonthStart), isoDateKey(nextMonthStart));
    html += '<div class="metis-structured-events-month-head">';
    ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].forEach(function (weekday) {
      html += '<div class="metis-structured-events-month-head__cell">' + weekday + '</div>';
    });
    html += '</div><div class="metis-structured-events-month-grid">';
    for (var cursorDate = new Date(firstCell.getTime()); cursorDate <= lastCell; cursorDate = addDays(cursorDate, 1)) {
      var dayKey = isoDateKey(cursorDate);
      var dayItems = grouped[dayKey] || [];
      var outside = cursorDate.getMonth() !== monthStart.getMonth() || cursorDate.getFullYear() !== monthStart.getFullYear();
      html += '<section class="metis-structured-events-month-day' + (outside ? ' is-outside' : '') + '">';
      html += '<header class="metis-structured-events-month-day__header"><strong>' + escapeHtml(String(cursorDate.getDate())) + '</strong></header>';
      html += '<div class="metis-structured-events-month-day__items">';
      dayItems.forEach(function (item, index) {
        html += renderEventPeekHtml(item, dayKey + '-month-' + String(index));
      });
      html += '</div></section>';
    }
    html += '</div>';
    html += renderEventsMobileList(monthItems, 'No events this month.');
    block.innerHTML = html;
    block.setAttribute("data-metis-events-cursor-current", currentKey);
    return true;
  }

  function renderWeekBlockLocally(block, state, cursor) {
    var weekDate = parseIsoDate(cursor) || parseIsoDate(block.getAttribute("data-metis-events-cursor-current") || "");
    if (!weekDate) return false;
    var weekStart = startOfWeek(weekDate);
    var weekEnd = addDays(weekStart, 7);
    var prevWeek = addDays(weekStart, -7);
    var nextWeek = addDays(weekStart, 7);
    var startKey = isoDateKey(weekStart);
    var endKey = isoDateKey(weekEnd);
    var grouped = Object.create(null);
    var weekItems = filterEventsForRange(state.items || [], startKey, endKey);
    weekItems.forEach(function (item) {
      var key = s(item && item.date_key || "");
      if (!grouped[key]) grouped[key] = [];
      grouped[key].push(item);
    });

    var html = s(block._metisEventsStateScript || "");
    html += renderEventsNavHtml("week", formatWeekRangeLabel(weekStart, addDays(weekEnd, -1)), startKey, isoDateKey(prevWeek), isoDateKey(nextWeek));
    html += '<div class="metis-structured-events-week-grid">';
    for (var day = 0; day < 7; day += 1) {
      var dayDate = addDays(weekStart, day);
      var dayKey = isoDateKey(dayDate);
      var dayItems = grouped[dayKey] || [];
      html += '<section class="metis-structured-events-day">';
      html += '<header class="metis-structured-events-day__header"><span class="metis-structured-events-day__weekday">' + escapeHtml(new Intl.DateTimeFormat("en-US", { weekday: "short" }).format(dayDate)) + '</span><strong class="metis-structured-events-day__date">' + escapeHtml(formatShortDate(dayDate)) + '</strong></header>';
      if (!dayItems.length) {
        html += '<p class="metis-structured-events-day__empty">No events scheduled.</p>';
      } else {
        html += '<div class="metis-structured-events-day__items">';
        dayItems.forEach(function (item, index) {
          html += renderEventPeekHtml(item, dayKey + '-week-' + String(index));
        });
        html += '</div>';
      }
      html += '</section>';
    }
    html += '</div>';
    html += renderEventsMobileList(weekItems, 'No events scheduled this week.');
    block.innerHTML = html;
    block.setAttribute("data-metis-events-cursor-current", startKey);
    return true;
  }

  function closeOpenSubmenus(exceptItem) {
    if (!exceptItem) {
      var openedAll = document.querySelectorAll(".metis-shell-menu-item.has-children.is-open");
      for (var a = 0; a < openedAll.length; a++) {
        setItemOpen(openedAll[a], false);
      }
      return;
    }

    var parentList = exceptItem.parentElement;
    if (!parentList) return;
    var opened = [];
    for (var c = 0; c < parentList.children.length; c++) {
      var child = parentList.children[c];
      if (child !== exceptItem && child.classList && child.classList.contains("metis-shell-menu-item") && child.classList.contains("has-children") && child.classList.contains("is-open")) {
        opened.push(child);
      }
    }
    for (var i = 0; i < opened.length; i++) {
      setItemOpen(opened[i], false);
    }
  }

  function isMobileViewport() {
    if (typeof window.matchMedia === "function") {
      return window.matchMedia("(max-width: " + String(mobileBreakpoint) + "px)").matches;
    }
    var visualWidth = window.visualViewport && window.visualViewport.width ? window.visualViewport.width : 0;
    var width = visualWidth || window.innerWidth || document.documentElement.clientWidth || 0;
    return width > 0 && width <= mobileBreakpoint;
  }

  function syncViewportMode() {
    var mobileViewport = isMobileViewport();
    if (mobileViewport) {
      body.classList.add("metis-nav-mobile-viewport");
    } else {
      body.classList.remove("metis-nav-mobile-viewport");
      setOpen(false);
      closeOpenSubmenus(null);
    }
  }

  function setOpen(open, triggerSource) {
    var active = !!open && isMobileViewport();
    var panel = document.querySelector("[data-metis-nav-panel]");
    var nav = document.querySelector(".metis-shell-nav-primary");
    if (active) {
      body.classList.add("metis-nav-open");
    } else {
      body.classList.remove("metis-nav-open");
      closeOpenSubmenus(null);
    }
    var btns = document.querySelectorAll("[data-metis-nav-toggle]");
    for (var i = 0; i < btns.length; i++) {
      btns[i].setAttribute("aria-expanded", active ? "true" : "false");
      btns[i].setAttribute("aria-label", active ? "Close primary menu" : "Open primary menu");
    }
    if (panel) {
      panel.setAttribute("aria-hidden", active ? "false" : "true");
    }
    if (nav) {
      nav.setAttribute("aria-hidden", active ? "false" : "true");
    }
    if (active) {
      if (triggerSource && typeof triggerSource.focus === "function") {
        lastNavToggle = triggerSource;
      }
      if (panel || nav) {
        var focusScope = panel || nav;
        var focusTarget = focusScope.querySelector("a[href], button:not([disabled]), [tabindex]:not([tabindex=\"-1\"])");
        if (focusTarget && typeof focusTarget.focus === "function") {
          window.setTimeout(function () { focusTarget.focus(); }, 0);
        }
      }
    } else if (lastNavToggle && typeof lastNavToggle.focus === "function") {
      window.setTimeout(function () { lastNavToggle.focus(); }, 0);
    }
  }

  var btns = document.querySelectorAll("[data-metis-nav-toggle]");
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener("click", function (event) {
      event.preventDefault();
      event.stopPropagation();
      setOpen(!body.classList.contains("metis-nav-open"), event.currentTarget || this);
    });
  }

  document.addEventListener("click", function (e) {
    var mobileViewport = isMobileViewport();
    if (body.classList.contains("metis-nav-open") && mobileViewport) {
      var nav = e.target && e.target.closest ? e.target.closest("[data-metis-nav-panel]") : null;
      var tgl = e.target && e.target.closest ? e.target.closest("[data-metis-nav-toggle]") : null;
      if (!nav && !tgl) {
        setOpen(false);
      }
    }

    if (!mobileViewport && dropdownBehavior === "click") {
      var insidePrimaryNav = e.target && e.target.closest ? e.target.closest(".metis-shell-nav-primary") : null;
      if (!insidePrimaryNav) {
        closeOpenSubmenus(null);
      }
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      setOpen(false);
      closeOpenSubmenus(null);
      return;
    }

    if (e.key !== "Enter" && e.key !== " ") {
      return;
    }

    var labelTrigger = e.target && e.target.closest
      ? e.target.closest(".metis-shell-menu-item.has-children > .metis-shell-menu-label")
      : null;
    if (!labelTrigger) {
      return;
    }

    var labelItem = labelTrigger.closest ? labelTrigger.closest(".metis-shell-menu-item.has-children") : null;
    if (!labelItem) return;
    e.preventDefault();
    e.stopPropagation();
    if (labelItem.classList.contains("is-open")) {
      setItemOpen(labelItem, false);
      return;
    }
    closeOpenSubmenus(labelItem);
    setItemOpen(labelItem, true);
  });

  function bindDesktopHoverMenus() {
    var items = document.querySelectorAll(".metis-shell-nav-primary .metis-shell-menu-item.has-children");
    for (var i = 0; i < items.length; i++) {
      var item = items[i];
      if (item.getAttribute("data-metis-hover-bound") === "1") {
        continue;
      }
      item.setAttribute("data-metis-hover-bound", "1");

      item.addEventListener("pointerenter", function (event) {
        if (isMobileViewport() || dropdownBehavior !== "hover") {
          return;
        }
        var currentItem = event.currentTarget;
        if (!currentItem || !currentItem.classList) {
          return;
        }
        if (event.pointerType && event.pointerType.toLowerCase() === "touch") {
          return;
        }
        clearHoverTimers(currentItem);
        var state = itemTimerState(currentItem);
        state.open = window.setTimeout(function () {
          closeOpenSubmenus(currentItem);
          setItemOpen(currentItem, true);
        }, hoverOpenDelay);
      });

      item.addEventListener("pointerleave", function (event) {
        if (isMobileViewport() || dropdownBehavior !== "hover") {
          return;
        }
        var currentItem = event.currentTarget;
        if (!currentItem || !currentItem.classList) {
          return;
        }
        clearHoverTimers(currentItem);
        var state = itemTimerState(currentItem);
        state.close = window.setTimeout(function () {
          if (currentItem.matches(":hover")) {
            return;
          }
          if (currentItem.contains(document.activeElement)) {
            return;
          }
          setItemOpen(currentItem, false);
        }, hoverCloseDelay);
      });

      item.addEventListener("focusin", function (event) {
        if (isMobileViewport() || dropdownBehavior !== "hover") {
          return;
        }
        var currentItem = event.currentTarget;
        if (!currentItem || !currentItem.classList) {
          return;
        }
        closeOpenSubmenus(currentItem);
        setItemOpen(currentItem, true);
      });

      item.addEventListener("focusout", function (event) {
        if (isMobileViewport() || dropdownBehavior !== "hover") {
          return;
        }
        var currentItem = event.currentTarget;
        if (!currentItem || !currentItem.classList) {
          return;
        }
        clearHoverTimers(currentItem);
        var state = itemTimerState(currentItem);
        state.close = window.setTimeout(function () {
          if (currentItem.contains(document.activeElement)) {
            return;
          }
          setItemOpen(currentItem, false);
        }, hoverCloseDelay);
      });
    }
  }

  document.addEventListener("click", function (e) {
    var mobileToggle = e.target && e.target.closest ? e.target.closest("[data-metis-mobile-toggle]") : null;
    if (mobileToggle) {
      var mobileItem = mobileToggle.closest ? mobileToggle.closest(".metis-mobile-nav-item.has-children") : null;
      if (!mobileItem) return;
      e.preventDefault();
      e.stopPropagation();
      var mobileList = mobileItem.parentElement;
      if (mobileList) {
        for (var m = 0; m < mobileList.children.length; m++) {
          var sibling = mobileList.children[m];
          if (sibling !== mobileItem && sibling.classList && sibling.classList.contains("metis-mobile-nav-item") && sibling.classList.contains("has-children")) {
            setMobileItemOpen(sibling, false);
          }
        }
      }
      setMobileItemOpen(mobileItem, !mobileItem.classList.contains("is-open"));
      return;
    }

    var trigger = e.target && e.target.closest
      ? e.target.closest(".metis-shell-menu-item.has-children > .metis-shell-menu-link, .metis-shell-menu-item.has-children > .metis-shell-menu-btn, .metis-shell-menu-item.has-children > .metis-shell-menu-label")
      : null;

    if (trigger) {
      var item = trigger.closest ? trigger.closest(".metis-shell-menu-item.has-children") : null;
      if (!item) return;
      var mobileViewport = isMobileViewport();
      var desktopClick = !mobileViewport && dropdownBehavior === "click";
      var shouldToggle = mobileViewport || desktopClick;
      if (!shouldToggle) {
        if (trigger.tagName && trigger.tagName.toLowerCase() === "button") {
          var hoverButtonUrl = triggerUrl(trigger);
          if (isNavigableUrl(hoverButtonUrl)) {
            navigate(hoverButtonUrl);
          }
        }
        return;
      }

      var url = triggerUrl(trigger);
      var alreadyOpen = item.classList.contains("is-open");
      e.preventDefault();
      e.stopPropagation();

      if (alreadyOpen) {
        if (!mobileViewport && isNavigableUrl(url)) {
          navigate(url);
          return;
        }
        setItemOpen(item, false);
        return;
      }

      closeOpenSubmenus(item);
      setItemOpen(item, true);
      return;
    }

    var button = e.target && e.target.closest ? e.target.closest("[data-metis-nav-url]") : null;
    if (!button) {
      var leafTrigger = e.target && e.target.closest
        ? e.target.closest(".metis-shell-nav-primary a.metis-shell-menu-link, .metis-shell-nav-primary .metis-shell-menu-btn, .metis-template-mobile-nav a.metis-mobile-nav-link, .metis-template-mobile-actions a.metis-mobile-nav-action")
        : null;
      if (leafTrigger && body.classList.contains("metis-nav-open") && isMobileViewport()) {
        window.setTimeout(function () { setOpen(false); }, 0);
      }
      return;
    }
    var url = String(button.getAttribute("data-metis-nav-url") || "").trim();
    if (!isNavigableUrl(url)) {
      return;
    }
    navigate(url);
  });

  function fetchEventsBlock(block, offset, link, cursor) {
    if (!block || block.getAttribute("data-metis-events-loading") === "1") {
      return;
    }
    var allBlocks = Array.prototype.slice.call(document.querySelectorAll("[data-metis-events-block=\"1\"]"));
    var blockIndex = allBlocks.indexOf(block);
    if (blockIndex < 0) blockIndex = 0;

    var requestUrl = new URL(link && link.href ? link.href : window.location.href, window.location.href);
    requestUrl.searchParams.set("metis_events_offset", String(offset));
    if (cursor) {
      requestUrl.searchParams.set("metis_events_cursor", cursor);
    } else {
      requestUrl.searchParams.delete("metis_events_cursor");
    }
    requestUrl.searchParams.set("_metis_events_nav", String(Date.now()));
    block.setAttribute("data-metis-events-loading", "1");
    block.classList.add("is-loading");

    window.fetch(requestUrl.toString(), {
      cache: "no-store",
      credentials: "same-origin",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "Cache-Control": "no-cache",
        "Pragma": "no-cache"
      }
    }).then(function (response) {
      if (!response.ok) throw new Error("Calendar request failed.");
      return response.text();
    }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, "text/html");
      var nextBlocks = doc.querySelectorAll("[data-metis-events-block=\"1\"]");
      var nextBlock = nextBlocks[blockIndex] || nextBlocks[0];
      if (!nextBlock) throw new Error("Calendar markup missing.");
      block.replaceWith(nextBlock);
    }).catch(function () {
      if (link && link.href) {
        window.location.assign(link.href);
      }
    }).finally(function () {
      var liveBlocks = document.querySelectorAll("[data-metis-events-block=\"1\"]");
      for (var i = 0; i < liveBlocks.length; i++) {
        liveBlocks[i].removeAttribute("data-metis-events-loading");
        liveBlocks[i].classList.remove("is-loading");
      }
    });
  }

  document.addEventListener("click", function (event) {
    var printButton = event.target && event.target.closest
      ? event.target.closest("[data-metis-events-print]")
      : null;
    if (printButton) {
      event.preventDefault();
      event.stopPropagation();
      var printHref = s(printButton.getAttribute("data-metis-events-print") || printButton.getAttribute("href") || "").trim();
      var printBlock = printButton.closest ? printButton.closest("[data-metis-events-block=\"1\"]") : null;
      openCalendarPrintView(printBlock, printHref);
      return;
    }
    var navLink = event.target && event.target.closest
      ? event.target.closest("[data-metis-events-nav]")
      : null;
    if (!navLink) {
      return;
    }
    var block = navLink.closest ? navLink.closest("[data-metis-events-block=\"1\"]") : null;
    if (!block) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    var offset = parseInt(String(navLink.getAttribute("data-metis-events-offset") || "0"), 10);
    var cursor = s(navLink.getAttribute("data-metis-events-cursor") || "");
    var localState = parseEventsState(block);
    if (localState && (localState.view === "calendar" || localState.view === "week")) {
      var rendered = localState.view === "calendar"
        ? renderCalendarBlockLocally(block, localState, cursor)
        : renderWeekBlockLocally(block, localState, cursor);
      if (rendered) {
        return;
      }
    }
    fetchEventsBlock(block, isFinite(offset) ? offset : 0, navLink, cursor);
  });

  function syncCondensedHeader() {
    var y = window.scrollY || window.pageYOffset || 0;
    if (y > 32) {
      body.classList.add("metis-template-scroll-condensed");
    } else {
      body.classList.remove("metis-template-scroll-condensed");
    }
  }

  window.addEventListener("scroll", syncCondensedHeader, { passive: true });
  window.addEventListener("resize", syncViewportMode, { passive: true });
  bindDesktopHoverMenus();
  syncViewportMode();
  syncCondensedHeader();
})();
