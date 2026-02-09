/**
 * Dashboard-only iOS Safari zoom preventer.
 * - blocks double‑tap zoom
 * - blocks pinch zoom gestures (page level)
 * Map still has its own +/- controls.
 */
(function(){
  var lastTouchEnd = 0;

  // Block double‑tap zoom
  document.addEventListener('touchend', function(e){
    var now = Date.now();
    if (now - lastTouchEnd <= 350) {
      e.preventDefault();
    }
    lastTouchEnd = now;
  }, { passive: false });

  // Block pinch zoom (gesture events are Safari-specific)
  ['gesturestart','gesturechange','gestureend'].forEach(function(ev){
    document.addEventListener(ev, function(e){ e.preventDefault(); }, { passive: false });
  });

  // Hint modern browsers to treat taps as clicks (no double‑tap zoom)
  var hintTargets = document.querySelectorAll('body, header, .map-layout, .card, .toolbar, .map-actions');
  hintTargets.forEach(function(el){ try { el.style.touchAction = 'manipulation'; } catch(_){} });
})();
