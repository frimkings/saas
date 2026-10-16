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
            ['target' => 'att-checklist', 'title' => 'Getting started', 'body' => 'Work down this list to get going. Each step ticks itself once it is done, and the list goes away when the first five are.'],
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
