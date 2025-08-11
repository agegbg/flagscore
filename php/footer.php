<?php
// php/footer.php
declare(strict_types=1);
?>
    <footer style="margin-top:2em; text-align:center; font-size:0.85em; color:#666;">
        <hr>
        <div>&copy; <?= date('Y') ?> My Football System</div>
        <div>Version 1.0.0</div>
    </footer>

    <!-- Matomo -->
    <script>
      var _paq = window._paq = window._paq || [];
      /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
      _paq.push(['trackPageView']);
      _paq.push(['enableLinkTracking']);
      (function() {
        var u="//www.zebras.se/matomo/";
        _paq.push(['setTrackerUrl', u+'matomo.php']);
        _paq.push(['setSiteId', '3']);
        var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
        g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
      })();
    </script>
    <!-- End Matomo Code -->

  </body>
</html>
