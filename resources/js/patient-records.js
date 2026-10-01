document.addEventListener('DOMContentLoaded', function () {
    window.focusFirstConsultationError = function (section) {
        const targets = section === 'examination'
            ? ['#consultation-iop-od', '#consultation-iop-os', '#consultation-section-examination']
            : section === 'management'
                ? ['#consultation-diagnosis-picker input', '#consultation-diagnosis-picker']
                : section === 'history'
                    ? ['#consultation-chief-complaint']
                    : ['#consultation-chief-complaint.is-invalid', '#consultation-iop-od.is-invalid', '#consultation-iop-os.is-invalid', '#consultation-diagnosis-picker.consultation-field-error input'];
        const target = targets.map(selector => document.querySelector(selector)).find(Boolean);
        if (!target) return;
        target.scrollIntoView({behavior:'smooth', block:'center'});
        target.closest('.consultation-section')?.classList.add('consultation-section--validation-focus');
        setTimeout(() => target.focus?.({preventScroll:true}), 350);
        setTimeout(() => target.closest('.consultation-section')?.classList.remove('consultation-section--validation-focus'), 1800);
    };
    window.addEventListener('scroll-consultation-section', event => {
        setTimeout(() => window.focusFirstConsultationError(event.detail?.section), 100);
    });
    window.addEventListener('beforeunload', function (event) {
        if (!window.consultationDirty) return;
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('consultation-form-clean', function () {
        window.consultationDirty = false;
    });
    window.addEventListener('focus-refraction-error', function (event) {
        setTimeout(() => {
            const form = document.querySelector('form[wire\\:submit="saveRefraction"]');
            const input = Array.from(form?.querySelectorAll('input, select, textarea') ?? []).find(element =>
                Array.from(element.attributes).some(attribute => attribute.name.startsWith('wire:model') && attribute.value === event.detail?.field)
            );
            if (!input) return;
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            input.scrollIntoView({behavior: 'smooth', block: 'center'});
            input.focus({preventScroll: true});
            input.addEventListener('input', () => {
                input.classList.remove('is-invalid');
                input.removeAttribute('aria-invalid');
            }, {once: true});
        }, 150);
    });
    document.addEventListener('click', function (event) {
        const tab = event.target.closest('.emr-tab');
        if (!tab || tab.classList.contains('emr-tab--active') || !window.consultationDirty) return;
        if (!confirm('You have unsaved consultation changes. Leave this section?')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        } else {
            window.consultationDirty = false;
        }
    }, true);
});

let patientVaTrendChart = null;
let patientIopTrendChart = null;

function getPatientClinicalTrendData() {
    const dataElement = document.getElementById('clinical-trend-data');

    if (!dataElement) {
        return null;
    }

    try {
        return JSON.parse(dataElement.dataset.trends || '{}');
    } catch (error) {
        return null;
    }
}

function renderPatientClinicalTrendCharts() {
    if (typeof Chart === 'undefined') {
        return;
    }

    const trendData = getPatientClinicalTrendData();

    if (!trendData || !trendData.labels || trendData.labels.length === 0) {
        return;
    }

    const vaCanvas = document.getElementById('visualAcuityTrendChart');
    const iopCanvas = document.getElementById('iopTrendChart');

    if (patientVaTrendChart) {
        patientVaTrendChart.destroy();
        patientVaTrendChart = null;
    }

    if (patientIopTrendChart) {
        patientIopTrendChart.destroy();
        patientIopTrendChart = null;
    }

    if (vaCanvas) {
        patientVaTrendChart = new Chart(vaCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: trendData.labels,
                datasets: [
                    {
                        label: 'OD',
                        data: trendData.va.od,
                        borderColor: '#2563EB',
                        backgroundColor: 'rgba(37, 99, 235, 0.07)',
                        pointBackgroundColor: '#2563EB',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: false,
                        tension: 0.3,
                        spanGaps: true
                    },
                    {
                        label: 'OS',
                        data: trendData.va.os,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.07)',
                        pointBackgroundColor: '#0EA5E9',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: false,
                        tension: 0.3,
                        spanGaps: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11, family: '-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif' }, boxWidth: 12, padding: 14 }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleColor: '#F1F5F9',
                        bodyColor: '#CBD5E1',
                        borderColor: '#334155',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: function (context) {
                                const datasetLabel = context.dataset.label;
                                const rawValues = datasetLabel === 'OD' ? trendData.va.odRaw : trendData.va.osRaw;
                                const rawValue = rawValues[context.dataIndex] || 'N/A';
                                return datasetLabel + ': ' + rawValue + ' (' + context.parsed.y + ')';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: '#F1F5F9' },
                        ticks: { font: { size: 10 }, color: '#94A3B8' }
                    },
                    y: {
                        reverse: true,
                        min: -0.40,
                        max: 3.00,
                        grid: { color: '#F1F5F9' },
                        ticks: {
                            stepSize: 0.30,
                            font: { size: 10 },
                            color: '#94A3B8',
                            callback: function(val) {
                                const map = {
                                    '-0.3': '6/3', '0': '6/6', '0.3': '6/12',
                                    '0.6': '6/24', '1': '6/60', '1.7': 'CF',
                                    '2.3': 'HM', '2.7': 'LP'
                                };
                                var key = parseFloat(val).toFixed(1).replace(/\.0$/, '');
                                return map[key] !== undefined ? map[key] + ' (' + val + ')' : val;
                            }
                        },
                        title: { display: true, text: 'LogMAR  (↑ worse  ↓ better)', font: { size: 10 }, color: '#94A3B8' }
                    }
                }
            }
        });
    }

    if (iopCanvas) {
        patientIopTrendChart = new Chart(iopCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: trendData.labels,
                datasets: [
                    {
                        label: 'OD',
                        data: trendData.iop.od,
                        borderColor: '#2563EB',
                        backgroundColor: 'rgba(37, 99, 235, 0.07)',
                        pointBackgroundColor: '#EF4444',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: false,
                        tension: 0.3,
                        spanGaps: true
                    },
                    {
                        label: 'OS',
                        data: trendData.iop.os,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.07)',
                        pointBackgroundColor: '#22C55E',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: false,
                        tension: 0.3,
                        spanGaps: true
                    },
                    {
                        label: 'IOP 21',
                        data: trendData.labels.map(function () { return 21; }),
                        borderColor: '#F59E0B',
                        borderDash: [6, 4],
                        pointRadius: 0,
                        fill: false,
                        tension: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11, family: '-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif' }, boxWidth: 12, padding: 14 }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleColor: '#F1F5F9',
                        bodyColor: '#CBD5E1',
                        borderColor: '#334155',
                        borderWidth: 1,
                        padding: 10
                    }
                },
                scales: {
                    x: { grid: { color: '#F1F5F9' }, ticks: { font: { size: 10 }, color: '#94A3B8' } },
                    y: {
                        grid: { color: '#F1F5F9' },
                        ticks: { beginAtZero: true, suggestedMax: 30, font: { size: 10 }, color: '#94A3B8' },
                        title: { display: true, text: 'IOP mmHg', font: { size: 10 }, color: '#94A3B8' }
                    }
                }
            }
        });
    }
}

