// /stream/ssr: each streamed section starts with
//   <script src="/js/stream-ssr.js" data-remove="sk1"></script>
// A parser-inserted script runs when the parser reaches it, so the section's
// skeleton is removed just before the section itself is painted.
document.getElementById(document.currentScript?.dataset.remove ?? '')?.remove();
