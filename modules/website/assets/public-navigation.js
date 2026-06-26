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
