<?php

namespace App\Support\Guides;

/**
 * The guided tours, page by page. A page asks for its tour with what it knows (who is looking, what the
 * plan includes) and gets the steps that apply; the browser also skips any step whose highlighted part is
 * not on the screen, so a tour never points at something the clinic does not have or has not used yet.
 *
 * A step: 'target' is the data-tour name of what to highlight (none: a message in the middle of the screen),
 * 'open' the data-tour name of something to click first (a tab, say), then 'title' and 'body'.
 * To show people a tour again after adding steps to it, raise its number in VERSIONS.
 * Another suite gets a tour by adding a case to tour() and putting <x-guide-tour page="..."> on its page.
 */
final class TourCatalog
{
    public const VERSIONS = [
        'attendance.manager' => 1,
        'attendance.head' => 1,
        'attendance.staff' => 1,
        'optical.dashboard' => 1,
        'optical.orders' => 1,
        'optical.order-create' => 1,
        'optical.lab' => 1,
        'optical.catalogue' => 1,
        'optical.pos' => 1,
    ];

    public static function version(string $key): ?int
    {
        return self::VERSIONS[$key] ?? null;
    }

    /** ['key', 'version', 'title', 'steps'] for the page and the person, or null when the page has no tour for them. */
    public static function tour(string $page, array $context = []): ?array
    {
        $tour = match ($page) {
            'attendance' => ($context['manager'] ?? false) ? self::attendanceManager($context) : self::attendanceHead($context),
            'my-attendance' => self::attendanceStaff($context),
            'optical.dashboard' => self::opticalDashboard($context),
            'optical.orders' => self::opticalOrders(),
            'optical.order-create' => self::opticalOrderCreate(),
            'optical.lab' => self::opticalLab(),
            'optical.catalogue' => self::opticalCatalogue($context),
            'optical.pos' => self::opticalPos(),
            default => null,
        };
        if (! $tour) return null;
        $tour['steps'] = array_values(array_filter($tour['steps']));

        return $tour + ['version' => self::version($tour['key']) ?? 1];
    }

