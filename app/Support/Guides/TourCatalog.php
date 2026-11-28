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
        'attendance.manager' => 4,
        'attendance.head' => 2,
        'attendance.staff' => 1,
        'optical.dashboard' => 3,
        'optical.orders' => 2,
        'optical.order-create' => 3,
        'optical.special-order' => 1,
        'optical.lab' => 2,
        'optical.catalogue' => 1,
        'optical.pos' => 2,
        'optical.requests' => 8,
        'clinic.dashboard' => 7,
        'clinic.registry' => 2,
        'clinic.clearance' => 2,
        'clinic.pos' => 4,
        'clinic.queue' => 4,
        'clinic.sales' => 4,
        'clinic.day-close' => 2,
        'clinic.cash-book' => 3,
        'clinic.settings' => 1,
        'clinic.insurers' => 1,
        'clinic.claims' => 1,
        'clinic.receivables' => 1,
        'clinic.insurer-payments' => 1,
        'clinic.scans' => 2,
        'clinic.optical-sent' => 6,
        'clinic.spectacles' => 2,
        'clinic.optical-owed' => 1,
        'platform.dashboard' => 2,
        'platform.clinics' => 1,
        'platform.plans' => 1,
        'platform.billing' => 1,
        'platform.pricing' => 1,
        'platform.quotes' => 1,
        'platform.sms' => 1,
        'platform.announcements' => 1,
        'platform.usage' => 1,
        'platform.links' => 1,
        'platform.roles' => 1,
        'platform.imports-other' => 2,
        'platform.analytics' => 1,
        'platform.support' => 1,
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
            'optical.special-order' => self::opticalSpecialOrder($context),
            'optical.lab' => self::opticalLab(),
            'optical.catalogue' => self::opticalCatalogue($context),
            'optical.pos' => self::opticalPos(),
            'optical.requests' => self::opticalRequests(),
            'clinic.dashboard' => self::clinicDashboard($context),
            'clinic.registry' => self::clinicRegistry(),
            'clinic.clearance' => self::clinicClearance(),
            'clinic.pos' => self::clinicPos(),
            'clinic.queue' => self::clinicQueue(),
            'clinic.sales' => self::clinicSales($context),
            'clinic.day-close' => self::clinicDayClose($context),
            'clinic.cash-book' => self::clinicCashBook($context),
            'clinic.settings' => self::clinicSettings(),
            'clinic.insurers' => self::clinicInsurers(),
            'clinic.claims' => self::clinicClaims(),
            'clinic.receivables' => self::clinicReceivables(),
            'clinic.insurer-payments' => self::clinicInsurerPayments(),
            'clinic.scans' => self::clinicScans($context),
            'clinic.optical-sent' => self::clinicOpticalSent(),
            'clinic.spectacles' => self::clinicSpectacles($context),
            'clinic.optical-owed' => self::clinicOpticalOwed(),
            default => str_starts_with($page, 'platform.') && auth()->user()?->is_platform_admin ? self::platform($page) : null,
        };
        if (! $tour) return null;
        $tour['steps'] = array_values(array_filter($tour['steps']));

        return $tour + ['version' => self::version($tour['key']) ?? 1];
    }

    /** Front Desk → Spectacles: glasses to order for patients, made here or sent to a linked optical centre (20 Nov 2026). */
    private static function clinicSpectacles(array $c): array
    {
        $send = (bool) ($c['send'] ?? false);

        return ['key' => 'clinic.spectacles', 'title' => 'Spectacle Orders', 'steps' => [
            ['target' => 'sp-status', 'title' => 'One bar for every list', 'body' => 'To order is every patient the doctor marked for glasses with no order yet. Orders shows the rest by status: Pending, In lab, Ready, Collected and Cancelled, each in its own colour. Click one to see that list.'],
            ['target' => 'sp-alerts', 'title' => 'What needs chasing', 'body' => 'Pickup overdue: glasses ready for more than a week. Renewal due: collected glasses due for a new test within a month. They turn red when there are any.'],
            ['target' => 'sp-filters', 'title' => 'Find a patient', 'body' => 'Search by name, folder number or order number, pick the dates, and type in Doctor or Lab to narrow the list. Sort by when it was created or by pickup date.'],
            ['target' => 'sp-list', 'title' => 'Open a patient', 'body' => 'Click a row to open the patient on the side: the POS payment, the prescription and what to do next. Create the order there, print the job card (you see a preview first), message the patient or cancel the order.'],
            ['title' => 'Paid at POS first', 'body' => 'An order is made, or sent to an optical centre, only once part or all of it is paid at the Point of Sale. Until then Create'.($send ? ' and Send are' : ' is').' greyed out.'],
            $send ? ['target' => 'send-to-optical', 'title' => 'Send to an optical centre', 'body' => 'The paper plane sends the prescription to a linked optical centre instead of ordering here. Follow it under Optical Centres in the menu. A sent patient cannot also be ordered here.'] : null,
            ['target' => 'sp-tick', 'title' => 'Several at once', 'body' => 'Tick rows, or this box for the whole page. On To order, create orders for all of them with one pickup date (only patients whose frame and lens are on the POS sale)'.($send ? ', or send them all to one optical centre' : '').'. On the orders lists, move them to the next status together. Any that cannot be done are listed with the reason.'],
            ['title' => 'Changed your mind?', 'body' => 'Cancel an order with a reason: the stock it took goes back and the patient returns to To order, to order again or send out. Money paid at POS is refunded at the POS, not here.'.($send ? ' A job already started at an optical centre: use Ask to cancel under Optical Centres.' : '')],
            ($c['branches'] ?? false) ? ['title' => 'Glasses made for another branch', 'body' => 'Under Ready, find a patient whose glasses are ready at another branch and press Hand over here. The bill must be paid first, at any branch (Outstanding Balances, All branches). The other branch sees it as collected.'] : null,
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    /** Front Desk → Sent to Optical: spectacle orders sent to linked optical centres. */
    private static function clinicOpticalSent(): array
    {
        return ['key' => 'clinic.optical-sent', 'title' => 'Sent to Optical', 'steps' => [
            ['target' => 'sent-to-optical', 'title' => 'Orders you sent', 'body' => 'Send an order from Spectacles → To order with the paper-plane button, once the patient has paid at the Point of Sale. Here you see each one: waiting, a question from the optical centre to answer, accepted (with the job’s progress in their lab), or turned down.'],
            ['title' => 'Approve the price first', 'body' => 'The optical centre prices each job and sends you a quote: the lens, frame and services with their trade price. Approve it and they start the job; decline it with a reason and the patient goes back to To order on Spectacles. An expired quote can be sent back for a new price.'],
            ['title' => 'Routine jobs without waiting', 'body' => 'On the Optical centres tab, an owner or manager can approve quotes automatically up to an amount: a quote within it starts at once and shows here as approved automatically. The same page shows the trade discount each optical centre gives you and your credit limit; at the limit you can’t send new jobs until you pay on the Owed tab. The owner can also let an optical centre look up your patients (Patient lookup, off until turned on): they find a patient only by full phone or patient number and order from the latest prescription you authorised, on your account, and every lookup is in your audit trail.'],
            ['title' => 'Changed your mind?', 'body' => 'While waiting or with a question, Cancel takes the request back; a quote you decline does the same. Once the optical centre has approved or started the job, Ask to cancel sends them your reason: they cancel it (nothing billed), stop it and charge it as a finished job when work was already done, or keep it and say why. A cancelled one puts the patient back in To order on Spectacles; a charged one stays on what you owe them.'],
            ['title' => 'Remakes', 'body' => 'Once glasses are ready or collected, Ask for a remake sends the job back with your reason. The optical centre decides whether it is free (their error or warranty) or charged; a charged one comes to you as a quote.'],
            ['target' => 'optical-tabs', 'title' => 'Three tabs, one menu link', 'body' => 'Optical Centres in the menu opens these tabs: the jobs you sent, what you owe (owners and managers), and the optical centres you are linked with.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Owners and managers link optical centres on the Optical centres tab and pay them on the Owed tab. Take this tour again at any time from here.'],
        ]];
    }

    /** Front Desk → Owed to Optical: what linked optical centres billed and what the clinic paid. */
    private static function clinicOpticalOwed(): array
    {
        return ['key' => 'clinic.optical-owed', 'title' => 'Owed to Optical Centres', 'steps' => [
            ['title' => 'One card per optical centre', 'body' => 'What they billed for jobs you approved, what you paid, payments they still have to confirm, and what is left to pay, with the open jobs underneath.'],
            ['title' => 'Record a payment', 'body' => 'Record payment books it in your expenses at once (Optical centre jobs), so cash from the till shows in End of day. The oldest jobs are paid first, or say which jobs it covers. The optical centre confirms it when the money arrives.'],
            ['target' => 'owed-payments', 'title' => 'Payments you recorded', 'body' => 'Waiting, confirmed, or not received. If the optical centre says it did not arrive, check and withdraw it: its expense is removed too.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Payments the optical centre recorded itself (cash at their counter) appear under "Paid but not in your expenses yet". Take this tour again at any time from here.'],
        ]];
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
            ['target' => 'att-setup', 'title' => 'Set up', 'body' => 'Things you set once: working hours and grace minutes, holidays, terminals'.(($extras['shifts'] ?? false) ? ', shifts (each with its own code and colour for the roster)' : '').(($extras['leave'] ?? false) ? ', leave types' : '').(($extras['phone'] ?? false) ? ', phone clock-in' : '').(($c['owner'] ?? false) ? ', and clock-in emails (to you as staff clock in or out, or as an evening summary; and to each person, with a reminder to clock out before their shift ends)' : '').'. Buttons for the features you start using, such as a roster, then appear up here too.'],
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
            ['target' => 'att-roster', 'title' => 'Roster', 'body' => 'Plan who works which shift, and swap shifts between people when someone asks. Each shift has its own code and colour; click one in the key to see only the people on it.'],
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
            ['target' => 'opt-attention', 'title' => 'What needs doing today', 'body' => 'Jobs due or late, glasses not collected, lenses still to order. Managers also see stock lenses held a day or more by jobs not started, with a button to release them (the number of days is under Settings). Click one to go straight to it.'],
            ['target' => 'opt-new-order', 'title' => 'Start here', 'body' => 'New Order takes a prescription job or a quotation step by step. Walk-in POS Sale is for selling something off the shelf.'],
            ['target' => 'opt-stats', 'title' => 'Today at a glance', 'body' => 'Orders taken today, glasses ready for collection, and (for the front desk and managers) money still owed by customers. Lab staff see the jobs without prices or customer phone numbers.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Every main page has its own short tour: look for this button. Take this one again at any time from here.'],
        ]];
    }

    /** Optical → Incoming Orders: spectacle orders sent online by linked clinics. */
    private static function opticalRequests(): array
    {
        return ['key' => 'optical.requests', 'title' => 'Incoming Orders', 'steps' => [
            ['target' => 'incoming-orders', 'title' => 'Orders from linked clinics', 'body' => 'Clinics you linked under Partner Clinics send their spectacle orders here, with the wearer’s name, phone and prescription. Open one to accept it: the order form opens with the prescription filled in, and you price the job as a partner order. Or ask the clinic a question, or turn it down with a reason they will see.'],
            ['title' => 'Quotes, then the job', 'body' => 'Pricing a job sends the clinic a quote; it waits under Quotes. Once they approve it, open it and press Start the job: it goes on their account at the approved price, with no deposit. A free job, such as a free remake, starts straight away.'],
            ['title' => 'Several approved quotes', 'body' => 'Under Quotes, tick the approved ones to start all their jobs at once, at the approved prices on each clinic\x27s account.'],
            ['title' => 'Agreed terms', 'body' => 'Under Partner Clinics, open a linked clinic to set its trade % off your prices for lenses, frames and services, and a credit limit. The trade % comes off every quote for them on its own line. At the credit limit they can’t send new jobs until they pay. If the clinic approves quotes automatically up to an amount, a quote within it starts the job at once.'],
            ['title' => 'Clinics not on Nexus', 'body' => 'Under Partner Clinics, open a partner that is not on Nexus and turn on its ordering page: you get a private link and set a 6-digit PIN to send them separately. They send orders with the prescription and follow each job there, without an account. Their orders arrive here marked "ordering page"; you price them as ordinary partner jobs, with no quote to approve. On the page they can press Check lenses to see which of your stock lenses fit, "In stock" or "Order in" per eye (never the counts), with prices after their trade % unless you switch prices off for that partner. A lens they pick is held for them until you accept the order, and the order form selects it when you check availability. If they join Nexus later and link, their orders move to their account.'],
            ['title' => 'Remakes and payments', 'body' => 'A clinic can ask for a remake of glasses you made; accept it to open the remake form and choose free or charged. When a clinic records a payment to you, confirm it on their card under Partner Clinics.'],
            ['title' => 'Cancel asked', 'body' => 'A clinic can ask you to cancel a job you approved or started, with a reason. It waits under Cancel asked: open it and cancel the job (stock goes back, nothing is billed), Stop & charge when work was already done (the job counts as finished and its full price stays on their account, no warranty), or keep it and say why.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'The clinic follows each job as you move it through the lab. Take this tour again at any time from here.'],
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
            ['target' => 'opt-order-list', 'title' => 'Open an order', 'body' => 'Click an order to take a payment, move it on (to the lab, ready, collected), print the docket or turn a quotation into an order. Tick several to print their dockets together or send them to one lab.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function opticalOrderCreate(): array
    {
        return ['key' => 'optical.order-create', 'title' => 'New order', 'steps' => [
            ['target' => 'opt-steps', 'title' => 'Seven short steps', 'body' => 'Who it is for, prescription and lenses, frame, fitting, services and price, deposit, then a last look before you place it. Go back to any finished step from here.'],
            ['target' => 'opt-source', 'title' => 'Who the job is for', 'body' => 'A patient of this clinic, a partner clinic\'s customer, or someone walking in. Partner work can be billed to the partner.'],
            ['target' => 'opt-work-type', 'title' => 'New glasses or a service only', 'body' => 'New glasses go through prescription, lenses and frame. Service only (a lens transfer, a repair, an adjustment) skips what it does not need: a lens transfer asks for the new frame, and the prescription is optional. A Manager or the Owner can switch on a service and set its price right here.'],
            ['title' => 'A linked clinic\'s patient without a paper Rx', 'body' => 'When a linked clinic\'s owner allows it, choose Partner and find their patient by full phone or patient number. Use this Rx starts the job from the latest prescription they authorised: it goes on their account and they see it under Sent to Optical. Each lookup is recorded in their audit trail.'],
            ['title' => 'Lenses from stock or special order', 'body' => 'On the next step, enter the prescription and the app finds lenses in stock that match. If there are none, it is a special order: you enter the price and the owner is told once it is paid.'],
            ['target' => 'opt-quote', 'title' => 'Not ready to order?', 'body' => 'Save it as a quotation once the prescription is in (for a service job, once the services are picked). It keeps its prices and turns into an order when the customer pays a deposit.'],
            ['target' => 'opt-next', 'title' => 'Next', 'body' => 'Each step is checked before you move on, so nothing is missed.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    /**
     * New order, the moment a lens has to be bought in: from pricing it to glazing. Starts by itself the first
     * time a special order appears on the lens step (one eye or both); the "Special orders" button repeats it.
     */
    private static function opticalSpecialOrder(array $c): array
    {
        $buyer = $c['buyer'] ?? false;

        return ['key' => 'optical.special-order', 'title' => 'Special orders', 'steps' => [
            ['title' => 'This lens has to be bought in', 'body' => 'There is no lens in stock for this prescription, so it is a special order: the shop buys it from a supplier for this customer. Here is what happens from now until the glasses are made. It takes half a minute.'],
            ['target' => 'opt-so-price', 'title' => 'Price it', 'body' => 'Enter what the customer pays for the lens. The catalogue price is shown when there is one. A price you type for a power with no catalogue price is emailed to the owner, to check it covers what the supplier charges.'],
            ['target' => 'opt-so-both', 'title' => 'One from stock, one bought in?', 'body' => 'For a bifocal or progressive pair, labs usually advise making both eyes together so they match. Special-order both eyes does that, and the stock lens stays on the shelf.'],
            ['target' => 'opt-so-wait', 'title' => 'The job waits for the lens', 'body' => 'You can place the order and take the deposit now, but glazing cannot start until the lens arrives. When the supplier\'s delivery time is known it shows here: allow for it in the pickup date.'],
            ['title' => 'After you place the order', 'body' => 'Once it is paid (or billed to a partner on account), the owner is told the lens needs buying. A quotation orders nothing. Until it is on a supplier order, the job shows in Needs attention and in Purchasing under Special-order lenses'.($buyer ? ': tick it there, choose the supplier and create the supplier order.' : ', where a manager orders it from the supplier.')],
            ['title' => 'When the lens arrives', 'body' => 'Receiving the supplier order marks the lens as arrived on the job. A lens that came another way can be marked arrived on the order or on the Lab Workbench. The job then goes to glazing as usual.'],
            ['target' => 'opt-so-replay', 'title' => 'That is it', 'body' => 'See this again at any time from here.'],
        ]];
    }

    private static function opticalLab(): array
    {
        return ['key' => 'optical.lab', 'title' => 'Lab Workbench', 'steps' => [
            ['title' => 'The lab\'s to-do list', 'body' => 'Every job to glaze, fit, transfer or repair, the most urgent first. Late jobs are at the top.'],
            ['target' => 'opt-lab-stages', 'title' => 'One stage at a time', 'body' => 'All open work, or only the jobs at one stage, with how many are waiting at each.'],
            ['target' => 'opt-lab-search', 'title' => 'Find a job', 'body' => 'By job number, customer, partner, frame or service, or by the kind of work.'],
            ['target' => 'opt-lab-list', 'title' => 'Work a job', 'body' => 'Each job shows its lenses and whether special-order ones have arrived. Mark a lens as arrived, move the job on, or click it for the full docket.'],
            ['target' => 'opt-lab-select', 'title' => 'Several at once', 'body' => 'Tick jobs (or this box for the whole page) to start edging, send them to one lab, pass QC or print their dockets together. A job that cannot move is skipped with the reason and stays ticked. After sending to a lab you can WhatsApp the lab the whole list.'],
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
            ['target' => 'opt-pos-cart', 'title' => 'Take the payment', 'body' => 'Check the cart, add the customer\'s name and phone if they want a receipt by text, take the payment and print the receipt. A discount up to your limit (shown under the field) goes through; a bigger one needs a Manager or the Owner.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    // Clinic Suite: one tour per main page. The owner and managers start on the Admin Dashboard (with the
    // getting-started checklist); reception, cashiers and doctors meet the pages their menu opens.
    private static function clinicDashboard(array $c): array
    {
        $owner = $c['owner'] ?? false;

        return ['key' => 'clinic.dashboard', 'title' => 'Clinic Suite', 'steps' => [
            ['title' => 'Welcome to the Clinic Suite', 'body' => 'Patients from registration to the doctor, the pharmacy counter, and the money: who paid, how, and what is left in the till. Here is a quick look round. It takes about a minute.'],
            ['target' => 'setup-checklist', 'title' => 'Getting started', 'body' => 'Work down this list to get the clinic ready. Each step ticks itself once it is done, and the list goes away when its main steps are.'],
            ['target' => 'nav-workspace', 'title' => 'Two workspaces', 'body' => 'Clinical holds the front desk, the doctors and the stock. Administration holds the money, staff and settings. Switch between them here.'],
            ['target' => 'clinic-kpis', 'title' => 'Today at a glance', 'body' => 'What happened today: sales, consultations and prescriptions waiting, and more figures below on plans that include them. Click a figure to open what is behind it.'],
            ['target' => 'nav-finances', 'title' => 'Finances', 'body' => 'The money pages your plan includes: sales records and the daily cash summary, and on bigger plans sales reports, expenses, the cash book and day close, outstanding balances, the income statement and each patient\'s ledger.'
                .(\App\Services\LicenseService::has(\App\Support\Feature::APPROVALS) ? ' Approvals is here too: discounts, refunds and voids, payment corrections and clearance revokes that staff ask for, with a count. Nobody approves their own request.'.($owner ? ' Voids and payment corrections are yours alone to approve, and you set each role\'s discount limit under Settings.' : '') : '')],
            ($c['branches'] ?? false) ? ['target' => 'dash-all-branches', 'title' => 'All your branches', 'body' => 'Every branch side by side for the period you pick: what each billed, the cash it took, expenses, visits, what patients owe and glasses waiting, with the totals. Open switches to a branch. Each patient has one chart: doctors at any branch see visits from the others, marked Seen at.'] : null,
            ['target' => 'nav-staff-security', 'title' => 'Staff & Security', 'body' => 'Users and their roles, login history and the audit trail. Each person sees only the parts of the menu their role opens. The shield button on a user opens their Access: switch single things on or off for that person.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Every main page has its own short tour: look for this button. Take this one again at any time from here.'],
        ]];
    }

    private static function clinicRegistry(): array
    {
        return ['key' => 'clinic.registry', 'title' => 'Registry Hub', 'steps' => [
            ['title' => 'Where every visit starts', 'body' => 'Register new patients, update their details, and send them on to clearance and the doctor.'],
            ['target' => 'reg-form', 'title' => 'Register a patient', 'body' => 'Start typing the name: if the patient is already registered they show up, so you never make the same person twice. Choose cash or insurance, then save. Each new patient gets a short number such as VS-2611-042: the clinic\'s initials, the year and month, then that month\'s count.'],
            ['target' => 'reg-import', 'title' => 'Bring in an old list', 'body' => 'Moving from paper or another system? Import patients from a CSV file.'],
            ['target' => 'reg-search', 'title' => 'Find a patient', 'body' => 'By name or patient number (new ones like VS-2611-042, or an older PX-... number), or show only one insurer\'s patients.'],
            ['target' => 'reg-list', 'title' => 'Open a record', 'body' => 'Edit a patient\'s details, or call or WhatsApp them, from their row. Tick several to act on them together.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicClearance(): array
    {
        return ['key' => 'clinic.clearance', 'title' => 'Patient Clearance', 'steps' => [
            ['title' => 'Payment before the doctor', 'body' => 'Patients registered today wait here until their consultation is paid or billed to their insurer. Once cleared, they join the doctor\'s queue.'],
            ['target' => 'clr-stats', 'title' => 'The day so far', 'body' => 'How many are waiting, cleared, paid and unpaid. Click a count to see those patients.'],
            ['target' => 'clr-lists', 'open' => 'clr-tab-pending', 'title' => 'Clear a patient', 'body' => 'Find the patient and press the button on their row: take the payment (cash, MoMo, card...), print or text the receipt, and they are sent to the doctor.'],
            \App\Services\LicenseService::has(\App\Support\Feature::VITALS)
                ? ['target' => 'clr-lists', 'open' => 'clr-tab-pending', 'title' => 'Vitals', 'body' => 'Press Vitals on a patient\'s row to record their blood pressure, blood sugar and eye pressure (IOP). The doctor sees them at the top of the consultation, and the IOP carries into the eye exam.']
                : null,
            ['target' => 'clr-lists', 'open' => 'clr-tab-cleared', 'title' => 'Already cleared', 'body' => 'Everyone cleared, with filters. A clearance made by mistake can be revoked with a reason; a manager approves it.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicPos(): array
    {
        return ['key' => 'clinic.pos', 'title' => 'Point of Sale', 'steps' => [
            ['title' => 'The cashier\'s desk', 'body' => 'Three columns: who is waiting to pay, their bill, and the payment.'],
            ['target' => 'pos-queue', 'title' => 'Waiting to pay', 'body' => 'Patients the doctors have sent, oldest first. Tap one to load their bill. Walk-in sale starts a bill for someone who did not see a doctor; Find a patient looks anyone up.'],
            ['target' => 'pos-cart', 'title' => 'The bill', 'body' => 'The doctor\'s lines are marked Rx and locked, with the dosing in grey. Lines you add are marked Added and can be removed.'],
            ['target' => 'pos-add-item', 'title' => 'Add items', 'body' => 'Add item opens the product search: tap an item to put it on the bill. Drugs and lenses for a patient come from the doctor; the desk adds services, frames and other items. Add frame finds the frame for prescribed glasses.'],
            ['target' => 'pos-payment', 'title' => 'Take payment', 'body' => 'Pick cash, MoMo or card, enter the amount and press Add; split it if needed. Total, paid and what is still needed stay at the bottom with Complete sale. A discount within your limit applies when you press Apply; a bigger one goes to a Manager or the Owner. Part payment shows when the items allow it, with the least to pay now.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicQueue(): array
    {
        return ['key' => 'clinic.queue', 'title' => 'Patient queue', 'steps' => [
            ['title' => 'Your patients for today', 'body' => 'Patients who have been cleared at the front desk and are waiting to be seen. The list refreshes by itself every 30 seconds.'],
            ['target' => 'queue-filters', 'title' => 'Waiting or seen', 'body' => 'Find a patient by name or folder, and switch between those still waiting and those you have seen.'],
            ['target' => 'queue-list', 'title' => 'See a patient', 'body' => 'Open a patient to record the consultation, refraction and prescription. Medicines you prescribe wait for the patient at the pharmacy counter. A drug you do not stock, or that is out of stock, can be prescribed to buy at a pharmacy: it prints on the prescription slip and is not billed here.'],
            ['title' => 'Drops or tablets', 'body' => 'Each drug line follows the form set on the product. Drops and ointments ask which eye; tablets and capsules ask the dose and when to take them with meals, and the strips to dispense are worked out from the dose, frequency and days. A drug with no form shows Form?: choose it once and the product keeps it.'],
            ['title' => 'Notes and tapering', 'body' => 'Each drug on a prescription has a note line for the patient, with quick notes such as "Shake well". Tick Taper for a drug that must be reduced step by step and write the steps in the note: it shows as TAPER at the pharmacy counter, on the prescription slip and in the patient’s history.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicSales(array $c = []): array
    {
        return ['key' => 'clinic.sales', 'title' => 'Sales Records', 'steps' => [
            ['title' => 'Every sale', 'body' => 'All sales for the days you pick, with the amount after any discount and who made it.'],
            ['target' => 'sales-totals', 'title' => 'The totals', 'body' => 'What was sold, and how much was given in discounts.'],
            ['target' => 'sales-filters', 'title' => 'Find a sale', 'body' => 'Search by name or transaction number, pick the days, show only refunded or paid sales, or download them as CSV.'],
            ($c['scans'] ?? false) ? ['target' => 'sales-scan-filter', 'title' => 'Diagnostic scans', 'body' => 'Show only scan sales from the Scan Desk, or leave them out. The totals and the CSV follow it. Scan sales carry a Scan tag with their order number.'] : null,
            ['target' => 'sales-list', 'title' => 'Look closer or put it right', 'body' => 'The eye shows the items, discount and receipt. The red arrow refunds a sale, or voids one entered by mistake today; on plans with approvals a manager decides first.'],
            ($c['previous'] ?? false) ? ['target' => 'sales-previous', 'title' => 'Before you moved here', 'body' => 'What patients paid in your previous software, for reference. Those payments are not sales here and stay out of these totals and your reports.'] : null,
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicDayClose(array $c): array
    {
        $owner = $c['owner'] ?? false;

        return ['key' => 'clinic.day-close', 'title' => 'Day close', 'steps' => [
            ['title' => 'Balance the till', 'body' => 'At the end of the day, count what is in the drawer, put the day\'s mistakes right and close the day. Once closed, the day\'s figures are settled.'],
            ['target' => 'day-close-count', 'title' => 'Count', 'body' => 'For each method you see what should be there: the start of the day, plus money in, less refunds, expenses and banking. Enter what you count. The cash must be counted; MoMo and card are optional.'],
            ['target' => 'day-close-cashiers', 'title' => 'Who took what', 'body' => 'Each cashier\'s takings by method, so a difference can be traced.'],
            ['target' => 'day-close-payments', 'title' => 'Wrong method?', 'body' => 'A payment entered as cash that was really MoMo? Ask for a correction here; the owner approves it. To cancel a sale entered by mistake, request a void from Sales Records.'],
            ['target' => 'day-close-blockers', 'title' => 'Waiting for a decision', 'body' => 'A void or correction still waiting holds the day open until the owner decides.'],
            ['target' => 'day-close-submit', 'title' => 'Close', 'body' => 'The tiles at the top show what the drawer should hold, what you counted and the difference. Here, enter what you bank or hand over (the suggestion leaves the float in the drawer), add a note for any difference, and close the day.'.($owner ? ' Only you can reopen a closed day.' : ' Only the owner can reopen it.')],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicInsurers(): array
    {
        return ['key' => 'clinic.insurers', 'title' => 'Insurers', 'steps' => [
            ['title' => 'The schemes you bill', 'body' => 'Add each insurer once. A patient linked to one has the insurer\'s share of every bill set aside: the patient pays only their part, and a claim is started for the rest.'],
            ['target' => 'ins-add', 'title' => 'Add an insurer', 'body' => 'Its name, scheme (NHIS, private or corporate) and contact. Choose what happens when it pays less than claimed: bill the patient the difference, or write it off. And whether a patient may pay the difference between your price and the insurer\'s tariff.'],
            ['target' => 'ins-coverage', 'title' => 'What it covers', 'body' => 'Set the share it pays for each category (consultations, drugs, frames...), and change it for single products. Anything without a rule is the patient\'s to pay.'],
            ['target' => 'ins-filters', 'title' => 'Find an insurer', 'body' => 'By name or code, or show one scheme.'],
            ['target' => 'ins-list', 'title' => 'Your insurers', 'body' => 'Edit one, see how many claims it has, or make it inactive when you stop working with it; its history stays.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicClaims(): array
    {
        return ['key' => 'clinic.claims', 'title' => 'Insurance claims', 'steps' => [
            ['title' => 'From draft to paid', 'body' => 'A bill with an insurer share starts a draft claim by itself. Here you send it, record the insurer\'s answer, and see it paid.'],
            ['target' => 'clm-stats', 'title' => 'Where things stand', 'body' => 'Claims still to send, those awaiting the insurer, what has been approved and what is still owed. Pre-auth pending shows patients waiting for the insurer\'s go-ahead.'],
            ['target' => 'clm-tabs', 'title' => 'By stage', 'body' => 'Draft, submitted, approved, part approved, rejected and paid.'],
            ['target' => 'clm-filters', 'title' => 'Find and export', 'body' => 'Search by patient or patient number, pick an insurer, export the list for the insurer, or add a claim by hand.'],
            ['target' => 'clm-list', 'title' => 'Move a claim on', 'body' => 'Submit it, then approve, part approve or reject it as the insurer answers. A shortfall is billed to the patient or written off, as set for the insurer. A claim is marked paid when you record the insurer\'s payment.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicReceivables(): array
    {
        return ['key' => 'clinic.receivables', 'title' => 'Receivables', 'steps' => [
            ['title' => 'What insurers owe you', 'body' => 'Every bill whose insurer share has not been paid yet.'],
            ['target' => 'rcv-aging', 'title' => 'Owed by insurer', 'body' => 'Each insurer\'s total, split by how long it has been waiting. Chase the ones over 60 and 90 days first.'],
            ['target' => 'rcv-bills', 'title' => 'Bill by bill', 'body' => 'The bills still awaiting payment, and those the insurer rejected, so the patient can be asked to pay. Search by patient, ID or bill number.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicInsurerPayments(): array
    {
        return ['key' => 'clinic.insurer-payments', 'title' => 'Insurer payments', 'steps' => [
            ['title' => 'Money from insurers', 'body' => 'Insurers usually pay several claims at once, weeks later. Record each payment here so the money and the claims agree.'],
            ['target' => 'pay-record', 'title' => 'Record a payment', 'body' => 'Pick the insurer, the amount, date and reference, then share it across the claims it pays. Each claim it covers is marked paid; a claim paid short follows the insurer\'s shortfall setting.'],
            ['target' => 'pay-list', 'title' => 'Payments received', 'body' => 'Every payment and how many claims it paid. Open one to see them.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicSettings(): array
    {
        return ['key' => 'clinic.settings', 'title' => 'Clinic settings', 'steps' => [
            ['title' => 'Your clinic on paper', 'body' => 'The name, address, contact details and logo here go on receipts, prescriptions, letters and reports.'],
            ['target' => 'settings-details', 'title' => 'Clinic information', 'body' => 'Change any detail and press Save. A field left blank is simply left off documents.'],
            ['target' => 'settings-patient-prefix', 'title' => 'Patient number initials', 'body' => 'New patients are numbered with these 2 or 3 letters, the year and month, then that month\'s count, e.g. VS-2611-042. Set them before registering your first patient: once a number is given out they can\'t change. Patients registered before keep their numbers.'],
            ['target' => 'settings-logo', 'title' => 'Your logo', 'body' => 'A PNG, JPG or WebP up to 1 MB, between 100 and 2000 pixels a side. A PNG with a transparent background, about 600 pixels wide, prints best.'],
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    /** Front Desk → Diagnostic Scans. Managers also hear about prices, partners' payout details and commissions. */
    private static function clinicScans(array $c): array
    {
        $manager = (bool) ($c['manager'] ?? false);

        return ['key' => 'clinic.scans', 'title' => 'Diagnostic Scans', 'steps' => [
            ['title' => 'Diagnostic scans', 'body' => 'OCT, visual fields, fundus photos and your other scans: register the patient, take the payment, and follow each scan to its report. Patients sent by partner clinics or your own doctors are tracked so the referrer earns their commission. Scans a doctor orders in the consultation arrive here to be paid.'],
            ['target' => 'scan-desk-tiles', 'title' => 'What needs doing', 'body' => 'Orders still to bill, scans still to do and reports still to write.'],
            ['target' => 'scan-new-order', 'title' => 'A new scan order', 'body' => 'Find the patient or register them, say who referred them, ask whether they agree to share their results with that clinic, and add the scans. Then take the payment: in full, or part now and the rest on Outstanding Balances.'],
            ['target' => 'scan-stages', 'title' => 'Work lists', 'body' => 'To bill, to scan and to report. Open an order to take its payment, add the result files, mark it scanned and reported, and send the results to the referring clinic when the patient agreed.'],
            ['target' => 'scan-tab-catalogue', 'title' => 'Scan catalogue', 'body' => $manager
                ? 'The scans you offer, their price (per eye or per scan) and the commission a referrer earns on each.'
                : 'The scans you offer and their price. A manager sets them.'],
            ['target' => 'scan-tab-partners', 'title' => 'Partner clinics', 'body' => $manager
                ? 'Clinics that send you patients: their code for referral slips, their doctors, how results reach them, and any commission agreed with one clinic.'
                : 'Clinics that send you patients, with their code and doctors. A manager adds them.'],
            $manager ? ['target' => 'scan-tab-commissions', 'title' => 'Commissions', 'body' => 'What each partner clinic and doctor is owed once their patients\' bills are paid in full. Pay partners from here; each payout is recorded as an expense, so the cash book balances. Doctors followed in Attendance get theirs in the monthly payroll: settle the month here after paying salaries.'] : null,
            $manager ? ['target' => 'scan-tab-reports', 'title' => 'Reports', 'body' => 'Referrals per partner clinic for any dates: orders, patients, scans, money and commission. Also by scan and by doctor, how fast results go out, and partners who have gone quiet. Download it for Excel.'] : null,
            ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from here.'],
        ]];
    }

    private static function clinicCashBook(array $c): array
    {
        return ['key' => 'clinic.cash-book', 'title' => 'Cash book', 'steps' => [
            ['title' => 'Where the money is', 'body' => 'A running balance for the cash drawer, the MoMo wallet, card settlements and every other method, built from payments, refunds, expenses and what you record by hand.'],
            ['target' => 'cash-book-start', 'title' => 'Start the book', 'body' => 'Count the drawer once and enter it. Balances are worked out from that day on.'],
            ['target' => 'cash-book-balances', 'title' => 'One book per method', 'body' => 'What each method holds now. Click one to see its entries.'],
            ['target' => 'cash-book-days', 'title' => 'One row per day', 'body' => 'Over a long period the book shows each day\'s money in, money out and closing balance. Open a day to see its entries; a short period lists every entry.'],
            ($c['branches'] ?? false) ? ['target' => 'cash-book-branch', 'title' => 'Other branches', 'body' => 'Pick All branches to see every branch\x27s book side by side (brought forward, in, out, carried forward), or one branch to look at its entries. Changes are made at the branch itself. Sales Reports has the same Branch filter.'] : null,
            ['target' => 'cash-book-record', 'title' => 'Money moved by hand', 'body' => 'Record cash banked, handed to the owner or added to the drawer. A mistake is cancelled with a reason and stays on the book, crossed out.'],
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

    /**
     * The platform console, one tour per page (and per tab of the main dashboard). Platform administrators
     * only; their progress is kept outside any clinic.
     */
    private static function platform(string $page): ?array
    {
        $end = ['target' => 'tour-replay', 'title' => 'That is it', 'body' => 'Take this tour again at any time from the Tour button.'];
        $steps = match ($page) {
            'platform.dashboard' => ['Platform Administration', [
                ['title' => 'Welcome to the platform console', 'body' => 'This is where you run the business behind the software: the clinics on it, their plans and invoices, and the people who might sign up next. Here is a quick look round.'],
                ['target' => 'pf-metrics', 'title' => 'The numbers at a glance', 'body' => 'How many clinics there are, how many pay, how many are on a trial and how many are behind with payment.'],
                ['target' => 'pf-find', 'title' => 'Find a clinic', 'body' => 'Search by clinic name, domain or administrator, or narrow the list by product, plan, status, branch usage or where it runs.'],
                ['target' => 'pf-directory', 'title' => 'Every clinic', 'body' => 'Open a clinic to edit its details, change or renew its plan, record a payment, or suspend it.'],
                ['target' => 'pf-onboard', 'title' => 'Add a clinic', 'body' => 'Sets up a new business in three steps: the business, its first branch, and its Super Admin and a plan, which starts (and is first invoiced) the same day. A clinic can start with common diagnoses and item categories already filled in.'],
                ['target' => 'pf-nav', 'title' => 'Everything else', 'body' => 'Plans, invoices, the pricing page, quotes, SMS, announcements and usage each have their own page here, and each has its own tour.'],
                ['target' => 'nav-quotes', 'title' => 'Quotes for new businesses', 'body' => 'Send a quotation or pro-forma invoice to a business before it is on the platform, then set it up as a clinic when it accepts.'],
                ['target' => 'pf-modes', 'title' => 'Clinic mode', 'body' => 'Switch to Clinic mode to use the software as a clinic does, or sign out.'],
            ]],
            'platform.clinics' => ['Clinics Directory', [
                ['target' => 'pf-find', 'title' => 'Find a clinic', 'body' => 'Type part of a name, domain or administrator, or use the filters. Each filter shows as a chip you can remove.'],
                ['target' => 'pf-directory', 'title' => 'Open a clinic', 'body' => 'Press a clinic to see its overview, plan and renewal, billing, people and branches. Suspending or erasing a clinic is under Danger zone, with a reason kept in the audit log.'],
                ['target' => 'pf-onboard', 'title' => 'Add a clinic', 'body' => 'Onboard a new business here, or from Quotes once a business accepts your quote.'],
            ]],
            'platform.plans' => ['Subscription Plans', [
                ['target' => 'pf-plans', 'title' => 'Your plans', 'body' => 'Each plan has its prices, the number of branches, users and storage it allows, and what it includes. Open one to edit it or to see the clinics on it.'],
                ['target' => 'pf-plan-products', 'title' => 'By product', 'body' => 'Show the plans for Clinic, Optical, Clinic + Optical or Attendance only.'],
                ['target' => 'pf-new-plan', 'title' => 'A new plan', 'body' => 'Add a plan. Changing a plan that clinics are on makes a new version, so the clinics already on it keep what they agreed to.'],
            ]],
            'platform.billing' => ['Invoices & Billing', [
                ['target' => 'pf-billing-sections', 'title' => 'Five parts', 'body' => 'Invoices, Collections for overdue accounts, Approvals for credits, refunds and voids, Operations for the billing runs, and Offboarding for clinics that leave.'],
                ['target' => 'pf-billing-summary', 'title' => 'Money at a glance', 'body' => 'What is owed, what is overdue and what has been collected this month.'],
                ['target' => 'pf-invoice-filters', 'title' => 'Find an invoice', 'body' => 'Search by invoice number or clinic, and filter by status, type or dates.'],
                ['target' => 'pf-invoice-actions', 'title' => 'Reports and new invoices', 'body' => 'Download the billing report as CSV or PDF, or raise an invoice by hand. Open an invoice to record a payment or download it.'],
            ]],
            'platform.pricing' => ['Pricing Page', [
                ['title' => 'The public pricing page', 'body' => 'Anyone can compare your plans and ask for one at /pricing. This page is where their requests land and where you choose what the page shows.'],
                ['target' => 'pr-tabs', 'title' => 'Three tabs', 'body' => 'Requests from the page, which clinic plans the page shows, and the attendance prices by number of staff.'],
                ['target' => 'pr-status', 'title' => 'What to follow up', 'body' => 'New and contacted requests are shown first. Switch to see the ones that became clinics or are not going ahead.'],
                ['target' => 'pr-list', 'title' => 'A request', 'body' => 'Press Details to see what they chose, add notes, mark how it went, or press "Make a quote" to send them a quotation or pro-forma invoice.'],
            ]],
            'platform.quotes' => ['Quotes', [
                ['title' => 'Quotes for businesses not on the platform yet', 'body' => 'Write a quotation or a pro-forma invoice for Clinic, Optical, Attendance or a mix, and send it by email or SMS. A quote is not an invoice: billing starts once the business is set up.'],
                ['target' => 'pq-new', 'title' => 'Start a quote', 'body' => 'Start from scratch here, or from a request on the Pricing Page with "Make a quote", which fills in the lines for you.'],
                ['target' => 'pq-status', 'title' => 'Open quotes first', 'body' => 'Drafts and sent quotes are shown first. A quote past its date shows as expired.'],
                ['target' => 'pq-list', 'title' => 'Send, download, set up', 'body' => 'Open a quote to change it or send it by email (with the PDF attached) or SMS (with a link). When they accept, press "Set up as clinic": onboarding opens filled in, and the quote is marked accepted.'],
            ]],
            'platform.sms' => ['SMS Messaging', [
                ['target' => 'sms-gateway', 'title' => 'The platform SMS account', 'body' => 'Hosted clinics send through this one account. Keep its balance above the credits clinics have already paid for.'],
                ['target' => 'sms-sections', 'title' => 'Credits, bundles and sender IDs', 'body' => 'Record payment for credits clinics have bought, adjust a clinic\'s credits, set the bundles and prices, and approve sender IDs.'],
            ]],
            'platform.announcements' => ['Announcements', [
                ['target' => 'an-list', 'title' => 'What you have sent', 'body' => 'Each announcement emails clinic owners and Super Admins, and can show a banner in the app until they dismiss it.'],
                ['target' => 'an-new', 'title' => 'A new announcement', 'body' => 'Write the message, choose how it goes out and which clinics get it, and preview the email before sending.'],
            ]],
            'platform.links' => ['Optical Links', [
                ['target' => 'ol-totals', 'title' => 'Links at a glance', 'body' => 'Clinics linked to optical centres, links paused, what clinics owe on jobs started, links at their credit limit, and payments an optical centre says it never received.'],
                ['target' => 'ol-filter', 'title' => 'Find a link', 'body' => 'Show linked, paused, ended or every link, or only those that need attention: a disputed payment, a credit limit reached, or a pause you made.'],
                ['target' => 'ol-list', 'title' => 'Every link', 'body' => 'Each row shows the agreed terms (trade %, credit limit, auto-approval), jobs, money owed and payments. Click one for its latest jobs and payments.'],
                ['title' => 'Step in when needed', 'body' => 'In a link\'s details you can pause it with a reason (new jobs stop, and only you can resume it) or end it. Both clinics see your reason. Jobs already sent carry on.'],
            ]],
            'platform.roles' => ['Roles', [
                ['target' => 'rl-cards', 'title' => 'The shared roles', 'body' => 'Every clinic uses the same roles. Each card says what the role is for and how many active people hold it across all clinics. Clinics see Super Admin as Owner.'],
                ['target' => 'rl-matrix', 'title' => 'Exactly what each gives', 'body' => 'Every permission against every role, the same table clinics see. To change a role, your developers change it in code, so it is tested before it reaches clinics.'],
                ['target' => 'rl-clinics', 'title' => 'Clinic by clinic', 'body' => 'Who holds which role in each clinic, and how many single permissions owners and managers have switched on or off for one person on their Access page.'],
            ]],
            'platform.usage' => ['Clinic Usage', [
                ['target' => 'cu-period', 'title' => 'Pick a period', 'body' => 'Every figure is a total for the days you pick: this month so far, last month, the last 30 or 7 days, any earlier month ("A month…"), or your own dates ("From – to…"). Nothing is deleted, so past months stay here. Enter your hosting bill to see what each clinic costs to host.'],
                ['target' => 'cu-totals', 'title' => 'All clinics together', 'body' => 'Requests, data sent, server time and rows stored across the platform.'],
                ['target' => 'cu-clinics', 'title' => 'Clinic by clinic', 'body' => 'Sort by any column to see who uses the most, and press a clinic for its day-by-day use.'],
            ]],
            'platform.imports-other' => ['Import from other software', [
                ['target' => 'xi-tabs', 'title' => 'Two kinds of import', 'body' => 'An SQL dump from our own older system creates a new clinic. Files from other software (Cloudify\'s export, or a capture of iTestEMR\'s pages) go into a clinic you have already set up through Clinics → Onboard new clinic.'],
                ['target' => 'xi-upload', 'title' => 'Check the files first', 'body' => 'Choose the clinic and their previous software, then upload all its files at once (or one .zip): .xlsx for Cloudify; for iTestEMR, the .zip its capture button makes (choose iTestEMR to see the button and its steps). Checking writes nothing to the clinic.'],
                ['target' => 'xi-list', 'title' => 'Every import', 'body' => 'Open one to see its report, import it, follow its progress, or undo it. Uploaded files are deleted once an import finishes.'],
            ]],
            'platform.analytics' => ['Subscription Analytics', [
                ['target' => 'sa-filters', 'title' => 'Choose what to look at', 'body' => 'Pick the dates, and a clinic, plan or status if you want. Every figure on the page follows.'],
                ['target' => 'sa-export', 'title' => 'Take it away', 'body' => 'Download the figures as a CSV file for a spreadsheet.'],
            ]],
            'platform.support' => ['Support Contact', [
                ['target' => 'sp-form', 'title' => 'How clinics reach you', 'body' => 'Your name, phone, WhatsApp and email. The email also receives pricing requests and is the reply-to on quotes.'],
                ['target' => 'sp-preview', 'title' => 'What they see', 'body' => 'Clinics see this box when they are locked for non-payment and on their renewal page.'],
            ]],
            default => null,
        };
        if (! $steps) return null;
        [$title, $list] = $steps;

        return ['key' => $page, 'title' => $title, 'steps' => array_merge($list, [$end])];
    }
}
