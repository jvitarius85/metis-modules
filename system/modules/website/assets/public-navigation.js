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
    var opened = document.querySelectorAll(".metis-shell-menu-item.has-children.is-open");
    for (var i = 0; i < opened.length; i++) {
      if (exceptItem && opened[i] === exceptItem) continue;
      opened[i].classList.remove("is-open");
    }
  }

  function isMobileViewport() {
    var width = window.innerWidth || document.documentElement.clientWidth || 0;
    return width <= mobileBreakpoint;
  }

  function syncViewportMode() {
    if (isMobileViewport()) {
      body.classList.add("metis-nav-mobile-viewport");
    } else {
      body.classList.remove("metis-nav-mobile-viewport");
      setOpen(false);
      closeOpenSubmenus(null);
    }
  }

  function setOpen(open) {
    var active = !!open && isMobileViewport();
    if (active) {
      body.classList.add("metis-nav-open");
    } else {
      body.classList.remove("metis-nav-open");
    }
    var btns = document.querySelectorAll("[data-metis-nav-toggle]");
    for (var i = 0; i < btns.length; i++) {
      btns[i].setAttribute("aria-expanded", active ? "true" : "false");
    }
  }

  var btns = document.querySelectorAll("[data-metis-nav-toggle]");
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener("click", function () {
      setOpen(!body.classList.contains("metis-nav-open"));
    });
  }

  document.addEventListener("click", function (e) {
    var mobileViewport = isMobileViewport();
    if (body.classList.contains("metis-nav-open") && mobileViewport) {
      var nav = e.target && e.target.closest ? e.target.closest(".metis-shell-nav-primary") : null;
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
    }
  });

  document.addEventListener("click", function (e) {
    var trigger = e.target && e.target.closest
      ? e.target.closest(".metis-shell-menu-item.has-children > .metis-shell-menu-link, .metis-shell-menu-item.has-children > .metis-shell-menu-btn")
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
        if (isNavigableUrl(url)) {
          navigate(url);
          return;
        }
        item.classList.remove("is-open");
        return;
      }

      closeOpenSubmenus(item);
      item.classList.add("is-open");
      return;
    }

    var button = e.target && e.target.closest ? e.target.closest("[data-metis-nav-url]") : null;
    if (!button) {
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
  syncViewportMode();
  syncCondensedHeader();
})();
