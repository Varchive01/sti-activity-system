<?php
/**
 * includes/head-theme-init.php
 *
 * Universal Anti-FOUC (Flash of Unstyled Content / White Flash Prevention) Component.
 * Included inside <head> across all pages to guarantee immediate, synchronous theme application
 * before the browser paints the first frame.
 */
$cachedThemeCookie = $_COOKIE['sti-theme'] ?? '';
?>
<script>
(function() {
  try {
    var serverTheme = <?= json_encode($cachedThemeCookie) ?>;
    var savedTheme = serverTheme || (window.localStorage ? localStorage.getItem('sti-theme') : null);
    if (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      savedTheme = 'dark';
    }
    if (savedTheme === 'dark') {
      document.documentElement.dataset.theme = 'dark';
    } else if (savedTheme === 'light') {
      document.documentElement.dataset.theme = 'light';
    }
  } catch (e) {}
})();
</script>
