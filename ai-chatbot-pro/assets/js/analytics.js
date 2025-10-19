(function() {
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof aicpAnalyticsData === 'undefined') {
            return;
        }

        const canvas = document.getElementById('aicp-analytics-chart');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        const { labels, conversations, leads, conversionRates, i18n } = aicpAnalyticsData;

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: i18n.conversations,
                        data: conversations,
                        borderColor: '#1d4ed8',
                        backgroundColor: 'rgba(29, 78, 216, 0.15)',
                        tension: 0.35,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        yAxisID: 'y',
                    },
                    {
                        label: i18n.leads,
                        data: leads,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.15)',
                        tension: 0.35,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        yAxisID: 'y',
                    },
                    {
                        label: i18n.conversionRate,
                        data: conversionRates,
                        borderColor: '#f97316',
                        backgroundColor: '#f97316',
                        tension: 0.35,
                        fill: false,
                        borderWidth: 2,
                        borderDash: [6, 6],
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    title: {
                        display: Boolean(i18n.chartTitle),
                        text: i18n.chartTitle,
                        padding: {
                            top: 10,
                            bottom: 20
                        },
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: i18n.conversations,
                        },
                        grid: {
                            drawBorder: false,
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: i18n.conversionRate,
                        },
                        grid: {
                            drawBorder: false,
                            drawOnChartArea: false,
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                        },
                        grid: {
                            drawBorder: false,
                        }
                    }
                }
            }
        });
    });
})();
