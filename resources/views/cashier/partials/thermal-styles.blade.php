<style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        @page {
            size: 80mm 297mm;
            margin: 0;
        }

        html,
        body {
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans Mono", "Courier New", monospace;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
            width: 80mm;
        }

        body {
            padding: 4mm;
        }

        .center {
            text-align: center;
        }

        .receipt-logo {
            max-height: 16mm;
            max-width: 34mm;
            object-fit: contain;
            margin-bottom: 3mm;
        }

        .clinic-name {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: .2px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .small {
            font-size: 9px;
        }

        .divider {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        .strong-divider {
            border-top: 2px solid #000;
            margin: 6px 0;
        }

        .section {
            margin: 7px 0;
        }

        .label {
            font-weight: 700;
            text-transform: uppercase;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            font-size: 9px;
            padding: 3px 1px;
            vertical-align: top;
        }

        th {
            border-bottom: 1px solid #000;
            font-weight: 700;
            text-align: left;
        }

        .col-item {
            width: 47%;
        }

        .col-qty {
            text-align: center;
            width: 11%;
        }

        .col-price,
        .col-total {
            text-align: right;
            width: 21%;
        }

        .money-row {
            clear: both;
            font-size: 10px;
            margin: 3px 0;
            overflow: hidden;
            width: 100%;
        }

        .money-row .left {
            float: left;
            width: 48%;
        }

        .money-row .right {
            float: right;
            text-align: right;
            width: 52%;
        }

        .grand-total {
            font-size: 13px;
            font-weight: 900;
            margin-top: 5px;
        }

        .muted {
            color: #555;
        }

        .footer {
            font-size: 9px;
            margin-top: 10px;
            text-align: center;
        }

        @media print {
            @page {
                size: 80mm 297mm;
                margin: 0;
            }

            body {
                padding: 4mm;
            }
        }

        /* ── Refund watermark ────────────────────────────── */
        .refund-watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 36px;
            font-weight: 900;
            color: rgba(220, 38, 38, 0.18);
            letter-spacing: 4px;
            text-transform: uppercase;
            white-space: nowrap;
            pointer-events: none;
            z-index: 9999;
            user-select: none;
            border: 5px solid rgba(220, 38, 38, 0.18);
            padding: 6px 14px;
        }

        .refund-notice {
            border: 2px solid #dc2626;
            color: #dc2626;
            text-align: center;
            font-weight: 900;
            font-size: 11px;
            padding: 5px;
            margin: 8px 0 4px;
            letter-spacing: 1px;
        }
    </style>
