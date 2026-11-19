<style>
        /*
         * Thermal receipts (80mm). A thermal printer prints black dots only: grey, colour and the soft edges of
         * thin text come out as scattered dots and look faded. So everything here is solid black, bold and no
         * smaller than 11px, in a plain sans-serif font with thick strokes, and there is no logo (19 Nov 2026).
         */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            color: #000 !important;
        }

        @page {
            size: 80mm 297mm;
            margin: 0;
        }

        html,
        body {
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; /* DejaVu first: the PDF copy needs its ₵ sign */
            font-size: 12px;
            font-weight: 700;
            line-height: 1.35;
            margin: 0;
            width: 80mm;
        }

        body {
            padding: 4mm;
        }

        strong,
        b,
        th {
            font-weight: 900;
        }

        .center {
            text-align: center;
        }

        /* Logos print as a dotted smudge on thermal paper: the clinic name is the header. */
        .receipt-logo {
            display: none !important;
        }

        .clinic-name {
            font-size: 18px;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 3px;
            text-transform: uppercase;
        }

        .small {
            font-size: 11px;
        }

        .doc-title {
            font-size: 15px;
            font-weight: 900;
            letter-spacing: .5px;
            margin: 6px 0 3px;
            text-transform: uppercase;
        }

        .divider {
            border-top: 2px dashed #000;
            margin: 7px 0;
        }

        .strong-divider {
            border-top: 3px solid #000;
            margin: 7px 0;
        }

        .section {
            margin: 7px 0;
        }

        .label {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .row-line {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            margin: 3px 0;
        }

        table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            font-size: 11px;
            padding: 3px 1px;
            vertical-align: top;
        }

        th {
            border-bottom: 2px solid #000;
            text-align: left;
        }

        /* Full item names: a long one wraps onto the next line within this column. */
        .col-item {
            width: 44%;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .col-qty {
            text-align: center;
            width: 10%;
        }

        /* Amounts stay on one line (GH₵ 1,300.00); the item name gives way instead. */
        .col-price,
        .col-total {
            text-align: right;
            white-space: nowrap;
            width: 23%;
        }

        .money-row {
            clear: both;
            font-size: 12px;
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
            font-size: 16px;
            font-weight: 900;
            margin-top: 5px;
        }

        /* Was grey, which prints as faint dots: secondary lines are told apart by italics instead. */
        .muted {
            font-style: italic;
        }

        /* Paid / unpaid: a heavy box that reads at a glance (no red or green). */
        .status-box {
            border: 3px solid #000;
            font-size: 15px;
            font-weight: 900;
            letter-spacing: 1px;
            margin: 8px 0 4px;
            padding: 5px;
            text-align: center;
            text-transform: uppercase;
        }

        .footer {
            font-size: 11px;
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

            .no-print {
                display: none !important;
            }
        }

        /* A faint diagonal watermark does not print on thermal paper: a refunded sale shows the notice box only. */
        .refund-watermark {
            display: none;
        }

        .refund-notice {
            border: 3px solid #000;
            text-align: center;
            font-weight: 900;
            font-size: 13px;
            padding: 5px;
            margin: 8px 0 4px;
            letter-spacing: 1px;
        }
    </style>