    private static function attendanceManager(array $c): array
    {
        $extras = $c['extras'] ?? [];

        return ['key' => 'attendance.manager', 'title' => 'Attendance', 'steps' => [
            ['title' => 'Welcome to Attendance', 'body' => 'This page shows who came in on time, who was late and who was absent, from your attendance terminal'.(($extras['phone'] ?? false) ? ' or staff phones' : '').'. Here is a quick look round. It takes about a minute.'],
            ['target' => 'setup-checklist', 'title' => 'Getting started', 'body' => 'Work down this list to get going. Each step ticks itself once it is done, and the list goes away when the first five are.'],
            ($c['board'] ?? false) ? ['target' => 'att-tab-today', 'open' => 'att-tab-today', 'title' => 'Today', 'body' => 'Who is in now, who is not in yet and who is due later, as punches arrive. Click any count to see the people behind it.'] : null,
            ['target' => 'att-period', 'open' => 'att-tab-report', 'title' => 'Report: pick the days', 'body' => 'Choose any days: this month, last month, a week or a range of your own. Everything below follows the days you pick.'],
            ['target' => 'att-views', 'title' => 'Three ways to look', 'body' => 'Summary has one line per person. Day by day is a month calendar: click a day to see who did what.'.(($extras['trends'] ?? false) ? ' Trends compares the last six months.' : '')],
            ['target' => 'att-show', 'title' => 'Start with what needs a look', 'body' => 'The list opens on the people who were late, absent or missed a punch. Switch to Everyone to see all staff.'],
            ['target' => 'att-people', 'title' => 'One person, day by day', 'body' => 'Click a name to see their days, punches and leave. Someone forgot to punch out? Use Correct on that day and give a reason; every correction is kept in the audit trail.'],
            ['target' => 'att-exports', 'title' => 'Take it away', 'body' => 'Download the days on show as a spreadsheet (CSV) or a PDF to print or send.'],
            ['target' => 'att-tab-requests', 'title' => 'Requests', 'body' => 'Leave that staff ask for'.(($extras['overtime'] ?? false) ? ', and overtime waiting for approval,' : '').' comes here, with a count on the tab. You get an email too.'],
            ['target' => 'att-setup', 'title' => 'Set up', 'body' => 'Things you set once: working hours and grace minutes, holidays, terminals'.(($extras['shifts'] ?? false) ? ', shifts' : '').(($extras['leave'] ?? false) ? ', leave types' : '').(($extras['phone'] ?? false) ? ', phone clock-in' : '').'. Buttons for the features you start using, such as a roster, then appear up here too.'],
            ['target' => 'att-upload', 'title' => 'No live connection?', 'body' => 'If the terminal is not sending punches over the internet, export its Attendance Record report to a USB drive and upload the .xls file here.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function attendanceHead(array $c): array
    {
        return ['key' => 'attendance.head', 'title' => 'Department attendance', 'steps' => [
            ['title' => 'Your department\'s attendance', 'body' => 'You see the people in the department'.(count($c['departments'] ?? []) > 1 ? 's' : '').' you head: who is in, who was late or absent, and what they ask for. A quick look round.'],
            ($c['board'] ?? false) ? ['target' => 'att-tab-today', 'open' => 'att-tab-today', 'title' => 'Today', 'body' => 'Who is in now and who is not in yet. If a shift is short of people, it shows at the top of the page.'] : null,
            ['target' => 'att-period', 'open' => 'att-tab-report', 'title' => 'Pick the days', 'body' => 'Choose any days to look back on. Everything below follows them.'],
            ['target' => 'att-department', 'title' => 'One department at a time', 'body' => 'Head more than one department? Pick one here, or see them all together.'],
            ['target' => 'att-people', 'title' => 'Your people', 'body' => 'Click a name to see their days and punches. Use Correct on a day when someone forgot to punch, and give a reason.'],
            ['target' => 'att-tab-requests', 'title' => 'Requests', 'body' => 'Leave and overtime your people ask for comes here for your answer, with a count on the tab.'],
            ['target' => 'att-roster', 'title' => 'Roster', 'body' => 'Plan who works which shift, and swap shifts between people when someone asks.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    // Optical Suite: one tour per main page, so each role meets the pages it opens (the menu shows a person
    // only what their role allows, and steps for links they cannot see are skipped).
    private static function opticalDashboard(array $c): array
    {
        return ['key' => 'optical.dashboard', 'title' => 'Optical Suite', 'steps' => [
            ['title' => 'Welcome to the Optical Suite', 'body' => 'Spectacle orders from the first quote to collection, the lab, stock and the money, all in one place. Here is a quick look round. It takes about a minute.'],
            ['target' => 'setup-checklist', 'title' => 'Getting started', 'body' => 'Work down this list to get the shop ready. Each step ticks itself once it is done, and the list goes away when all five are.'],
            ['target' => 'nav-desk', 'title' => 'Front desk', 'body' => 'Orders and quotations, the counter (Retail POS), sales records, prescriptions and partner clinics.'],
            ['target' => 'nav-lab', 'title' => 'Lab', 'body' => 'The workbench lists what to glaze, fit or repair, most urgent first. Job Tracking shows where every job is.'],
            ['target' => 'nav-stock', 'title' => 'Stock', 'body' => 'Frames, lenses and prices; View Grid shows lens stock by power; receiving, purchasing and stock counts.'],
            ['target' => 'nav-money', 'title' => 'Money', 'body' => 'Reports, profit and loss, and expenses.'],
            ['target' => 'nav-admin', 'title' => 'Admin', 'body' => 'Order rules, staff and their roles, and the audit trail. Each person sees only the parts of the menu their role opens.'],
            ['target' => 'opt-attention', 'title' => 'What needs doing today', 'body' => 'Jobs due or late, glasses not collected, lenses still to order. Click one to go straight to it.'],
            ['target' => 'opt-new-order', 'title' => 'Start here', 'body' => 'New Order takes a prescription job or a quotation step by step. Walk-in POS Sale is for selling something off the shelf.'],
            ['target' => 'opt-stats', 'title' => 'Today at a glance', 'body' => 'Orders taken today, glasses ready for collection, and money still owed by customers.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Every main page has its own short tour: look for this button. Take this one again at any time from here.'],
        ]];
    }

    private static function opticalOrders(): array
    {
        return ['key' => 'optical.orders', 'title' => 'Orders', 'steps' => [
            ['target' => 'opt-new-order', 'title' => 'A new order or quote', 'body' => 'Starts a spectacle order, a quotation, or a service job such as a repair.'],
            ['target' => 'opt-order-search', 'title' => 'Find an order', 'body' => 'By order number, customer name or phone, frame or service.'],
            ['target' => 'opt-order-filters', 'title' => 'Narrow the list', 'body' => 'By the day it was ordered or is due for pickup, where it came from, and who took it.'],
            ['target' => 'opt-order-status', 'title' => 'Where each order is', 'body' => 'Quotations, in the lab, ready, collected and more. The count on each tells you how many.'],
            ['target' => 'opt-collections', 'title' => 'Waiting to be collected', 'body' => 'Glasses that are ready, with the reminder texts sent to customers.'],
            ['target' => 'opt-order-list', 'title' => 'Open an order', 'body' => 'Click an order to take a payment, move it on (to the lab, ready, collected), print the docket or turn a quotation into an order.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function opticalOrderCreate(): array
    {
        return ['key' => 'optical.order-create', 'title' => 'New order', 'steps' => [
            ['target' => 'opt-steps', 'title' => 'Seven short steps', 'body' => 'Who it is for, prescription and lenses, frame, fitting, services and price, deposit, then a last look before you place it. Go back to any finished step from here.'],
            ['target' => 'opt-source', 'title' => 'Who the job is for', 'body' => 'A patient of this clinic, a partner clinic\'s customer, or someone walking in. Partner work can be billed to the partner.'],
            ['target' => 'opt-work-type', 'title' => 'Glasses or a service', 'body' => 'Prescription glasses go through lenses and frame; a service job (a repair, say) goes straight to its price.'],
            ['title' => 'Lenses from stock or special order', 'body' => 'On the next step, enter the prescription and the app finds lenses in stock that match. If there are none, it is a special order: you enter the price and the owner is told once it is paid.'],
            ['target' => 'opt-quote', 'title' => 'Not ready to order?', 'body' => 'Save it as a quotation at any step. It keeps its prices and turns into an order when the customer pays a deposit.'],
            ['target' => 'opt-next', 'title' => 'Next', 'body' => 'Each step is checked before you move on, so nothing is missed.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function opticalLab(): array
    {
        return ['key' => 'optical.lab', 'title' => 'Lab Workbench', 'steps' => [
            ['title' => 'The lab\'s to-do list', 'body' => 'Every job to glaze, fit, transfer or repair, the most urgent first. Late jobs are at the top.'],
            ['target' => 'opt-lab-stages', 'title' => 'One stage at a time', 'body' => 'All open work, or only the jobs at one stage, with how many are waiting at each.'],
            ['target' => 'opt-lab-search', 'title' => 'Find a job', 'body' => 'By job number, customer, partner, frame or service, or by the kind of work.'],
            ['target' => 'opt-lab-list', 'title' => 'Work a job', 'body' => 'Each job shows its lenses and whether special-order ones have arrived. Mark a lens as arrived, move the job on, or click it for the full docket.'],
            ['target' => 'opt-lab-print', 'title' => 'On paper', 'body' => 'Print the list for the bench.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function opticalCatalogue(array $c): array
    {
        $manager = $c['manager'] ?? false;

        return ['key' => 'optical.catalogue', 'title' => 'Catalogue & Stock', 'steps' => [
            ['target' => 'opt-cat-tabs', 'title' => 'Everything you sell', 'body' => 'Frames, lenses, the price of each lens type, coatings and options, and the price of each service.'],
            $manager ? ['target' => 'opt-cat-add', 'title' => 'Add an item', 'body' => 'Every item is a frame, a lens or an accessory. A frame takes its brand, model, colour and size.'] : null,
            $manager ? ['target' => 'opt-cat-receive', 'title' => 'Stock in', 'body' => 'Record what arrives from suppliers, including a whole lens delivery from the supplier\'s Excel sheet.'] : null,
            ['target' => 'opt-cat-filter', 'title' => 'Running low', 'body' => 'Show only items that are low or out of stock.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Lens stock by power is on View Grid in the menu. Take this tour again at any time from here.'],
        ]];
    }

    private static function opticalPos(): array
    {
        return ['key' => 'optical.pos', 'title' => 'Retail POS', 'steps' => [
            ['title' => 'The counter', 'body' => 'For selling things off the shelf with no prescription: cases, cloths, solutions, ready readers. Glasses made to a prescription go through New Order instead.'],
            ['target' => 'opt-pos-search', 'title' => 'Find or scan', 'body' => 'Type a name or scan the barcode (SKU). Tap Add to put it in the cart.'],
            ['target' => 'opt-pos-cart', 'title' => 'Take the payment', 'body' => 'Check the cart, add the customer\'s name and phone if they want a receipt by text, take the payment and print the receipt.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function attendanceStaff(array $c): array
    {
        return ['key' => 'attendance.staff', 'title' => 'My attendance', 'steps' => [
            ['target' => 'att-my-tab', 'open' => 'att-my-tab', 'title' => 'My attendance', 'body' => 'Your own days from the attendance terminal: when you came in and left, and any lateness or absence.'],
            ['target' => 'att-my-period', 'title' => 'Pick the days', 'body' => 'This month to date at first. Choose any other days to look back on.'],
            ['target' => 'att-my-totals', 'title' => 'At a glance', 'body' => 'Days present, late and absent, and the time you usually arrive.'],
            ['target' => 'att-my-shifts', 'title' => 'Your shifts', 'body' => 'What you work over the next two weeks, including any swaps.'],
            ['target' => 'att-my-clock', 'title' => 'Clock in from your phone', 'body' => 'At work and allowed to use your phone? Tap here to clock in or out. Your phone asks to share your location: allow it, because you must be at the branch.'],
            ['target' => 'att-my-leave', 'title' => 'Ask for time off', 'body' => 'Pick the days and the kind of leave and send it. You see here when it is approved or turned down.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }
}
