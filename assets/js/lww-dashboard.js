(function () {
    if (!document.getElementById('lww-dashboard-canvas')) return;

    const canvas = document.getElementById('lww-dashboard-canvas').getContext('2d');

    function fetchData() {
        return new Promise(function (resolve, reject) {
            jQuery.post(lwwDashboard.ajax_url, {
                action: 'lww_get_dashboard_data',
                nonce: lwwDashboard.nonce
            }, function (response) {
                if (response && response.success) {
                    resolve(response.data);
                } else {
                    reject(response);
                }
            });
        });
    }

    function fetchAnalysis() {
        return new Promise(function (resolve, reject) {
            jQuery.post(lwwDashboard.ajax_url, {
                action: 'lww_get_demand_analysis',
                nonce: lwwDashboard.nonce
            }, function (response) {
                if (response && response.success) {
                    resolve(response);
                } else {
                    reject(response);
                }
            });
        });
    }

    fetchData().then(function (res) {
        const labels = res.labels || [];
        const data = res.data || [];

        const useSample = labels.length === 0;
        const chartLabels = useSample ? ['--'] : labels;
        const chartData = useSample ? [0] : data;

        const chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Importierte Einträge',
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    data: chartData,
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { display: true },
                    y: { beginAtZero: true, precision: 0 }
                }
            }
        });
    }).catch(function (err) {
        console.error('lww dashboard data error', err);
    });

    fetchAnalysis().then(function (response) {
        const analysis = response.data.analysis || null;
        const summary = response.data.summary || null;
        const el = document.getElementById('lww-demand-analysis');
        if (!el) return;

        if (analysis === null || !summary) {
            el.innerText = '<?php // Placeholder to be replaced by localized string on enqueue; keep simple fallback ?>';
            return;
        }

        let html = '<div><strong>Demand Analyse:</strong> ' + summary + '</div>';
        el.innerHTML = html;
    }).catch(function (err) {
        console.error('lww demand analysis error', err);
    });

})();
