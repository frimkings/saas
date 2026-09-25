<?php

return [
    'admin.expenses-component' => [
        'fields' => ['fromDate', 'toDate', 'categoryId', 'search', 'perPage'], 'methods' => ['resetFilters'],
    ],
    'admin.income-statement-component' => [
        'fields' => ['fromDate', 'toDate', 'section', 'selectedPreset'], 'methods' => ['setThisMonth', 'setLastMonth'],
    ],
    'admin.daily-cash-summary-component' => [
        'fields' => ['reportDate', 'line'], 'methods' => ['print'],
    ],
    'admin.combined-statement-component' => [
        'fields' => ['from', 'to'], 'methods' => ['setPeriod'],
    ],
    'optical.optical-jobs-component' => [
        'fields' => ['from', 'to'], 'methods' => [],
    ],
    'optical.optical-profit-component' => [
        'fields' => ['from', 'to', 'lockNotes'], 'methods' => ['setPeriod'],
    ],
    'secretary.patients-component' => [
        'fields' => ['nameSearch', 'pxSearch', 'fromDate', 'toDate', 'fromDateDisplay', 'toDateDisplay', 'insurerFilter', 'activeTab'],
        'methods' => ['resetFilters', 'setDatePreset', 'selectPatient'],
    ],
    'reports-component' => [
        'fields' => ['fromDate', 'toDate', 'searchQuery', 'perPage', 'showRefunded', 'activeTab', 'analyticsView', 'chartPeriod', 'paymentStatus', 'purchaseType'],
        'methods' => ['switchTab', 'switchAnalyticsView', 'loadChart', 'resetFilters', 'refreshData', 'exportCsv', 'showItemsModal', 'closeItemsModal', 'showRefundDetailsModal', 'closeRefundDetailsModal'],
    ],
    'doctor.patient-records-component' => [
        'fields' => [], 'methods' => ['printRefraction', 'cancelAndGoBack'],
    ],
];
