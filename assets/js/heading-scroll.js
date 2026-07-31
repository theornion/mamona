(function () {
  'use strict';

  if (window.MamonaPerformance && window.MamonaPerformance.shouldReduceEffects()) return;
  var headings = Array.prototype.slice.call(
    document.querySelectorAll('#main > .post.featured h2, #main > .post.featured h3')
  );
  if (!headings.length || !('IntersectionObserver' in window)) return;

  var states = new WeakMap();
  var observer = new IntersectionObserver(function (entries) {
    if (document.hidden) return;
    entries.forEach(function (entry) {
      var wasVisible = states.get(entry.target) === true;
      states.set(entry.target, entry.isIntersecting);
      if (!entry.isIntersecting || wasVisible) return;
      entry.target.classList.add('is-scroll-lit');
      window.setTimeout(function () {
        entry.target.classList.remove('is-scroll-lit');
      }, 1000);
    });
  }, { rootMargin: '-8% 0px -20% 0px', threshold: 0 });

  headings.forEach(function (heading) {
    states.set(heading, false);
    observer.observe(heading);
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) headings.forEach(function (heading) {
      heading.classList.remove('is-scroll-lit');
    });
  }, { passive: true });
}());
