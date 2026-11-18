{{-- Chart.js (69 KB gzipped) is fetched only by the few pages that draw a chart:
     withChart(fn) runs fn once it has loaded, or at once if it already has. --}}
<script>
window.withChart = window.withChart || (function () {
    var queue = [], loading = false;
    return function (callback) {
        if (window.Chart) { callback(); return; }
        queue.push(callback);
        if (loading) return;
        loading = true;
        var script = document.createElement('script');
        script.src = @js(asset('backend/plugins/vendor-js/chart.min.js'));
        script.onload = function () {
            var waiting = queue; queue = [];
            waiting.forEach(function (fn) { try { fn(); } catch (e) { console.error(e); } });
        };
        script.onerror = function () { loading = false; };
        document.head.appendChild(script);
    };
})();
</script>
