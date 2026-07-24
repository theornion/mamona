(function () {
  var headings = Array.prototype.slice.call(document.querySelectorAll("#main > .post.featured h2, #main > .post.featured h3"));

  if (!headings.length) {
    return;
  }

  var lastScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
  var ticking = false;
  var litDuration = 1000;
  var headingStates = headings.map(function (heading) {
    return {
      heading: heading,
      region: null,
      timeout: null
    };
  });

  function getRegion(heading, topTrigger, bottomTrigger) {
    var rect = heading.getBoundingClientRect();
    var center = rect.top + rect.height / 2;

    if (center < topTrigger) {
      return "above";
    }

    if (center > bottomTrigger) {
      return "below";
    }

    return "inside";
  }

  function lightHeading(state) {
    if (state.timeout) {
      window.clearTimeout(state.timeout);
    }

    state.heading.classList.add("is-scroll-lit");
    state.timeout = window.setTimeout(function () {
      state.heading.classList.remove("is-scroll-lit");
      state.timeout = null;
    }, litDuration);
  }

  function updateHeadings() {
    var currentScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var topTrigger = viewportHeight * 0.08;
    var bottomTrigger = viewportHeight * 0.8;

    headingStates.forEach(function (state) {
      var nextRegion = getRegion(state.heading, topTrigger, bottomTrigger);

      if (
        (state.region === "below" && nextRegion === "inside")
        || (state.region === "above" && nextRegion === "inside")
      ) {
        lightHeading(state);
      }

      state.region = nextRegion;
    });

    lastScrollY = currentScrollY;
    ticking = false;
  }

  function requestUpdate() {
    if (ticking) {
      return;
    }

    ticking = true;
    window.requestAnimationFrame(updateHeadings);
  }

  (function initHeadingStates() {
    var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    var topTrigger = viewportHeight * 0.08;
    var bottomTrigger = viewportHeight * 0.8;

    headingStates.forEach(function (state) {
      state.region = getRegion(state.heading, topTrigger, bottomTrigger);
    });
  }());

  window.addEventListener("scroll", requestUpdate, { passive: true });
  window.addEventListener("resize", requestUpdate);
}());