// Auto-hide flash messages after 10 seconds
document.addEventListener('DOMContentLoaded', function () {
    refreshPatientClinicalTrendCharts();

    setTimeout(function () {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            // Fade out animation
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';

            // Remove from DOM after fade
            setTimeout(() => {
                alert.querySelector('.close')?.click();
            }, 500);
        });
    }, 10000); // 10 seconds
});

// Redraw only when the trend data changed or a chart canvas has no chart yet (it was just
// added, e.g. on switching back to the History tab). The canvases are wire:ignore, so a
// Livewire update never wipes a drawn chart.
let renderedTrendJson = null;

function refreshPatientClinicalTrendCharts() {
    if (typeof Chart === 'undefined') {
        return;
    }

    const dataElement = document.getElementById('clinical-trend-data');
    const trendJson = dataElement ? dataElement.dataset.trends : null;
    const missingChart = ['visualAcuityTrendChart', 'iopTrendChart'].some(function (id) {
        const canvas = document.getElementById(id);
        return canvas && !Chart.getChart(canvas);
    });

    if (trendJson !== renderedTrendJson || missingChart) {
        renderedTrendJson = trendJson;
        renderPatientClinicalTrendCharts();
    }
}

document.addEventListener('livewire:init', function () {
    refreshPatientClinicalTrendCharts();

    if (window.Livewire && window.Livewire.hook) {
        // After the server's update has been applied to the page, not in the middle of it.
        window.Livewire.hook('commit', function ({ succeed }) {
            succeed(function () {
                setTimeout(refreshPatientClinicalTrendCharts, 0);
            });
        });
    }
});

window.addEventListener('render-clinical-trend-charts', function () {
    setTimeout(refreshPatientClinicalTrendCharts, 100);
});

// Refraction: "Continue to Dispensing" takes the doctor to the Dispensing card.
window.goToDispensing = function () {
    const card = document.querySelector('.refraction-dispensing');
    if (!card) return;

    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.style.transition = 'box-shadow .3s ease';
    card.style.boxShadow = '0 0 0 4px rgba(180, 83, 9, .35)';
    setTimeout(() => { card.style.boxShadow = ''; }, 1600);

    // P.D. when dispensing is on, otherwise the switch that turns it on.
    const field = card.querySelector('input[wire\\:model="state.pd"]') || card.querySelector('#dispensing-required');
    setTimeout(() => field?.focus({ preventScroll: true }), 400);
};

// Print refraction
window.addEventListener('printRefraction', event => {
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write('<html><head><title>Refraction Prescription</title>');
    printWindow.document.write('<style>body{font-family:Arial;padding:20px;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(event.detail.html);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
});
